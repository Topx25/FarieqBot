// WhatsApp gateway hub: ONE Node process runs every number that is set to the hub mode (accounts.json "gateway": "hub",
// the main number by config.json "main_gateway": "hub"). Each number is a session of gateway_session.js with its own data
// folder, auth, archive, media and its own port and token, exactly as in the per-number mode, so moving needs no re-link and
// the dashboard calls it the same way. Built for a host that limits processes and memory:
//  - exactly one hub: a pid file, and its health port as a lock (a second copy exits when the port is taken);
//  - no worker threads, no child processes; heavy work waits in a small global queue, and a full queue answers 503 "busy";
//  - one number failing never stops the others: each session is started and restarted on its own, with a growing delay;
//  - GET /health (hub token) shows every session and the memory.
import fs from 'node:fs';
import net from 'node:net';
import http from 'node:http';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const DIR = path.dirname(fileURLToPath(import.meta.url));
const INBOX = path.join(DIR, '..', 'wa-inbox', '.data');
const readJson = (f, d) => { try { return JSON.parse(fs.readFileSync(f, 'utf8')); } catch { return d; } };
const conf = () => readJson(path.join(INBOX, 'config.json'), {}) || {};
const HUB_PORT = Number(process.env.HUB_PORT || conf().hub_port || 3190);
const PID_FILE = process.env.HUB_PID_FILE || path.join(DIR, 'hub.pid');
const FAKE = process.env.HUB_FAKE === '1'; // tests: sessions without WhatsApp (see fakeSession)

/* ---------- one hub only ---------- */
function alreadyRunning() {
  const pid = Number(readJson(PID_FILE, 0) || String(fs.existsSync(PID_FILE) ? fs.readFileSync(PID_FILE, 'utf8') : '').trim());
  if (!pid || pid === process.pid) return false;
  try { process.kill(pid, 0); } catch { return false; }
  try { return fs.readFileSync(`/proc/${pid}/cmdline`, 'utf8').includes('hub.js'); } catch { return false; }
}
if (alreadyRunning()) { console.error('hub already running'); process.exit(0); }

