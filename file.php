<?php
/**
 * Tally — one saved session's markers as a file: ?id=<session>&fmt=edl|csv|txt.
 * Only the signed-in person's own, always as a download.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';
tally_start_page();
$user = fm_require_login(TALLY_TOOL);

$fmt = (string) ($_GET['fmt'] ?? '');
$data = tally_saved_session((int) $user['id'], (string) ($_GET['id'] ?? ''));
if (!isset(TALLY_FORMATS[$fmt]) || $data === null) {
    http_response_code(404);
    tally_page_head('Not found');
    echo '<div class="empty"><strong>That session isn’t saved to your account</strong>'
        . 'It may have been deleted. <a href="saved.php">See your saved sessions</a>.</div>';
    tally_page_foot();
    exit;
}

$s = tally_session_for_export($data);
$text = tally_build($s, $fmt);
$name = tally_file_safe($s['name']) . '.' . $fmt;

header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Content-Length: ' . strlen($text));
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
echo $text;
