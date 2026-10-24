// Load test of the hub with made-up sessions (HUB_FAKE=1: no WhatsApp, nothing sent): 20 numbers in one process,
// a burst of heavy requests, one session that crashes, and a second hub started on purpose.
// Checks: one hub process, the queue limits hold (503 busy instead of piling up), memory stays under the cap,
// a crashing session does not stop the others, a second copy exits. Run: node wa-local/test/hub_load.mjs
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import crypto from 'node:crypto';
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const DIR = path.dirname(fileURLToPath(import.meta.url));
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'hubtest-'));
const HUB_PORT = 3390, N = 20, BASE = 3400, HEAP_MB = 192;
const tok = () => crypto.randomBytes(24).toString('hex');
const accounts = Array.from({ length: N }, (_, i) => ({ id: i === N - 1 ? 'zz_crash' : `zz${i}`, port: BASE + i, token: tok(), enabled: true, gateway: 'hub', data_dir: path.join(tmp, `a${i}`) }));
accounts.push({ id: 'zz_other', port: BASE + 50, token: tok(), enabled: true, data_dir: path.join(tmp, 'other') }); // not in the hub mode: never started
fs.writeFileSync(path.join(tmp, 'accounts.json'), JSON.stringify(accounts));
const hubToken = tok();
const env = { ...process.env, HUB_FAKE: '1', HUB_PORT: String(HUB_PORT), HUB_ACCOUNTS_FILE: path.join(tmp, 'accounts.json'), HUB_PID_FILE: path.join(tmp, 'hub.pid'), HUB_TOKEN: hubToken };
const args = ['--v8-pool-size=1', '--expose-gc', `--max-old-space-size=${HEAP_MB}`, path.join(DIR, '..', 'hub.js')];
const results = []; const check = (name, ok, seen = '') => { results.push({ name, ok, seen }); console.log(`${ok ? '✅' : '❌'} ${name}${ok ? '' : `  (seen: ${seen})`}`); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const health = async () => { try { const r = await fetch(`http://127.0.0.1:${HUB_PORT}/health`, { headers: { Authorization: `Bearer ${hubToken}` } }); return await r.json(); } catch { return null; } };

const hub = spawn(process.execPath, args, { env, stdio: ['ignore', 'pipe', 'pipe'] }); let hubOut = '';
hub.stdout.on('data', (d) => { hubOut += d; }); hub.stderr.on('data', (d) => { hubOut += d; });
let h = null; for (let i = 0; i < 40 && !(h && h.sessions?.filter((s) => s.status === 'up').length >= N); i++) { await sleep(500); h = await health(); }
check(`all ${N} hub numbers are up in one process`, h && h.sessions.filter((s) => s.status === 'up').length === N, JSON.stringify(h?.sessions?.map((s) => s.status)));
check('a number not set to the hub mode is not started', h && !h.sessions.some((s) => s.id === 'zz_other'));
check('the health address needs the hub token', (await fetch(`http://127.0.0.1:${HUB_PORT}/health`)).status === 401);

// a second hub must leave at once (the pid file and the health port are its lock)
const second = spawn(process.execPath, args, { env, stdio: 'ignore' });
const secondCode = await new Promise((r) => { const t = setTimeout(() => { second.kill('SIGKILL'); r('still running'); }, 8000); second.on('exit', (c) => { clearTimeout(t); r(c); }); });
check('a second hub exits at once', secondCode === 0, String(secondCode));

// burst: heavy archive reads and sends on every number at the same time
let peak = { archive: 0, send: 0 }; let maxRss = 0; let sampling = true;
(async () => { while (sampling) { const x = await health(); if (x) { peak.archive = Math.max(peak.archive, x.queue.archive.active); peak.send = Math.max(peak.send, x.queue.send.active); maxRss = Math.max(maxRss, x.memory_mb.rss); } await sleep(100); } })();
const codes = {}; const one = async (a, kind) => { try { const r = kind === 'send'
  ? await fetch(`http://127.0.0.1:${a.port}/chat/send`, { method: 'POST', headers: { Authorization: `Bearer ${a.token}`, 'Content-Type': 'application/json' }, body: '{"jid":"x","text":"t"}' })
  : await fetch(`http://127.0.0.1:${a.port}/archive/chats`, { headers: { Authorization: `Bearer ${a.token}` } }); codes[r.status] = (codes[r.status] || 0) + 1; await r.arrayBuffer(); } catch { codes.err = (codes.err || 0) + 1; } };
const t0 = Date.now(); const jobs = [];
for (let round = 0; round < 10; round++) for (const a of accounts.slice(0, N - 1)) { jobs.push(one(a, 'archive')); jobs.push(one(a, 'send')); jobs.push(one(a, 'send')); }
await Promise.all(jobs); sampling = false; await sleep(200);
const total = jobs.length;
check(`${total} requests in ${Date.now() - t0} ms: every one answered (ok or 503 busy, nothing lost)`, (codes[200] || 0) + (codes[503] || 0) === total, JSON.stringify(codes));
check('the queue said "busy" (503) instead of piling work up', (codes[503] || 0) > 0, JSON.stringify(codes));
check(`archive reads never ran more than 2 at a time (peak ${peak.archive})`, peak.archive <= 2);
check(`sends never ran more than 3 at a time (peak ${peak.send})`, peak.send <= 3);
check(`memory stayed under the cap (${maxRss} MB, heap cap ${HEAP_MB} MB)`, maxRss > 0 && maxRss < HEAP_MB + 120, String(maxRss));
h = await health();
check('the crashing session did not stop the hub or the other numbers', hub.exitCode === null && h && h.sessions.filter((s) => s.status === 'up').length >= N - 1, JSON.stringify(h?.sessions?.filter((s) => s.status !== 'up')));
let procs = 0; for (const p of fs.readdirSync('/proc')) { if (!/^\d+$/.test(p)) continue; try { const c = fs.readFileSync(`/proc/${p}/cmdline`, 'utf8'); if (c.includes('hub.js') && fs.readFileSync(`/proc/${p}/environ`, 'utf8').includes(`HUB_PORT=${HUB_PORT}`)) procs++; } catch { /* not ours */ } }
check(`exactly one hub process (${procs})`, procs === 1, String(procs));
// a removed number is stopped by the next round
fs.writeFileSync(path.join(tmp, 'accounts.json'), JSON.stringify(accounts.slice(1)));
for (let i = 0; i < 40; i++) { await sleep(500); h = await health(); if (h && !h.sessions.some((s) => s.id === 'zz0')) break; }
check('a number taken out of the hub mode is stopped (its port freed)', h && !h.sessions.some((s) => s.id === 'zz0'));

hub.kill('SIGTERM'); await new Promise((r) => { hub.on('exit', r); setTimeout(r, 8000); });
fs.rmSync(tmp, { recursive: true, force: true });
const bad = results.filter((r) => !r.ok).length;
console.log(`\n${results.length} checks · ${results.length - bad} passed · ${bad} failed`);
if (bad) console.log(hubOut.slice(-1500));
process.exit(bad ? 1 : 0);
