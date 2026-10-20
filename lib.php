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
function tally_start(): void
{
    try {
        require_once __DIR__ . '/../auth/auth.php';
        fm_db();
    // PDOException extends RuntimeException, so it has to be caught first.
    } catch (PDOException $e) {
        error_log('tally: ' . $e->getMessage());
        tally_reply(['error' => 'not_set_up'], 503);
    } catch (RuntimeException $e) {
        error_log('tally: ' . $e->getMessage());
        tally_reply(['error' => 'not_set_up'], 503);
    }
    fm_error_handler('json');
    if (fm_tool(TALLY_TOOL) === null) {
        error_log("tally: no '" . TALLY_TOOL . "' entry in auth/tools.php");
        tally_reply(['error' => 'not_set_up'], 503);
    }
    tally_migrate(fm_db());
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
