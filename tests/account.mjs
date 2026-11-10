// Saving to the account, run as the app runs it: index.html's own "Saving to the account" code in
// node:vm, in tabs that share one phone's storage, against a stand-in for api.php that answers for
// whoever holds the cookie. It checks the cases where a shared phone or a stale tab could send one
// person's sessions to another account, or where one bad row could stop a device syncing.
// Run: node tests/account.mjs (needs php, for lib.php's tally_clean_item).
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
const appCode = [
  between('const RATES = [', 'const rateById'),
  between('const RESOLVE_COLORS', 'const DEFAULT_NAMES'),
  'const MAX_BUTTONS = 10;',
  "const S = { sessions: [], folders: [], view: 'home', currentId: null }; let openId = null;",
  between('// ================= Saving to the account', '// ================= Nav bar'),
  'globalThis.T = { Acct, S, acctSync, acctNote, acctPending };',
].join('\n');

// ---- api.php, as far as the app can tell: one row per kind:id per account, one global rev ----
function server() {
  const accounts = {}, csrf = {};
  let rev = 0, cookie = null, online = true, session = 0;
  const rows = u => (accounts[u] ??= new Map());
  const put = (u, it) => rows(u).set(it.kind + ':' + it.id, { ...it, rev: ++rev });
  const reply = (status, body) => ({ ok: status === 200, status, json: async () => body });
  async function fetch(url, opts = {}) {
    if (!online) throw new TypeError('Failed to fetch');
    const q = new URLSearchParams(url.slice(url.indexOf('?') + 1)), action = q.get('action');
    if (!cookie) return reply(401, { ok: false, error: 'not_signed_in', login_url: '/login' });
    if (action === 'me') return reply(200, { ok: true, user: { name: cookie, username: cookie }, csrf: csrf[cookie] });
    // api.php refuses a request made for one account with another's cookie.
    if (q.has('as') && q.get('as') !== cookie) return reply(409, { ok: false, error: 'wrong_account' });
    if (action === 'changes') {
      const since = +q.get('since');
      const items = [...rows(cookie).values()].filter(r => r.rev > since).sort((a, b) => a.rev - b.rev);
      return reply(200, { ok: true, cursor: Math.max(since, ...items.map(r => r.rev)), more: false,
        items: items.map(({ rev, ...it }) => ({ deleted: false, data: null, ...it })) });
    }
    if (action === 'save') {
      if (opts.headers['X-Slate-CSRF'] !== csrf[cookie]) return reply(403, { ok: false, error: 'bad_csrf' });
      const out = { ok: true, saved: [], stale: [], rejected: [] };
      for (const it of JSON.parse(opts.body).items) {
        const have = rows(cookie).get(it.kind + ':' + it.id);
        if (have && have.updated >= it.updated) { out.stale.push({ kind: it.kind, id: it.id }); continue; }
        put(cookie, it); out.saved.push({ kind: it.kind, id: it.id });
      }
      return reply(200, out);
    }
    return reply(404, { ok: false, error: 'unknown_action' });
  }
  return {
    fetch, accounts, put,
    signIn(u) { cookie = u; csrf[u] = `csrf-${u}-${++session}`; },
    signOut() { cookie = null; },
    set online(v) { online = v; },
    has: (u, kind, id) => rows(u).has(kind + ':' + id),
  };
}

