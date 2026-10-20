<?php
/**
 * Tally — saving to the filmmaking accounts.
 *
 * Tally itself is index.html, a static page that runs offline and keeps
 * everything on the phone. This is the one part that runs on the server: when
 * someone is signed in to their filmmaking account, api.php keeps a copy of
 * their sessions and folders in the shared database, so they're safe if the
 * phone is lost and show up on their other devices. Sign-in is the shared one
 * in ../auth/; nothing here does authentication.
 */

declare(strict_types=1);

const TALLY_TOOL = 'tally';

/** What can be saved. Keys are stored; never rename one. */
const TALLY_KINDS = ['session', 'folder'];

// A session is a few hundred bytes plus about 150 per marker, so this is tens
// of thousands of markers. A body over it is refused rather than truncated.
const TALLY_MAX_ITEM_BYTES = 2 * 1024 * 1024;
const TALLY_MAX_BODY_BYTES = 8 * 1024 * 1024;
const TALLY_MAX_ITEMS_PER_SAVE = 100;
// Per person, deleted ones included. Generous: a busy year is a few hundred.
const TALLY_MAX_ITEMS = 20000;
// How many changes one pull returns; the app asks again for the rest.
const TALLY_PULL_PAGE = 200;
// The team's, for dates on the Saved sessions page. The app's own times are the phone's.
const TALLY_TIMEZONE = 'America/Chicago';

/** Answer with JSON and stop. */
function tally_reply(array $body, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['ok' => $status < 400] + $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Load the shared accounts and make sure the table exists.
 *
 * A missing config or database answers 503 rather than 401, so the app says
 * saving isn't working rather than asking someone to sign in again.
 */
function tally_start(bool $json = true): void
{
    $fail = static function () use ($json): void {
        if ($json) {
            tally_reply(['error' => 'not_set_up'], 503);
        }
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Saving to accounts isn't set up on this server yet. Tally itself still works.\n";
        exit;
    };
    try {
        require_once __DIR__ . '/../auth/auth.php';
        fm_db();
    // PDOException extends RuntimeException, so it has to be caught first.
    } catch (PDOException $e) {
        error_log('tally: ' . $e->getMessage());
        $fail();
    } catch (RuntimeException $e) {
        error_log('tally: ' . $e->getMessage());
        $fail();
    }
    fm_error_handler($json ? 'json' : 'html');
    if (fm_tool(TALLY_TOOL) === null) {
        error_log("tally: no '" . TALLY_TOOL . "' entry in auth/tools.php");
        $fail();
    }
    tally_migrate(fm_db());
}

/** tally_start() for a page a person opens rather than one the app calls. */
function tally_start_page(): void
{
    tally_start(false);
}

function tally_migrate(PDO $pdo): void
{
    $have = $pdo->query("SELECT to_regclass('public.tally_items') IS NOT NULL AS ok")->fetch();
    if (!empty($have['ok'])) {
        return;
    }
    $pdo->exec('SELECT pg_advisory_lock(8264800)');
    try {
        $sql = file_get_contents(__DIR__ . '/schema.sql');
        if ($sql === false) {
            throw new RuntimeException('schema_missing');
        }
        $pdo->exec($sql);
    } finally {
        $pdo->exec('SELECT pg_advisory_unlock(8264800)');
    }
}

/**
 * Where sign-in should send someone back to: this folder, i.e. the app.
 * Through fm_safe_next() like any other return address.
 */
function tally_app_path(): string
{
    $dir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
    return fm_safe_next($dir . '/');
}

/** The ids the app makes: letters and digits, see uid() in index.html. */
function tally_valid_id(string $id): bool
{
    return (bool) preg_match('/^[A-Za-z0-9_-]{4,64}$/', $id);
}

/**
 * Check one item from the app before it's stored.
 *
 * Only what the server depends on (its kind, id, time) and what another of
 * the person's devices will trust: the app escapes names and notes when it
 * draws them, so those are kept as typed; it also re-checks colours.
 *
 * @return ?array [kind, id, updated, deleted, json] or null if it won't do
 */
function tally_clean_item($item): ?array
{
    if (!is_array($item)) {
        return null;
    }
    $kind = (string) ($item['kind'] ?? '');
    $id = (string) ($item['id'] ?? '');
    $updated = $item['updated'] ?? null;
    if (!in_array($kind, TALLY_KINDS, true) || !tally_valid_id($id)
        || !is_int($updated) || $updated <= 0) {
        return null;
    }
    $deleted = ($item['deleted'] ?? false) === true;
    if ($deleted) {
        return [$kind, $id, $updated, true, null];
    }
    $data = $item['data'] ?? null;
    if (!is_array($data) || (string) ($data['id'] ?? '') !== $id) {
        return null;
    }
    if ($kind === 'session' && (!isset($data['markers']) || !is_array($data['markers']))) {
        return null;
    }
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false || strlen($json) > TALLY_MAX_ITEM_BYTES) {
        return null;
    }
    return [$kind, $id, $updated, false, $json];
}

