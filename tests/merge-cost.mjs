// A session with one large marker repeated thousands of times, all edited at the same moment,
// must merge in about the time of a session the same size with distinct ids. It once took
// 17.6 s of server CPU for 1.4 MB, because the kept marker's tiebreak was recomputed for every
// copy. Also checks that accepting a session keeps one marker per id.
// Run: node tests/merge-cost.mjs (needs php).
import { writeFileSync, mkdtempSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
// The large marker must win every tie (its text sorts last), so it stays the kept one, as in
// the attack: a later, smaller copy that won would make the old code cheap again.
const note = 'z'.repeat(64 * 1024);
const copies = 8000;
const big = { id: 'mBig1', frames: 1, name: 'Marker', color: '#ff0000', bid: '', note, u: 5 };
const dup = { id: 'sess1', name: 'dup', markers: Array.from({ length: copies }, (_, i) => ({ ...big, note: i ? 'a' : note })) };
const distinct = { id: 'sess1', name: 'distinct', markers: Array.from({ length: copies }, (_, i) => ({ ...big, id: 'm' + i + 'abcd', note: i ? 'a' : note })) };

const dir = mkdtempSync(join(tmpdir(), 'tally-cost-'));
writeFileSync(join(dir, 'in.json'), JSON.stringify({ dup, distinct }));
const php = `require ${JSON.stringify(join(root, 'lib.php'))};
$in = json_decode(file_get_contents($argv[1]), true);
$time = function ($s) { $t = hrtime(true); tally_merge_session($s, $s); return (hrtime(true) - $t) / 1e6; };
$clean = tally_clean_item(['kind' => 'session', 'id' => 'sess1', 'updated' => 1, 'data' => $in['dup']]);
echo json_encode(['dup' => $time($in['dup']), 'distinct' => $time($in['distinct']),
  'kept' => $clean === null ? -1 : count(json_decode($clean[4], true)['markers'])]);`;
const r = JSON.parse(execFileSync('php', ['-d', 'memory_limit=512M', '-r', php, join(dir, 'in.json')], { encoding: 'utf8', maxBuffer: 1 << 26 }));

const fail = [];
if (r.dup > Math.max(250, 20 * r.distinct)) fail.push(`duplicate ids took ${r.dup.toFixed(0)} ms against ${r.distinct.toFixed(0)} ms for distinct ids`);
if (r.kept !== 1) fail.push(`accepting a session with ${copies} copies of one id kept ${r.kept} markers, not 1`);
if (fail.length) { console.error(fail.join('\n')); process.exit(1); }
console.log(`ok: ${copies} copies of one id merged in ${r.dup.toFixed(0)} ms (distinct ids: ${r.distinct.toFixed(0)} ms); accepting the session kept 1 marker`);