// ---- One phone's storage, and a tab opened on it (boot() in index.html, the account part) ----
function phone() {
  const mem = {};
  return { mem, Store: { async get(k) { return k in mem ? JSON.parse(mem[k]) : null; }, async set(k, v) { mem[k] = JSON.stringify(v); } } };
}
async function tab(ph, srv) {
  const el = () => ({ classList: { toggle() {} }, dataset: {}, textContent: '', innerHTML: '', href: '' });
  const ctx = {
    Store: ph.Store, fetch: srv.fetch, location: { protocol: 'https:' }, console: { warn() {}, log() {} },
    window: { addEventListener() {} }, document: { addEventListener() {}, visibilityState: 'visible' },
    setInterval() {}, setTimeout() { return 0; }, clearTimeout() {},
    $: el, $$: () => [], toast() {}, renderHome() {}, renderRec() {}, esc: String, safeHref: () => '',
    uid: () => Math.random().toString(36).slice(2, 10),
  };
  vm.createContext(ctx); vm.runInContext(appCode, ctx);
  const { Acct, S } = ctx.T;
  const [ss, fs, am] = await Promise.all(['tally:sessions', 'tally:folders', 'tally:account'].map(k => ph.Store.get(k)));
  if (Array.isArray(fs)) S.folders = fs;
  if (Array.isArray(ss)) S.sessions = ss;
  if (am && typeof am === 'object') Acct.meta = { owner: am.owner || null, cursor: +am.cursor || 0, pushed: am.pushed || {}, tombs: am.tombs || {} };
  vm.runInContext('acctPrime()', ctx);
  const t = ctx.T;
  // saveSessions() in index.html.
  t.newSession = (id, note = '') => {
    S.sessions.push({ id, name: id, created: 1, rateId: '24', status: 'ended', folderId: null, rolls: [],
      markers: [{ id: 'mk' + id, frames: 10, name: 'M', color: '#3d7cf2', bid: '', note, u: 5 }] });
    t.acctNote('session'); return ph.Store.set('tally:sessions', S.sessions);
  };
  t.find = id => S.sessions.find(s => s.id === id);
  return t;
}

const failures = [];
const check = (what, ok) => { console.log((ok ? 'ok   ' : 'FAIL ') + what); if (!ok) failures.push(what); };

// 1. One row the app can't use, stored in the account (here from a session someone planted in the
//    phone's storage while signed out), must not stop the person's other devices pulling or saving.
{
  const srv = server(), ph = phone(), other = phone();
  const poison = { id: 'poison01', name: 'P', created: 1, updated: 100, rateId: '24', status: 'ended', folderId: null, rolls: [],
    markers: [{ id: 'mark0001', frames: 10, name: 'M', color: { toString: 0 }, bid: '', note: '' }] };
  const badFolder = { id: 'fold0001', name: 'F', created: 1, updated: 100, buttons: [{ id: 'btn00001', name: 'B', color: { toString: 0 } }] };
  ph.mem['tally:sessions'] = JSON.stringify([poison]);
  ph.mem['tally:folders'] = JSON.stringify([badFolder]);
  srv.signIn('alice');
  const a = await tab(ph, srv); await a.acctSync();
  const sent = srv.accounts.alice?.get('session:poison01');
  check('a planted session is saved with a colour that is a colour',
    !!sent && sent.data.markers.every(m => typeof m.color === 'string'));
  check('…and a planted folder with button colours that are colours',
    !!srv.accounts.alice?.get('folder:fold0001')?.data.buttons.every(b => typeof b.color === 'string'));
  // What an older app (or anything else) may already have stored.
  srv.put('alice', { kind: 'session', id: 'poison02', updated: 200, data: { ...poison, id: 'poison02' } });
  srv.put('alice', { kind: 'folder', id: 'fold0002', updated: 200, data: { ...badFolder, id: 'fold0002' } });
  srv.put('alice', { kind: 'session', id: 'thing001', updated: 200, data: { id: 'thing001', markers: 7 } });
  srv.put('alice', { kind: 'session', id: 'good0001', updated: 300, data: { ...poison, id: 'good0001', markers: [] } });
  const b = await tab(other, srv);
  await b.newSession('local001');
  await b.acctSync(); await b.acctSync();
  check('another device stays signed in, not "offline"', b.Acct.state === 'in');
  check('…reads past the bad rows', b.Acct.meta.cursor > 0 && !!b.find('good0001'));
  check('…and still saves its own sessions', srv.has('alice', 'session', 'local001'));
  check('…keeping the bad rows it could clean, with real colours',
    b.find('poison02')?.markers[0].color === '#3d7cf2' && b.S.folders.find(f => f.id === 'fold0002')?.buttons[0].color.startsWith('#'));
  // A local copy the cleaner can't read must not stop the pull either.
  const c = phone(); c.mem['tally:sessions'] = JSON.stringify([{ id: 'thing002', updated: 1, markers: 5, owner: 'alice' }]);
  srv.put('alice', { kind: 'session', id: 'thing002', updated: 400, data: { ...poison, id: 'thing002', markers: [] } });
  const d = await tab(c, srv); await d.acctSync();
  check('a local copy that can\'t be merged doesn\'t stop the pull', d.Acct.state === 'in' && !!d.find('good0001'));
}

