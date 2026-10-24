// The real session code (gateway_session.js) of two numbers inside one hub, without a WhatsApp connection (GW_NO_SOCKET=1):
// each answers on its own port with its own token and reads its own archive; a send is refused (not connected), nothing leaves.
// Run: node wa-local/test/hub_real.mjs
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import crypto from 'node:crypto';
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const DIR = path.dirname(fileURLToPath(import.meta.url));
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'hubreal-'));
const tok = () => crypto.randomBytes(24).toString('hex');
const acc = [0, 1].map((i) => ({ id: `zzr${i}`, port: 3460 + i, token: tok(), enabled: true, gateway: 'hub', data_dir: path.join(tmp, `a${i}`) }));
for (const [i, a] of acc.entries()) { fs.mkdirSync(path.join(a.data_dir, 'archive'), { recursive: true });
  fs.writeFileSync(path.join(a.data_dir, 'archive', '2026-10.jsonl'), JSON.stringify({ time: '2026-10-20T10:00:00.000Z', dir: 'in', id: `m${i}`, jid: `20100000000${i}@s.whatsapp.net`, phone: `20100000000${i}`, kind: 'text', text: `hello ${i}` }) + '\n'); }
fs.writeFileSync(path.join(tmp, 'accounts.json'), JSON.stringify(acc));
const hubToken = tok();
const hub = spawn(process.execPath, ['--v8-pool-size=1', '--expose-gc', '--max-old-space-size=256', path.join(DIR, '..', 'hub.js')],
  { env: { ...process.env, GW_NO_SOCKET: '1', HUB_PORT: '3391', HUB_ACCOUNTS_FILE: path.join(tmp, 'accounts.json'), HUB_PID_FILE: path.join(tmp, 'hub.pid'), HUB_TOKEN: hubToken }, stdio: 'ignore' });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms)); const res = []; const check = (n, ok, s = '') => { res.push(ok); console.log(`${ok ? '✅' : '❌'} ${n}${ok ? '' : `  (seen: ${s})`}`); };
const get = async (a, p, t = a.token, o = {}) => { try { const r = await fetch(`http://127.0.0.1:${a.port}${p}`, { ...o, headers: { Authorization: `Bearer ${t}`, 'Content-Type': 'application/json' } }); return [r.status, await r.json()]; } catch (e) { return [0, { error: String(e) }]; } };
let up = false; for (let i = 0; i < 60 && !up; i++) { await sleep(500); up = (await get(acc[0], '/status'))[0] === 200 && (await get(acc[1], '/status'))[0] === 200; }
check('both numbers answer from one hub process', up);
for (const [i, a] of acc.entries()) { const [, c] = await get(a, '/archive/chats'); check(`number ${i} reads its own archive only`, c.ok && c.chats.length === 1 && c.chats[0].last.text === `hello ${i}`, JSON.stringify(c).slice(0, 200)); }
check('a token of another number is refused', (await get(acc[0], '/status', acc[1].token))[0] === 401);
const [sc, sb] = await get(acc[0], '/chat/send', acc[0].token, { method: 'POST', body: JSON.stringify({ jid: '201000000000@s.whatsapp.net', text: 'x' }) });
check('a send without a WhatsApp connection is refused, nothing leaves', sb.ok !== true, `${sc} ${JSON.stringify(sb).slice(0, 120)}`);
hub.kill('SIGTERM'); await new Promise((r) => { hub.on('exit', r); setTimeout(r, 8000); });
fs.rmSync(tmp, { recursive: true, force: true });
const bad = res.filter((x) => !x).length; console.log(`\n${res.length} checks · ${res.length - bad} passed · ${bad} failed`); process.exit(bad ? 1 : 0);