/* ---------- limits: a global work queue per kind of work, and per number ---------- */
const LIMITS = { send: { active: 3, queue: 30 }, archive: { active: 2, queue: 20 }, media: { active: 2, queue: 20 }, light: { active: 40, queue: 100 } };
const PER_NUMBER = { active: 6, sendsPerMinute: 40 };
const WAIT_MS = 60000;
const lanes = Object.fromEntries(Object.keys(LIMITS).map((k) => [k, { active: 0, waiting: [] }]));
const kindOf = (p) => (/^\/(chat\/send|send|media\/send|group\/send)/.test(p) ? 'send' : /^\/(archive|library|search)/.test(p) ? 'archive' : /^\/media\//.test(p) ? 'media' : 'light');
function takeLane(kind) {
  const L = lanes[kind], lim = LIMITS[kind];
  if (L.active < lim.active) { L.active++; return Promise.resolve(true); }
  if (L.waiting.length >= lim.queue) return Promise.resolve(false);
  return new Promise((res) => { const w = { res, t: setTimeout(() => { L.waiting.splice(L.waiting.indexOf(w), 1); res(false); }, WAIT_MS) }; L.waiting.push(w); });
}
function giveLane(kind) {
  const L = lanes[kind]; const w = L.waiting.shift();
  if (w) { clearTimeout(w.t); w.res(true); return; } // the place goes straight to the next one waiting
  L.active = Math.max(0, L.active - 1);
}
function heapHigh() { const m = process.memoryUsage(); return m.heapUsed > 0.7 * (m.heapTotal || 1) && m.heapUsed > 150 * 1048576; }
/** The express middleware each session puts first: back-pressure instead of piling up work. */
function makeGuard(id) {
  const me = { active: 0, sends: [] };
  sessions.get(id).load = me;
  return (req, res, next) => {
    const kind = kindOf(req.path);
    if (kind === 'light' && req.method === 'GET') { next(); return; } // status checks and small reads never wait
    if (me.active >= PER_NUMBER.active) { res.status(503).json({ ok: false, busy: true, error: 'The gateway is busy for this number, try again shortly.' }); return; }
    if (kind === 'send') { const now = Date.now(); me.sends = me.sends.filter((t) => t > now - 60000);
      if (me.sends.length >= PER_NUMBER.sendsPerMinute) { res.status(503).json({ ok: false, busy: true, error: 'Too many sends this minute for this number.' }); return; }
      me.sends.push(now); }
    me.active++;
    takeLane(kind).then((ok) => {
      if (!ok) { me.active--; res.status(503).json({ ok: false, busy: true, error: 'The gateway is busy, try again shortly.' }); return; }
      let done = false; const end = () => { if (done) return; done = true; me.active--; giveLane(kind); if (kind === 'archive' && typeof global.gc === 'function' && heapHigh()) setImmediate(() => { try { global.gc(); } catch { /* ignore */ } }); };
      res.on('finish', end); res.on('close', end); next();
    });
  };
}

/* ---------- the numbers of the hub ---------- */
/** Numbers set to the hub mode: id → { port, token, dataDir }. */
function wanted() {
  const out = new Map(); const c = conf();
  const reg = readJson(process.env.HUB_ACCOUNTS_FILE || path.join(INBOX, 'accounts.json'), []) || [];
  if (!process.env.HUB_ACCOUNTS_FILE && c.main_gateway === 'hub' && String(c.gateway_token || '').length >= 32) out.set('main', { port: Number(c.main_port || 3188), token: String(c.gateway_token), dataDir: DIR });
  for (const a of reg) {
    const id = String(a.id || '').toLowerCase().replace(/[^a-z0-9_-]/g, ''); const port = Number(a.port || 0); const token = String(a.token || '');
    if (!id || id === 'main' || a.gateway !== 'hub' || !a.enabled || a.archived || port < 1024 || port > 65535 || token.length < 32) continue;
    out.set(id, { port, token, dataDir: a.data_dir ? String(a.data_dir) : path.join(DIR, 'accounts', id) });
  }
  return out;
}
const portFree = (port) => new Promise((res) => { const s = net.createServer(); s.once('error', () => res(false)); s.listen(port, '127.0.0.1', () => s.close(() => res(true))); });

/** sessions: id → { cfg, status, handle, restarts, lastError, nextAt, log, load } */
const sessions = new Map();
const logOf = (dataDir) => { const f = path.join(dataDir, 'gateway.log'); const w = (lvl) => (...a) => { try { fs.appendFileSync(f, `${new Date().toISOString()} ${lvl}${a.map((x) => (x instanceof Error ? x.stack : typeof x === 'string' ? x : JSON.stringify(x))).join(' ')}\n`); } catch { /* ignore */ } };
  return { log: w(''), info: w(''), warn: w('WARN '), error: w('ERROR '), debug: () => {} }; };
const delay = (n) => Math.min(300000, 5000 * 2 ** Math.min(6, n)); // 5 s, 10 s, 20 s … up to 5 minutes

async function startOne(id) {
  const s = sessions.get(id); if (!s || s.status === 'starting' || s.status === 'up') return;
  s.status = 'starting';
  // never two sessions on one number: a per-number copy still holding the port means: wait for it to stop
  if (!(await portFree(s.cfg.port))) { s.status = 'waiting'; s.lastError = `port ${s.cfg.port} is still taken (a per-number gateway is running)`; s.nextAt = Date.now() + delay(s.restarts++); return; }
  fs.mkdirSync(path.join(s.cfg.dataDir, 'archive'), { recursive: true });
  const rt = { hub: true, guard: makeGuard(id), console: s.log, on: () => {},
    env: { ...process.env, ACCOUNT_ID: id, DATA_DIR: s.cfg.dataDir, PORT: String(s.cfg.port), GATEWAY_TOKEN: s.cfg.token, STORAGE_ROOT: path.join(DIR, '..', 'storage'), HOME: DIR },
    exit: (code) => { s.log.warn(`session asked to stop (${code}); restarting it on its own`); stopOne(id, true); } };
  try { s.handle = await (FAKE ? fakeSession(rt) : startSession(rt)); s.status = 'up'; s.startedAt = Date.now(); }
  catch (e) { s.lastError = String(e?.message || e); s.log.error('session failed to start', e); s.status = 'down'; s.nextAt = Date.now() + delay(s.restarts++); }
}
async function stopOne(id, restart) {
  const s = sessions.get(id); if (!s) return; const h = s.handle; s.handle = null;
  s.status = restart ? 'down' : 'stopped';
  if (h) { try { await h.stop(); } catch (e) { s.log.error('stop failed', e); } }
  if (restart) s.nextAt = Date.now() + delay(s.restarts++); else sessions.delete(id);
}
/** Every 15 s: start new numbers, stop removed ones, restart failed ones when their delay has passed. */
async function reconcile() {
  const want = wanted();
  for (const [id] of sessions) if (!want.has(id)) await stopOne(id, false);
  for (const [id, cfg] of want) {
    let s = sessions.get(id);
    if (!s) { s = { cfg, status: 'new', handle: null, restarts: 0, lastError: '', nextAt: 0, log: logOf(cfg.dataDir) }; sessions.set(id, s); }
    else if (s.cfg.port !== cfg.port || s.cfg.token !== cfg.token) { await stopOne(id, true); s.cfg = cfg; s.nextAt = 0; }
    if (['new', 'down', 'waiting'].includes(s.status) && Date.now() >= s.nextAt) startOne(id).catch(() => {});
    if (s.status === 'up' && s.startedAt && Date.now() - s.startedAt > 600000) s.restarts = 0; // up for 10 minutes: the delay starts small again
  }
}

/* ---------- the hub's own health address (also its lock) ---------- */
const hubToken = () => String(process.env.HUB_TOKEN || conf().hub_token || conf().gateway_token || '');
const health = http.createServer((req, res) => {
  const u = new URL(req.url, 'http://x'); const tok = (req.headers.authorization || '').replace(/^Bearer\s+/i, '') || u.searchParams.get('token') || '';
  if (u.pathname !== '/health') { res.writeHead(404).end(); return; }
  if (!hubToken() || tok !== hubToken()) { res.writeHead(401, { 'Content-Type': 'application/json' }).end('{"ok":false,"error":"Unauthorized"}'); return; }
  const m = process.memoryUsage();
  const body = { ok: true, pid: process.pid, uptime_s: Math.round(process.uptime()), memory_mb: { rss: Math.round(m.rss / 1048576), heap_used: Math.round(m.heapUsed / 1048576), heap_total: Math.round(m.heapTotal / 1048576) },
    queue: Object.fromEntries(Object.entries(lanes).map(([k, L]) => [k, { active: L.active, waiting: L.waiting.length, limit: LIMITS[k].active }])),
    sessions: [...sessions].map(([id, s]) => ({ id, status: s.status, restarts: s.restarts, last_error: s.lastError, port: s.cfg.port, active: s.load?.active || 0, ...(s.handle?.state ? (() => { try { const st = s.handle.state(); return { connected: !!st.connected, connecting: !!st.connecting, last_error_wa: st.lastError || '' }; } catch { return {}; } })() : {}) })) };
  res.writeHead(200, { 'Content-Type': 'application/json' }).end(JSON.stringify(body));
});
health.on('error', (e) => { console.error(`hub not started: ${e.code || e.message} on port ${HUB_PORT}`); process.exit(0); });

// one session's fault never stops the hub: logged, the session keeps its state and the watchdog sees it in /health
process.on('uncaughtException', (e) => { console.error('uncaught', e); });
process.on('unhandledRejection', (e) => { console.error('unhandled', e); });
const leave = async () => { for (const id of [...sessions.keys()]) await stopOne(id, false); try { fs.rmSync(PID_FILE, { force: true }); } catch { /* ignore */ } process.exit(0); };
process.on('SIGTERM', leave); process.on('SIGINT', leave);

let startSession = null;
health.listen(HUB_PORT, '127.0.0.1', async () => {
  fs.writeFileSync(PID_FILE, String(process.pid));
  if (!FAKE) ({ startSession } = await import('./gateway_session.js'));
  console.log(`hub listening on 127.0.0.1:${HUB_PORT} (pid ${process.pid})`);
  await reconcile(); setInterval(() => { reconcile().catch((e) => console.error('reconcile', e)); }, 15000);
});

/* ---------- tests: a session that behaves like a gateway without WhatsApp ---------- */
async function fakeSession(rt) {
  const { default: express } = await import('express');
  const app = express(); app.use(rt.guard); app.use(express.json());
  const tokenOk = (req) => (req.headers.authorization || '') === `Bearer ${rt.env.GATEWAY_TOKEN}`;
  app.get('/status', (req, res) => res.json({ ok: tokenOk(req), connected: true, account_id: rt.env.ACCOUNT_ID }));
  app.get('/archive/chats', (req, res) => { const big = Array.from({ length: 20000 }, (_, i) => ({ jid: `${i}@s.whatsapp.net`, text: 'x'.repeat(40) })); setTimeout(() => res.json({ ok: tokenOk(req), chats: big.length }), 150); });
  app.post('/chat/send', (req, res) => setTimeout(() => res.json({ ok: tokenOk(req), id: `f${Date.now()}` }), 80));
  if (rt.env.ACCOUNT_ID === 'zz_crash') setTimeout(() => { throw new Error('fake session crash'); }, 300);
  const srv = await new Promise((res, rej) => { const s = app.listen(Number(rt.env.PORT), '127.0.0.1', () => res(s)); s.on('error', rej); });
  return { state: () => ({ connected: true }), stop: () => new Promise((r) => srv.close(() => r())) };
}
