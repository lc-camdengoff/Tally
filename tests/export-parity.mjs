// The Saved sessions page builds its downloads in PHP (lib.php); the app builds them in
// index.html. This checks the two make the same files, byte for byte, for random sessions at
// every frame rate, drop frame included. Run: node tests/export-parity.mjs (needs php).
import { readFileSync, writeFileSync, mkdtempSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const html = readFileSync(join(root, 'index.html'), 'utf8');
const between = (from, to) => {
  const a = html.indexOf(from), b = html.indexOf(to, a);
  if (a < 0 || b < 0) throw new Error(`index.html no longer has ${from} … ${to}`);
  return html.slice(a, b);
};
// The app's own code, not a copy of it.
const code = [
  between('// ===== Timecode math =====', '// ===== LTC decoder'),
  between('const RESOLVE_COLORS', 'const LOOKS'),
  between('function hexToRgb', 'function inkFor'),
  between('const sortedMarkers', 'function sessionRange'),
  between('function edlSafe', 'function exportFiles'),
  'globalThis.out = { RATES, buildEDL, buildCSV, buildTXT, fileSafe };',
].join('\n');
const ctx = {}; vm.createContext(ctx); vm.runInContext(code, ctx);
const { RATES, buildEDL, buildCSV, buildTXT, fileSafe } = ctx.out;

let seed = 7;
const rnd = () => (seed = (seed * 1103515245 + 12345) % 2147483648) / 2147483648;
const pick = a => a[Math.floor(rnd() * a.length)];
const words = ['Good take', 'Bad, take', 'Line "flub"', 'Focus | issue', 'B-roll', 'Café', 'Pickup', 'Note\nline', ''];
const sessions = [];
for (let i = 0; i < 60; i++) {
  const rate = pick(RATES);
  const markers = Array.from({ length: Math.floor(rnd() * 25) }, (_, j) => ({
    id: 'm' + i + 'x' + j, frames: Math.floor(rnd() * 24 * 3600 * 60 * 1.2), name: pick(words) || 'Marker',
    color: '#' + Math.floor(rnd() * 0xffffff).toString(16).padStart(6, '0'), bid: '', note: rnd() < 0.3 ? pick(words) : '',
  }));
  // Some on the same frame, to check ties keep their order.
  if (markers.length > 2) markers[1].frames = markers[0].frames;
  sessions.push({ id: 'sess' + i, name: pick(['Day 1 A-cam', 'Easter: "promo"', 'Pickups / Café', 'x']) + ' ' + i,
    rateId: rate.id, created: Date.now(), status: 'ended', markers, rolls: [] });
}

const dir = mkdtempSync(join(tmpdir(), 'tally-parity-'));
writeFileSync(join(dir, 'sessions.json'), JSON.stringify(sessions));
const php = `require ${JSON.stringify(join(root, 'lib.php'))};
$out = [];
foreach (json_decode(file_get_contents($argv[1]), true) as $raw) {
  $s = tally_session_for_export($raw);
  $out[] = ['edl' => tally_build($s, 'edl'), 'csv' => tally_build($s, 'csv'), 'txt' => tally_build($s, 'txt'),
            'file' => tally_file_safe($s['name'])];
}
echo json_encode($out);`;
const fromPhp = JSON.parse(execFileSync('php', ['-r', php, join(dir, 'sessions.json')], { encoding: 'utf8' }));

let bad = 0;
sessions.forEach((s, i) => {
  const want = { edl: buildEDL([s], s.name), csv: buildCSV([s]), txt: buildTXT([s]), file: fileSafe(s.name) };
  for (const k of Object.keys(want)) {
    if (want[k] !== fromPhp[i][k]) {
      bad++;
      if (bad <= 3) console.error(`session ${i} (${s.rateId}) ${k} differs:\n--- app\n${want[k].slice(0, 400)}\n--- php\n${fromPhp[i][k].slice(0, 400)}`);
    }
  }
});
if (bad) { console.error(`${bad} file(s) differ`); process.exit(1); }
console.log(`ok: ${sessions.length} sessions, ${sessions.reduce((n, s) => n + s.markers.length, 0)} markers, every format matches`);
