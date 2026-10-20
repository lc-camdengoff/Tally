<?php
/**
 * Tally — Saved sessions: everything someone has saved to their account, by
 * folder, with each one's files to download. The same files the app shares,
 * built here from the saved copy, so any computer can get them without
 * opening the app.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';
tally_start_page();
$user = fm_require_login(TALLY_TOOL);

$saved = tally_saved((int) $user['id']);
$byFolder = [];
foreach ($saved['sessions'] as $s) {
    $fid = is_string($s['folderId'] ?? null) && isset($saved['folders'][$s['folderId']]) ? $s['folderId'] : '';
    $byFolder[$fid][] = $s;
}
// Folders newest first, as the app lists them; sessions not in one last.
$order = array_keys($saved['folders']);
usort($order, static fn(string $a, string $b): int => $saved['folders'][$b]['created'] <=> $saved['folders'][$a]['created']);
$order = array_values(array_filter($order, static fn(string $f): bool => isset($byFolder[$f])));
if (isset($byFolder[''])) {
    $order[] = '';
}

/** "Oct 20, 2026, 3:04 PM" in the team's time, from the app's ms timestamp. */
function tally_when($ms): string
{
    if (!is_numeric($ms) || $ms <= 0) {
        return '';
    }
    $d = (new DateTimeImmutable('@' . intdiv((int) $ms, 1000)))
        ->setTimezone(new DateTimeZone(TALLY_TIMEZONE));
    return $d->format('M j, Y, g:i A');
}

tally_page_head('Saved sessions');
?>
<div class="head">
  <div>
    <div class="eyebrow">Tally</div>
    <h1>Saved sessions</h1>
    <p class="lede">Everything you’ve saved to your account from Tally, on any device. Download a session’s
      markers here, or <a href="./">open Tally</a> to log more.</p>
  </div>
</div>

<?php if (!$saved['sessions']): ?>
  <div class="empty">
    <strong>Nothing saved yet</strong>
    Open <a href="./">Tally</a> on your phone while you’re signed in, and each session you log saves here.
  </div>
<?php endif; ?>

<?php foreach ($order as $fid):
    $list = $byFolder[$fid]; ?>
  <section class="folder">
    <h2><?= $fid === '' ? ($order !== [''] ? 'Not in a folder' : 'Sessions') : fm_h($saved['folders'][$fid]['name']) ?>
      <span class="count"><?= count($list) ?> session<?= count($list) === 1 ? '' : 's' ?></span></h2>
    <ul class="sessions">
    <?php foreach ($list as $raw):
        $s = tally_session_for_export($raw);
        $n = count($s['markers']);
        $live = ($raw['status'] ?? '') === 'rolling';
        $rate = tally_rate($s['rateId']); ?>
      <li>
        <div class="main">
          <div class="name"><?= fm_h($s['name']) ?><?= $live ? ' <span class="live">Live</span>' : '' ?></div>
          <div class="sub">
            <span><?= fm_h(tally_when($raw['created'] ?? null)) ?></span>
            <span><?= fm_h($rate['label']) ?> fps</span>
            <span><?= $n ?> marker<?= $n === 1 ? '' : 's' ?></span>
          </div>
          <?php if ($n): ?>
            <div class="swatches" aria-hidden="true"><?php foreach (array_slice($s['markers'], 0, 40) as $m): ?><svg viewBox="0 0 14 6" width="14" height="6"><rect width="14" height="6" rx="1" fill="<?= fm_h($m['color']) ?>"/></svg><?php endforeach; ?></div>
          <?php endif; ?>
        </div>
        <div class="files">
          <?php if ($n): foreach (TALLY_FORMATS as $fmt => $label): ?>
            <a class="btn small<?= $fmt === 'edl' ? '' : ' ghost' ?>" href="file.php?id=<?= rawurlencode($s['id']) ?>&amp;fmt=<?= $fmt ?>"
               download><?= fm_h($label) ?></a>
          <?php endforeach; else: ?>
            <span class="none">No markers</span>
          <?php endif; ?>
        </div>
      </li>
    <?php endforeach; ?>
    </ul>
  </section>
<?php endforeach; ?>
<?php
tally_page_foot();
