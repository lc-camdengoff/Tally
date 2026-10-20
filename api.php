<?php
/**
 * Tally — what the app talks to, to save to someone's account. JSON in and
 * out. The session cookie is the shared sign-in's; writes also need the
 * X-Slate-CSRF header, whose value ?action=me hands out.
 *
 *   GET  ?action=me                 →  {user, csrf}, or 401 {login_url}
 *   GET  ?action=changes&since=N    →  {items, cursor, more}  what changed after N
 *   POST ?action=save   {items}     →  {saved, stale, rejected}
 *
 * An item is {kind: 'session'|'folder', id, updated (ms), deleted?, data}.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';
tally_start();

header('X-Content-Type-Options: nosniff');

$action = (string) ($_GET['action'] ?? '');
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

$user = fm_current_user();
if ($user === null) {
    // The app shows a Sign in link with this. Back to the app afterwards.
    tally_reply(['error' => 'not_signed_in',
                 'login_url' => fm_auth_url_with_next('login.php', tally_app_path())], 401);
}
if (!fm_can_use($user, TALLY_TOOL)) {
    tally_reply(['error' => 'no_access'], 403);
}
$userId = (int) $user['id'];

if ($action === 'me') {
    tally_reply([
        'user' => ['name' => (string) $user['display_name'], 'username' => (string) $user['username']],
        'csrf' => fm_csrf_token(),
    ]);
}

if ($action === 'changes') {
    $since = filter_var($_GET['since'] ?? '0', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    if ($since === false) {
        tally_reply(['error' => 'bad_request'], 400);
    }
    tally_reply(tally_changes($userId, $since));
}

if ($action === 'save') {
    if ($method !== 'POST') {
        tally_reply(['error' => 'post_only'], 405);
    }
    $given = $_SERVER['HTTP_X_SLATE_CSRF'] ?? null;
    if (!fm_check_csrf(is_string($given) ? $given : null)) {
        tally_reply(['error' => 'bad_csrf'], 403);
    }
    $raw = file_get_contents('php://input', false, null, 0, TALLY_MAX_BODY_BYTES + 1);
    if ($raw === false || strlen($raw) > TALLY_MAX_BODY_BYTES) {
        tally_reply(['error' => 'too_big'], 413);
    }
    $body = json_decode($raw, true);
    $items = is_array($body) ? ($body['items'] ?? null) : null;
    if (!is_array($items) || !array_is_list($items) || count($items) > TALLY_MAX_ITEMS_PER_SAVE) {
        tally_reply(['error' => 'bad_request'], 400);
    }
    tally_reply(tally_save($userId, $items));
}

tally_reply(['error' => 'unknown_action'], 404);
