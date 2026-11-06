// Two devices' copies of one session are merged on the phone (mergeSession() in index.html) and
// on the server (tally_merge_session() in lib.php). This checks the two give the same answer for
// random pairs of copies, tie-breaks and deletions included, and that a merge keeps every marker
// either side added and none that either deleted. Run: node tests/merge.mjs (needs php).
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
  "const okId = v => typeof v === 'string' && /^[A-Za-z0-9_-]{4,64}$/.test(v);",
  between('const GONE_KEPT', 'async function acctApi'),
  'globalThis.out = { mergeSession, markersSig };',
].join('\n');
const ctx = { TextEncoder }; vm.createContext(ctx); vm.runInContext(code, ctx);
const { mergeSession, markersSig } = ctx.out;

let seed = 11;
const rnd = () => (seed = (seed * 1103515245 + 12345) % 2147483648) / 2147483648;
const pick = a => a[Math.floor(rnd() * a.length)];
const notes = ['', 'Good take', 'Focus | issue', 'Café', 'Ünïcode 🎬', 'x'];
const failures = [];
const pairs = [];

for (let i = 0; i < 300; i++) {
  // What both devices had in common, then what each did on its own.
  const base = Array.from({ length: Math.floor(rnd() * 30) }, (_, j) => ({
    id: `m${i}x${j}`, frames: Math.floor(rnd() * 1e6), name: 'Marker', color: '#ff0000', bid: '', note: '',
    ...(rnd() < 0.5 ? { u: 1000 + j } : {}),
  }));
  const side = (tag, t0) => {
    const markers = base.map(m => ({ ...m })), gone = {};
    for (const m of [...markers]) {
      const r = rnd();
      if (r < 0.15) { markers.splice(markers.indexOf(m), 1); gone[m.id] = t0 + Math.floor(rnd() * 100); }
      // Same moment on both sides now and then, to exercise the tie-break.
      else if (r < 0.35) Object.assign(m, { note: pick(notes), frames: m.frames + 1, u: rnd() < 0.2 ? 5000 : t0 + Math.floor(rnd() * 100) });
    }
    for (let k = 0; k < Math.floor(rnd() * 10); k++) {
      markers.push({ id: `n${i}${tag}${k}`, frames: Math.floor(rnd() * 1e6), name: 'New', color: '#00ff00', bid: 'b1', note: pick(notes), u: t0 + k });
    }
    return { id: 's' + i, name: tag + i, status: 'rolling', rateId: '25', rolls: [], markers, ...(Object.keys(gone).length ? { gone } : {}) };
  };
  const a = side('A', 4000 + Math.floor(rnd() * 2000)), b = side('B', 4000 + Math.floor(rnd() * 2000));
  pairs.push([a, b]);

  const m = mergeSession(a, b), ids = new Set(m.markers.map(x => x.id));
  // Commutative in what it keeps.
  if (markersSig(m) !== markersSig(mergeSession(b, a))) failures.push(`pair ${i}: merge depends on order`);
  // Nothing either side added is lost.
  for (const s of [a, b]) for (const x of s.markers) if (x.id.startsWith('n') && !ids.has(x.id)) failures.push(`pair ${i}: lost ${x.id}`);
  // Nothing deleted after its last edit comes back.
  for (const s of [a, b]) for (const [id, at] of Object.entries(s.gone || {})) {
    const kept = m.markers.find(x => x.id === id);
    if (kept && (kept.u || 0) <= at) failures.push(`pair ${i}: ${id} came back`);
  }
  // Merging again changes nothing.
  if (markersSig(mergeSession(m, b)) !== markersSig(m)) failures.push(`pair ${i}: not stable`);
  if (m.name !== a.name) failures.push(`pair ${i}: took the older session's details`);
}

const dir = mkdtempSync(join(tmpdir(), 'tally-merge-'));
writeFileSync(join(dir, 'pairs.json'), JSON.stringify(pairs));
const php = `require ${JSON.stringify(join(root, 'lib.php'))};
$out = [];
foreach (json_decode(file_get_contents($argv[1]), true) as [$a, $b]) {
  $m = tally_merge_session($a, $b);
  if (isset($m['gone'])) $m['gone'] = (object) $m['gone'];
  $out[] = $m;
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);`;
const fromPhp = JSON.parse(execFileSync('php', ['-r', php, join(dir, 'pairs.json')], { encoding: 'utf8', maxBuffer: 1 << 28 }));
pairs.forEach(([a, b], i) => {
  const js = mergeSession(a, b);
  if (markersSig(js) !== markersSig(fromPhp[i])) failures.push(`pair ${i}: app and server merge differently`);
  if (fromPhp[i].name !== js.name) failures.push(`pair ${i}: server took different session details`);
});

if (failures.length) { console.error(failures.slice(0, 10).join('\n')); console.error(`${failures.length} failure(s)`); process.exit(1); }
console.log(`ok: ${pairs.length} pairs merged the same in the app and on the server`);