/**
 * Store what the app sent, each only if it's newer than what's here: two
 * devices editing the same session settle on the later change.
 *
 * @return array{saved: list<array>, stale: list<array>, rejected: list<array>}
 */
function tally_save(int $userId, array $items): array
{
    $pdo = fm_db();
    $out = ['saved' => [], 'stale' => [], 'rejected' => []];
    $upsert = $pdo->prepare(
        "INSERT INTO tally_items (user_id, kind, id, data, updated, deleted)
              VALUES (?, ?, ?, CAST(? AS jsonb), ?, ?)
         ON CONFLICT (user_id, kind, id) DO UPDATE
                 SET data = EXCLUDED.data, updated = EXCLUDED.updated, deleted = EXCLUDED.deleted,
                     rev = nextval('tally_rev'), saved_at = now()
               WHERE tally_items.updated < EXCLUDED.updated
           RETURNING rev"
    );
    $count = $pdo->prepare('SELECT count(*) AS n FROM tally_items WHERE user_id = ?');
    $count->execute([$userId]);
    $have = (int) $count->fetch()['n'];

    foreach ($items as $raw) {
        $clean = tally_clean_item($raw);
        $ref = ['kind' => (string) ($raw['kind'] ?? ''), 'id' => (string) ($raw['id'] ?? '')];
        if ($clean === null) {
            $out['rejected'][] = $ref;
            continue;
        }
        [$kind, $id, $updated, $deleted, $json] = $clean;
        if ($have >= TALLY_MAX_ITEMS && !tally_exists($userId, $kind, $id)) {
            $out['rejected'][] = $ref + ['reason' => 'full'];
            continue;
        }
        $upsert->bindValue(1, $userId, PDO::PARAM_INT);
        $upsert->bindValue(2, $kind);
        $upsert->bindValue(3, $id);
        $upsert->bindValue(4, $json, $json === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $upsert->bindValue(5, $updated, PDO::PARAM_INT);
        fm_bind_bool($upsert, 6, $deleted);
        $upsert->execute();
        $row = $upsert->fetch();
        if ($row === false) {
            // Not newer than what's here: the app takes the stored one on its
            // next pull.
            $out['stale'][] = $ref;
        } else {
            $out['saved'][] = $ref + ['updated' => $updated];
            $have++;
        }
    }
    return $out;
}

function tally_exists(int $userId, string $kind, string $id): bool
{
    $stmt = fm_db()->prepare('SELECT 1 FROM tally_items WHERE user_id = ? AND kind = ? AND id = ?');
    $stmt->execute([$userId, $kind, $id]);
    return $stmt->fetch() !== false;
}

/**
 * What changed since the app last asked, oldest first.
 *
 * @return array{items: list<array>, cursor: int, more: bool}
 */
function tally_changes(int $userId, int $since): array
{
    $stmt = fm_db()->prepare(
        'SELECT kind, id, data, updated, deleted, rev FROM tally_items
          WHERE user_id = ? AND rev > ? ORDER BY rev LIMIT ' . (TALLY_PULL_PAGE + 1)
    );
    $stmt->execute([$userId, $since]);
    $rows = $stmt->fetchAll();
    $more = count($rows) > TALLY_PULL_PAGE;
    $rows = array_slice($rows, 0, TALLY_PULL_PAGE);
    $items = [];
    $cursor = $since;
    foreach ($rows as $row) {
        $deleted = $row['deleted'] === true || $row['deleted'] === 't';
        $items[] = [
            'kind' => (string) $row['kind'],
            'id' => (string) $row['id'],
            'updated' => (int) $row['updated'],
            'deleted' => $deleted,
            'data' => $deleted || $row['data'] === null ? null : json_decode((string) $row['data'], true),
        ];
        $cursor = max($cursor, (int) $row['rev']);
    }
    return ['items' => $items, 'cursor' => $cursor, 'more' => $more];
}

// ---------------------------------------------------------------------------
// Exports, for the Saved sessions page
//
// The same files the app makes (buildEDL/buildCSV/buildTXT in index.html),
// so a session downloaded here is byte for byte what the phone would have
// shared. tests/export-parity.mjs checks the two agree; change both together.
// ---------------------------------------------------------------------------

/** index.html's RATES: id => [label, base, df]. */
const TALLY_RATES = [
    '23.976' => ['23.976', 24, false], '24' => ['24', 24, false], '25' => ['25', 25, false],
    '29.97' => ['29.97 NDF', 30, false], '29.97df' => ['29.97 DF', 30, true], '30' => ['30', 30, false],
    '48' => ['48', 48, false], '50' => ['50', 50, false], '59.94' => ['59.94 NDF', 60, false],
    '59.94df' => ['59.94 DF', 60, true], '60' => ['60', 60, false],
];

/** index.html's RESOLVE_COLORS, in its order: the first of two equally near wins. */
const TALLY_RESOLVE_COLORS = [
    'Blue' => '#3d7cf2', 'Cyan' => '#2fc5d8', 'Green' => '#3fb24a', 'Yellow' => '#f2c12e', 'Red' => '#e8423a',
    'Pink' => '#f35ca8', 'Purple' => '#9a4fd1', 'Fuchsia' => '#c33db8', 'Rose' => '#f08ca0',
    'Lavender' => '#a795f0', 'Sky' => '#86c8f0', 'Mint' => '#72ddb0', 'Lemon' => '#ecec70',
    'Sand' => '#c8a06a', 'Cocoa' => '#6e4e3c', 'Cream' => '#f2e6cc',
];

const TALLY_FORMATS = ['edl' => 'Resolve EDL', 'csv' => 'CSV', 'txt' => 'Text list'];

/** @return array{label: string, base: int, df: bool} */
function tally_rate(?string $id): array
{
    [$label, $base, $df] = TALLY_RATES[$id] ?? TALLY_RATES['23.976'];
    return ['label' => $label, 'base' => $base, 'df' => $df];
}

function tally_frames_per_day(array $r): int
{
    if (!$r['df']) {
        return $r['base'] * 86400;
    }
    $drop = $r['base'] === 30 ? 2 : 4;
    return ($r['base'] * 600 - $drop * 9) * 144;
}

function tally_tc(int $f, array $r): string
{
    $day = tally_frames_per_day($r);
    $f = (($f % $day) + $day) % $day;
    $b = $r['base'];
    if ($r['df']) {
        $drop = $b === 30 ? 2 : 4;
        $per10 = $b * 600 - $drop * 9;
        $perMin = $b * 60 - $drop;
        $d = intdiv($f, $per10);
        $m = $f % $per10;
        $f += $drop * 9 * $d + ($m > $drop ? $drop * intdiv($m - $drop, $perMin) : 0);
    }
    return sprintf('%02d:%02d:%02d%s%02d', intdiv($f, $b * 3600), intdiv($f, $b * 60) % 60, intdiv($f, $b) % 60,
        $r['df'] ? ';' : ':', $f % $b);
}

/** @return array{float, float, float} */
function tally_lab(string $hex): array
{
    $n = hexdec(ltrim($hex, '#'));
    $lin = static function (int $c): float {
        $c /= 255;
        return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    };
    [$r, $g, $b] = [$lin(($n >> 16) & 255), $lin(($n >> 8) & 255), $lin($n & 255)];
    $x = ($r * 0.4124 + $g * 0.3576 + $b * 0.1805) / 0.95047;
    $y = $r * 0.2126 + $g * 0.7152 + $b * 0.0722;
    $z = ($r * 0.0193 + $g * 0.1192 + $b * 0.9505) / 1.08883;
    $t = static fn(float $v): float => $v > 0.008856 ? $v ** (1 / 3) : 7.787 * $v + 16 / 116;
    [$x, $y, $z] = [$t($x), $t($y), $t($z)];
    return [116 * $y - 16, 500 * ($x - $y), 200 * ($y - $z)];
}

function tally_nearest_resolve(string $hex): string
{
    $a = tally_lab($hex);
    $best = 'Blue';
    $bd = INF;
    foreach (TALLY_RESOLVE_COLORS as $name => $h) {
        $b = tally_lab($h);
        $d = ($a[0] - $b[0]) ** 2 + ($a[1] - $b[1]) ** 2 + ($a[2] - $b[2]) ** 2;
        if ($d < $bd) {
            $bd = $d;
            $best = $name;
        }
    }
    return $best;
}

/**
 * A session as stored, made safe to build from: markers with whole frame
 * counts, colours that are colours, text that is text.
 */
function tally_session_for_export(array $data): array
{
    $markers = [];
    foreach (is_array($data['markers'] ?? null) ? $data['markers'] : [] as $m) {
        if (!is_array($m) || !is_numeric($m['frames'] ?? null)) {
            continue;
        }
        $color = (string) ($m['color'] ?? '');
        $markers[] = [
            'frames' => (int) floor((float) $m['frames']),
            'name' => is_string($m['name'] ?? null) && $m['name'] !== '' ? $m['name'] : 'Marker',
            'color' => preg_match('/^#[0-9a-f]{6}$/i', $color) ? $color : TALLY_RESOLVE_COLORS['Blue'],
            'note' => is_string($m['note'] ?? null) ? $m['note'] : '',
        ];
    }
    // Stable since PHP 8, like Array.prototype.sort.
    usort($markers, static fn(array $a, array $b): int => $a['frames'] <=> $b['frames']);
    return [
        'id' => (string) ($data['id'] ?? ''),
        'name' => is_string($data['name'] ?? null) && $data['name'] !== '' ? $data['name'] : 'Session',
        'rateId' => is_string($data['rateId'] ?? null) && isset(TALLY_RATES[$data['rateId']]) ? $data['rateId'] : '23.976',
        'markers' => $markers,
    ];
}

function tally_edl_safe(string $s): string
{
    return trim((string) preg_replace('/[|\r\n]+/', ' ', $s));
}

function tally_build_edl(array $s): string
{
    $r = tally_rate($s['rateId']);
    $out = ['TITLE: ' . tally_edl_safe($s['name']), 'FCM: ' . ($r['df'] ? 'DROP FRAME' : 'NON-DROP FRAME'), ''];
    foreach ($s['markers'] as $i => $m) {
        $a = tally_tc($m['frames'], $r);
        $b = tally_tc($m['frames'] + 1, $r);
        $name = tally_edl_safe($m['note'] !== '' ? $m['name'] . ' - ' . $m['note'] : $m['name']);
        $out[] = sprintf('%03d', $i + 1) . "  001      V     C        $a $b $a $b  ";
        $out[] = ' |C:ResolveColor' . tally_nearest_resolve($m['color']) . " |M:$name |D:1";
        $out[] = '';
    }
    return implode("\n", $out);
}

function tally_csv_cell($v): string
{
    $v = (string) $v;
    return preg_match('/[",\n]/', $v) ? '"' . str_replace('"', '""', $v) . '"' : $v;
}

function tally_build_csv(array $s): string
{
    $r = tally_rate($s['rateId']);
    $rows = [['Session', 'Marker', 'Timecode', 'Name', 'Color', 'Resolve color', 'Note', 'Frame rate']];
    foreach ($s['markers'] as $i => $m) {
        $rows[] = [$s['name'], $i + 1, tally_tc($m['frames'], $r), $m['name'], $m['color'],
            tally_nearest_resolve($m['color']), $m['note'], $r['label']];
    }
    return implode("\n", array_map(static fn(array $row): string => implode(',', array_map('tally_csv_cell', $row)), $rows));
}

function tally_build_txt(array $s): string
{
    $r = tally_rate($s['rateId']);
    $n = count($s['markers']);
    $lines = [$s['name'], $r['label'] . ' fps, ' . $n . ' marker' . ($n === 1 ? '' : 's'), ''];
    foreach ($s['markers'] as $i => $m) {
        $lines[] = sprintf('%3d', $i + 1) . '  ' . tally_tc($m['frames'], $r) . '  ' . $m['name']
            . ($m['note'] !== '' ? '  (' . $m['note'] . ')' : '');
    }
    return implode("\n", $lines);
}

/** index.html's fileSafe(): the name a download is saved under. */
function tally_file_safe(string $s): string
{
    $s = trim((string) preg_replace('/[^\w\- ]+/', '', $s));
    $s = (string) preg_replace('/\s+/', '_', $s);
    return $s === '' ? 'markers' : $s;
}

function tally_build(array $s, string $fmt): string
{
    return $fmt === 'edl' ? tally_build_edl($s) : ($fmt === 'csv' ? tally_build_csv($s) : tally_build_txt($s));
}

// ---------------------------------------------------------------------------
// Pages
// ---------------------------------------------------------------------------

/** A file in this folder with its deploy time on, like fm_asset_url(). */
function tally_asset_url(string $file): string
{
    $time = @filemtime(__DIR__ . '/' . $file);
    return $file . ($time ? '?v=' . $time : '');
}

/** The top of a page here: headers, the shared look and nav bar, this tool's styles. */
function tally_page_head(string $title): void
{
    require_once __DIR__ . '/../auth/page.php';
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    // Like The Scripts': nav.css, the favicon and people's photos come from
    // the auth folder, brand.css and its fonts from style/ beside it.
    $parts = parse_url(fm_auth_url());
    $auth = empty($parts['scheme']) || empty($parts['host']) ? '' : ' ' . strtolower($parts['scheme'] . '://'
        . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''));
    $self = "'self'" . $auth;
    header("Content-Security-Policy: default-src 'none'; style-src $self; font-src $self; img-src $self; "
        . "form-action $self; base-uri 'none'; frame-ancestors 'none'");
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/png" sizes="192x192" href="icons/icon-192.png">
<link rel="stylesheet" href="<?= fm_h(fm_style_url()) ?>">
<link rel="stylesheet" href="<?= fm_h(fm_asset_url('nav.css')) ?>">
<link rel="stylesheet" href="<?= fm_h(tally_asset_url('saved.css')) ?>">
<title><?= fm_h($title) ?> — Tally</title>
</head>
<body>
    <?php
    fm_nav((string) (fm_tool(TALLY_TOOL)['path'] ?? ''));
    echo '<main class="page">';
}