// 2. A tab left open as alice while bob signs in elsewhere in the same browser must not take bob's
//    sessions as alice's, or hand them to alice's account later.
{
  const srv = server(), ph = phone();
  srv.signIn('bob');
  srv.put('bob', { kind: 'session', id: 'bobSess1', updated: 10, data: { id: 'bobSess1', name: 'Bob', markers: [] } });
  srv.signIn('alice');
  const t1 = await tab(ph, srv); await t1.acctSync();
  srv.signOut(); srv.signIn('bob');
  srv.put('bob', { kind: 'session', id: 'bobSess2', updated: 20,
    data: { id: 'bobSess2', name: 'Bob later shoot', markers: [{ id: 'mkb2', frames: 1, name: 'M', color: '#3d7cf2', bid: '', note: 'bob private note 2' }] } });
  await t1.acctSync();                      // the stale tab comes back to the front
  await t1.acctSync();
  const s2 = t1.find('bobSess2');
  check('the stale tab never labels bob\'s session as alice\'s', !s2 || s2.owner === 'bob');
  check('…and notices it is now bob\'s account', t1.Acct.meta.owner === 'bob');
  const t2 = await tab(ph, srv); await t2.acctSync();
  srv.signOut(); srv.signIn('alice');
  const t3 = await tab(ph, srv); await t3.acctSync();
  check('alice\'s account never gets bob\'s sessions',
    !srv.has('alice', 'session', 'bobSess2') && !srv.has('alice', 'session', 'bobSess1'));
}

// 3. Sessions alice logs while signed in but offline, not yet saved, belong to alice: the next
//    person to sign in on the phone doesn't get them, and alice's account does when she's back.
//    Sessions made while nobody was signed in still go to whoever signs in next.
{
  const srv = server(), ph = phone();
  srv.signIn('alice');
  const t = await tab(ph, srv); await t.acctSync();
  srv.online = false;
  await t.newSession('aliceSess1', 'alice-only note');
  await t.acctSync();
  check('offline, alice\'s session waits on the phone', t.Acct.state === 'offline' && !!t.find('aliceSess1'));
  srv.online = true; srv.signOut();
  await t.acctSync();
  await t.newSession('nobodys1');            // made while signed out
  srv.signIn('bob');
  await t.acctSync();
  check('bob\'s account doesn\'t get alice\'s unsaved session', !srv.has('bob', 'session', 'aliceSess1'));
  check('…the phone still has it, still alice\'s', t.find('aliceSess1')?.owner === 'alice');
  check('…and a session made while signed out still goes to bob', srv.has('bob', 'session', 'nobodys1'));
  srv.signOut(); srv.signIn('alice');
  const t2 = await tab(ph, srv); await t2.acctSync();
  check('alice\'s account gets it when she signs in again', srv.has('alice', 'session', 'aliceSess1'));
}

// 4. api.php stores colours that are colours, or none, so an older app can't trip over them.
{
  const dir = mkdtempSync(join(tmpdir(), 'tally-acct-'));
  writeFileSync(join(dir, 'in.json'), JSON.stringify([
    { kind: 'session', id: 'poison01', updated: 1, data: { id: 'poison01', markers: [
      { id: 'mark0001', frames: 1, color: { toString: 0 } }, { id: 'mark0002', frames: 2, color: '#ff0000' }] } },
    { kind: 'folder', id: 'fold0001', updated: 1, data: { id: 'fold0001', buttons: [{ id: 'b1', color: [1] }, { id: 'b2', color: '#00ff00' }] } },
  ]));
  const php = `require ${JSON.stringify(join(root, 'lib.php'))};
$out = [];
foreach (json_decode(file_get_contents($argv[1]), true) as $it) $out[] = json_decode(tally_clean_item($it)[4], true);
echo json_encode($out);`;
  const [s, f] = JSON.parse(execFileSync('php', ['-r', php, join(dir, 'in.json')], { encoding: 'utf8' }));
  check('the server drops a marker colour that isn\'t text, and keeps one that is',
    !('color' in s.markers[0]) && s.markers[1].color === '#ff0000');
  check('…and the same for a folder\'s buttons', !('color' in f.buttons[0]) && f.buttons[1].color === '#00ff00');
}

if (failures.length) { console.error(`\n${failures.length} failure(s)`); process.exit(1); }
console.log('\nok: account sync');