function tally_page_foot(): void
{
    echo "</main>\n</body>\n</html>\n";
}

/**
 * Someone's saved sessions and folders, newest first, deleted ones left out.
 *
 * @return array{sessions: list<array>, folders: array<string, array>}
 */
function tally_saved(int $userId): array
{
    $stmt = fm_db()->prepare(
        "SELECT kind, data, saved_at FROM tally_items WHERE user_id = ? AND NOT deleted AND data IS NOT NULL"
    );
    $stmt->execute([$userId]);
    $sessions = [];
    $folders = [];
    foreach ($stmt->fetchAll() as $row) {
        $data = json_decode((string) $row['data'], true);
        if (!is_array($data) || !tally_valid_id((string) ($data['id'] ?? ''))) {
            continue;
        }
        if ($row['kind'] === 'folder') {
            $folders[$data['id']] = ['name' => is_string($data['name'] ?? null) ? $data['name'] : 'Folder',
                                     'created' => (float) ($data['created'] ?? 0)];
        } elseif ($row['kind'] === 'session') {
            $data['_saved_at'] = (string) $row['saved_at'];
            $sessions[] = $data;
        }
    }
    usort($sessions, static fn(array $a, array $b): int => ($b['created'] ?? 0) <=> ($a['created'] ?? 0));
    return ['sessions' => $sessions, 'folders' => $folders];
}

/** One saved session of someone's, or null. */
function tally_saved_session(int $userId, string $id): ?array
{
    if (!tally_valid_id($id)) {
        return null;
    }
    $stmt = fm_db()->prepare(
        "SELECT data FROM tally_items WHERE user_id = ? AND kind = 'session' AND id = ? AND NOT deleted"
    );
    $stmt->execute([$userId, $id]);
    $row = $stmt->fetch();
    $data = $row === false ? null : json_decode((string) $row['data'], true);
    return is_array($data) ? $data : null;
}
