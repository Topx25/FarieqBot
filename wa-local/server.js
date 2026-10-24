import express from 'express';
import fs from 'node:fs';
import path from 'node:path';
import http from 'node:http';
import https from 'node:https';
import { fileURLToPath } from 'node:url';
import crypto, { webcrypto } from 'node:crypto';
import pino from 'pino';
import QRCode from 'qrcode';

if (!globalThis.crypto) {
  globalThis.crypto = webcrypto;
}

const {
  default: makeWASocket,
  DisconnectReason,
  downloadMediaMessage,
  downloadContentFromMessage,
  fetchLatestBaileysVersion,
  useMultiFileAuthState,
} = await import('baileys');

const __dirname = path.dirname(fileURLToPath(import.meta.url));
// Extra WhatsApp accounts run this same file with DATA_DIR / ACCOUNT_ID / PORT / GATEWAY_TOKEN set;
// the main account keeps everything in this folder as before.
const accountId = process.env.ACCOUNT_ID || 'main';
const gatewayStartedAt = Date.now();
let lastMessageAt = 0;
let messagesSinceStart = 0;
const dataDir = process.env.DATA_DIR ? path.resolve(process.env.DATA_DIR) : __dirname;
const authDir = path.join(dataDir, 'auth');
const authBackupDir = path.join(dataDir, 'auth-previous');
const storageRoot = path.resolve(process.env.STORAGE_ROOT || path.join(__dirname, '..', 'storage'));
const gatewayToken = process.env.GATEWAY_TOKEN || '';
const baseUri = process.env.PASSENGER_BASE_URI || '';
const port = Number(process.env.PORT || 3000);
const publicBasePath = baseUri || '/wa-local';
const pidFile = path.join(dataDir, 'gateway.pid');
// Call the site on this server directly: the public hostname sits behind a Cloudflare challenge
// that blocks server-to-server requests. The Host header selects the right virtual host.
/** Is the payment bot on for this number? Read from the Inbox settings (cached 30 s); on when unknown. */
let payFlag = { at: 0, on: true };
function paymentsEnabled() {
  if (Date.now() - payFlag.at < 30000) return payFlag.on;
  let on = true;
  try {
    const dir = path.join(__dirname, '..', 'wa-inbox', '.data');
    const id = process.env.ACCOUNT_ID || 'main';
    if (id === 'main') { const c = JSON.parse(fs.readFileSync(path.join(dir, 'config.json'), 'utf8')); if (c && c.main_payments === false) on = false; }
    else { const list = JSON.parse(fs.readFileSync(path.join(dir, 'accounts.json'), 'utf8')); const a = (list || []).find((x) => x.id === id); if (a && !a.payments) on = false; }
  } catch { /* keep on */ }
  payFlag = { at: Date.now(), on };
  return on;
}
// Addresses of this installation: wa-local/gateway.json (written per installation), environment variables override it.
let gwConf = {}; try { gwConf = JSON.parse(fs.readFileSync(path.join(__dirname, 'gateway.json'), 'utf8')) || {}; } catch { gwConf = {}; }
const inboxHook = { host: process.env.INBOX_HOOK_HOST || gwConf.inbox_hook_host || '127.0.0.1', port: Number(process.env.INBOX_HOOK_PORT || gwConf.inbox_hook_port || 80),
  path: process.env.INBOX_HOOK_PATH || gwConf.inbox_hook_path || '/wa-inbox/index.php?bot_hook=1', hostname: process.env.INBOX_HOOK_HOSTNAME || gwConf.inbox_hook_hostname || 'localhost' };
// Optional invoice add-on: empty URL = off.
const invoiceWebhookUrl = process.env.INVOICE_WEBHOOK_URL || gwConf.invoice_webhook_url || '';
const invoiceWebhookHost = process.env.INVOICE_WEBHOOK_HOST || gwConf.invoice_webhook_host || '';
const invoiceWebhookTokenFile = path.resolve(__dirname, process.env.INVOICE_TOKEN_FILE || gwConf.invoice_token_file || path.join('..', 'application', 'cache', 'whatsapp_payment_webhook_token'));

try {
  if (fs.existsSync(pidFile)) {
    const oldPid = Number(fs.readFileSync(pidFile, 'utf8'));
    if (oldPid && oldPid !== process.pid) {
      try {
        process.kill(oldPid, 0);
        console.error(`WhatsApp gateway already running with PID ${oldPid}`);
        process.exit(0);
      } catch {
        fs.rmSync(pidFile, { force: true });
      }
    }
  }
  fs.writeFileSync(pidFile, String(process.pid));
  const clearPid = () => {
    try {
      if (fs.existsSync(pidFile) && fs.readFileSync(pidFile, 'utf8').trim() === String(process.pid)) {
        fs.rmSync(pidFile, { force: true });
      }
    } catch {
      // ignore pid cleanup errors
    }
  };
  process.on('exit', clearPid);
  process.on('SIGINT', () => {
    clearPid();
    process.exit(0);
  });
  process.on('SIGTERM', () => {
    clearPid();
    process.exit(0);
  });
} catch (error) {
  console.error('PID guard failed', error);
}

let socket = null;
let connected = false;
let connecting = false;
let latestQr = '';
let latestQrDataUrl = '';
let lastError = '';
let account = null;
let reconnectTimer = null;
let consecutiveConnectionFailures = 0;

const app = express();
app.use(express.json({ limit: '10mb' }));

process.on('uncaughtException', (error) => {
  lastError = error?.message || 'Uncaught exception';
  console.error(error);
});

process.on('unhandledRejection', (error) => {
  lastError = error?.message || 'Unhandled rejection';
  console.error(error);
});

function bearer(req) {
  const auth = req.get('authorization') || '';
  if (/^Bearer\s+/i.test(auth)) {
    return auth.replace(/^Bearer\s+/i, '').trim();
  }
  return String(req.query.token || '');
}

function tokenOk(given) {
  // Constant-time compare; without a configured token nothing is allowed.
  if (!gatewayToken) return false;
  const a = Buffer.from(String(given || '')), b = Buffer.from(String(gatewayToken));
  return a.length === b.length && crypto.timingSafeEqual(a, b);
}
function requireToken(req, res, next) {
  if (!tokenOk(bearer(req))) {
    res.status(401).json({ ok: false, error: 'Unauthorized' });
    return;
  }
  next();
}

function escapeHtml(value) {
  return String(value).replace(/[&<>"']/g, (char) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;',
  })[char]);
}

function normalizePhone(value) {
  return String(value || '').replace(/\D+/g, '').replace(/^00/, '');
}

function safeAttachment(attachment) {
  if (!attachment) {
    return null;
  }
  const resolved = path.resolve(storageRoot, String(attachment).replace(/^\/+/, ''));
  if (!resolved.startsWith(storageRoot + path.sep) || !fs.existsSync(resolved)) {
    return null;
  }
  return resolved;
}

/**
 * Tells the Inbox bot right away that a private message arrived (8 s after the customer's last message,
 * so several quick messages are answered once). The Inbox decides whether to answer.
 */
const botTimers = new Map();
/** /stop and /start typed in a private chat (by us or the customer) pause / resume the bot there. */
/** Asks the Inbox (server to server, bridge token) and returns its JSON answer. */
function inboxAsk(payload, timeout = 8000) {
  return new Promise((resolve) => {
    try {
      const tok = fs.readFileSync(path.join(__dirname, '..', 'wa-inbox', '.data', 'bridge_token'), 'utf8').trim();
      const body = JSON.stringify({ account: process.env.ACCOUNT_ID || 'main', ...payload });
      const r = http.request({ host: inboxHook.host, port: inboxHook.port, path: inboxHook.path, method: 'POST', timeout,
        headers: { Host: inboxHook.hostname, 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(body), 'X-Bot-Token': tok } }, (res) => {
        let d = ''; res.setEncoding('utf8'); res.on('data', (c) => { d += c; }); res.on('end', () => { try { resolve(JSON.parse(d)); } catch { resolve(null); } });
      });
      r.on('error', () => resolve(null)); r.on('timeout', () => { r.destroy(); resolve(null); }); r.end(body);
    } catch { resolve(null); }
  });
}
/** A quick-reply shortcut typed on the phone ("#تحصين-المكان", "/المكان"): the message is edited into the full reply from the Inbox. */
const expandedIds = new Set();
async function expandShortcut(sock, jid, key, text) {
  const t = String(text || '').trim();
  if (!key?.fromMe || !key.id || expandedIds.has(key.id) || t.length > 60 || t.includes('\n') || !/^[#\/]\s?[^\s#\/]/.test(t) || /^\/(stop|start)$/i.test(t)) return false;
  expandedIds.add(key.id); if (expandedIds.size > 2000) expandedIds.clear();
  const r = await inboxAsk({ cmd: 'expand', jid, text: t });
  if (!r?.ok || !r.text) return false;
  try { await sock.sendMessage(jid, { text: r.text, edit: { remoteJid: jid, fromMe: true, id: key.id } }); archive({ dir: 'edited', id: key.id, jid, text: r.text, via: 'shortcut' }); return true; }
  catch (e) { console.log(`shortcut edit failed: ${e.message}`); return false; }
}
/** A member writes a keyword ("#تحصين") in a group or a private chat: the Inbox finds the quick reply set for that keyword
 *  and the number answers it (quoting the member), as if the owner had written it. Limits and cooldowns are checked by the Inbox. */
async function keywordReply(sock, jid, msg, text) {
  const t = String(text || '').trim(), key = msg?.key || {};
  if (key.fromMe || !key.id || t.length > 60 || t.includes('\n') || !/^[#\/]\s?[^\s#\/]/.test(t) || /^\/(stop|start)$/i.test(t)) return false; // "#تحصين" or "# تحصين"
  const r = await inboxAsk({ cmd: 'keyword', jid, text: t, name: msg.pushName || '', from: String(key.participantAlt || key.participant || '').split('@')[0] });
  if (!r?.ok || !r.text) return false;
  try {
    try { await sock.presenceSubscribe(jid); await sock.sendPresenceUpdate('composing', jid); } catch { /* best effort */ }
    await new Promise((ok) => setTimeout(ok, Math.min(6000, 1200 + r.text.length * 15))); // looks typed, not instant
    const sent = await sock.sendMessage(jid, { text: r.text }, { quoted: msg });
    archiveSent(sent, jid, 'text', r.text, '', 'keyword'); return true;
  } catch (e) { console.log(`keyword reply failed: ${e.message}`); return false; }
}
/** "#حالة" + a person's details in a group (keyword set per number in Cases → Case settings): the Inbox opens a case, asks the
 *  writer what is missing (quoting them) and waits for their answer. Only messages with "#", or from a writer the Inbox waits for, are sent. */
const intakeWait = new Map(), botSentIds = new Set();
const recentVoice = new Map(); // group|writer -> their last recording (a case's voice may come just before the keyword)
async function caseIntake(sock, jid, msg, text, kind, file) {
  const t = String(text || '').trim(), key = msg?.key || {};
  const isVoice = (kind === 'voice' || kind === 'audio') && /^inbox_media\//.test(String(file || ''));
  if (!jid.endsWith('@g.us') || !key.id || (!t && !isVoice) || botSentIds.has(key.id)) return;
  const from = key.fromMe ? String(sock?.user?.id || '').split(':')[0].split('@')[0] : String(key.participantAlt || key.participant || '').split('@')[0];
  const pk = `${jid}|${from}`, w = intakeWait.get(pk), waiting = w && w > Date.now();
  if (isVoice) { recentVoice.set(pk, { id: key.id, file, at: Date.now() }); if (recentVoice.size > 500) recentVoice.clear(); }
  if (!gateGroups().has(jid) && (isVoice ? !waiting : (!t.includes('#') && !waiting))) return; // reception-gate groups: every message ("woman" / "man", the recording)
  const pl = unwrapMessageContent(msg.message) || {};
  const c = pl.extendedTextMessage?.contextInfo || pl.audioMessage?.contextInfo || {};
  // The case's recording: this message, the recording it quotes, or the writer's recording of the last 10 minutes.
  const voices = [];
  if (isVoice) voices.push({ id: key.id, file });
  else if (c.stanzaId && c.quotedMessage?.audioMessage) { const q = readArchive({ jid, limit: 3000, months: 2 }).find((r) => r.id === c.stanzaId); if (q?.attachment) voices.push({ id: q.id, file: q.attachment }); }
  else if (t.includes('#')) { const rv = recentVoice.get(pk); if (rv && Date.now() - rv.at < 600000) { voices.push({ id: rv.id, file: rv.file }); recentVoice.delete(pk); } } // used once: the next case does not take it again
  // Tags: this number tagged (the case is addressed to the bot), the others resolved to phone numbers with their WhatsApp names.
  const me = [sock?.user?.id, sock?.user?.lid].map((x) => String(x || '').split(':')[0].split('@')[0]).filter(Boolean);
  let taggedMe = false; const mentions = [], people = [];
  for (const j of (c.mentionedJid || []).slice(0, 10)) {
    const n = String(j).split(':')[0].split('@')[0];
    if (me.includes(n)) { taggedMe = true; continue; }
    const pj = String(j).endsWith('@lid') ? (await phoneJidForLid(sock, j).catch(() => '')) || j : j;
    mentions.push(pj); people.push({ jid: pj, name: names[j] || names[pj] || '' });
  }
  const r = await inboxAsk({ cmd: 'case_intake', jid, text: t, from, name: key.fromMe ? '' : msg.pushName || '', id: key.id, mentions, people, tagged_me: taggedMe, voices, from_me: Boolean(key.fromMe) });
  if (r?.done) intakeWait.delete(pk); else if (r?.ok && r.wait) intakeWait.set(pk, Date.now() + r.wait * 1000);
  if (intakeWait.size > 500) for (const [k, v] of intakeWait) if (v < Date.now()) intakeWait.delete(k);
}
function botCommand(sock, jid, key, text) {
  const m = String(text || '').trim().match(/^\/(stop|start)$/i);
  if (!m || !jid || jid.endsWith('@g.us')) return false;
  try {
    const tok = fs.readFileSync(path.join(__dirname, '..', 'wa-inbox', '.data', 'bridge_token'), 'utf8').trim();
    const body = JSON.stringify({ account: process.env.ACCOUNT_ID || 'main', jid, cmd: m[1].toLowerCase(), by: key.fromMe ? 'team' : 'customer' });
    const r = http.request({ host: inboxHook.host, port: inboxHook.port, path: inboxHook.path, method: 'POST', timeout: 8000,
      headers: { Host: inboxHook.hostname, 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(body), 'X-Bot-Token': tok } }, (res) => res.resume());
    r.on('error', () => {}); r.on('timeout', () => r.destroy()); r.end(body);
  } catch { /* ignore */ }
  // Typed by us on the phone: remove it from the customer's chat so they never see the command.
  if (key.fromMe && key.id) setTimeout(() => { sock.sendMessage(jid, { delete: { remoteJid: jid, fromMe: true, id: key.id } }).then(() => archive({ dir: 'deleted', id: key.id, jid })).catch(() => {}); }, 1500);
  return true;
}
/* Bot ping to the dashboard. Private chats: the customer's messages are gathered while they keep typing; the bot is asked
 * to answer once they have been quiet for "quiet_secs" (Bot settings), at the latest "gather_secs" after their first message. */
const botFirst = new Map(), botTyping = new Map();
function botFire(jid) {
  botTimers.delete(jid); botFirst.delete(jid); botTyping.delete(jid);
  try {
    const tok = fs.readFileSync(path.join(__dirname, '..', 'wa-inbox', '.data', 'bridge_token'), 'utf8').trim();
    const body = JSON.stringify({ account: process.env.ACCOUNT_ID || 'main', jid });
    const r = http.request({ host: inboxHook.host, port: inboxHook.port, path: inboxHook.path, method: 'POST', timeout: 5000,
      headers: { Host: inboxHook.hostname, 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(body), 'X-Bot-Token': tok } }, (res) => res.resume());
    r.on('error', () => {}); r.on('timeout', () => r.destroy()); r.end(body);
  } catch { /* the every-minute check still runs */ }
}
function botArm(jid, ms) {
  clearTimeout(botTimers.get(jid));
  botTimers.set(jid, setTimeout(() => {
    const g = gatherCfg(), waited = Date.now() - (botFirst.get(jid) || Date.now());
    const typing = Date.now() - (botTyping.get(jid) || 0) < 12000; // "composing" is repeated while they type
    if (typing && waited < g.maxMs) { botArm(jid, 3000); return; } // still typing: look again shortly
    botFire(jid);
  }, ms));
}
function botPing(jid) {
  if (!jid) return;
  if (jid.endsWith('@g.us')) { // a busy group: wait for a 3 s pause, but never more than 20 s after the first unanswered message
    if (!botFirst.has(jid)) botFirst.set(jid, Date.now());
    if (Date.now() - botFirst.get(jid) >= 20000) { clearTimeout(botTimers.get(jid)); botFire(jid); return; }
    clearTimeout(botTimers.get(jid)); botTimers.set(jid, setTimeout(() => botFire(jid), 3000)); return; }
  if (!botFirst.has(jid)) botFirst.set(jid, Date.now());
  try { if (socket) socket.presenceSubscribe(jid).catch(() => {}); } catch { /* typing info is best effort */ }
  const g = gatherCfg(), left = g.maxMs - (Date.now() - botFirst.get(jid));
  botArm(jid, Math.max(1000, Math.min(g.quietMs, left)));
}
function botTypingSeen(jid, state) {
  if (!botFirst.has(jid)) return;
  if (state === 'composing' || state === 'recording') { botTyping.set(jid, Date.now()); botArm(jid, gatherCfg().quietMs); }
  else if (state === 'paused') { botTyping.delete(jid); botArm(jid, gatherCfg().quietMs); }
}
/** Groups whose join requests are approved automatically (Bot settings, re-read every minute). */
let approveCache = { at: 0, set: new Set() };
function approveGroups() {
  if (Date.now() - approveCache.at < 60000) return approveCache.set;
  const set = new Set();
  try { const b = JSON.parse(fs.readFileSync(path.join(__dirname, '..', 'wa-inbox', '.data', 'bot.json'), 'utf8')); const g = ((b[process.env.ACCOUNT_ID || 'main'] || {}).run || {}).groups || {};
    for (const [j, x] of Object.entries(g)) if (x && x.approve && x.approve !== '0') set.add(j); } catch { /* none */ }
  approveCache = { at: Date.now(), set };
  return set;
}
/** Approves waiting join requests: each new request at once, and every 10 minutes the ones that came while offline. */
async function approveRequests(sock, gjid, who) {
  try {
    const list = who ? [who] : ((await withTimeout(sock.groupRequestParticipantsList(gjid), 15000, 't')) || []).map((r) => r.jid).filter(Boolean);
    if (!list.length) return;
    await withTimeout(sock.groupRequestParticipantsUpdate(gjid, list.slice(0, 50), 'approve'), 20000, 't');
    archive({ dir: 'group_event', id: `ge_${Date.now()}_${Math.random().toString(36).slice(2, 7)}`, jid: gjid, action: 'approved', member: list.map((j) => String(j).split('@')[0]).join(','), by: 'bot' });
  } catch (e) { console.log('approve failed', gjid, e?.message || e); }
}
/** Groups with the reception gate on (Bot settings, re-read every minute). */
let gateCache = { at: 0, set: new Set() };
function gateGroups() {
  if (Date.now() - gateCache.at < 60000) return gateCache.set;
  const set = new Set();
  try { const b = JSON.parse(fs.readFileSync(path.join(__dirname, '..', 'wa-inbox', '.data', 'bot.json'), 'utf8')); const g = ((b[process.env.ACCOUNT_ID || 'main'] || {}).run || {}).groups || {};
    for (const [j, x] of Object.entries(g)) if (x && ((x.gate && x.gate !== '0') || (x.antispam && x.antispam !== '0'))) set.add(j); } catch { /* none */ } // reception gate and anti-spam groups: every message
  gateCache = { at: Date.now(), set };
  return set;
}
/** Bot timing from Bot settings (re-read every minute): quiet time after the last message / typing, and the longest wait. */
let gatherCache = { at: 0, quietMs: 6000, maxMs: 45000 };
function gatherCfg() {
  if (Date.now() - gatherCache.at < 60000) return gatherCache;
  let quiet = 6, max = 45;
  try { const b = JSON.parse(fs.readFileSync(path.join(__dirname, '..', 'wa-inbox', '.data', 'bot.json'), 'utf8')); const r = (b[process.env.ACCOUNT_ID || 'main'] || {}).run || {};
    if (r.quiet_secs !== undefined) quiet = Number(r.quiet_secs) || 0; if (r.gather_secs !== undefined) max = Number(r.gather_secs) || 0; } catch { /* defaults */ }
  gatherCache = { at: Date.now(), quietMs: Math.min(120000, Math.max(3000, quiet * 1000)), maxMs: Math.min(600000, Math.max(5000, max * 1000)) };
  return gatherCache;
}

/** Reply/mention context of a message: which message it quotes, whose it was, and who is @mentioned. */
function ctxOf(message) {
  try {
    const c = unwrapMessageContent(message) || {};
    for (const v of Object.values(c)) {
      const ci = v && typeof v === 'object' ? v.contextInfo : null;
      if (!ci) continue;
      const num = (j) => String(j || '').split('@')[0].split(':')[0];
      const out = {};
      if (ci.stanzaId) { out.quote = ci.stanzaId; if (ci.participant) out.quote_from = num(ci.participant); }
      if (Array.isArray(ci.mentionedJid) && ci.mentionedJid.length) out.mentions = ci.mentionedJid.slice(0, 30).map(num);
      return out;
    }
  } catch {}
  return {};
}
function unwrapMessageContent(message) {
  let payload = message?.message || {};
  for (let i = 0; i < 5; i += 1) {
    const wrapped = payload.ephemeralMessage?.message
      || payload.viewOnceMessage?.message
      || payload.viewOnceMessageV2?.message
      || payload.viewOnceMessageV2Extension?.message
      || payload.documentWithCaptionMessage?.message
      || payload.editedMessage?.message;
    if (!wrapped) break;
    payload = wrapped;
  }
  return payload;
}

function textFromMessage(message) {
  const payload = unwrapMessageContent(message);
  return payload.conversation
    || payload.extendedTextMessage?.text
    || payload.imageMessage?.caption
    || payload.documentMessage?.caption
    || payload.videoMessage?.caption
    || '';
}

/** Archive type of any message (voice, video, sticker, reaction, …), wider than the payment bot's mediaKind. */
/** View-once photo / video / voice: WhatsApp gives its content to the phone only, linked devices get the wrapper without the file. */
function isViewOnce(message) {
  const m = message?.message || {};
  if (m.viewOnceMessage || m.viewOnceMessageV2 || m.viewOnceMessageV2Extension) return true;
  const p = unwrapMessageContent(message) || {};
  return Boolean(p.imageMessage?.viewOnce || p.videoMessage?.viewOnce || p.audioMessage?.viewOnce);
}
function archiveKind(message) {
  const p = unwrapMessageContent(message) || {};
  if (isViewOnce(message) && !((p.imageMessage || p.videoMessage || p.audioMessage || {}).mediaKey)) return 'viewonce'; // nothing to download: shown as a note
  if (p.reactionMessage) return 'reaction';
  if (p.imageMessage) return 'image';
  if (p.audioMessage) return p.audioMessage.ptt ? 'voice' : 'audio';
  if (p.videoMessage) return p.videoMessage.gifPlayback ? 'gif' : 'video';
  if (p.stickerMessage) return 'sticker';
  if (p.documentMessage || p.documentWithCaptionMessage) return 'document';
  if (p.contactMessage || p.contactsArrayMessage) return 'contact';
  if (p.locationMessage || p.liveLocationMessage) return 'location';
  if (p.pollCreationMessage || p.pollCreationMessageV3 || p.pollUpdateMessage) return 'poll';
  return 'text';
}
function archiveText(message) {
  const p = unwrapMessageContent(message) || {};
  if (p.reactionMessage) return p.reactionMessage.text || '';
  return textFromMessage(message) || p.documentMessage?.fileName || p.contactMessage?.displayName || p.pollCreationMessage?.name || p.pollCreationMessageV3?.name || '';
}

/* ---------- Names: contacts (saved names / push names) and group subjects, kept per account ---------- */
const namesFile = path.join(dataDir, 'names.json');
let names = {};
try { names = JSON.parse(fs.readFileSync(namesFile, 'utf8')) || {}; } catch { names = {}; }
let namesDirty = false;
function setName(jid, name) {
  if (!jid || !name || typeof name !== 'string') return;
  const k = String(jid).replace(/:\d+@/, '@');
  if (names[k] !== name) { names[k] = name.slice(0, 120); namesDirty = true; }
}
setInterval(() => { if (namesDirty) { namesDirty = false; try { fs.writeFileSync(namesFile, JSON.stringify(names)); } catch (e) { /* ignore */ } } }, 15000);
const groupFetching = new Set();
async function groupName(sock, jid) {
  if (names[jid] || groupFetching.has(jid) || !sock) return names[jid] || '';
  groupFetching.add(jid);
  try { const meta = await sock.groupMetadata(jid); if (meta?.subject) setName(jid, meta.subject); } catch (e) { /* not a member any more */ }
  return names[jid] || '';
}

function mediaKind(message) {
  const payload = unwrapMessageContent(message);
  if (payload.imageMessage) {
    return 'image';
  }
  const docType = payload.documentMessage?.mimetype || '';
  if (docType.startsWith('image/') || docType === 'application/pdf') {
    return 'document';
  }
  return '';
}

async function saveIncomingMedia(message, kind) {
  if (!kind) {
    return '';
  }
  const dir = path.join(storageRoot, 'whatsapp_payments');
  fs.mkdirSync(dir, { recursive: true });
  const content = unwrapMessageContent(message);
  const mimeType = content.imageMessage?.mimetype || content.documentMessage?.mimetype || content.videoMessage?.mimetype || '';
  const extension = mimeType.includes('png') ? 'png' : mimeType.includes('webp') ? 'webp' : mimeType.includes('pdf') ? 'pdf' : kind === 'image' ? 'jpg' : 'bin';
  const safeId = String(message.key?.id || Date.now()).replace(/[^A-Za-z0-9_-]+/g, '_');
  const file = path.join(dir, `${safeId}.${extension}`);
  const buffer = await downloadMediaMessage(message, 'buffer', {}, { logger: pino({ level: 'silent' }) });
  fs.writeFileSync(file, buffer);
  return path.relative(storageRoot, file).replace(/\\/g, '/');
}

/** What is needed to download a media message later (WhatsApp keeps media for a limited time). */
function mediaRef(message) {
  const c = unwrapMessageContent(message) || {};
  const m = c.audioMessage || c.imageMessage || c.videoMessage || c.documentMessage || c.stickerMessage;
  if (!m || !m.mediaKey || !m.directPath) return undefined;
  const b64 = (v) => (v ? Buffer.from(v).toString('base64') : undefined);
  const type = c.audioMessage ? 'audio' : (c.imageMessage ? 'image' : (c.videoMessage ? 'video' : (c.stickerMessage ? 'sticker' : 'document')));
  return { type, directPath: m.directPath, mediaKey: b64(m.mediaKey), url: m.url, mimetype: m.mimetype, fileLength: Number(m.fileLength || 0),
    seconds: Number(m.seconds || 0) || undefined, fileName: m.fileName || undefined, ptt: m.ptt || undefined };
}
/** Same recording = same exact size, duration and type (forwarded copies share them). */
function mediaSig(m) { return m && m.fileLength ? `${m.fileLength}:${m.seconds || 0}:${String(m.mimetype || '').split(';')[0]}` : ''; }
/** Content rules of this installation (wa-local/rules.json, edited in the dashboard): which texts are never stored,
 *  which link domains are stripped, and the tags given to texts (e.g. quran / ruqyah). Reloaded when the file changes. */
// A number may have its own rules (a client's panel: its own library sections, no healing tags): DATA_DIR/rules.json wins over the shared file.
const RULES_FILE = dataDir !== __dirname ? (fs.existsSync(path.join(dataDir, 'rules.json')) ? path.join(dataDir, 'rules.json') : path.join(__dirname, 'rules.json'))
  : (fs.existsSync(path.join(__dirname, 'rules.main.json')) ? path.join(__dirname, 'rules.main.json') : path.join(__dirname, 'rules.json')); // the main number may have its own too
const rules = { mtime: 0, checked: 0, secret: null, social: null, tags: [] };
function loadRules() {
  if (Date.now() - rules.checked < 60000) return rules; rules.checked = Date.now();
  let st; try { st = fs.statSync(RULES_FILE); } catch { st = null; }
  const mt = st ? st.mtimeMs : -1; if (mt === rules.mtime) return rules; rules.mtime = mt; rules.lib = null;
  let r = {}; try { r = st ? JSON.parse(fs.readFileSync(RULES_FILE, 'utf8')) : {}; } catch (e) { console.error(`rules.json: ${e.message}`); r = {}; }
  const rx = (list, flags) => { const parts = (Array.isArray(list) ? list : []).map(String).filter(Boolean).filter((p) => { try { new RegExp(p); return true; } catch { console.error(`rules.json: bad pattern ${p.slice(0, 60)}`); return false; } }); return parts.length ? new RegExp(parts.map((p) => `(?:${p})`).join('|'), flags) : null; };
  // default when no rules file: login codes / passwords are still never stored
  rules.secret = rx(r.secret_patterns || ['رمز دخول|رمز التحقق|كود التحقق|one-time password|verification code|\\bOTP\\b|password|كلمة المرور|كلمة السر'], 'i');
  const doms = (Array.isArray(r.strip_link_domains) ? r.strip_link_domains : []).map((d) => String(d).trim().toLowerCase().replace(/[^a-z0-9.-]/g, '')).filter(Boolean);
  rules.social = doms.length ? new RegExp(`(?:https?:\\/\\/)?(?:[\\w-]+\\.)*(?:${doms.map((d) => d.replace(/\./g, '\\.')).join('|')})\\/?\\S*`, 'gi') : null;
  rules.media = r.media && typeof r.media === 'object' ? r.media : null;
  // payment bot admin commands and money words (rules.json "payment_commands" / "money_words"; these are only the starting values)
  const esc = (s) => String(s).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const cmds = Array.isArray(r.payment_commands) && r.payment_commands.length ? r.payment_commands : ['موافق', 'موافقة', 'اعتماد', 'approve', 'ok', 'رفض', 'reject'];
  rules.payCmd = new RegExp(`^(${cmds.map(esc).join('|')})\\s*#?\\s*p?\\s*[0-9٠-٩]+`, 'iu');
  const money = Array.isArray(r.money_words) && r.money_words.length ? r.money_words : ['EGP', 'LE', 'L.E', 'جنيه', 'ج.م', 'ج', 'ريال', 'SAR', '$', 'USD', 'دولار'];
  rules.money = new RegExp(`(\\d{1,3}(?:[,٬]\\d{3})+|\\d+(?:\\.\\d+)?)\\s*(?:${money.map(esc).join('|')})`, 'gi');
  rules.tags = (Array.isArray(r.tags) ? r.tags : []).filter((t) => t && t.key).map((t) => ({ key: String(t.key), re: rx(t.patterns, ''), quranIndex: !!t.quran_index, ratio: Number(t.tashkeel_ratio || 0) }));
  console.log(`Content rules: ${rules.tags.length} tags, ${doms.length} stripped domains`);
  return rules;
}
function tagMatch(key, text) { const t = loadRules().tags.find((x) => x.key === key); return !!(t && t.re && t.re.test(String(text || ''))); }
function textTags(text) {
  const t = String(text || '');
  if (t.length < 12) return undefined;
  const letters = (t.match(/[ء-ي]/g) || []).length;
  const marks = (t.match(/[ً-ْٰ]/g) || []).length;
  const tags = [];
  for (const g of loadRules().tags) {
    let hit = false;
    if (g.quranIndex && quran.ayat.length) hit = quranRefs(t).length > 0;
    else hit = (g.re ? g.re.test(t) : false) || (g.ratio > 0 && letters > 40 && marks / letters > g.ratio);
    if (hit) tags.push(g.key);
  }
  return tags.length ? tags : undefined;
}

/** Media kept automatically (live, history sync and the startup check) and the size limit of each kind, from rules.json "media"
 *  (Settings → Gateway rules). Photos, stickers and recordings are saved as they arrive; videos and documents are downloaded in the background. */
function mediaCfg() {
  const m = loadRules().media || {}, d = { image: 8, sticker: 8, audio: 64, video: 64, document: 32 };
  const auto = Array.isArray(m.auto) ? m.auto.map(String) : ['image', 'sticker', 'voice', 'audio', 'video', 'document'];
  const max = {}; for (const k of Object.keys(d)) max[k] = Number(m.max_mb?.[k]) > 0 ? Number(m.max_mb[k]) : d[k];
  return { auto, max };
}
const MEDIA_AUTO = { includes: (kind) => mediaCfg().auto.includes(kind === 'voice' ? 'audio' : kind) || (kind === 'voice' && mediaCfg().auto.includes('voice')) };
/** Size limit (bytes) for a media reference. */
function mediaMaxBytes(m) { const c = mediaCfg().max; const k = m?.type === 'audio' ? 'audio' : (m?.type || 'document'); return (c[k] || c.document) * 1048576; }
/** Keeps a copy of photos, stickers and voice/audio (≤ 8 MB) so the Inbox can show them. Returns a storage-relative path or ''. */
async function saveArchiveMedia(message, kind) {
  const c0 = unwrapMessageContent(message) || {};
  const audioDoc = kind === 'document' && /^audio\//.test(String(c0.documentMessage?.mimetype || ''));
  if (!['image', 'sticker', 'voice', 'audio'].includes(kind) && !audioDoc) return '';
  try {
    const c = c0;
    const media = c.imageMessage || c.stickerMessage || c.audioMessage || (audioDoc ? c.documentMessage : null);
    const size = Number(media?.fileLength || 0);
    const isAudio = kind === 'voice' || kind === 'audio' || audioDoc;
    if (size && size > (isAudio ? mediaCfg().max.audio : mediaCfg().max.image) * 1048576) return ''; // limits: Settings → Gateway rules
    const mime = String(media?.mimetype || '');
    const ext = kind === 'sticker' ? 'webp' : (kind === 'image' ? (mime.includes('png') ? 'png' : (mime.includes('webp') ? 'webp' : 'jpg')) : (mime.includes('mpeg') ? 'mp3' : (mime.includes('mp4') ? 'm4a' : 'ogg')));
    const dir = chatMediaDir(message.key?.remoteJid);
    fs.mkdirSync(dir, { recursive: true });
    // A recording already on disk (same length, duration and type) is linked, not downloaded again.
    const sg = isAudio ? mediaSig(mediaRef(message)) : '';
    mqRow(''); // builds the signature index once
    if (sg && sigFiles.has(sg) && fs.existsSync(path.join(storageRoot, sigFiles.get(sg)))) return sigFiles.get(sg);
    const safeId = String(message.key?.id || Date.now()).replace(/[^A-Za-z0-9_-]+/g, '_').slice(0, 60);
    const file = path.join(dir, `${safeId}.${ext}`);
    if (!fs.existsSync(file)) {
      const buffer = await withTimeout(downloadMediaMessage(message, 'buffer', {}, { logger: pino({ level: 'silent' }) }), 180000, 'media download timed out');
      // Identical content already saved (a forwarded photo or recording): keep one copy.
      const sha = crypto.createHash('sha256').update(buffer).digest('hex'), prev = hashIndex[sha];
      if (prev && fs.existsSync(path.join(storageRoot, prev))) { if (sg && !sigFiles.has(sg)) sigFiles.set(sg, prev); mediaQueue.dupes = (mediaQueue.dupes || 0) + 1; mqDirty = true; return prev; }
      fs.writeFileSync(file, buffer);
      hashIndex[sha] = path.relative(storageRoot, file).replace(/\\/g, '/'); hashDirty = true;
    }
    const rel = path.relative(storageRoot, file).replace(/\\/g, '/');
    if (sg && !sigFiles.has(sg)) sigFiles.set(sg, rel);
    return rel;
  } catch (err) { return ''; }
}

// Uses node:https instead of fetch so it also runs on Node 16 (no global fetch there).
// The receipt AI can take up to ~1 minute, so the timeout is generous.
/** Alerts the admin on the gateway's own WhatsApp (shows as "Message yourself"); at most once per 10 minutes per kind. */
const adminAlertAt = {};
async function alertAdmin(sock, kind, text) {
  try {
    if (Date.now() - (adminAlertAt[kind] || 0) < 10 * 60 * 1000) return;
    adminAlertAt[kind] = Date.now();
    const own = String(sock?.user?.id || '').split(':')[0].split('@')[0];
    if (!own) return;
    archiveSent(await sock.sendMessage(`${own}@s.whatsapp.net`, { text: `⚠️ Payment bot\n${text}` }), `${own}@s.whatsapp.net`, 'text', `⚠️ Payment bot\n${text}`, '', 'alert');
    console.log(`Admin alert sent kind=${kind}`);
  } catch (err) { console.error('Admin alert failed', err?.message || err); }
}

/** WhatsApp may hide the sender's number behind a LID; resolve it from the session's saved LID mappings. */
async function phoneJidForLid(sock, lidJid) {
  try {
    const repo = sock && sock.signalRepository && sock.signalRepository.lidMapping;
    if (repo && typeof repo.getPNForLID === 'function') {
      const pn = await repo.getPNForLID(lidJid);
      if (pn && String(pn).includes('@s.whatsapp.net')) return String(pn).replace(/:\d+@/, '@');
    }
  } catch (err) { /* fall back to the auth files */ }
  try {
    const lid = String(lidJid).split('@')[0].split(':')[0];
    const file = path.join(authDir, `lid-mapping-${lid}_reverse.json`);
    if (fs.existsSync(file)) {
      const pn = String(JSON.parse(fs.readFileSync(file, 'utf8')) || '').replace(/\D+/g, '');
      if (pn) return `${pn}@s.whatsapp.net`;
    }
  } catch (err) { /* unknown LID */ }
  return '';
}

async function postInvoiceWebhook(payload) {
  if (!invoiceWebhookUrl) return { ok: false, error: 'Invoice add-on is not set up' };
  let invoiceWebhookToken = '';
  try {
    invoiceWebhookToken = fs.readFileSync(invoiceWebhookTokenFile, 'utf8').trim();
  } catch {
    return { ok: false, error: 'Project payment webhook is not configured' };
  }
  if (!invoiceWebhookToken) {
    return { ok: false, error: 'Project payment webhook is not configured' };
  }
  const url = new URL(invoiceWebhookUrl);
  url.searchParams.set('token', invoiceWebhookToken);
  const body = Buffer.from(JSON.stringify(payload), 'utf8');
  const client = url.protocol === 'http:' ? http : https;
  const text = await new Promise((resolve, reject) => {
    const request = client.request(url, {
      method: 'POST',
      headers: { ...(invoiceWebhookHost ? { Host: invoiceWebhookHost } : {}), Accept: 'application/json', 'Content-Type': 'application/json', 'Content-Length': body.length },
      timeout: 150000,
    }, (response) => {
      const chunks = [];
      response.on('data', (chunk) => chunks.push(chunk));
      response.on('end', () => resolve(Buffer.concat(chunks).toString('utf8')));
    });
    request.on('timeout', () => request.destroy(new Error('Invoice webhook timed out')));
    request.on('error', reject);
    request.end(body);
  });
  try {
    return JSON.parse(text);
  } catch {
    return { ok: false, error: text.slice(0, 250) };
  }
}

/* ---------- Message archive: every message in and out, one JSON line each (wa-local/archive/YYYY-MM.jsonl, not web-readable). */
const archiveDir = path.join(dataDir, 'archive');
/* ---------- Live events for the Inbox (new messages, typing, ticks) ---------- */
const liveEvents = [];
let liveSeq = 0;
const msgStatus = new Map(); // message id -> 2 sent, 3 delivered, 4 read, 5 played
function pushEvent(type, data) {
  liveEvents.push({ seq: ++liveSeq, type, at: Date.now(), ...data });
  if (liveEvents.length > 1000) liveEvents.splice(0, liveEvents.length - 1000);
}
const archivedIds = new Set();
let knownIds = null; // every message id already in the archive (loaded once)
function knownIdSet() {
  if (knownIds) return knownIds;
  knownIds = new Set();
  try {
    for (const f of fs.readdirSync(path.join(dataDir, 'archive')).filter((x) => /\.jsonl$/.test(x))) {
      for (const line of fs.readFileSync(path.join(dataDir, 'archive', f), 'utf8').split('\n')) {
        const m = /"id":"([^"]+)"/.exec(line); if (m && /"dir":"(in|out)"/.test(line)) knownIds.add(m[1]);
      }
    }
  } catch (e) { /* first run */ }
  return knownIds;
}
// YouTube / Facebook links are not kept (user, 2026-10-08): removed from the text; a message that was only links is skipped.
function stripSocialLinks(text) {
  const re = loadRules().social; if (!re) return String(text || '');
  re.lastIndex = 0; const out = String(text || '').replace(re, '').replace(/[ \t]+\n/g, '\n').replace(/\n{3,}/g, '\n\n').trim(); re.lastIndex = 0; return out;
}
// The chat list is built from the whole archive (slow on a busy number): kept ready, rebuilt only after something new was archived.
let archiveVersion = 0; const chatsCache = new Map();
function archive(entry) {
  archiveVersion++;
  try {
    const R = loadRules();
    if (entry.text && R.secret && R.secret.test(entry.text)) return; // login codes and passwords are never stored
    if (['in', 'out'].includes(entry.dir) && (!entry.kind || entry.kind === 'text') && !String(entry.text || '').trim() && !entry.attachment) return; // empty system messages
    if (entry.text && R.social) {
      R.social.lastIndex = 0;
      if (R.social.test(entry.text)) { entry = { ...entry, text: stripSocialLinks(entry.text) }; if (!entry.text && (!entry.kind || entry.kind === 'text')) return; }
      R.social.lastIndex = 0;
    }
    if (entry.id && entry.dir !== 'hidden' && entry.dir !== 'unhidden') { // delete / put back can repeat
      const key = `${entry.dir}:${entry.id}`;
      if (archivedIds.has(key)) return;
      archivedIds.add(key);
      if (archivedIds.size > 20000) archivedIds.clear();
    }
    fs.mkdirSync(archiveDir, { recursive: true });
    const row = { time: new Date().toISOString(), ...entry };
    if (entry.ts) { row.time = new Date(Number(entry.ts) * 1000).toISOString(); delete row.ts;
      if (Date.now() - Date.parse(row.time) > 120000) row.got = new Date().toISOString(); } // arrived late (phone was offline): when it reached us, for "deleted chat" checks
    if (row.text && row.text.length > 4000) row.text = Array.from(row.text).slice(0, 4000).join('');
    fs.appendFileSync(path.join(archiveDir, `${row.time.slice(0, 7)}.jsonl`), `${JSON.stringify(row)}\n`);
    if (['in', 'out', 'revoked', 'edited', 'deleted'].includes(row.dir)) pushEvent('msg', { row });
    if (knownIds && row.id && (row.dir === 'in' || row.dir === 'out')) knownIds.add(row.id);
    if (mqIndex && row.id) {
      if (row.mref && (row.dir === 'in' || row.dir === 'out')) mqIndex.set(row.id, row);
      else if (row.dir === 'patch' && row.mref) { const o = mqIndex.get(row.id); mqIndex.set(row.id, { ...(o || { id: row.id, jid: row.jid }), mref: row.mref, kind: row.kind }); }
      else if (row.dir === 'media') { const o = mqIndex.get(row.id); if (o) { o.attachment = row.attachment; const sg = mediaSig(o.mref); if (sg && !sigFiles.has(sg)) sigFiles.set(sg, row.attachment); } }
    }
  } catch (err) { console.error('Archive write failed', err?.message || err); }
}
function archiveSent(result, jid, kind, text, attachment, via) {
  archive({ dir: 'out', id: result?.key?.id || '', jid, phone: String(jid).split('@')[0], kind, text: text || '', attachment: attachment ? path.relative(storageRoot, attachment) : '', via });
}
// The archive files are append-only. Each is kept in memory with an index of its lines by chat, and when it grows only the new part is read:
// a thread request used to read and scan every file (tens of MB) each time.
const archiveCache = new Map();
function archiveIndexLine(c, n) {
  const line = c.lines[n]; if (!line) return; const i = line.indexOf('"jid":"'); if (i < 0) return; const j = line.indexOf('"', i + 7); if (j < 0) return;
  const k = line.slice(i + 7, j); let a = c.idx.get(k); if (!a) c.idx.set(k, a = []); a.push(n);
}
function archiveFile(fp) {
  const st = fs.statSync(fp); let c = archiveCache.get(fp);
  if (c && c.s === st.size) return c;
  if (c && st.size > c.s && c.lines.length && c.lines[c.lines.length - 1] === '') { // grew, and the cached text ended on a whole line: read only what was added
    const fd = fs.openSync(fp, 'r');
    try { const buf = Buffer.alloc(st.size - c.s); fs.readSync(fd, buf, 0, buf.length, c.s); c.lines.pop(); const start = c.lines.length; for (const l of buf.toString('utf8').split('\n')) c.lines.push(l); for (let n = start; n < c.lines.length; n++) archiveIndexLine(c, n); c.s = st.size; return c; }
    catch { archiveCache.delete(fp); } finally { fs.closeSync(fd); }
  }
  const buf = fs.readFileSync(fp); c = { s: buf.length, lines: buf.toString('utf8').split('\n'), idx: new Map() };
  for (let n = 0; n < c.lines.length; n++) archiveIndexLine(c, n);
  archiveCache.delete(fp); archiveCache.set(fp, c); while (archiveCache.size > 64) archiveCache.delete(archiveCache.keys().next().value);
  return c;
}
// After a read over whole months the memory is given back at once (the host stops a gateway whose memory jumps).
let gcTimer = null; function gcSoon() { if (typeof global.gc === 'function' && !gcTimer) gcTimer = setTimeout(() => { gcTimer = null; try { global.gc(); } catch { /* ignore */ } }, 200); }
/** "Keep separate" after a new number was linked (history.json, written by the dashboard): only messages from that time on belong to this number. */
let histCache = { at: 0, mtime: -1, from: '' };
function historyFrom() {
  if (Date.now() - histCache.at < 5000) return histCache.from;
  histCache.at = Date.now();
  try { const f = path.join(dataDir, 'history.json'), m = fs.statSync(f).mtimeMs; if (m !== histCache.mtime) { histCache.mtime = m; histCache.from = String(JSON.parse(fs.readFileSync(f, 'utf8')).from || ''); } } catch { histCache.from = ''; histCache.mtime = -1; }
  return histCache.from;
}
function readArchive(opts = {}) { const out = readArchiveRows(opts); if (!opts.jid) gcSoon(); return out; }
function readArchiveRows({ phone = '', q = '', limit = 300, months = 3, jid = '', events = false } = {}) {
  const jidNeedle = jid ? `"jid":${JSON.stringify(String(jid))}` : ''; // every row and marker of a chat carries its jid exactly like this
  const files = fs.existsSync(archiveDir) ? fs.readdirSync(archiveDir).filter((f) => /^\d{4}-\d{2}\.jsonl$/.test(f)).sort().slice(-months) : [];
  const deleted = new Set();
  const revoked = new Map();
  const edits = new Map();
  const fetched = new Map();
  const patches = new Map();
  const reactions = new Map();
  const hidden = new Set(); // messages deleted from the dashboard
  const cleared = new Map(); // chats deleted from the dashboard: jid -> time (older messages are hidden)
  const rows = [];
  for (const f of files) {
    const ac = archiveFile(path.join(archiveDir, f)), ids = jid ? (ac.idx.get(String(jid)) || []) : null; // a chat asked for: only its own lines
    for (let ai = 0, an = ids ? ids.length : ac.lines.length; ai < an; ai++) {
      const line = ac.lines[ids ? ids[ai] : ai];
      if (!line || (jidNeedle && !line.includes(jidNeedle))) continue; // one chat asked for: other chats' lines are skipped without parsing
      let r; try { r = JSON.parse(line); } catch { continue; }
      if (r.dir === 'deleted') { deleted.add(r.id); continue; }
      if (r.dir === 'revoked') { revoked.set(r.id, r.time); continue; }
      if (r.dir === 'media') { fetched.set(r.id, r.attachment); continue; }
      if (r.dir === 'patch') { patches.set(r.id, r); continue; }
      if (r.dir === 'reaction') { reactions.set(r.id, r.emoji); continue; }
      if (r.dir === 'hidden') { hidden.add(r.id); continue; }
      if (r.dir === 'unhidden') { hidden.delete(r.id); continue; } // put back from the recycle bin
      if (r.dir === 'chat_cleared') { cleared.set(r.jid, r.time); continue; }
      if (r.dir === 'chat_restored') { cleared.delete(r.jid); continue; }
      if (r.dir === 'group_event' && !events) continue;
      if (r.dir === 'edited') { (edits.get(r.id) || edits.set(r.id, []).get(r.id)).push({ time: r.time, text: r.text }); continue; }
      rows.push(r);
    }
  }
  const needle = String(q).toLowerCase();
  const from = historyFrom();
  const out = rows.filter((r) => !from || String(r.time) >= from).filter((r) => !(r.id && hidden.has(r.id)) && !(r.jid && cleared.has(r.jid) && String(r.got || r.time) <= cleared.get(r.jid))) // a message that arrived after the chat was deleted shows
    .filter((r) => (!jid || r.jid === jid) && (!phone || String(r.phone || '').includes(phone)) && (!needle || JSON.stringify(r).toLowerCase().includes(needle)))
    .map((r) => (r.id && patches.has(r.id) ? { ...r, kind: (!r.kind || r.kind === 'text') ? (patches.get(r.id).kind || r.kind) : r.kind, mref: r.mref || patches.get(r.id).mref, file_name: r.file_name || patches.get(r.id).file_name, from: r.from || patches.get(r.id).from, quote: r.quote || patches.get(r.id).quote, quote_from: r.quote_from || patches.get(r.id).quote_from, mentions: r.mentions || patches.get(r.id).mentions } : r))
    .map((r) => ({ ...r, ...(r.id && fetched.has(r.id) ? { attachment: fetched.get(r.id) } : {}), deleted: r.id ? deleted.has(r.id) : false, reaction: r.id && reactions.get(r.id) ? reactions.get(r.id) : undefined, revoked_at: r.id && revoked.has(r.id) ? revoked.get(r.id) : undefined, edits: r.id && edits.has(r.id) ? edits.get(r.id) : undefined }));
  out.sort((a, b) => String(a.time).localeCompare(String(b.time)));
  return out.slice(-Math.max(1, Math.min(300000, Number(limit) || 300))).reverse();
}

/** Retries a temporary server failure (HTTP 5xx page, timeout, connection error) twice before giving up. */
async function postInvoiceWebhookWithRetry(payload) {
  let result;
  for (const wait of [0, 4000, 15000]) {
    if (wait) await new Promise((r) => setTimeout(r, wait));
    try { result = await postInvoiceWebhook(payload); } catch (err) { result = { ok: false, error: err?.message || String(err) }; }
    const err = String(result?.error || '');
    const temporary = !result?.ok && (/<html|<!doctype|internal server error|timed out|ECONN|EAI_AGAIN|socket hang up|50\d/i.test(err) || !err);
    if (!temporary) break;
    console.log(`Webhook temporary failure, retrying: ${err.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').slice(0, 80)}`);
  }
  if (result && !result.ok && result.error) result.error = String(result.error).replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 160);
  return result;
}

async function sendWebhookReplies(sock, jid, replies) {
  if (!Array.isArray(replies)) {
    return;
  }
  for (const reply of replies) {
    const text = String(reply.message || '').trim();
    const attachment = safeAttachment(reply.attachment || '');
    if (attachment) {
      const ext = path.extname(attachment).toLowerCase();
      if (['.png', '.jpg', '.jpeg', '.webp'].includes(ext)) {
        archiveSent(await sock.sendMessage(jid, text ? { image: fs.readFileSync(attachment), caption: text } : { image: fs.readFileSync(attachment) }), jid, 'image', text, attachment, 'bot');
      } else {
        archiveSent(await sock.sendMessage(jid, {
          document: fs.readFileSync(attachment),
          fileName: path.basename(attachment),
          mimetype: 'application/pdf',
          ...(text ? { caption: text } : {}),
        }), jid, 'document', text, attachment, 'bot');
      }
    } else if (text) {
      archiveSent(await sock.sendMessage(jid, { text }), jid, 'text', text, '', 'bot');
    }
  }
}

function withTimeout(promise, ms, label) {
  let timer;
  const timeout = new Promise((_, reject) => {
    timer = setTimeout(() => reject(new Error(label || 'Operation timed out')), ms);
  });
  return Promise.race([promise, timeout]).finally(() => clearTimeout(timer));
}

// Reconnect with exponential backoff (3s, 6s, 12s ... max 60s) so a flapping link is not hammered.
let reconnectAttempts = 0;
let disconnectedSince = Date.now();
let lastDisconnectCode = null;
// Number health: recent connection events and send failures (for the Inbox health page and alerts).
const healthLog = [];
function healthEvent(type, detail) { healthLog.push({ at: new Date().toISOString(), type, detail: String(detail || '').slice(0, 200) }); if (healthLog.length > 200) healthLog.shift(); }
let loggedOut = false;
function scheduleReconnect(delayMs) {
  if (reconnectTimer) {
    return;
  }
  if (delayMs === undefined) {
    delayMs = Math.min(60000, 3000 * Math.pow(2, reconnectAttempts));
    reconnectAttempts += 1;
  }
  reconnectTimer = setTimeout(() => {
    reconnectTimer = null;
    startSocket().catch((error) => {
      lastError = error.message;
    });
  }, delayMs);
}

function resetAuthState() {
  // Keep the hidden-number (LID) → phone mappings across a re-link, so customers stay recognised.
  const keep = {};
  try { for (const f of fs.readdirSync(authDir)) if (/^lid-mapping-/.test(f)) keep[f] = fs.readFileSync(path.join(authDir, f)); } catch (err) { /* no auth yet */ }
  // Full copy of the old session, so a re-link that is not completed can be undone (/restore-auth).
  // Keep the old session aside in one step (a rename, not a copy of thousands of files that would freeze the gateway).
  try {
    if (fs.existsSync(path.join(authDir, 'creds.json'))) {
      const trash = authBackupDir + '-old-' + Date.now();
      if (fs.existsSync(authBackupDir)) fs.renameSync(authBackupDir, trash);
      fs.renameSync(authDir, authBackupDir);
      fs.rm(trash, { recursive: true, force: true }, () => {}); // removed in the background
    }
  } catch (err) { console.error('Auth backup failed', err?.message || err); }
  fs.rmSync(authDir, { recursive: true, force: true });
  try { fs.mkdirSync(authDir, { recursive: true }); for (const [f, buf] of Object.entries(keep)) fs.writeFileSync(path.join(authDir, f), buf); } catch (err) { console.error('LID mapping restore failed', err?.message || err); }
  console.log(`Auth reset; kept ${Object.keys(keep).length} LID mappings`);
  latestQr = '';
  latestQrDataUrl = '';
}

async function startSocket() {
  if (connecting || connected) {
    return;
  }
  connecting = true;
  try {
    const { state, saveCreds } = await useMultiFileAuthState(authDir);
    const { version } = await fetchLatestBaileysVersion();
    const sock = makeWASocket({
      auth: state,
      browser: [process.env.DEVICE_NAME || 'WhatsGuard', 'Desktop', '1.0'], // name shown under Linked devices on the phone
      syncFullHistory: true, // old chats (archived ones too) arrive once, when the device is linked
      markOnlineOnConnect: false, // the bot's number does not show "online" because of the gateway
      logger: pino({ level: 'silent' }),
      printQRInTerminal: false,
      version,
    });
    socket = sock;

    sock.ev.on('creds.update', saveCreds);
    const takeContacts = (list) => { for (const c of list || []) { const nm = c.name || c.notify || c.verifiedName; if (c.id && nm) setName(c.id, nm); if (c.lid && nm) setName(c.lid, nm); if (c.phoneNumber && nm) setName(c.phoneNumber, nm); } };
    sock.ev.on('presence.update', ({ id, presences }) => {
      for (const [who, p] of Object.entries(presences || {})) {
        const st = p?.lastKnownPresence;
        if (['composing', 'recording', 'paused', 'available', 'unavailable'].includes(st)) pushEvent('presence', { jid: id, who, state: st });
        if (!String(id).endsWith('@g.us')) botTypingSeen(id, st);
      }
    });
    sock.ev.on('messages.update', (updates) => {
      for (const u of updates || []) {
        const st = Number(u?.update?.status || 0);
        if (u?.key?.id && st >= 2) { msgStatus.set(u.key.id, st); pushEvent('status', { id: u.key.id, jid: u.key.remoteJid, status: st }); }
      }
      if (msgStatus.size > 20000) msgStatus.clear();
    });
    sock.ev.on('message-receipt.update', (updates) => {
      for (const u of updates || []) {
        const st = u?.receipt?.readTimestamp ? 4 : (u?.receipt?.receiptTimestamp ? 3 : 0);
        if (u?.key?.id && st) { msgStatus.set(u.key.id, Math.max(st, msgStatus.get(u.key.id) || 0)); pushEvent('status', { id: u.key.id, jid: u.key.remoteJid, status: msgStatus.get(u.key.id) }); }
      }
    });
    sock.ev.on('contacts.upsert', takeContacts);
    sock.ev.on('contacts.update', takeContacts);
    sock.ev.on('groups.upsert', (list) => { for (const g of list || []) if (g.id && g.subject) setName(g.id, g.subject); });
    sock.ev.on('groups.update', (list) => { for (const g of list || []) if (g.id && g.subject) setName(g.id, g.subject); });
    // Joins / leaves / admin changes in groups are kept in the archive (for group management and case follow-up).
    sock.ev.on('group.join-request', (u) => { if (u?.action === 'created' && approveGroups().has(u.id)) approveRequests(sock, u.id, u.participant); });
    sock.ev.on('group-participants.update', (u) => {
      try {
        for (const p of u.participants || []) {
          const pid = typeof p === 'string' ? p : (p.phoneNumber || p.id || '');
          archive({ dir: 'group_event', id: `ge_${Date.now()}_${Math.random().toString(36).slice(2, 7)}`, jid: u.id, action: u.action, member: String(pid).split('@')[0].split(':')[0], by: String(u.author || '').split('@')[0] });
        }
        // New members: the Inbox may welcome them (with a mention) if this group is set up for it.
        if (u.action === 'add') {
          const members = (u.participants || []).map((p) => (typeof p === 'string' ? p : (p.phoneNumber || p.id || ''))).filter(Boolean);
          try {
            const tok = fs.readFileSync(path.join(__dirname, '..', 'wa-inbox', '.data', 'bridge_token'), 'utf8').trim();
            const by = String(u.authorPn || u.author || '').split('@')[0].split(':')[0]; // the admin who added them (the healer who moved them)
            const body = JSON.stringify({ account: process.env.ACCOUNT_ID || 'main', jid: u.id, cmd: 'join', members, by });
            const r = http.request({ host: inboxHook.host, port: inboxHook.port, path: inboxHook.path, method: 'POST', timeout: 8000,
              headers: { Host: inboxHook.hostname, 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(body), 'X-Bot-Token': tok } }, (res) => res.resume());
            r.on('error', () => {}); r.on('timeout', () => r.destroy()); r.end(body);
          } catch { /* ignore */ }
        }
      } catch (e) { console.error('group event', e?.message || e); }
    });
    sock.ev.on('chats.upsert', (list) => { for (const c of list || []) if (c.id && c.name) setName(c.id, c.name); });
    // Chat history WhatsApp sends to a newly linked device: archived with the original message time.
    sock.ev.on('messaging-history.set', async ({ messages = [], chats = [], contacts = [] }) => {
      try {
        const names = {};
        const hnames = {};
        for (const c of contacts) if (c?.id) { hnames[c.id] = c.name || c.notify || c.verifiedName || ''; setName(c.id, hnames[c.id]); }
        for (const c of chats) if (c?.id && c.name) { hnames[c.id] = c.name; setName(c.id, c.name); }
        let n = 0;
        for (const msg of messages) {
          const key = msg.key || {};
          const jid = key.remoteJid || '';
          if (!jid || jid === 'status@broadcast' || !msg.message) continue;
          let pj = [key.remoteJidAlt, jid].find((j) => j && j.endsWith('@s.whatsapp.net')) || '';
          if (!pj && jid.endsWith('@lid')) pj = await phoneJidForLid(sock, jid);
          if (!key.fromMe && msg.pushName && !jid.endsWith('@g.us')) setName(jid, msg.pushName);
          const hk = archiveKind(msg);
          const isAudio = ['voice', 'audio'].includes(hk) || (hk === 'document' && /^audio\//.test(String(unwrapMessageContent(msg)?.documentMessage?.mimetype || '')));
          const href = mediaRef(msg);
          // Group sender: prefer the phone-number JID; remember their WhatsApp name.
          const hpart = jid.endsWith('@g.us') && !key.fromMe ? String(key.participantAlt || key.participant || msg.participant || '') : '';
          const hfrom = hpart.split('@')[0].split(':')[0];
          if (hpart && msg.pushName) setName(hpart, msg.pushName);
          if (key.id && knownIdSet().has(key.id)) {
            // Already archived (earlier sync): only fill in what was missing, then queue its audio.
            const hctx = ctxOf(msg);
            if (hk !== 'text' || href || hfrom || hctx.quote || hctx.mentions) archive({ dir: 'patch', id: key.id, jid, kind: hk, mref: href, file_name: href?.fileName, from: hfrom || undefined, ...hctx });
            if ((isAudio || MEDIA_AUTO.includes(hk)) && href) queueMedia(key.id);
            n++;
            continue;
          }
          if ((isAudio || MEDIA_AUTO.includes(hk)) && href) queueMedia(key.id); // photos and recordings: downloaded in the background, one by one
          const hmedia = '';
          archive({ dir: key.fromMe ? 'out' : 'in', id: key.id || '', jid, phone: pj ? pj.split('@')[0] : '', kind: hk, text: archiveText(msg), mref: href, tags: textTags(archiveText(msg)), attachment: hmedia || undefined,
            attachment: '', via: 'history', name: hnames[jid] || msg.pushName || undefined, ts: Number(msg.messageTimestamp || 0) || undefined,
            group: jid.endsWith('@g.us') ? jid : undefined, from: hfrom || undefined, ...ctxOf(msg) });
          n++;
        }
        console.log(`History sync archived ${n} messages (${chats.length} chats)`);
      } catch (err) { console.error('History sync archive failed', err?.message || err); }
    });
    sock.ev.on('messages.upsert', async (event) => {
      if (!event || !Array.isArray(event.messages)) return;
      console.log(`WhatsApp message batch type=${event.type || 'unknown'} count=${event.messages.length}`);
      if (['notify', 'append'].includes(event.type)) { lastMessageAt = Date.now(); messagesSinceStart += event.messages.length; }
      for (const msg of event.messages) {
        const remoteJid = msg.key?.remoteJid || '';
        try {
          const key = msg.key || {};
          let phoneJid = [key.remoteJidAlt, key.senderPn, remoteJid].find((jid) => jid && jid.endsWith('@s.whatsapp.net')) || '';
          if (!phoneJid && remoteJid.endsWith('@lid')) phoneJid = await phoneJidForLid(sock, remoteJid); // hidden-number chats
          if (!phoneJid) phoneJid = remoteJid;
          const unresolvedLid = phoneJid.endsWith('@lid');
          const timestamp = Number(msg.messageTimestamp || 0);
          // Deleted-for-everyone / edited messages: the archive keeps the original text and records what happened.
          const proto = msg.message?.protocolMessage || msg.message?.editedMessage?.message?.protocolMessage;
          if (proto && proto.key?.id) {
            const t = Number(proto.type);
            if (t === 0) archive({ dir: 'revoked', id: proto.key.id, jid: remoteJid, by: key.fromMe ? 'me' : 'them' });
            else if (t === 14 && proto.editedMessage) archive({ dir: 'edited', id: proto.key.id, jid: remoteJid, text: textFromMessage({ message: proto.editedMessage }) });
            continue;
          }
          // Full archive (user, 2026-10-08): every chat message, including what the owner types on the phone and groups.
          if (remoteJid && remoteJid !== 'status@broadcast' && ['notify', 'append'].includes(event.type) && (timestamp === 0 || Math.floor(Date.now() / 1000) - timestamp < 86400)) {
            const isGroup = remoteJid.endsWith('@g.us');
            if (key.fromMe && !isGroup) botCommand(sock, remoteJid, key, archiveText(msg));
            if (key.fromMe && (timestamp === 0 || Math.floor(Date.now() / 1000) - timestamp < 300)) expandShortcut(sock, remoteJid, key, archiveText(msg)).catch(() => {});
            if (key.fromMe || isGroup) {
              let pj = [key.remoteJidAlt, remoteJid].find((j) => j && j.endsWith('@s.whatsapp.net')) || '';
              if (!pj && remoteJid.endsWith('@lid')) pj = await phoneJidForLid(sock, remoteJid);
              if (isGroup) groupName(sock, remoteJid).catch(() => {});
              if (isGroup && !key.fromMe && msg.pushName) setName(String(key.participantAlt || key.participant || ''), msg.pushName);
              const ak = archiveKind(msg);
              const amedia = await saveArchiveMedia(msg, ak);
              archive({ dir: key.fromMe ? 'out' : 'in', id: key.id || '', jid: remoteJid, phone: pj ? pj.split('@')[0] : '', name: !key.fromMe ? msg.pushName || undefined : undefined, kind: ak, text: archiveText(msg),
                mref: mediaRef(msg) || undefined, tags: textTags(archiveText(msg)), attachment: amedia, via: key.fromMe ? 'phone' : 'group', group: isGroup ? remoteJid : undefined, from: isGroup && !key.fromMe ? String(key.participantAlt || key.participant || '').split('@')[0] : undefined,
                from_lid: isGroup && !key.fromMe && String(key.participant || '').endsWith('@lid') && key.participantAlt ? String(key.participant).split('@')[0] : undefined, ...ctxOf(msg) });
              if (!amedia && MEDIA_AUTO.includes(ak) && mediaRef(msg)) queueMedia(key.id); // download failed now: retried in the background
              if (!key.fromMe && event.type === 'notify') keywordReply(sock, remoteJid, msg, archiveText(msg)).catch(() => {});
              if (isGroup && event.type === 'notify') caseIntake(sock, remoteJid, msg, archiveText(msg), ak, amedia).catch(() => {});
              if (isGroup && !key.fromMe && event.type === 'notify') botPing(remoteJid); // the Inbox decides if the bot answers in groups
            }
          }
          // Admin commands typed in the owner's own chat ("موافق 12" / "رفض 12 ..."): approve or reject held receipts.
          if (key.fromMe && event.type === 'notify') {
            const own = [sock?.user?.id, sock?.user?.lid].filter(Boolean).map((j) => String(j).split(':')[0].split('@')[0]);
            const chatId = String(remoteJid).split(':')[0].split('@')[0];
            const cmd = textFromMessage(msg).trim();
            if (own.includes(chatId) && loadRules().payCmd.test(cmd)) {
              console.log(`Admin command id=${key.id || ''}`);
              archive({ dir: 'in', id: key.id || '', jid: remoteJid, phone: own[0] || '', kind: 'text', text: cmd, attachment: '', bot: 'admin_command' });
              const res = await postInvoiceWebhookWithRetry({ account: accountId, admin_command: cmd, message_id: key.id || '', phone: own[0] || '', jid: remoteJid, text: cmd, timestamp });
              await sendWebhookReplies(sock, remoteJid, res?.replies?.length ? res.replies : [{ message: `⚠️ ${res?.error || 'Command failed'}` }]);
              continue;
            }
          }
          const isRecentAppend = event.type === 'append' && timestamp > 0 && Math.floor(Date.now() / 1000) - timestamp < 180;
          if (!remoteJid || key.fromMe || (!['notify', 'append'].includes(event.type)) || (event.type === 'append' && !isRecentAppend)
            || !(remoteJid.endsWith('@s.whatsapp.net') || remoteJid.endsWith('@lid'))) {
            continue;
          }
          const phone = normalizePhone(phoneJid.split('@')[0]);
          const text = textFromMessage(msg);
          const kind = mediaKind(msg);
          const messageId = key.id || '';
          console.log(`Incoming WhatsApp message type=${event.type} id=${messageId} phone=${phone.slice(0, 3)}*** kind=${kind || 'text'} textLength=${text.length}`);

          // Payment bot switched off for this number (Inbox → WhatsApp accounts): messages are only archived.
          const payOn = paymentsEnabled();
          const mediaPath = payOn ? await saveIncomingMedia(msg, kind) : '';
          const result = !payOn ? { ok: true, status: 'payments_off' } : await postInvoiceWebhookWithRetry({
            phone,
            jid: remoteJid,
            phone_jid: phoneJid,
            message_id: messageId,
            text,
            media_path: mediaPath,
            media_kind: kind,
            account: accountId,
            timestamp: timestamp || Math.floor(Date.now() / 1000),
          });
          console.log(`WhatsApp invoice webhook id=${messageId} ok=${Boolean(result?.ok)} status=${result?.status || 'error'} error=${result?.error || ''}`);
          if (msg.pushName) { setName(remoteJid, msg.pushName); if (phoneJid.endsWith('@s.whatsapp.net')) setName(phoneJid, msg.pushName); }
          const inKind = archiveKind(msg);
          const inMedia = await saveArchiveMedia(msg, inKind);
          archive({ ...ctxOf(msg), dir: 'in', id: messageId, jid: remoteJid, phone: phoneJid.endsWith('@s.whatsapp.net') ? phone : '', name: msg.pushName || undefined, kind: inKind, text: archiveText(msg), attachment: inMedia,
            mref: mediaRef(msg) || undefined, tags: textTags(archiveText(msg)), bot: result?.status || 'error' });
          if (!inMedia && MEDIA_AUTO.includes(inKind) && mediaRef(msg)) queueMedia(messageId); // download failed now: retried in the background
          if (event.type === 'notify') keywordReply(sock, remoteJid, msg, archiveText(msg)).catch(() => {});
          if (!botCommand(sock, remoteJid, key, archiveText(msg))) botPing(remoteJid);
          if (!result?.ok) {
            await alertAdmin(sock, 'webhook', `The bot could not process a message (${result?.error || 'no response'}). Check the WhatsApp Assistant page.`);
          } else if (kind === 'image' && unresolvedLid && result?.status === 'ignored') {
            await alertAdmin(sock, 'lid', `A picture arrived from a hidden WhatsApp number that could not be matched to a customer, so it was not checked as a receipt. If it is a payment, record it by hand. The image is kept for 7 days.`);
          }
          if (result?.replies?.length) {
            await sendWebhookReplies(sock, remoteJid, result.replies);
          }
        } catch (error) {
          lastError = error.message;
          console.error('WhatsApp incoming handler failed', error?.stack || error);
          // No automatic reply on errors: the admin sees the message in the normal chat.
        }
      }
    });
    sock.ev.on('connection.update', async (update) => {
      if (socket !== sock && update.connection !== 'open') {
        return;
      }
      if (update.qr) {
        latestQr = update.qr;
        latestQrDataUrl = await QRCode.toDataURL(update.qr, { margin: 1, width: 320 });
        connected = false;
      }
      if (update.connection === 'open') {
        socket = sock;
        connected = true;
        setTimeout(() => { sock.sendPresenceUpdate('unavailable').catch(() => {}); }, 3000);
        consecutiveConnectionFailures = 0;
        reconnectAttempts = 0;
        disconnectedSince = null;
        lastDisconnectCode = null;
        loggedOut = false;
        latestQr = '';
        latestQrDataUrl = '';
        account = sock.user || null;
        lastError = '';
        console.log('WhatsApp connected', account?.id || '');
        healthEvent('connected', account?.id || '');
      }
      if (update.connection === 'close') {
        if (socket !== sock) {
          return;
        }
        connected = false;
        account = null;
        const code = update.lastDisconnect?.error?.output?.statusCode;
        lastError = update.lastDisconnect?.error?.message || 'Disconnected';
        if (!disconnectedSince) disconnectedSince = Date.now();
        lastDisconnectCode = code || null;
        loggedOut = code === DisconnectReason.loggedOut;
        console.log('WhatsApp disconnected', code || '', lastError);
        healthEvent(code === DisconnectReason.loggedOut ? 'logged_out' : 'disconnected', `${code || ''} ${lastError}`);
        socket = null;
        connecting = false;
        if (code === 440 || /conflict/i.test(lastError)) {
          console.log('WhatsApp conflict detected. Stopping this gateway process to avoid duplicate sessions.');
          setTimeout(() => process.exit(0), 500);
          return;
        }
        if (code === 405 || /connection failure/i.test(lastError)) {
          // Previously the session was wiped after 3 failures, silently forcing a new QR scan.
          // Now it keeps the session and retries with backoff; the watchdog alerts the admin if it stays down.
          consecutiveConnectionFailures += 1;
        }
        if (/qr refs attempts ended/i.test(lastError)) {
          latestQr = '';
          latestQrDataUrl = '';
        }
        if (code !== DisconnectReason.loggedOut) {
          scheduleReconnect();
        }
      }
    });
  } catch (error) {
    lastError = error.message;
  } finally {
    connecting = false;
  }
}

async function resetSocket(clearAuth = false) {
  if (socket) {
    try {
      socket.end(undefined);
    } catch {
      // ignore shutdown errors
    }
    socket = null;
  }
  connected = false;
  connecting = false;
  account = null;
  latestQr = '';
  latestQrDataUrl = '';
  lastError = '';
  consecutiveConnectionFailures = 0;
  if (clearAuth) {
    resetAuthState();
  }
  await startSocket();
}

// Never send half an emoji (a lone UTF-16 surrogate left by cutting text): other languages reject the whole JSON.
const LONE_SURROGATE = /[\uD800-\uDBFF](?![\uDC00-\uDFFF])|(?<![\uD800-\uDBFF])[\uDC00-\uDFFF]/g;
app.set('json replacer', (_k, v) => (typeof v === 'string' ? v.replace(LONE_SURROGATE, '') : v));

app.get('/', requireToken, (_req, res) => {
  res.redirect(`${baseUri}/qr`);
});

app.get('/status', requireToken, (_req, res) => {
  const qrAvailable = Boolean(latestQrDataUrl) && !/qr refs attempts ended/i.test(lastError);
  res.json({
    ok: true,
    connected,
    connecting,
    qr_available: qrAvailable,
    account,
    last_error: lastError,
    // For the watchdog's alerts: how long it has been down and whether a new QR scan is needed.
    disconnected_since: disconnectedSince ? new Date(disconnectedSince).toISOString() : null,
    down_seconds: disconnectedSince ? Math.round((Date.now() - disconnectedSince) / 1000) : 0,
    last_disconnect_code: lastDisconnectCode,
    needs_qr: loggedOut || qrAvailable,
    reconnect_attempts: reconnectAttempts,
    // Gateway health for the Inbox "Gateway" page.
    account_id: accountId,
    pid: process.pid,
    started_at: new Date(gatewayStartedAt).toISOString(),
    uptime_seconds: Math.round((Date.now() - gatewayStartedAt) / 1000),
    memory_mb: Math.round(process.memoryUsage().rss / 1048576),
    node: process.version,
    last_message_at: lastMessageAt ? new Date(lastMessageAt).toISOString() : null,
    messages_since_start: messagesSinceStart,
  });
});

/** Full restart: the process exits and the Gateway Guard (watchdog) starts it again within a minute. */
app.post('/shutdown', requireToken, (_req, res) => {
  res.json({ ok: true, restarting: true });
  console.log('Shutdown requested from the Inbox; the watchdog will start the gateway again.');
  setTimeout(() => process.exit(0), 600);
});

app.post('/restart', requireToken, async (_req, res) => {
  await resetSocket(false);
  res.json({ ok: true });
});

app.post('/reset', requireToken, async (_req, res) => {
  await resetSocket(true);
  res.json({ ok: true });
});

app.get('/send', requireToken, (_req, res) => {
  const wantsJson = String(_req.get('accept') || '').includes('application/json');
  const payload = {
    ok: true,
    method: 'POST required',
    connected,
    message: 'This endpoint sends WhatsApp messages from the invoice system. Open it by POST with JSON: { "to": "201xxxxxxxxx", "message": "text" }.',
  };
  if (wantsJson) {
    res.json(payload);
    return;
  }
  res.type('html').send(`<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>WhatsApp Send Gateway</title>
  <style>
    body{font-family:Arial,sans-serif;background:#f3f6fb;color:#0f172a;display:grid;place-items:center;min-height:100vh;margin:0}
    main{background:#fff;border:1px solid #d8e0ef;border-radius:12px;padding:28px;box-shadow:0 18px 50px rgba(15,23,42,.08);max-width:560px}
    .ok{color:#087f5b}.bad{color:#b91c1c}code{background:#f1f5f9;padding:2px 5px}
  </style>
</head>
<body>
  <main>
    <h1>WhatsApp Send Gateway</h1>
    <p class="${connected ? 'ok' : 'bad'}">${connected ? 'WhatsApp is linked and ready.' : 'WhatsApp is not linked yet.'}</p>
    <p>This URL is for the invoice system only. It sends messages with <code>POST</code>, not by opening it directly in the browser.</p>
  </main>
</body>
</html>`);
});

/** Undo a re-link that was not completed: put the previous session back and reconnect. */
app.post('/restore-auth', requireToken, async (_req, res) => {
  if (!fs.existsSync(path.join(authBackupDir, 'creds.json'))) { res.status(404).json({ ok: false, error: 'No previous session to restore.' }); return; }
  if (connected) { res.status(409).json({ ok: false, error: 'Already connected.' }); return; }
  await resetSocket(false);
  fs.rmSync(authDir, { recursive: true, force: true });
  fs.renameSync(authBackupDir, authDir); // instant restore
  startSocket().catch((e) => { lastError = e.message; });
  res.json({ ok: true });
});

/** QR as JSON for the inbox app's link page. */
app.get('/qr.json', requireToken, async (_req, res) => {
  await startSocket();
  const qrAvailable = Boolean(latestQrDataUrl) && !/qr refs attempts ended/i.test(lastError);
  res.set('Cache-Control', 'no-store');
  res.json({ ok: true, connected: Boolean(connected), qr: connected || !qrAvailable ? '' : latestQrDataUrl, account: socket?.user?.name || '', error: lastError || '' });
});

app.get('/qr', requireToken, async (_req, res) => {
  await startSocket();
  const qrAvailable = Boolean(latestQrDataUrl) && !/qr refs attempts ended/i.test(lastError);
  const status = connected ? 'Connected. You can close this page.' : (qrAvailable ? 'Scan this QR with WhatsApp Linked devices' : 'Waiting for QR');
  const image = connected
    ? '<div class="empty ok">Connected</div>'
    : (qrAvailable ? `<img alt="WhatsApp QR" src="${latestQrDataUrl}">` : '<div class="empty">QR is being prepared. Refresh in a few seconds.</div>');
  res.set('Cache-Control', 'no-store, no-cache, must-revalidate, proxy-revalidate');
  res.set('Pragma', 'no-cache');
  res.set('Expires', '0');
  const token = bearer(_req);
  const statusUrl = `${publicBasePath}/status?token=${encodeURIComponent(token)}`;
  const resetUrl = `${publicBasePath}/reset?token=${encodeURIComponent(token)}`;
  res.type('html').send(`<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta http-equiv="refresh" content="8">
  <title>WhatsApp QR</title>
  <style>
    body{font-family:Arial,sans-serif;background:#f3f6fb;color:#0f172a;display:grid;place-items:center;min-height:100vh;margin:0}
    main{background:#fff;border:1px solid #d8e0ef;border-radius:12px;padding:28px;box-shadow:0 18px 50px rgba(15,23,42,.08);text-align:center;max-width:440px}
    img{width:320px;height:320px;display:block;margin:16px auto}
    .ok{color:#087f5b}.empty{padding:56px 24px;border:1px dashed #cbd5e1;border-radius:10px;color:#64748b}
    .actions{display:flex;gap:10px;justify-content:center;margin-top:18px;flex-wrap:wrap}
    button,a{border:0;border-radius:8px;background:#0f766e;color:#fff;padding:10px 14px;text-decoration:none;font-weight:700;cursor:pointer}
    button.secondary,a.secondary{background:#e8eef7;color:#172033}
    small{color:#64748b}
  </style>
</head>
<body>
  <main>
    <h1>WhatsApp Web Gateway</h1>
    <p id="status" class="${connected ? 'ok' : ''}">${escapeHtml(status)}</p>
    <div id="qr">${image}</div>
    <small id="details">${escapeHtml(account?.id || lastError || 'Keep this page open until pairing completes.')}</small>
    <div class="actions">
      <button type="button" onclick="location.reload()">Refresh</button>
      <button class="secondary" type="button" onclick="fetch(${JSON.stringify(resetUrl)},{method:'POST'}).then(()=>setTimeout(()=>location.reload(),900))">New QR</button>
    </div>
  </main>
  <script data-cfasync="false">
    async function poll() {
      try {
        const response = await fetch(${JSON.stringify(statusUrl)}, { headers: { Accept: 'application/json' } });
        const status = await response.json();
        const label = document.getElementById('status');
        const details = document.getElementById('details');
        if (status.connected) {
          label.className = 'ok';
          label.textContent = 'Connected. You can close this page.';
          details.textContent = (status.account && status.account.id) ? status.account.id : 'WhatsApp is linked.';
          document.getElementById('qr').innerHTML = '<div class="empty ok">Connected</div>';
          setTimeout(function(){
            try { window.close(); } catch(e) {}
            if (!window.closed) { window.location.href = '/index.php/settings?config=settings_email'; }
          }, 1500);
          return;
        }
        details.textContent = status.last_error || 'Keep this page open until pairing completes.';
      } catch (e) {}
      setTimeout(poll, 2500);
    }
    poll();
  </script>
</body>
</html>`);
});

app.get('/archive', requireToken, (req, res) => {
  try {
    res.json({ ok: true, rows: readArchive({ phone: String(req.query.phone || '').replace(/\D+/g, ''), q: req.query.q || '', limit: req.query.limit, months: Number(req.query.months) || 3 }) });
  } catch (error) { res.status(500).json({ ok: false, error: error.message }); }
});

/** One row per conversation: last message, counts, name. */
app.get('/archive/chats', requireToken, async (req, res) => {
  try {
    const ck = String(Number(req.query.months) || 36), hit = chatsCache.get(ck);
    if (hit && hit.v === archiveVersion && Date.now() - hit.at < 120000) { res.json(hit.body); return; } // names refresh at least every 2 minutes
    const rows = readArchive({ limit: 300000, months: Number(req.query.months) || 36 });
    const chats = new Map();
    for (const r of rows.slice().reverse()) { // oldest → newest
      const k = r.jid || r.phone;
      if (!k) continue;
      const c = chats.get(k) || { jid: r.jid, phone: '', name: '', group: Boolean(r.group), count: 0, in: 0, out: 0, last: null };
      if (r.phone && !c.phone) c.phone = r.phone;
      if (r.name && r.dir === 'in' && !r.group) c.name = r.name;
      c.count++; if (r.dir === 'out') c.out++; else if (r.dir === 'in') c.in++;
      if (r.dir !== 'deleted') c.last = { time: r.time, dir: r.dir, text: r.text || '', kind: r.kind || 'text' };
      chats.set(k, c);
    }
    for (const c of chats.values()) {
      if (!c.phone && c.jid && c.jid.endsWith('@lid')) { const pj = await phoneJidForLid(socket, c.jid); if (pj) c.phone = pj.split('@')[0]; }
      if (c.name && /[·•∙⋅]{2,}|\*{3,}/.test(c.name)) c.name = '';
      const saved = names[c.jid] || (c.phone ? names[`${c.phone}@s.whatsapp.net`] : '');
      if (saved && !/[·•∙⋅]{2,}|\*{3,}/.test(saved)) c.name = saved;
      if (c.group && !names[c.jid]) groupName(socket, c.jid).catch(() => {});
      if (c.last && !c.last.text) c.last.text = '';
    }
    const body = { ok: true, chats: [...chats.values()].sort((a, b) => String(b.last?.time).localeCompare(String(a.last?.time))) };
    chatsCache.set(ck, { v: archiveVersion, at: Date.now(), body }); res.json(body);
  } catch (error) { res.status(500).json({ ok: false, error: error.message }); }
});

/** Numbers for the inbox dashboard. */
app.get('/archive/stats', requireToken, (req, res) => {
  try {
    const rows = readArchive({ limit: 300000, months: 36 });
    const now = Date.now(), dayMs = 86400000, days = {};
    for (let i = 13; i >= 0; i--) days[new Date(now - i * dayMs).toISOString().slice(0, 10)] = { in: 0, out: 0 };
    const today = new Date(now).toISOString().slice(0, 10);
    const chats = new Set(), groups = new Set(), active7 = new Set(), byChat = {};
    let total = 0, inN = 0, outN = 0, todayN = 0, media = 0, bot = 0;
    for (const r of rows) {
      if (r.dir === 'deleted') continue;
      total++; if (r.dir === 'out') outN++; else inN++;
      if (r.kind && r.kind !== 'text') media++;
      if (r.via === 'bot' || r.via === 'alert') bot++;
      const d = String(r.time).slice(0, 10);
      if (d === today) todayN++;
      if (days[d]) days[d][r.dir === 'out' ? 'out' : 'in']++;
      const k = r.jid || r.phone; if (!k) continue;
      chats.add(k); if (r.group) groups.add(k);
      if (now - Date.parse(r.time) < 7 * dayMs) active7.add(k);
      byChat[k] = byChat[k] || { jid: r.jid, phone: r.phone || '', name: '', count: 0, group: Boolean(r.group) };
      byChat[k].count++; if (r.phone && !byChat[k].phone) byChat[k].phone = r.phone; if (r.name && r.dir === 'in') byChat[k].name = r.name;
    }
    const first = rows.length ? rows[rows.length - 1].time : null;
    res.json({ ok: true, total, in: inN, out: outN, today: todayN, media, bot, chats: chats.size, groups: groups.size, active7: active7.size, since: first,
      days: Object.entries(days).map(([date, v]) => ({ date, ...v })), top: Object.values(byChat).sort((a, b) => b.count - a.count).slice(0, 8),
      connected: Boolean(connected), account: socket?.user?.name || '' });
  } catch (error) { res.status(500).json({ ok: false, error: error.message }); }
});

// Group senders stored as LID: resolved to their phone number once, then remembered.
const lidPhone = new Map();
let lidBusy = false;
async function resolveLidPhones(froms) {
  for (const f of froms) {
    if (lidPhone.has(f)) continue;
    let pn = '';
    try { pn = String((await withTimeout(phoneJidForLid(socket, `${f}@lid`), 3000, 't')) || '').split('@')[0]; } catch {}
    lidPhone.set(f, pn && pn !== f ? pn : '');
  }
}
/**
 * Everything about one person across ALL chats in one pass: their own group messages (by phone or LID),
 * messages that quote them or @mention them, and their private chat. ids = phone,lid,…
 */
/** Phone numbers written inside messages (e.g. case reports), with where they were written. */
app.get('/archive/numbers', requireToken, (req, res) => {
  try {
    const map = new Map();
    const conv = (t) => String(t || '').replace(/[٠-٩]/g, (d) => '٠١٢٣٤٥٦٧٨٩'.indexOf(d)).replace(/[۰-۹]/g, (d) => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d));
    for (const r of readArchive({ limit: 300000, months: 24 })) {
      if (!r.text || !['in', 'out'].includes(r.dir)) continue;
      for (const m of conv(r.text).matchAll(/(?<![\d@])(?:\+|00)?\d[\d \-.]{8,17}\d(?!\d)/g)) {
        let d = m[0].replace(/\D/g, ''); if (d.startsWith('00')) d = d.slice(2);
        if (d.length === 11 && d.startsWith('01')) d = `2${d}`;
        if (d.length < 10 || d.length > 15) continue;
        const e = map.get(d) || { phone: d, count: 0, chats: {}, last: '' };
        e.count++; e.chats[r.jid] = names[r.jid] || ''; if (r.time > e.last) e.last = r.time;
        map.set(d, e);
      }
    }
    res.json({ ok: true, numbers: [...map.values()] });
  } catch (error) { res.status(500).json({ ok: false, error: error.message }); }
});
/**
 * Learning material: in private chats, a customer's message(s) followed by our reply (from the phone or the Inbox,
 * not the bot) within 6 hours. Consecutive messages on each side are joined.
 */
app.get('/archive/pairs', requireToken, (req, res) => {
  try {
    const rows = readArchive({ limit: 300000, months: Number(req.query.months) || 24 }).filter((r) => !r.group && ['in', 'out'].includes(r.dir) && String(r.jid || '').match(/@(s\.whatsapp\.net|lid)$/));
    rows.sort((a, b) => (a.jid === b.jid ? String(a.time).localeCompare(String(b.time)) : String(a.jid).localeCompare(String(b.jid))));
    const pairs = []; let q = null, a = null, jid = '';
    const flush = () => { if (q && a && q.text.trim() && a.text.trim()) pairs.push({ jid, q: q.text.slice(0, 1500), a: a.text.slice(0, 2000), q_time: q.time, a_time: a.time, a_by: a.by || a.via || '' }); };
    for (const r of rows) {
      if (r.jid !== jid) { flush(); q = null; a = null; jid = r.jid; }
      const txt = String(r.text || '').trim(); if (!txt) continue;
      if (r.dir === 'in') { if (a) { flush(); q = null; a = null; } q = q && Date.parse(r.time) - Date.parse(q.time) < 1800000 ? { text: `${q.text}\n${txt}`, time: q.time } : { text: txt, time: r.time }; }
      else if (q && (r.via !== 'bot') && Date.parse(r.time) - Date.parse(q.time) < 6 * 3600000) { a = a ? { ...a, text: `${a.text}\n${txt}` } : { text: txt, time: r.time, by: r.by, via: r.via }; }
    }
    flush();
    // Groups chosen for learning: a member's message and the answer from us / a team member (admin, sheikh).
    // Linked by WhatsApp's "reply to" when present; otherwise the closest member question before it (within 30 min).
    const gset = new Set(String(req.query.groups || '').split(',').filter((g) => g.endsWith('@g.us')));
    const team = new Set(String(req.query.team || '').split(',').map((x) => x.replace(/\D/g, '')).filter(Boolean));
    if (gset.size) {
      const norm = (x) => { const v = String(x || '').replace(/\D/g, ''); return lidPhone.get(v) || v; };
      const byG = new Map();
      for (const r of readArchive({ limit: 300000, months: Number(req.query.months) || 24 })) if (gset.has(r.jid) && ['in', 'out'].includes(r.dir) && String(r.text || '').trim()) (byG.get(r.jid) || byG.set(r.jid, []).get(r.jid)).push(r);
      for (const [g, list] of byG) {
        list.sort((a, b) => String(a.time).localeCompare(String(b.time)));
        const byId = new Map(list.map((r) => [r.id, r]));
        const isTeam = (r) => r.dir === 'out' ? r.via !== 'bot' : team.has(norm(r.from));
        for (let i = 0; i < list.length; i++) {
          const r = list[i]; if (!isTeam(r)) continue;
          let q = r.quote && byId.get(r.quote);
          if (q && (q.dir !== 'in' || isTeam(q))) q = null; // only a member's own message is a question (never the bot's / ours)
          if (!q) for (let k = i - 1; k >= 0 && Date.parse(r.time) - Date.parse(list[k].time) < 1800000; k--) { const c = list[k]; if (!isTeam(c) && /[؟?]|ازاي|إزاي|ايه|إيه|ليه|امتى|هل|كيف|ممكن|عايز|عايزة|محتاج/.test(String(c.text))) { q = c; break; } }
          if (q && String(q.text).trim().length > 3 && String(r.text).trim().length > 5) pairs.push({ jid: g, q: String(q.text).slice(0, 1500), a: String(r.text).slice(0, 2000), q_time: q.time, a_time: r.time, a_by: r.dir === 'out' ? (r.via || 'phone') : 'team ' + norm(r.from), group: true });
        }
      }
    }
    const since = String(req.query.since || '');
    res.json({ ok: true, pairs: (since ? pairs.filter((p) => String(p.a_time) > since) : pairs).slice(-20000) });
  } catch (error) { res.status(500).json({ ok: false, error: error.message }); }
});

app.get('/archive/person', requireToken, (req, res) => {
  try {
    const ids = new Set(String(req.query.ids || '').split(',').map((x) => x.replace(/\D/g, '')).filter((x) => x.length >= 8));
    for (const [lid, pn] of lidPhone) if (pn && ids.has(pn)) ids.add(lid);
    const norm = (x) => { const v = String(x || '').replace(/\D/g, ''); return lidPhone.get(v) || v; };
    const isMe = (x) => { const v = String(x || '').replace(/\D/g, ''); return v && (ids.has(v) || ids.has(norm(v))); };
    const rows = readArchive({ limit: 300000, months: 24 });
    const own = new Set(), out = [];
    for (const r of rows) if (r.group && r.dir === 'in' && (isMe(r.from) || isMe(r.from_lid))) own.add(r.id);
    const privateJids = new Set([...ids].map((x) => `${x}@s.whatsapp.net`).concat([...ids].map((x) => `${x}@lid`)));
    for (const r of rows) {
      let why = '';
      if (privateJids.has(r.jid) || (!r.group && ids.has(String(r.phone || '')))) why = 'private';
      else if (r.group && r.dir === 'in' && (isMe(r.from) || isMe(r.from_lid))) why = 'own';
      else if (r.group && r.quote && own.has(r.quote)) why = 'reply';
      else if (r.group && r.quote_from && isMe(r.quote_from)) why = 'reply';
      else if (r.group && (r.mentions || []).some(isMe)) why = 'mention';
      if (!why || !['in', 'out'].includes(r.dir)) continue;
      out.push({ id: r.id, jid: r.jid, chat: names[r.jid] || '', dir: r.dir, time: r.time, kind: r.kind, text: r.text, attachment: r.attachment || '', file_name: r.file_name, fetchable: Boolean(!r.attachment && r.mref),
        from: r.from ? norm(r.from) : undefined, from_name: r.from ? (names[`${norm(r.from)}@s.whatsapp.net`] || names[`${r.from}@lid`] || '') : undefined, by: r.by, quote: r.quote, why, src: r.group ? 'group' : 'private' });
    }
    out.sort((a, b) => String(a.time).localeCompare(String(b.time)));
    res.json({ ok: true, ids: [...ids], rows: out.slice(-20000) });
  } catch (error) { res.status(500).json({ ok: false, error: error.message }); }
});

app.get('/archive/thread', requireToken, async (req, res) => {
  try {
    const rows = readArchive({ jid: String(req.query.jid || ''), limit: Math.min(60000, Number(req.query.limit) || 5000), months: 24 }).reverse();
    if (String(req.query.jid || '').endsWith('@g.us')) {
      const ids = []; for (const r of rows) { if (r.from) ids.push(String(r.from)); if (r.quote_from) ids.push(String(r.quote_from)); for (const m of r.mentions || []) ids.push(String(m)); }
      const froms = [...new Set(ids.filter((x) => x.length >= 14))];
      // Never make the request wait: unknown IDs are resolved in the background for next time.
      const unknown = froms.filter((f) => !lidPhone.has(f));
      if (socket && unknown.length && !lidBusy) { lidBusy = true; resolveLidPhones(unknown.slice(0, 500)).finally(() => { lidBusy = false; }); }
      for (const r of rows) {
        if (r.from && lidPhone.get(String(r.from))) { r.from_lid = r.from; r.from = lidPhone.get(String(r.from)); }
        if (r.quote_from && lidPhone.get(String(r.quote_from))) { r.quote_from_lid = r.quote_from; r.quote_from = lidPhone.get(String(r.quote_from)); }
        if (r.mentions) r.mentions = r.mentions.map((m) => lidPhone.get(String(m)) || m);
      }
    }
    for (const r of rows) {
      if (r.group && r.from && !r.from_name) r.from_name = names[`${r.from}@s.whatsapp.net`] || names[`${r.from_lid || ''}@lid`] || names[`${r.from}@lid`] || '';
      if (r.dir === 'out' && r.id && msgStatus.has(r.id)) r.status = msgStatus.get(r.id);
    }
    res.json({ ok: true, rows, name: names[String(req.query.jid || '')] || '' });
  }
  catch (error) { res.status(500).json({ ok: false, error: error.message }); }
});

/* ---------- Chat mode (reply from WhatsApp Inbox) ----------
 * Safety rules so the number behaves like a person on WhatsApp Web, not a bulk sender:
 * only existing conversations, "typing…" before each message, ≥3s between messages, ≤20 per minute. */
const chatSends = [];
let chatLastAt = 0;
app.post('/chat/send', requireToken, async (req, res) => {
  if (!connected || !socket) { res.status(503).json({ ok: false, error: 'WhatsApp is not connected.' }); return; }
  const jid = String(req.body.jid || '');
  const text = String(req.body.text || '').trim();
  // Optional photo / audio / file uploaded through the Inbox (kept under storage/inbox_uploads).
  const attachment = req.body.attachment ? safeAttachment(String(req.body.attachment)) : null;
  // files sent from the dashboard, the payment bot, or this number's own media library (never another number's folder)
  const ownMedia = new RegExp(`^inbox_media/${String(accountId).replace(/[^A-Za-z0-9_-]/g, '')}/(?:[0-9-]{3,40}/)?[A-Za-z0-9_.-]+$`);
  const attRel = attachment ? path.relative(storageRoot, attachment).replace(/\\/g, '/') : ''; // checked after resolving, so "inbox_uploads/../" cannot reach another folder
  if (req.body.attachment && (!attachment || String(req.body.attachment).includes('..') || !(/^(inbox_uploads|payment_bot)\/[^/]+$/.test(attRel) || ownMedia.test(attRel)))) { res.status(422).json({ ok: false, error: 'File not found.' }); return; }
  if (attachment && fs.statSync(attachment).size > 70 * 1048576) { res.status(422).json({ ok: false, error: 'File is larger than 70 MB.' }); return; }
  if (!/^[0-9A-Za-z._:-]+@(s\.whatsapp\.net|lid|g\.us)$/.test(jid) || (!text && !attachment)) { res.status(422).json({ ok: false, error: 'Conversation and message are required.' }); return; }
  if (text.length > 4000) { res.status(422).json({ ok: false, error: 'Message is too long.' }); return; }
  const known = readArchive({ jid, limit: 1, months: 24 }).length > 0;
  if (!known) { res.status(403).json({ ok: false, error: 'Replies are allowed only in existing conversations.' }); return; }
  const now = Date.now();
  while (chatSends.length && now - chatSends[0] > 60000) chatSends.shift();
  if (chatSends.length >= 20) { res.status(429).json({ ok: false, error: 'Slow down: max 20 messages per minute.' }); return; }
  const wait = Math.max(0, 3000 - (now - chatLastAt));
  chatLastAt = now + wait; chatSends.push(now);
  try {
    if (wait) await new Promise((r) => setTimeout(r, wait));
    const ext = attachment ? path.extname(attachment).toLowerCase() : '';
    let kind = !attachment ? 'text' : (['.jpg', '.jpeg', '.png', '.webp'].includes(ext) ? 'image' : (['.ogg', '.opus', '.mp3', '.m4a', '.aac', '.wav'].includes(ext) ? 'audio' : (['.mp4', '.3gp', '.mov'].includes(ext) ? 'video' : 'document')));
    // Photos over 16 MB go as a file. Audio over 16 MB is tried as audio first and falls back to a file if WhatsApp refuses it.
    const big = attachment ? fs.statSync(attachment).size > 16 * 1048576 : false;
    if (big && (kind === 'image' || kind === 'video')) kind = 'document';
    const docMimes = { '.jpg': 'image/jpeg', '.jpeg': 'image/jpeg', '.png': 'image/png', '.webp': 'image/webp' };
    try { await socket.presenceSubscribe(jid); await socket.sendPresenceUpdate(kind === 'audio' ? 'recording' : 'composing', jid); } catch { /* presence is best effort */ }
    // Typing indicator before sending (shorter for bot replies, which should feel instant).
    await new Promise((r) => setTimeout(r, req.body.via === 'bot' ? (Number(req.body.typing_ms) > 0 ? Math.max(500, Math.min(15000, Number(req.body.typing_ms))) : Math.min(2000, 500 + text.length * 8)) : Math.min(4500, (attachment ? 1800 : 800) + text.length * 35)));
    const mimes = { '.ogg': 'audio/ogg; codecs=opus', '.opus': 'audio/ogg; codecs=opus', '.mp3': 'audio/mpeg', '.m4a': 'audio/mp4', '.aac': 'audio/aac', '.wav': 'audio/wav', '.pdf': 'application/pdf' };
    let content = { text };
    // @mentions (e.g. welcoming new group members): JIDs that are tagged in the text.
    const mentions = Array.isArray(req.body.mentions) ? req.body.mentions.map(String).filter((j) => /^[0-9]+@(s\.whatsapp\.net|lid)$/.test(j)).slice(0, 50) : [];
    if (mentions.length && !attachment) content.mentions = mentions;
    // Reply to a specific message (quoted).
    let opts = {};
    if (req.body.quote_id) {
      const q = readArchive({ jid, limit: 5000, months: 24 }).find((r) => r.id === String(req.body.quote_id));
      if (q) opts = { quoted: { key: { remoteJid: jid, id: q.id, fromMe: q.dir === 'out', ...(jid.endsWith('@g.us') && q.from ? { participant: `${q.from}@s.whatsapp.net` } : {}) }, message: { conversation: String(q.text || '').slice(0, 500) || '…' } } };
    }
    const as = String(req.body.as || ''); // the library knows what a file really is: a sticker or a GIF
    if (as === 'sticker' && ext === '.webp') { kind = 'sticker'; }
    if (kind === 'sticker') content = { sticker: fs.readFileSync(attachment) }; // stickers carry no caption
    else if (kind === 'image') content = { image: fs.readFileSync(attachment), ...(text ? { caption: text } : {}) };
    else if (kind === 'video') content = { video: fs.readFileSync(attachment), ...(as === 'gif' ? { gifPlayback: true } : {}), ...(text ? { caption: text } : {}) };
    else if (kind === 'audio') content = { audio: fs.readFileSync(attachment), mimetype: mimes[ext] || 'audio/mpeg', ptt: ext === '.ogg' || ext === '.opus' };
    else if (kind === 'document') content = { document: fs.readFileSync(attachment), fileName: String(req.body.file_name || path.basename(attachment)).slice(0, 120), mimetype: mimes[ext] || docMimes[ext] || 'application/octet-stream', ...(text ? { caption: text } : {}) };
    let sent;
    try {
      sent = await withTimeout(socket.sendMessage(jid, content, opts), 180000, 'WhatsApp send timed out');
    } catch (err) {
      healthEvent('send_failed', err.message);
      if (!(big && kind === 'audio')) throw err;
      console.log(`Large audio refused as audio (${err.message}); sending it as a file`);
      kind = 'document';
      content = { document: fs.readFileSync(attachment), fileName: String(req.body.file_name || path.basename(attachment)).slice(0, 120), mimetype: mimes[ext] || 'audio/mpeg' };
      sent = await withTimeout(socket.sendMessage(jid, content), 180000, 'WhatsApp send timed out');
    }
    if (kind === 'audio' && text) { await new Promise((r) => setTimeout(r, 1500)); await socket.sendMessage(jid, { text }); } // audio has no caption
    try { await socket.sendPresenceUpdate('paused', jid); await socket.sendPresenceUpdate('unavailable'); } catch { /* ignore */ }
    let pj = jid.endsWith('@s.whatsapp.net') ? jid : (jid.endsWith('@lid') ? await phoneJidForLid(socket, jid) : '');
    if (sent?.key?.id) { botSentIds.add(sent.key.id); if (botSentIds.size > 2000) botSentIds.clear(); } // never read back as a team member's answer
    archive({ dir: 'out', id: sent?.key?.id || '', jid, phone: pj ? pj.split('@')[0] : '', kind, text, attachment: attachment ? path.relative(storageRoot, attachment) : '', file_name: req.body.file_name || undefined, via: String(req.body.via || 'inbox').slice(0, 20), by: String(req.body.by || '').slice(0, 120), quote: req.body.quote_id ? String(req.body.quote_id) : undefined });
    res.json({ ok: true, id: sent?.key?.id || '' });
  } catch (error) { res.status(502).json({ ok: false, error: error.message }); }
});

/** Emoji reaction on a message ('' removes it). */
app.post('/chat/react', requireToken, async (req, res) => {
  if (!connected || !socket) { res.status(503).json({ ok: false, error: 'WhatsApp is not connected.' }); return; }
  try {
    const jid = String(req.body.jid || ''), id = String(req.body.id || ''), emoji = String(req.body.emoji || '').slice(0, 8);
    const q = readArchive({ jid, limit: 5000, months: 24 }).find((r) => r.id === id);
    if (!q) { res.status(404).json({ ok: false, error: 'Message not found.' }); return; }
    await socket.sendMessage(jid, { react: { text: emoji, key: { remoteJid: jid, id, fromMe: q.dir === 'out', ...(jid.endsWith('@g.us') && q.from ? { participant: `${q.from}@s.whatsapp.net` } : {}) } } });
    archive({ dir: 'reaction', id, jid, emoji, by: String(req.body.by || '').slice(0, 120) });
    res.json({ ok: true });
  } catch (error) { res.status(502).json({ ok: false, error: error.message }); }
});

/** A message's WhatsApp key from its archive row. */
/** Where a chat's files are kept: storage/inbox_media/<this number>/<the chat's number or group id>/ (files from before stay where they are). */
function chatMediaDir(jid) {
  const id = String(jid || '').split('@')[0].split(':')[0].replace(/[^0-9-]/g, '').slice(0, 40);
  return path.join(storageRoot, 'inbox_media', accountId, id.length >= 3 ? id : '000');
}
function rowKey(jid, q) {
  const key = { remoteJid: jid, id: q.id, fromMe: q.dir === 'out' };
  if (jid.endsWith('@g.us') && q.dir !== 'out' && q.from) { const p = String(q.from); key.participant = p.includes('@') ? p : (p.length >= 14 ? `${p}@lid` : `${p}@s.whatsapp.net`); }
  return key;
}
/** Delete one message: for everyone when this number sent it and WhatsApp still allows it (about two days), otherwise from this
 *  linked device only. The dashboard hides it either way. */
app.post('/chat/delete-msg', requireToken, async (req, res) => {
  try {
    const jid = String(req.body.jid || ''), id = String(req.body.id || '');
    const q = readArchive({ jid, limit: 60000, months: 24 }).find((r) => r.id === id);
    if (!q) { res.status(404).json({ ok: false, error: 'Message not found.' }); return; }
    let everyone = false, phone = false;
    if (connected && socket) {
      const key = rowKey(jid, q), ageH = (Date.now() - Date.parse(q.time)) / 3600000;
      try {
        if ((q.dir === 'out' || (jid.endsWith('@g.us') && req.body.admin === true)) && ageH < 48 && req.body.everyone !== false) { await socket.sendMessage(jid, { delete: key }); everyone = true; }
        else { await socket.chatModify({ deleteForMe: { deleteMedia: true, key, timestamp: Math.floor(Date.parse(q.time) / 1000) } }, jid); }
        phone = true;
      } catch { /* WhatsApp refused (too old, not ours): the dashboard still hides it */ }
    }
    archive({ dir: 'hidden', id, jid, by: String(req.body.by || '').slice(0, 120), everyone });
    pushEvent('hidden', { jid, id });
    res.json({ ok: true, everyone, phone });
  } catch (error) { res.status(502).json({ ok: false, error: error.message }); }
});
/** Delete a whole chat: from WhatsApp on this number (all linked devices) and from the dashboard (older messages hidden; new ones show again). */
app.post('/chat/delete', requireToken, async (req, res) => {
  try {
    const jid = String(req.body.jid || ''); if (!jid) { res.status(422).json({ ok: false, error: 'Choose a conversation.' }); return; }
    const last = readArchive({ jid, limit: 1, months: 24 })[0];
    let phone = false;
    if (connected && socket && last && last.id) {
      try { await socket.chatModify({ delete: true, lastMessages: [{ key: rowKey(jid, last), messageTimestamp: Math.floor(Date.parse(last.time) / 1000) }] }, jid); phone = true; } catch { /* kept on the phone; hidden here */ }
    }
    archive({ dir: 'chat_cleared', jid, by: String(req.body.by || '').slice(0, 120) });
    pushEvent('hidden', { jid, chat: true });
    res.json({ ok: true, phone });
  } catch (error) { res.status(502).json({ ok: false, error: error.message }); }
});

/** Library files and forwards to several chats and groups: queued here and sent one by one, paced like a person.
 *  The dashboard gets its answer at once, so the user's own connection (or closing the page) changes nothing. */
const sendQueue = []; let sendBusy = false;
function selfSend(body) {
  return new Promise((resolve) => {
    const b = JSON.stringify(body);
    const r = http.request({ host: '127.0.0.1', port, path: '/chat/send', method: 'POST', timeout: 300000, headers: { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(b), Authorization: `Bearer ${gatewayToken}` } }, (res) => {
      let d = ''; res.on('data', (c) => { d += c; }); res.on('end', () => { try { resolve(JSON.parse(d)); } catch { resolve({ ok: false, error: `HTTP ${res.statusCode}` }); } });
    });
    r.on('error', (e) => resolve({ ok: false, error: e.message })); r.on('timeout', () => r.destroy(new Error('timed out'))); r.end(b);
  });
}
async function runSendQueue() {
  if (sendBusy) return; sendBusy = true;
  try {
    while (sendQueue.length) {
      const it = sendQueue.shift(); let r = await selfSend(it);
      if (!r.ok && /Slow down|429/.test(String(r.error || ''))) { await new Promise((z) => setTimeout(z, 20000)); r = await selfSend(it); } // the per-minute limit: wait and try once more
      if (!r.ok) console.log(`queued send failed (${String(it.jid).slice(-8)}): ${String(r.error || '').slice(0, 120)}`);
      if (sendQueue.length) await new Promise((z) => setTimeout(z, 3000 + Math.floor(Math.random() * 3000)));
    }
  } finally { sendBusy = false; }
}
app.post('/chat/send-batch', requireToken, (req, res) => {
  const items = (Array.isArray(req.body.items) ? req.body.items : []).slice(0, 30).filter((x) => x && x.jid && (x.text || x.attachment));
  if (!items.length) { res.status(422).json({ ok: false, error: 'Nothing to send.' }); return; }
  if (sendQueue.length > 300) { res.status(429).json({ ok: false, error: 'Too many messages waiting, try again in a few minutes.' }); return; }
  for (const x of items) sendQueue.push({ jid: String(x.jid), text: String(x.text || ''), attachment: x.attachment ? String(x.attachment) : undefined, as: x.as ? String(x.as) : undefined, by: String(x.by || '').slice(0, 120), via: x.via ? String(x.via) : undefined });
  runSendQueue();
  res.json({ ok: true, queued: items.length, waiting: sendQueue.length });
});

/** Recycle bin: chats and messages deleted from the dashboard. Their rows stay in the archive, so they can be put back. */
app.get('/trash', requireToken, (req, res) => {
  try {
    const files = fs.existsSync(archiveDir) ? fs.readdirSync(archiveDir).filter((f) => /^\d{4}-\d{2}\.jsonl$/.test(f)).sort().slice(-36) : [];
    const msgs = new Map(), chats = new Map(), rows = [];
    for (const f of files) {
      for (const line of fs.readFileSync(path.join(archiveDir, f), 'utf8').split('\n')) {
        if (!line) continue;
        let r; try { r = JSON.parse(line); } catch { continue; }
        if (r.dir === 'hidden') msgs.set(r.id, { jid: r.jid, id: r.id, time: r.time, by: r.by || '' });
        else if (r.dir === 'unhidden') msgs.delete(r.id);
        else if (r.dir === 'chat_cleared') chats.set(r.jid, { jid: r.jid, time: r.time, by: r.by || '', count: 0, last: null });
        else if (r.dir === 'chat_restored') chats.delete(r.jid);
        else if (r.dir === 'in' || r.dir === 'out') rows.push(r);
      }
    }
    const brief = (r) => ({ dir: r.dir, kind: r.kind || 'text', text: String(r.text || '').slice(0, 160), time: r.time, from: r.from || '', name: r.name || '' });
    for (const r of rows) {
      const c = r.jid && chats.get(r.jid);
      if (c && String(r.got || r.time) <= c.time) { c.count++; c.last = brief(r); if (!c.name && r.name && r.dir === 'in') c.name = r.name; }
      const m = r.id && msgs.get(r.id);
      if (m) Object.assign(m, { msg: brief(r) });
    }
    const desc = (a, b) => String(b.time).localeCompare(String(a.time));
    res.json({ ok: true, chats: [...chats.values()].filter((c) => c.count).sort(desc).slice(0, 300), messages: [...msgs.values()].filter((m) => m.msg).sort(desc).slice(0, 300) });
  } catch (error) { res.status(500).json({ ok: false, error: error.message }); }
});
/** Puts a deleted chat (jid) or message (jid + id) back in the dashboard. WhatsApp itself cannot undo a delete on the phone. */
app.post('/trash/restore', requireToken, (req, res) => {
  const jid = String(req.body.jid || ''), id = String(req.body.id || '');
  if (!jid) { res.status(422).json({ ok: false, error: 'Choose a conversation.' }); return; }
  archive(id ? { dir: 'unhidden', id, jid, by: String(req.body.by || '').slice(0, 120) } : { dir: 'chat_restored', jid, by: String(req.body.by || '').slice(0, 120) });
  pushEvent('restored', { jid, id });
  res.json({ ok: true });
});

/** Adds a person to a group this number administers (no need to save their number on the phone).
 *  WhatsApp answers per person: added, already in, or refused by their privacy setting (then an invite link is the way). */
app.post('/groups/add', requireToken, async (req, res) => {
  if (!connected || !socket) { res.status(503).json({ ok: false, error: 'WhatsApp is not connected.' }); return; }
  try {
    const g = String(req.body.group || ''), who = String(req.body.jid || '');
    if (!g.endsWith('@g.us') || !who) { res.status(422).json({ ok: false, error: 'Choose a group and a person.' }); return; }
    const r = await withTimeout(socket.groupParticipantsUpdate(g, [who], 'add'), 20000, 'timed out');
    const st = String(r?.[0]?.status || '');
    groupsCache = null;
    // Privacy refused the add: WhatsApp gives a personal invite (works even for someone removed earlier, unlike the public link).
    const ar = (Array.isArray(r?.[0]?.content?.content) ? r[0].content.content : []).find((n) => n?.tag === 'add_request');
    if (st === '403' && ar?.attrs?.code && req.body.private_invite) {
      let subject = ''; try { subject = (await withTimeout(socket.groupMetadata(g), 10000, 't'))?.subject || ''; } catch { /* name is optional */ }
      await socket.sendMessage(who, { groupInvite: { jid: g, inviteCode: ar.attrs.code, inviteExpiration: Number(ar.attrs.expiration || 0), text: String(req.body.text || '').slice(0, 500), subject } });
      res.json({ ok: true, status: st, result: 'invited' }); return;
    }
    res.json({ ok: st === '200' || st === '409', status: st, can_invite: Boolean(ar?.attrs?.code), result: st === '200' ? 'added' : (st === '409' ? 'already' : (st === '403' ? 'privacy' : (st === '408' ? 'left_recently' : (st === '401' ? 'blocked' : 'failed')))) });
  } catch (error) { res.status(502).json({ ok: false, error: error.message }); }
});
/** Removes a person from a group this number administers (reception groups: a new member who never answered). */
app.post('/groups/remove', requireToken, async (req, res) => {
  if (!connected || !socket) { res.status(503).json({ ok: false, error: 'WhatsApp is not connected.' }); return; }
  try {
    const g = String(req.body.group || ''), who = String(req.body.jid || '');
    if (!g.endsWith('@g.us') || !who) { res.status(422).json({ ok: false, error: 'Choose a group and a person.' }); return; }
    const r = await withTimeout(socket.groupParticipantsUpdate(g, [who], 'remove'), 20000, 'timed out');
    groupsCache = null; const st = String(r?.[0]?.status || '');
    res.json({ ok: st === '200' || st === '404', status: st, result: st === '200' ? 'removed' : (st === '404' ? 'not in group' : 'refused') });
  } catch (error) { res.status(502).json({ ok: false, error: error.message }); }
});
/** Invite link of a group this number administers. */
app.get('/groups/invite', requireToken, async (req, res) => {
  if (!connected || !socket) { res.status(503).json({ ok: false, error: 'WhatsApp is not connected.' }); return; }
  try { const code = await withTimeout(socket.groupInviteCode(String(req.query.jid || '')), 15000, 'timed out'); res.json({ ok: Boolean(code), link: code ? `https://chat.whatsapp.com/${code}` : '' }); }
  catch (error) { res.status(502).json({ ok: false, error: error.message }); }
});

/** The real kind of every stored media file (sticker, image, gif, video, voice, audio, document) — for the media library. Kept until something new is archived. */
let mediaKindsCache = null;
app.get('/archive/media-kinds', requireToken, (req, res) => {
  try {
    if (!mediaKindsCache || mediaKindsCache.v !== archiveVersion) { const kinds = {}, names = {}, chats = {}, senders = {};
      for (const r of readArchive({ limit: 300000, months: 60 })) if (r.attachment) {
        if (r.kind && !kinds[r.attachment]) kinds[r.attachment] = r.kind;
        if (!names[r.attachment] && r.file_name) names[r.attachment] = String(r.file_name).slice(0, 120); // the name the file was sent with
        if (!chats[r.attachment] && r.jid) chats[r.attachment] = r.jid; // the chat or group it came from (media library filter)
        if (!senders[r.attachment]) senders[r.attachment] = Object.assign(r.dir === 'out' ? { o: 1 } : { n: String(r.from_name || r.name || '').slice(0, 60), p: String(r.from || r.phone || '').replace(/\D/g, '').slice(0, 20) }, { m: String(r.id || '').slice(0, 80) }); } // who sent it (the owner of the media)
      mediaKindsCache = { v: archiveVersion, kinds, names, chats, senders }; }
    res.json({ ok: true, kinds: mediaKindsCache.kinds, names: mediaKindsCache.names, chats: mediaKindsCache.chats, senders: mediaKindsCache.senders });
  } catch (error) { res.status(500).json({ ok: false, error: error.message }); }
});

/** Groups this number is in, with member counts (cached 10 minutes). */
let groupsCache = null, groupsAt = 0;
app.get('/groups', requireToken, async (req, res) => {
  if (!connected || !socket) { res.status(503).json({ ok: false, error: 'WhatsApp is not connected.' }); return; }
  try {
    if (!groupsCache || Date.now() - groupsAt > 600000 || req.query.refresh === '1') { groupsCache = await withTimeout(socket.groupFetchAllParticipating(), 30000, 'timed out'); groupsAt = Date.now(); }
    // This number in a group: by its phone number or by its hidden-number id (LID), whichever the group uses.
    const bare = (x) => String(x || '').split('@')[0].split(':')[0];
    const mine = new Set([bare(account?.id), bare(socket.user?.id), bare(socket.user?.lid), bare(account?.lid)].filter(Boolean));
    const list = Object.values(groupsCache).map((g) => {
      const meP = (g.participants || []).find((p) => [p.id, p.lid, p.phoneNumber, p.jid].some((x) => x && mine.has(bare(x))));
      if (g.subject) setName(g.id, g.subject);
      return { jid: g.id, name: g.subject || '', members: (g.participants || []).length, admins: (g.participants || []).filter((p) => p.admin).length, i_am_admin: Boolean(meP?.admin), created: g.creation ? new Date(g.creation * 1000).toISOString() : null, desc: String(g.desc || '').slice(0, 500), announce: Boolean(g.announce), restrict: Boolean(g.restrict) };
    });
    res.json({ ok: true, groups: list.sort((a, b) => b.members - a.members) });
  } catch (error) { res.status(502).json({ ok: false, error: error.message }); }
});
app.get('/groups/events', requireToken, (req, res) => {
  const jid = String(req.query.jid || '');
  const ev = readArchive({ jid, limit: 300000, months: 12, events: true }).filter((r) => r.dir === 'group_event');
  res.json({ ok: true, events: ev.slice(0, 2000) });
});
app.get('/groups/members', requireToken, async (req, res) => {
  if (!connected || !socket) { res.status(503).json({ ok: false, error: 'WhatsApp is not connected.' }); return; }
  try {
    const jid = String(req.query.jid || '');
    const g = await withTimeout(socket.groupMetadata(jid), 20000, 'timed out');
    const members = [];
    for (const p of g.participants || []) {
      let ph = String(p.phoneNumber || '').split('@')[0].split(':')[0];
      const lid = String(p.id || '').endsWith('@lid') ? String(p.id).split('@')[0].split(':')[0] : '';
      if (!ph && lid) ph = lidPhone.get(lid) || String((await phoneJidForLid(socket, p.id).catch(() => '')) || '').split('@')[0];
      if (!ph && !lid) ph = String(p.id || '').split('@')[0].split(':')[0];
      if (lid && ph) lidPhone.set(lid, ph);
      members.push({ phone: ph || lid, is_lid: !ph, lid, jid: p.id, admin: p.admin || '', name: names[`${ph}@s.whatsapp.net`] || names[p.id] || '' });
    }
    res.json({ ok: true, jid, name: g.subject, members });
  } catch (error) { res.status(502).json({ ok: false, error: error.message }); }
});

/** Posts a WhatsApp Status (text, photo or video) to the chosen contacts (max 500, paced). */
let statusLast = 0;
app.post('/status/post', requireToken, async (req, res) => {
  if (!connected || !socket) { res.status(503).json({ ok: false, error: 'WhatsApp is not connected.' }); return; }
  if (Date.now() - statusLast < 60000) { res.status(429).json({ ok: false, error: 'Wait a minute between status posts.' }); return; }
  try {
    const text = String(req.body.text || '').slice(0, 700);
    const attachment = req.body.attachment ? safeAttachment(String(req.body.attachment)) : null;
    const audience = (Array.isArray(req.body.audience) ? req.body.audience : []).map((x) => String(x).replace(/\D/g, '')).filter((x) => x.length >= 8).slice(0, 500).map((x) => `${x}@s.whatsapp.net`);
    if (!audience.length) { res.status(422).json({ ok: false, error: 'No contacts to show the status to.' }); return; }
    if (!text && !attachment) { res.status(422).json({ ok: false, error: 'Write a text or choose a photo/video.' }); return; }
    const ext = attachment ? path.extname(attachment).toLowerCase() : '';
    let content = { text, backgroundColor: String(req.body.color || '#0f766e'), font: 1 };
    if (attachment && ['.jpg', '.jpeg', '.png', '.webp'].includes(ext)) content = { image: fs.readFileSync(attachment), caption: text || undefined };
    else if (attachment && ['.mp4', '.mov', '.3gp'].includes(ext)) content = { video: fs.readFileSync(attachment), caption: text || undefined };
    statusLast = Date.now();
    const sent = await withTimeout(socket.sendMessage('status@broadcast', content, { statusJidList: audience, broadcast: true }), 180000, 'timed out');
    archive({ dir: 'out', id: sent?.key?.id || '', jid: 'status@broadcast', kind: attachment ? 'image' : 'text', text, via: 'status', by: String(req.body.by || '').slice(0, 120), audience: audience.length });
    res.json({ ok: true, id: sent?.key?.id || '', audience: audience.length });
  } catch (error) { healthEvent('status_failed', error.message); res.status(502).json({ ok: false, error: error.message }); }
});

/** Number health: connection, recent disconnects and failures. */
app.get('/health', requireToken, (req, res) => {
  const day = Date.now() - 86400000;
  const recent = healthLog.filter((e) => Date.parse(e.at) > day);
  res.json({ ok: true, connected, account: account?.id || null, uptime_s: Math.round(process.uptime()), disconnected_since: disconnectedSince ? new Date(disconnectedSince).toISOString() : null,
    last_disconnect_code: lastDisconnectCode, last_error: lastError || '', logged_out: loggedOut,
    day: { disconnects: recent.filter((e) => e.type === 'disconnected').length, send_failed: recent.filter((e) => e.type === 'send_failed').length, logged_out: recent.some((e) => e.type === 'logged_out') },
    events: healthLog.slice(-40).reverse() });
});

/** Marks a conversation as read on WhatsApp when it is opened in the Inbox (blue ticks for the customer). */
app.post('/chat/read', requireToken, async (req, res) => {
  try {
    const jid = String(req.body.jid || ''); const ids = Array.isArray(req.body.ids) ? req.body.ids.slice(-20) : [];
    if (connected && socket && jid && ids.length) await socket.readMessages(ids.map((id) => ({ remoteJid: jid, id, fromMe: false })));
    res.json({ ok: true });
  } catch (error) { res.json({ ok: false, error: error.message }); }
});

/* ---------- Analytics: activity + data extracted from message text ---------- */
app.get('/archive/analyze', requireToken, (req, res) => {
  try {
    const days = Math.max(1, Math.min(365, Number(req.query.days) || 30));
    const since = Date.now() - days * 86400000;
    const rows = readArchive({ limit: 300000, months: Math.ceil(days / 28) + 1 }).filter((r) => Date.parse(r.time) >= since && (r.dir === 'in' || r.dir === 'out')).reverse(); // oldest → newest
    const heat = Array.from({ length: 7 }, () => Array(24).fill(0));
    const perDay = {}, perChat = {}, lastByChat = {}, waitingIn = {};
    const resp = [];
    const ent = { phones: {}, emails: {}, links: {}, amounts: {}, refs: {} };
    const add = (bag, key, r, extra) => { const k = String(key).trim(); if (!k) return; const e = bag[k] || (bag[k] = { value: k, count: 0, chats: {}, last: r.time, ...(extra || {}) }); e.count++; e.chats[r.jid] = 1; e.last = r.time; };
    const words = {};
    const STOP = new Set('the and you for are with this that from have your was not but can will عن في من على الى إلى ان أن هذا هذه انا أنا انت إنت مع لو اللي الي ده دي كده يا و او أو ما لا ايه إيه هو هي تم بس كان عشان علشان ممكن'.split(' '));
    for (const r of rows) {
      const t = new Date(r.time);
      if (!r.group) heat[t.getUTCDay()][(t.getUTCHours() + 3) % 24]++; // Cairo time (UTC+3)
      const d = r.time.slice(0, 10); perDay[d] = perDay[d] || { in: 0, out: 0 }; perDay[d][r.dir]++;
      const c = perChat[r.jid] || (perChat[r.jid] = { jid: r.jid, phone: r.phone || '', name: names[r.jid] || '', group: Boolean(r.group), in: 0, out: 0, last: r.time, lastDir: r.dir });
      c[r.dir]++; c.last = r.time; c.lastDir = r.dir; if (r.phone && !c.phone) c.phone = r.phone;
      if (!r.group) {
        if (r.dir === 'in' && !waitingIn[r.jid]) waitingIn[r.jid] = Date.parse(r.time);
        if (r.dir === 'out' && waitingIn[r.jid]) { const dt = (Date.parse(r.time) - waitingIn[r.jid]) / 60000; if (dt >= 0 && dt < 72 * 60) resp.push(dt); delete waitingIn[r.jid]; }
      }
      const text = String(r.text || '');
      if (!text) continue;
      for (const m of text.replace(/@\d{6,}/g, ' ').matchAll(/(?<![\d@])(?:\+|00)?\d[\d\s-]{8,16}\d(?!\d)/g)) { const n = m[0].replace(/[\s-]/g, '').replace(/^00/, '+'); if (n.replace(/\D/g, '').length >= 10 && n.replace(/\D/g, '').length <= 15) add(ent.phones, n, r); }
      for (const m of text.matchAll(/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/g)) add(ent.emails, m[0].toLowerCase(), r);
      for (const m of text.matchAll(/https?:\/\/[^\s<>"']+/g)) add(ent.links, m[0].replace(/[).,]+$/, ''), r);
      for (const m of text.matchAll(new RegExp(loadRules().money.source, 'gi'))) add(ent.amounts, m[0].replace(/\s+/g, ' '), r, { num: Number(m[1].replace(/[,٬]/g, '')) });
      for (const m of text.matchAll(/\b(?:INV|inv|ORD|ord|REF|ref)[-#\s]?\d{3,}\b|\bرقم\s+(?:العملية|المرجع|الطلب|الفاتورة)\s*[:：]?\s*\d{4,}/g)) add(ent.refs, m[0].toUpperCase().replace(/\s+/g, ' '), r);
      if (r.dir === 'in') for (const w of text.toLowerCase().match(/[\p{L}]{3,}/gu) || []) if (!STOP.has(w)) words[w] = (words[w] || 0) + 1;
    }
    const now = Date.now();
    const waiting = Object.values(perChat).filter((c) => !c.group && c.lastDir === 'in').map((c) => ({ ...c, waiting_minutes: Math.round((now - Date.parse(c.last)) / 60000) }))
      .sort((a, b) => b.waiting_minutes - a.waiting_minutes).slice(0, 50);
    const pack = (bag) => Object.values(bag).map((e) => ({ ...e, chats: Object.keys(e.chats) })).sort((a, b) => b.count - a.count).slice(0, 500);
    resp.sort((a, b) => a - b);
    res.json({ ok: true, days, total: rows.length, received: rows.filter((r) => r.dir === 'in').length, sent: rows.filter((r) => r.dir === 'out').length,
      chats: Object.keys(perChat).length, heat, per_day: Object.entries(perDay).sort().map(([date, v]) => ({ date, ...v })),
      response: { count: resp.length, avg: resp.length ? Math.round(resp.reduce((a, b) => a + b, 0) / resp.length) : null, median: resp.length ? Math.round(resp[Math.floor(resp.length / 2)]) : null,
        within15: resp.length ? Math.round(resp.filter((x) => x <= 15).length / resp.length * 100) : null },
      waiting, top: Object.values(perChat).sort((a, b) => (b.in + b.out) - (a.in + a.out)).slice(0, 15),
      entities: { phones: pack(ent.phones), emails: pack(ent.emails), links: pack(ent.links), amounts: pack(ent.amounts), refs: pack(ent.refs) },
      words: Object.entries(words).sort((a, b) => b[1] - a[1]).slice(0, 40).map(([w, n]) => ({ w, n })) });
  } catch (error) { res.status(500).json({ ok: false, error: error.message }); }
});

/* ---------- Grouping similar texts and ranking the best version ---------- */
function normArabic(t) {
  return String(t || '')
    .replace(/[ً-ٰٟۖ-ۭ]/g, '')           // diacritics / Quran marks
    .replace(/[إأآٱ]/g, 'ا').replace(/ى/g, 'ي').replace(/ة/g, 'ه').replace(/ـ/g, '')
    .replace(/[^\p{L}\p{N}\s]/gu, ' ')                              // emojis, decorations, punctuation
    .replace(/\s+/g, ' ').trim().toLowerCase();
}
function shingles(norm) {
  const w = norm.split(' ').filter(Boolean), set = new Set();
  if (w.length < 3) { if (w.length) set.add(w.join(' ')); return set; }
  for (let i = 0; i + 2 < w.length && i < 600; i++) set.add(w[i] + ' ' + w[i + 1] + ' ' + w[i + 2]);
  return set;
}
/* ---------- Quran (Tanzil Uthmani text, wa-local/data/quran-uthmani.txt): exact verse matching ---------- */
const SURAH_NAMES = ['الفاتحة','البقرة','آل عمران','النساء','المائدة','الأنعام','الأعراف','الأنفال','التوبة','يونس','هود','يوسف','الرعد','إبراهيم','الحجر','النحل','الإسراء','الكهف','مريم','طه','الأنبياء','الحج','المؤمنون','النور','الفرقان','الشعراء','النمل','القصص','العنكبوت','الروم','لقمان','السجدة','الأحزاب','سبأ','فاطر','يس','الصافات','ص','الزمر','غافر','فصلت','الشورى','الزخرف','الدخان','الجاثية','الأحقاف','محمد','الفتح','الحجرات','ق','الذاريات','الطور','النجم','القمر','الرحمن','الواقعة','الحديد','المجادلة','الحشر','الممتحنة','الصف','الجمعة','المنافقون','التغابن','الطلاق','التحريم','الملك','القلم','الحاقة','المعارج','نوح','الجن','المزمل','المدثر','القيامة','الإنسان','المرسلات','النبأ','النازعات','عبس','التكوير','الانفطار','المطففين','الانشقاق','البروج','الطارق','الأعلى','الغاشية','الفجر','البلد','الشمس','الليل','الضحى','الشرح','التين','العلق','القدر','البينة','الزلزلة','العاديات','القارعة','التكاثر','العصر','الهمزة','الفيل','قريش','الماعون','الكوثر','الكافرون','النصر','المسد','الإخلاص','الفلق','الناس'];
function normQ(t) {
  return String(t || '').replace(/[ؐ-ًؚ-ٰٟۖ-ۭـ]/g, '').replace(/[إأآٱ]/g, 'ا').replace(/ى/g, 'ي').replace(/ة/g, 'ه').replace(/ؤ/g, 'و').replace(/ئ/g, 'ي')
    .replace(/[^ء-ي\s]/g, ' ').replace(/\s+/g, ' ').trim();
}
const quran = { ayat: [], grams: new Map() };
try {
  for (const line of fs.readFileSync(path.join(__dirname, 'data', 'quran-uthmani.txt'), 'utf8').split('\n')) {
    const m = /^(\d+)\|(\d+)\|(.+)$/.exec(line); if (!m) continue;
    const n = normQ(m[3]), w = n.split(' ');
    const idx = quran.ayat.push({ s: +m[1], a: +m[2], text: m[3], norm: n, words: w.length }) - 1;
    for (let i = 0; i + 2 < w.length; i++) { const g = w[i] + ' ' + w[i + 1] + ' ' + w[i + 2]; const l = quran.grams.get(g); if (l) { if (l.length < 40) l.push(idx); } else quran.grams.set(g, [idx]); }
  }
  console.log(`Quran index: ${quran.ayat.length} verses`);
} catch (e) { console.log('Quran text not available: ' + e.message); }
const quranCache = new Map();
/** Verses quoted in a text: [{s, a, surah}] (each verse ≥ 3 words, found almost word for word). */
function quranRefs(text) {
  if (!quran.ayat.length) return [];
  const n = normQ(text); if (n.length < 15) return [];
  const key = n.length > 3000 ? n.slice(0, 3000) : n;
  if (quranCache.has(key)) return quranCache.get(key);
  const w = n.split(' '), votes = new Map();
  for (let i = 0; i + 2 < w.length && i < 4000; i++) { const l = quran.grams.get(w[i] + ' ' + w[i + 1] + ' ' + w[i + 2]); if (l && l.length < 40) for (const x of l) votes.set(x, (votes.get(x) || 0) + 1); }
  const refs = [];
  for (const [x, v] of votes) {
    const ay = quran.ayat[x], need = Math.max(1, ay.words - 2);
    if (ay.words >= 3 && v >= Math.max(1, Math.floor(need * 0.8))) refs.push({ s: ay.s, a: ay.a, surah: SURAH_NAMES[ay.s - 1] });
  }
  refs.sort((p, q) => p.s - q.s || p.a - q.a);
  const out = refs.slice(0, 60);
  if (quranCache.size > 5000) quranCache.clear();
  quranCache.set(key, out);
  return out;
}

function overlap(a, b) { // share of the smaller text found in the bigger one (catches shorter / longer copies)
  let n = 0; const [s, l] = a.size <= b.size ? [a, b] : [b, a];
  for (const x of s) if (l.has(x)) n++;
  return s.size ? n / s.size : 0;
}
/* Library topics and sections come from the content rules (rules.json "library"); empty = everything is "general". */
function libRules() {
  const R = loadRules(); if (R.lib) return R.lib;
  let raw = {}; try { raw = JSON.parse(fs.readFileSync(RULES_FILE, 'utf8')).library || {}; } catch { raw = {}; }
  const re = (p) => { try { return p ? new RegExp(p) : null; } catch { return null; } };
  R.lib = { topics: (raw.topics || []).map((t) => [String(t.name), re(t.pattern)]).filter((t) => t[1]), defaultTopic: raw.default_topic || 'General',
    sections: (raw.sections || []).map((t) => [String(t.name), re(t.pattern), t.body !== false]).filter((t) => t[1]),
    tagSection: raw.tag_section && raw.tag_section.tag ? { tag: String(raw.tag_section.tag), name: String(raw.tag_section.name || raw.tag_section.tag), unless: re(raw.tag_section.unless) } : null,
    other: raw.other_section || 'Other', audio: raw.audio_section || 'Audio', voiceTitle: raw.voice_title || 'Voice message', audioTitle: raw.audio_title || 'Audio file', audioTag: raw.audio_tag || '' };
  return R.lib;
}
function topicOf(norm, title) {
  const L = libRules(), t = normArabic(title || '');
  for (const [name, re] of L.topics) if (re.test(t)) return name;            // the heading decides first
  let best = L.defaultTopic, bestN = 0;
  for (const [name, re] of L.topics) { const n = (norm.match(new RegExp(re.source, 'g')) || []).length; if (n > bestN) { bestN = n; best = name; } }
  return best;
}
function titleOf(text) {
  for (const line of String(text).split('\n')) {
    const l = line.replace(/[━─=*_~•●◆◇★☆🌙🔥✨💫🕌🕋🤲📿🌹🌸]+/g, ' ').replace(/\s+/g, ' ').trim();
    if (l.replace(/[^\p{L}]/gu, '').length >= 6) return Array.from(l).slice(0, 120).join('');
  }
  return Array.from(String(text)).slice(0, 80).join('');
}
function groupTexts(items) {
  const now = Date.now();
  const prepared = items.map((e) => { const n = normArabic(e.text); return { ...e, norm: n, sh: shingles(n), len: n.length }; }).filter((e) => e.len >= 12)
    .sort((a, b) => b.len - a.len); // longest first: it becomes the group's anchor
  const groups = [];
  for (const it of prepared) {
    let best = null, bestSim = 0;
    for (const g of groups) {
      if (g.anchor.len > it.len * 6 && it.len < 200) continue;
      const sim = overlap(g.anchor.sh, it.sh);
      if (sim > bestSim) { bestSim = sim; best = g; }
      if (sim >= 0.98) break;
    }
    if (best && bestSim >= 0.6) best.items.push(it); else groups.push({ anchor: it, items: [it] });
  }
  return groups.map((g, gi) => {
    const maxLen = Math.max(...g.items.map((x) => x.len)), maxCount = Math.max(...g.items.map((x) => x.count));
    const scored = g.items.map((x) => {
      const letters = (x.text.match(/[ء-ي]/g) || []).length || 1;
      const marks = (x.text.match(/[ً-ْٰ]/g) || []).length;
      const junk = (x.text.match(/[^\p{L}\p{N}\s.,،:؛!?؟()"'«»\-ً-ْٰ]/gu) || []).length;
      const ageDays = (now - Date.parse(x.last)) / 86400000;
      const parts = {
        complete: x.len / maxLen,
        popular: Math.log(1 + x.count) / Math.log(1 + maxCount || 2),
        tashkeel: Math.min(1, marks / letters / 0.6),
        clean: Math.max(0, 1 - junk / Math.max(30, x.text.length) * 4),
        recent: Math.max(0, 1 - ageDays / 120),
      };
      // Emojis and decorations are kept and never lower the score (user choice); only completeness, spread, diacritics and recency count.
      const score = Math.round(100 * (0.4 * parts.complete + 0.25 * parts.popular + 0.25 * parts.tashkeel + 0.1 * parts.recent));
      return { key: crypto.createHash('sha1').update(x.norm.slice(0, 3000)).digest('hex').slice(0, 16), text: x.text, count: x.count, chats: x.chats, last: x.last, first: x.first, jid: x.jid, name: x.name, tags: x.tags, score, parts };
    }).sort((a, b) => b.score - a.score);
    const top = scored[0];
    const ttl = titleOf(top.text);
    return { id: 'g' + gi, title: ttl, topic: topicOf(g.anchor.norm, ttl), versions: scored.length, total: scored.reduce((a, b) => a + b.count, 0),
      chats: new Set(scored.map((x) => x.jid)).size, tags: [...new Set(scored.flatMap((x) => x.tags || []))], best: top, others: scored.slice(1, 60), refs: quranRefs(top.text) };
  }).sort((a, b) => b.total - a.total);
}

/* Content sections inside the library (what kind of text it is), checked in this order. */
function sectionOf(norm, title, tags) {
  const L = libRules(), t = normArabic(title || ''), ts = L.tagSection, hasTag = ts && (tags || []).includes(ts.tag);
  for (const [name, re] of L.sections) if (re.test(t)) return name;   // the heading first
  for (const [name, re, body] of L.sections) if (body && re.test(norm.slice(0, 400))) return name;
  if (hasTag && !(ts.unless && ts.unless.test(norm))) return ts.name;
  for (const [name, re] of L.sections) if (re.test(norm)) return name;
  return hasTag ? ts.name : L.other;
}
/** Audio files grouped: same name (or caption) and about the same length/size = the same recording. */
function groupAudio(rows) {
  const groups = new Map();
  for (const r of rows) {
    const label = normArabic(String(r.file_name || r.text || '').replace(/\.(mp3|m4a|ogg|opus|wav|aac)$/i, ''));
    const sig = r.sig ? 'f:' + r.sig : (label ? 'n:' + label : 'i:' + r.id);
    const g = groups.get(sig) || { id: 'a' + groups.size, sig: r.sig || '', title: r.file_name || r.text || '', items: [], ruqyah: false };
    if (!g.title && (r.file_name || r.text)) g.title = r.file_name || r.text;
    g.items.push(r); g.ruqyah = g.ruqyah || r.ruqyah;
    groups.set(sig, g);
  }
  return [...groups.values()].map((g) => {
    const items = g.items.sort((a, b) => (b.attachment ? 1 : 0) - (a.attachment ? 1 : 0) || (b.size || 0) - (a.size || 0) || String(b.time).localeCompare(String(a.time)));
    items.forEach((x) => { x.key = 'a:' + x.id; });
    return { id: g.id, sig: g.sig, section: libRules().audio, title: g.title || (items[0].kind === 'voice' ? libRules().voiceTitle : libRules().audioTitle), topic: topicOf(normArabic(`${g.title} ${items.map((x) => x.text).join(' ')}`), g.title),
      versions: items.length, total: items.length, chats: new Set(items.map((x) => x.jid)).size, ruqyah: g.ruqyah, best: items[0], others: items.slice(1, 20), audio: true };
  }).sort((a, b) => b.total - a.total || String(b.best.time).localeCompare(String(a.best.time)));
}

/* ---------- Library: audio files and Quran / ruqyah texts ---------- */
app.get('/library', requireToken, (req, res) => {
  try {
    const type = String(req.query.type || 'audio');
    const q = String(req.query.q || '').toLowerCase();
    const rows = readArchive({ limit: 300000, months: 36 });
    let out;
    if (type === 'sections') {
      // Everything at once: audio groups + text groups, each with its section and topic.
      const audio = rows.filter((r) => (r.dir === 'in' || r.dir === 'out') && (r.kind === 'voice' || r.kind === 'audio' || (r.mref && /^audio\//.test(String(r.mref.mimetype || '')))
          || (r.kind === 'document' && /\.(mp3|m4a|ogg|opus|wav|aac)$/i.test(String(r.attachment || r.file_name || r.mref?.fileName || '')))))
        .map((r) => ({ id: r.id, jid: r.jid, phone: r.phone, name: names[r.jid] || r.name || '', group: Boolean(r.group), from: r.from || '', from_name: r.from ? (names[r.from + '@s.whatsapp.net'] || names[r.from + '@lid'] || '') : '', dir: r.dir, time: r.time, kind: r.kind, text: r.text || '',
          file_name: r.file_name || r.mref?.fileName || '', attachment: r.attachment || '', fetchable: Boolean(!r.attachment && r.mref), seconds: r.mref?.seconds, size: r.mref?.fileLength,
          sig: mediaSig(r.mref), ruqyah: !!libRules().audioTag && tagMatch(libRules().audioTag, `${r.text || ''} ${r.file_name || ''} ${r.mref?.fileName || ''} ${names[r.jid] || ''}`) }));
      const seen = new Map();
      for (const r of rows) {
        if (!(r.dir === 'in' || r.dir === 'out') || !r.text) continue;
        const tags = textTags(r.text);
        if (!tags) continue;
        const key = r.text.replace(/\s+/g, ' ').trim().slice(0, 400);
        const e = seen.get(key) || { text: r.text, tags, count: 0, chats: new Set(), first: r.time, last: r.time, jid: r.jid, name: names[r.jid] || '', group: Boolean(r.group), dir: r.dir, from: r.from || '', from_name: r.from ? (names[r.from + '@s.whatsapp.net'] || names[r.from + '@lid'] || '') : '' };
        e.count++; e.chats.add(r.jid); if (r.time < e.first) e.first = r.time; if (r.time > e.last) e.last = r.time;
        seen.set(key, e);
      }
      const minLen = Math.max(0, Number(req.query.min || 60));
      const texts = [...seen.values()].map((e) => ({ ...e, chats: e.chats.size })).filter((e) => normArabic(e.text).length >= minLen);
      const tg = groupTexts(texts).map((g) => ({ ...g, section: sectionOf(normArabic(g.best.text), g.title, g.tags) }));
      let all = groupAudio(audio).concat(tg);
      if (q) all = all.filter((g) => JSON.stringify(g).toLowerCase().includes(q));
      const sections = {}, topics = {};
      for (const g of all) { sections[g.section] = (sections[g.section] || 0) + 1; const k = g.section + '|' + g.topic; topics[k] = (topics[k] || 0) + 1; }
      res.json({ ok: true, type, grouped: true, groups: all.slice(0, 1500), sections, topics });
      return;
    }
    if (type === 'files') {
      // Documents and photos (PDF, Word, pictures…) — downloaded ones and those that can still be fetched.
      const out2 = rows.filter((r) => (r.dir === 'in' || r.dir === 'out') && (r.kind === 'document' || r.kind === 'image' || r.kind === 'video' || (r.mref && ['document', 'image', 'video'].includes(r.mref.type))) && !/^audio\//.test(String(r.mref?.mimetype || '')))
        .map((r) => ({ id: r.id, jid: r.jid, phone: r.phone, name: names[r.jid] || r.name || '', group: Boolean(r.group), from: r.from || '', from_name: r.from ? (names[r.from + '@s.whatsapp.net'] || names[r.from + '@lid'] || '') : '', group: Boolean(r.group), dir: r.dir, time: r.time, kind: r.kind, text: r.text || '',
          file_name: r.file_name || r.mref?.fileName || '', mimetype: r.mref?.mimetype || '', size: r.mref?.fileLength, attachment: r.attachment || '', fetchable: Boolean(!r.attachment && r.mref), sig: mediaSig(r.mref) }))
        .filter((x) => !q || JSON.stringify(x).toLowerCase().includes(q));
      const bySig = new Map();
      for (const x of out2) { const k = x.sig ? 'f:' + x.sig : 'i:' + x.id; const e = bySig.get(k); if (e) { e.copies++; if (!e.attachment && x.attachment) Object.assign(e, x, { copies: e.copies }); } else bySig.set(k, { ...x, copies: 1 }); }
      res.json({ ok: true, type, items: [...bySig.values()].sort((a, b) => String(b.time).localeCompare(String(a.time))).slice(0, 3000), total: bySig.size });
      return;
    }
    if (type === 'audio') {
      out = rows.filter((r) => (r.dir === 'in' || r.dir === 'out') && (r.kind === 'voice' || r.kind === 'audio' || (r.kind === 'document' && /\.(mp3|m4a|ogg|opus|wav|aac)$/i.test(String(r.attachment || r.file_name || r.mref?.fileName || '')))
        || (r.mref && r.mref.type === 'audio') || (r.mref && /^audio\//.test(String(r.mref.mimetype || '')))))
        .map((r) => ({ id: r.id, jid: r.jid, phone: r.phone, name: names[r.jid] || r.name || '', group: Boolean(r.group), from: r.from || '', from_name: r.from ? (names[r.from + '@s.whatsapp.net'] || names[r.from + '@lid'] || '') : '', group: Boolean(r.group), dir: r.dir, time: r.time, kind: r.kind, text: r.text || '',
          file_name: r.file_name || r.mref?.fileName || '', attachment: r.attachment || '', fetchable: Boolean(!r.attachment && r.mref), seconds: r.mref?.seconds, size: r.mref?.fileLength,
          ruqyah: !!libRules().audioTag && tagMatch(libRules().audioTag, `${r.text || ''} ${r.file_name || ''} ${r.mref?.fileName || ''} ${names[r.jid] || ''}`) }));
    } else {
      const seen = new Map();
      for (const r of rows) {
        if (!(r.dir === 'in' || r.dir === 'out') || !r.text) continue;
        const tags = r.tags || textTags(r.text);
        if (!tags) continue;
        const key = r.text.replace(/\s+/g, ' ').trim().slice(0, 400);
        const e = seen.get(key) || { text: r.text, tags, count: 0, chats: new Set(), first: r.time, last: r.time, jid: r.jid, name: names[r.jid] || '', group: Boolean(r.group), dir: r.dir, from: r.from || '', from_name: r.from ? (names[r.from + '@s.whatsapp.net'] || names[r.from + '@lid'] || '') : '' };
        e.count++; e.chats.add(r.jid); if (r.time < e.first) e.first = r.time; if (r.time > e.last) e.last = r.time;
        seen.set(key, e);
      }
      out = [...seen.values()].map((e) => ({ ...e, chats: e.chats.size })).filter((e) => type === 'all' || e.tags.includes(type)).sort((a, b) => b.count - a.count);
      if (String(req.query.group || '') === '1') {
        const minLen = Math.max(0, Number(req.query.min || 60));
        const shortN = out.filter((e) => normArabic(e.text).length < minLen).length;
        let groups = groupTexts(out.filter((e) => normArabic(e.text).length >= minLen));
        if (q) groups = groups.filter((g) => JSON.stringify(g).toLowerCase().includes(q));
        const topics = {}; for (const g of groups) topics[g.topic] = (topics[g.topic] || 0) + 1;
        res.json({ ok: true, type, grouped: true, groups: groups.slice(0, 600), total_groups: groups.length, total_texts: out.length, hidden_short: shortN, topics });
        return;
      }
    }
    if (q) out = out.filter((x) => JSON.stringify(x).toLowerCase().includes(q));
    res.json({ ok: true, type, rows: out.slice(0, 2000), total: out.length });
  } catch (error) { res.status(500).json({ ok: false, error: error.message }); }
});

/* ---------- Background audio downloads (history syncs can bring thousands of files) ---------- */
const mqFile = path.join(dataDir, 'media-queue.json');
let mediaQueue = { pending: [], done: 0, failed: 0, expired: 0, last_error: '', started_at: null };
try { mediaQueue = { ...mediaQueue, ...JSON.parse(fs.readFileSync(mqFile, 'utf8')) }; } catch { /* empty */ }
let mqDirty = false, mqBusy = false;
function queueMedia(id) { if (id && !mediaQueue.pending.includes(id)) { mediaQueue.pending.push(id); if (!mediaQueue.started_at) mediaQueue.started_at = new Date().toISOString(); mqDirty = true; } }
setInterval(() => { if (mqDirty) { mqDirty = false; try { fs.writeFileSync(mqFile, JSON.stringify(mediaQueue)); } catch { /* ignore */ } } }, 10000);
let mqIndex = null, mqIndexAt = 0, sigFiles = new Map();
function mqRow(id) {
  // Built once, then kept up to date by archive() (re-reading the whole archive froze the gateway).
  if (!mqIndex) {
    mqIndex = new Map(); sigFiles = new Map();
    for (const r of readArchive({ limit: 30000, months: 2 })) if (r.id && r.mref) { mqIndex.set(r.id, r); const sg = mediaSig(r.mref); if (sg && r.attachment && !sigFiles.has(sg)) sigFiles.set(sg, r.attachment); }
    mqIndexAt = Date.now();
  }
  return mqIndex.get(id);
}
// Content fingerprints (sha256 -> file): a file identical to one already downloaded is linked, not kept twice.
const hashFile = path.join(dataDir, 'media-hashes.json');
let hashIndex = {}; try { hashIndex = JSON.parse(fs.readFileSync(hashFile, 'utf8')) || {}; } catch {}
let hashDirty = false;
setInterval(() => { if (hashDirty) { hashDirty = false; fs.writeFile(hashFile, JSON.stringify(hashIndex), () => {}); } }, 10000);
async function downloadRow(row) {
  const m = row.mref;
  // Same size + length + type as a file we already have: link it, no download.
  mqRow(row.id); // makes sure the signature index is built
  const sg0 = m.seconds ? mediaSig(m) : ''; // recordings and videos: same size + length + type = same file
  if (sg0 && sigFiles.has(sg0) && fs.existsSync(path.join(storageRoot, sigFiles.get(sg0)))) {
    const rel0 = sigFiles.get(sg0);
    archive({ dir: 'media', id: row.id, jid: row.jid, attachment: rel0, dup: true });
    return { rel: rel0, size: m.fileLength || 0, dup: true };
  }
  const stream = await downloadContentFromMessage({ mediaKey: Buffer.from(m.mediaKey, 'base64'), directPath: m.directPath, url: m.url }, m.type === 'sticker' ? 'sticker' : m.type);
  const mime = String(m.mimetype || '');
  const docExt = String(m.fileName || '').toLowerCase().match(/\.(pdf|docx?|xlsx?|pptx?|txt|csv|zip|rar|7z|mp4|mov|3gp|mp3|m4a|ogg|opus|wav|jpg|jpeg|png|webp)$/);
  const ext = m.type === 'image' ? 'jpg' : (m.type === 'sticker' ? 'webp' : (m.type === 'video' ? (mime.includes('3gpp') ? '3gp' : 'mp4') : (m.type === 'document' && docExt ? docExt[1].replace('jpeg', 'jpg') : (mime.includes('mpeg') ? 'mp3' : (mime.includes('mp4') ? 'm4a' : (mime.includes('ogg') ? 'ogg' : (mime.includes('pdf') ? 'pdf' : 'bin')))))));
  const dir = chatMediaDir(row.jid); fs.mkdirSync(dir, { recursive: true });
  const file = path.join(dir, `${String(row.id).replace(/[^A-Za-z0-9_-]+/g, '_').slice(0, 60)}.${ext}`);
  // Written to disk piece by piece (big recordings never sit whole in memory).
  const tmp = file + '.part';
  const out = fs.createWriteStream(tmp);
  const hash = crypto.createHash('sha256');
  let size = 0;
  try { for await (const ch of stream) { size += ch.length; hash.update(ch); if (!out.write(ch)) await new Promise((r) => out.once('drain', r)); } }
  finally { await new Promise((r) => out.end(r)); }
  const sha = hash.digest('hex');
  const prev = hashIndex[sha];
  if (prev && fs.existsSync(path.join(storageRoot, prev))) {
    // Identical content already on disk: drop this copy and point to the existing file.
    fs.unlinkSync(tmp);
    archive({ dir: 'media', id: row.id, jid: row.jid, attachment: prev, dup: true, sha });
    if (sg0 && !sigFiles.has(sg0)) sigFiles.set(sg0, prev);
    mediaQueue.dupes = (mediaQueue.dupes || 0) + 1; mqDirty = true;
    return { rel: prev, size, dup: true };
  }
  fs.renameSync(tmp, file);
  const rel = path.relative(storageRoot, file).replace(/\\/g, '/');
  hashIndex[sha] = rel; hashDirty = true;
  if (sg0 && !sigFiles.has(sg0)) sigFiles.set(sg0, rel);
  archive({ dir: 'media', id: row.id, jid: row.jid, attachment: rel, sha });
  return { rel, size };
}
setInterval(async () => {
  if (mqBusy || !connected || !socket || !mediaQueue.pending.length) return;
  mqBusy = true;
  // Duplicates of a recording already downloaded are only counted and linked to that file.
  for (let guard = 0; guard < 200 && mediaQueue.pending.length; guard++) {
    const row0 = mqRow(mediaQueue.pending[0]);
    const sg = row0 ? mediaSig(row0.mref) : '';
    if (!row0 || row0.attachment) { mediaQueue.pending.shift(); mediaQueue.done++; mqDirty = true; continue; }
    if (sg && sigFiles.has(sg)) { mediaQueue.pending.shift(); archive({ dir: 'media', id: row0.id, jid: row0.jid, attachment: sigFiles.get(sg), dup: true }); mediaQueue.dupes = (mediaQueue.dupes || 0) + 1; mqDirty = true; continue; }
    break;
  }
  if (!mediaQueue.pending.length) { mqBusy = false; return; }
  const id = mediaQueue.pending.shift(); mqDirty = true;
  try {
    const row = mqRow(id);
    if (!row || row.attachment) { mediaQueue.done++; }
    else if (!row.manual && Number(row.mref.fileLength || 0) > mediaMaxBytes(row.mref)) { mediaQueue.skipped = (mediaQueue.skipped || 0) + 1; } // larger than the automatic limit: fetched on demand only
    else { await withTimeout(downloadRow(row), 900000, 'download timed out'); mediaQueue.done++; }
  } catch (e) {
    if (/404|410|expired|gone|not found/i.test(e.message)) { mediaQueue.expired++; (mediaQueue.gone ||= []).push(id); if (mediaQueue.gone.length > 50000) mediaQueue.gone.splice(0, 10000); } // WhatsApp no longer has it: not tried again else { mediaQueue.failed++; mediaQueue.last_error = String(e.message).slice(0, 160); }
  }
  mqBusy = false;
}, 2500);
app.get('/media/queue', requireToken, (_req, res) => { res.json({ ok: true, pending: mediaQueue.pending.length, done: mediaQueue.done, dupes: mediaQueue.dupes || 0, failed: mediaQueue.failed, expired: mediaQueue.expired, last_error: mediaQueue.last_error, started_at: mediaQueue.started_at }); });
/** Queue every archived recording (and, with images=1 or by default at startup, every photo/sticker) that has download details but no file yet. */
// At start only the last months are scanned, once a day (reading years of archive at once used so much memory that the host stopped the gateway, again at every start).
function queueMissingMedia(images, months = 60) {
  let n = 0; const gone = new Set(mediaQueue.gone || []);
  for (const r of readArchive({ limit: months < 60 ? 50000 : 300000, months })) {
    if (!r.mref || r.attachment || !r.id || gone.has(r.id)) continue;
    const audio = r.kind === 'voice' || r.kind === 'audio' || r.mref.type === 'audio' || /^audio\//.test(String(r.mref.mimetype || ''));
    if (audio || (images && MEDIA_AUTO.includes(r.kind) && Number(r.mref.fileLength || 0) <= mediaMaxBytes(r.mref))) { queueMedia(r.id); n++; }
  }
  return n;
}
app.post('/media/queue-audio', requireToken, (req, res) => {
  const n = queueMissingMedia(Boolean(req.body?.images));
  res.json({ ok: true, queued: n, pending: mediaQueue.pending.length });
});
// After every start: anything that should have been downloaded but was not (offline, expired link, crash) is queued again.
let missingChecked = false;
setInterval(() => { if (!missingChecked && connected && socket) { missingChecked = true; if (Date.now() - Number(mediaQueue.scanned_at || 0) < 86400000) return; mediaQueue.scanned_at = Date.now(); mqDirty = true; try { const n = queueMissingMedia(true, 2); if (n) console.log(`media: ${n} missing photos/recordings queued`); } catch { /* next start */ } } }, 60000);

/** Downloads one archived media message on demand (works while WhatsApp still keeps the file). */
// One-time pass over files downloaded before fingerprints existed: identical files keep one copy,
// the others are re-linked to it and moved to a backup folder (nothing is deleted).
let dedupeRun = null;
app.post('/media/dedupe', requireToken, (req, res) => {
  if (dedupeRun && dedupeRun.running) { res.json({ ok: true, ...dedupeRun }); return; }
  dedupeRun = { running: true, scanned: 0, duplicates: 0, freed: 0, relinked: 0, started: new Date().toISOString() };
  res.json({ ok: true, ...dedupeRun });
  (async () => {
    try {
      const dir = path.join(storageRoot, 'inbox_media', accountId);
      const backup = path.join(process.env.HOME || '/tmp', 'backups', 'dup-media', accountId);
      const files = fs.existsSync(dir) ? fs.readdirSync(dir).filter((f) => !f.endsWith('.part')) : [];
      const known = new Map(Object.entries(hashIndex).map(([h, r]) => [r, h]));
      const moved = new Map(); // dup rel -> kept rel
      for (const f of files) {
        const rel = path.relative(storageRoot, path.join(dir, f)).replace(/\\/g, '/');
        let sha = known.get(rel);
        if (!sha) {
          const h = crypto.createHash('sha256');
          await new Promise((ok, bad) => fs.createReadStream(path.join(dir, f)).on('data', (c) => h.update(c)).on('end', ok).on('error', bad));
          sha = h.digest('hex');
        }
        dedupeRun.scanned++;
        const keep = hashIndex[sha];
        if (keep && keep !== rel && fs.existsSync(path.join(storageRoot, keep))) {
          fs.mkdirSync(backup, { recursive: true });
          const size = fs.statSync(path.join(dir, f)).size;
          fs.renameSync(path.join(dir, f), path.join(backup, f));
          moved.set(rel, keep); dedupeRun.duplicates++; dedupeRun.freed += size;
        } else if (!keep || !fs.existsSync(path.join(storageRoot, keep))) { hashIndex[sha] = rel; hashDirty = true; }
        if (dedupeRun.scanned % 50 === 0) await new Promise((r) => setImmediate(r));
      }
      if (moved.size) {
        // Point every message that used a moved file to the kept copy.
        for (const r of readArchive({ limit: 300000, months: 60 })) {
          if (r.attachment && moved.has(r.attachment)) { archive({ dir: 'media', id: r.id, jid: r.jid, attachment: moved.get(r.attachment), dup: true }); dedupeRun.relinked++; }
        }
        for (const [sg, rel] of sigFiles) if (moved.has(rel)) sigFiles.set(sg, moved.get(rel));
        if (mqIndex) for (const o of mqIndex.values()) if (o.attachment && moved.has(o.attachment)) o.attachment = moved.get(o.attachment);
      }
      hashDirty = true;
    } catch (e) { dedupeRun.error = String(e.message || e).slice(0, 200); }
    dedupeRun.running = false; dedupeRun.finished = new Date().toISOString();
    console.log('Media dedupe', JSON.stringify(dedupeRun));
  })();
});
app.get('/media/dedupe', requireToken, (req, res) => res.json({ ok: true, ...(dedupeRun || { running: false }) }));

app.post('/media/fetch', requireToken, async (req, res) => {
  try {
    const id = String(req.body.id || '');
    const jid = String(req.body.jid || ''); // with the chat given only that chat is read (reading the whole archive raised the memory)
    const row = readArchive(jid ? { jid, limit: 5000, months: 6 } : { limit: 300000, months: 36 }).find((r) => r.id === id && r.mref);
    if (!row) { res.status(404).json({ ok: false, error: 'Nothing to download for this message.' }); return; }
    if (Number(row.mref.fileLength || 0) > 300 * 1048576) { res.status(413).json({ ok: false, error: 'File larger than 300 MB.' }); return; }
    const d = await withTimeout(downloadRow(row), 900000, 'download timed out');
    res.json({ ok: true, attachment: d.rel, size: d.size, dup: Boolean(d.dup) });
  } catch (error) { res.status(502).json({ ok: false, error: /404|410|expired|gone/i.test(error.message) ? 'WhatsApp no longer keeps this file.' : error.message }); }
});

app.get('/events', requireToken, (req, res) => {
  if (req.query.since === undefined || req.query.since === '') { res.json({ ok: true, seq: liveSeq, events: [] }); return; } // first call: just the position
  const since = Number(req.query.since);
  if (!(since >= 0) || since > liveSeq) { res.json({ ok: true, seq: liveSeq, events: [], reset: true }); return; }
  res.json({ ok: true, seq: liveSeq, events: liveEvents.filter((e) => e.seq > since).slice(-300) });
});

/** Subscribe to someone's presence so "typing…" shows while their chat is open in the Inbox. */
app.post('/presence/subscribe', requireToken, async (req, res) => {
  try { if (connected && socket && req.body.jid) await socket.presenceSubscribe(String(req.body.jid)); res.json({ ok: true }); } catch (e) { res.json({ ok: false }); }
});

/** Everything we know about one chat: number, names, about, photo, business profile, group info, archive counts. */
// Saved contacts and every person we know a name for (phone numbers only).
app.get('/contacts', requireToken, (_req, res) => {
  const out = new Map();
  for (const [jid, name] of Object.entries(names)) {
    if (!name) continue;
    let ph = '';
    if (jid.endsWith('@s.whatsapp.net')) ph = jid.split('@')[0].split(':')[0];
    else if (jid.endsWith('@lid')) ph = String(lidPhone.get(jid.split('@')[0]) || lidPhone.get(jid) || '').split('@')[0];
    if (ph && /^\d{8,15}$/.test(ph) && !out.has(ph)) out.set(ph, { phone: ph, name });
  }
  res.json({ ok: true, contacts: [...out.values()] });
});
app.get('/contact', requireToken, async (req, res) => {
  const jid = String(req.query.jid || '');
  if (!/^[0-9A-Za-z._:-]+@(s\.whatsapp\.net|lid|g\.us)$/.test(jid)) { res.status(422).json({ ok: false, error: 'Invalid chat.' }); return; }
  const out = { ok: true, jid, group: jid.endsWith('@g.us') };
  let pj = jid.endsWith('@s.whatsapp.net') ? jid : (jid.endsWith('@lid') ? await phoneJidForLid(socket, jid) : '');
  out.phone = pj ? pj.split('@')[0] : '';
  out.lid = jid.endsWith('@lid') ? jid.split('@')[0] : '';
  out.name = names[jid] || (pj ? names[pj] : '') || '';
  if (/[·•∙⋅]{2,}/.test(out.name)) out.name = '';
  const rows = readArchive({ jid, limit: 20000, months: 24 });
  out.messages = rows.length; out.received = rows.filter((r) => r.dir === 'in').length; out.sent = rows.filter((r) => r.dir === 'out').length;
  out.first_at = rows.length ? rows[rows.length - 1].time : null; out.last_at = rows.length ? rows[0].time : null;
  out.push_names = [...new Set(rows.filter((r) => r.dir === 'in' && r.name && !/[·•∙⋅]{2,}/.test(r.name)).map((r) => r.name))].slice(0, 5);
  if (connected && socket) {
    const target = out.group ? jid : (pj || jid);
    try { out.photo = await withTimeout(socket.profilePictureUrl(target, 'image'), 8000, 't'); } catch { out.photo = ''; }
    if (out.group) {
      try {
        const m = await withTimeout(socket.groupMetadata(jid), 10000, 't');
        out.name = m.subject || out.name; out.description = m.desc || ''; out.participants = (m.participants || []).length;
        out.created_at = m.creation ? new Date(m.creation * 1000).toISOString() : null;
        out.admins = (m.participants || []).filter((p) => p.admin).length;
      } catch { /* not a member any more */ }
    } else {
      try { const st = await withTimeout(socket.fetchStatus(target), 8000, 't'); const v = Array.isArray(st) ? st[0]?.status : st; out.about = v?.status || ''; out.about_at = v?.setAt ? new Date(v.setAt).toISOString() : null; } catch { out.about = ''; }
      try { const b = await withTimeout(socket.getBusinessProfile(target), 8000, 't'); if (b) out.business = { description: b.description || '', category: b.category || '', email: b.email || '', website: (b.website || [])[0] || '', address: b.address || '' }; } catch { /* personal account */ }
      try { const [r] = await withTimeout(socket.onWhatsApp(target), 8000, 't'); out.on_whatsapp = Boolean(r?.exists); } catch { /* unknown */ }
    }
  }
  res.json(out);
});

/* ---------- Broadcast (safe): one message to many existing chats, sent slowly ----------
 * Rules against bans: only numbers that already chatted with this account, 30–60 s random gap between
 * messages, max 60 per day per account, typing before each, stops after 3 failures in a row.
 * Jobs are kept in DATA_DIR/broadcasts.json so a restart resumes them. */
const bcFile = path.join(dataDir, 'broadcasts.json');
let broadcasts = [];
try { broadcasts = JSON.parse(fs.readFileSync(bcFile, 'utf8')) || []; } catch { broadcasts = []; }
const saveBc = () => { try { fs.writeFileSync(bcFile, JSON.stringify(broadcasts)); } catch (e) { /* ignore */ } };
const BC_DAILY_MAX = 60;
let bcNextAt = 0, bcBusy = false;
function bcSentToday() {
  const day = new Date().toISOString().slice(0, 10);
  let n = 0; for (const b of broadcasts) for (const r of b.recipients) if (r.sent_at && r.sent_at.slice(0, 10) === day) n++;
  return n;
}
setInterval(async () => {
  if (bcBusy || !connected || !socket || Date.now() < bcNextAt) return;
  const job = broadcasts.find((b) => b.status === 'running');
  if (!job) return;
  const r = job.recipients.find((x) => x.status === 'pending');
  if (!r) { job.status = 'done'; job.finished_at = new Date().toISOString(); saveBc(); return; }
  if (bcSentToday() >= BC_DAILY_MAX) { job.note = `Daily limit (${BC_DAILY_MAX}) reached — continues tomorrow`; saveBc(); bcNextAt = Date.now() + 30 * 60000; return; }
  bcBusy = true;
  try {
    const text = String(job.text).replace(/\{name\}/g, r.name || '').replace(/\s+\n/g, '\n').trim();
    try { await socket.presenceSubscribe(r.jid); await socket.sendPresenceUpdate('composing', r.jid); } catch { /* best effort */ }
    await new Promise((res) => setTimeout(res, Math.min(5000, 1200 + text.length * 30)));
    const sent = await withTimeout(socket.sendMessage(r.jid, { text }), 30000, 'send timed out');
    try { await socket.sendPresenceUpdate('paused', r.jid); await socket.sendPresenceUpdate('unavailable'); } catch { /* ignore */ }
    r.status = 'sent'; r.sent_at = new Date().toISOString(); r.id = sent?.key?.id || '';
    job.failures_in_row = 0; job.note = '';
    let pj = r.jid.endsWith('@s.whatsapp.net') ? r.jid : (r.jid.endsWith('@lid') ? await phoneJidForLid(socket, r.jid) : '');
    archive({ dir: 'out', id: r.id, jid: r.jid, phone: pj ? pj.split('@')[0] : '', kind: 'text', text, attachment: '', via: 'broadcast', by: job.by || '' });
  } catch (err) {
    r.status = 'failed'; r.error = String(err.message || err).slice(0, 160);
    job.failures_in_row = (job.failures_in_row || 0) + 1;
    if (job.failures_in_row >= 3) { job.status = 'paused'; job.note = 'Paused after 3 failures in a row'; }
  }
  saveBc();
  bcNextAt = Date.now() + (30 + Math.floor(Math.random() * 31)) * 1000;
  bcBusy = false;
}, 5000);

app.post('/broadcast/start', requireToken, (req, res) => {
  const text = String(req.body.text || '').trim();
  const list = Array.isArray(req.body.recipients) ? req.body.recipients : [];
  if (!text || text.length > 2000) { res.status(422).json({ ok: false, error: 'Message is required (max 2000 characters).' }); return; }
  const known = new Set(readArchive({ limit: 300000, months: 36 }).map((r) => r.jid));
  const seen = new Set(), recipients = [], skipped = [];
  for (const x of list.slice(0, 300)) {
    const jid = String(x.jid || '');
    if (!/^[0-9A-Za-z._:-]+@(s\.whatsapp\.net|lid)$/.test(jid) || seen.has(jid)) continue;
    seen.add(jid);
    if (!known.has(jid)) { skipped.push(jid); continue; } // never message numbers that have not chatted with us
    recipients.push({ jid, name: String(x.name || '').slice(0, 60), status: 'pending' });
  }
  if (!recipients.length) { res.status(422).json({ ok: false, error: 'No eligible recipients (only numbers with an existing chat can receive a broadcast).', skipped: skipped.length }); return; }
  const job = { id: Date.now().toString(36), name: String(req.body.name || 'Broadcast').slice(0, 80), text, by: String(req.body.by || '').slice(0, 120), status: 'running', created_at: new Date().toISOString(), recipients };
  broadcasts.unshift(job); broadcasts = broadcasts.slice(0, 50); saveBc();
  res.json({ ok: true, id: job.id, queued: recipients.length, skipped: skipped.length, estimate_minutes: Math.ceil(recipients.length * 45 / 60) });
});
app.get('/broadcast/status', requireToken, (_req, res) => {
  res.json({ ok: true, sent_today: bcSentToday(), daily_max: BC_DAILY_MAX, jobs: broadcasts.map((b) => ({ id: b.id, name: b.name, text: b.text, status: b.status, note: b.note || '', created_at: b.created_at, by: b.by,
    total: b.recipients.length, sent: b.recipients.filter((r) => r.status === 'sent').length, failed: b.recipients.filter((r) => r.status === 'failed').length,
    pending: b.recipients.filter((r) => r.status === 'pending').length })) });
});
app.post('/broadcast/control', requireToken, (req, res) => {
  const job = broadcasts.find((b) => b.id === String(req.body.id || ''));
  if (!job) { res.status(404).json({ ok: false, error: 'Not found.' }); return; }
  const a = String(req.body.action || '');
  if (a === 'pause' && job.status === 'running') job.status = 'paused';
  else if (a === 'resume' && job.status === 'paused') { job.status = 'running'; job.failures_in_row = 0; job.note = ''; }
  else if (a === 'cancel' && ['running', 'paused'].includes(job.status)) { job.status = 'cancelled'; for (const r of job.recipients) if (r.status === 'pending') r.status = 'cancelled'; }
  saveBc(); res.json({ ok: true, status: job.status });
});

/** Profile photo download (proxied, so the Inbox can save it). */
// Profile photos are cached on disk (3 days; "no photo" for 1 day) and looked up gently, one at a time.
const photoDir = path.join(dataDir, 'photos');
let photoChain = Promise.resolve();
const photoPending = new Set();
app.get('/contact/photo', requireToken, async (req, res) => {
  try {
    const jid = String(req.query.jid || '').replace(/[^0-9A-Za-z@.:_-]/g, '');
    if (!jid) { res.status(404).end(); return; }
    fs.mkdirSync(photoDir, { recursive: true });
    const base = path.join(photoDir, jid.replace(/[^0-9A-Za-z_-]/g, '_'));
    const fresh = (f, ms) => { try { return Date.now() - fs.statSync(f).mtimeMs < ms; } catch { return false; } };
    if (req.query.refresh !== '1') {
      if (fresh(base + '.jpg', 3 * 86400000)) { res.set('Content-Type', 'image/jpeg'); fs.createReadStream(base + '.jpg').pipe(res); return; }
      if (fresh(base + '.none', 86400000)) { res.status(404).end(); return; }
    }
    if (fs.existsSync(base + '.jpg') && req.query.refresh !== '1') { res.set('Content-Type', 'image/jpeg'); fs.createReadStream(base + '.jpg').pipe(res); return; }
    // Not cached yet: answer at once and look it up in the background (requests never wait in line),
    // so the browser's next load finds it. Keeps web requests short (hosting process limits).
    if (connected && socket && !photoPending.has(jid) && photoPending.size < 300) {
      photoPending.add(jid);
      photoChain = photoChain.then(async () => {
        try {
          let target = jid; if (jid.endsWith('@lid')) target = (await phoneJidForLid(socket, jid)) || jid;
          const url = await withTimeout(socket.profilePictureUrl(target, 'image'), 8000, 't').catch(() => null);
          const buf = url ? await new Promise((ok) => https.get(url, (r) => { const c = []; r.on('data', (d) => c.push(d)); r.on('end', () => ok(r.statusCode === 200 ? Buffer.concat(c) : null)); }).on('error', () => ok(null))) : null;
          if (buf && buf.length) { fs.writeFileSync(base + '.jpg', buf); try { fs.unlinkSync(base + '.none'); } catch {} } else fs.writeFileSync(base + '.none', '');
          await new Promise((r) => setTimeout(r, 400)); // pace lookups
        } catch {} finally { photoPending.delete(jid); }
      });
    }
    res.set('Retry-After', '20'); res.status(404).end();
  } catch { res.status(404).end(); }
});

/** Deletes a message we sent, for everyone (WhatsApp allows this for about two days). */
app.post('/delete', requireToken, async (req, res) => {
  if (!connected || !socket) { res.status(503).json({ ok: false, error: 'WhatsApp is not connected.' }); return; }
  const id = String(req.body.id || '');
  const row = readArchive({ limit: 5000 }).find((r) => r.id === id && r.dir === 'out');
  if (!row) { res.status(404).json({ ok: false, error: 'Message not found in the archive.' }); return; }
  try {
    await withTimeout(socket.sendMessage(row.jid, { delete: { remoteJid: row.jid, fromMe: true, id } }), 20000, 'Delete timed out');
    archive({ dir: 'deleted', id, jid: row.jid, phone: row.phone });
    res.json({ ok: true });
  } catch (error) { res.status(502).json({ ok: false, error: error.message }); }
});

app.post('/send', requireToken, async (req, res) => {
  if (!connected || !socket) {
    res.status(503).json({ ok: false, error: 'WhatsApp is not connected. Scan QR first.' });
    return;
  }
  const to = normalizePhone(req.body.to);
  const message = String(req.body.message || '').trim();
  if (!to || (!message && !req.body.attachment)) {
    res.status(422).json({ ok: false, error: 'Recipient and message are required.' });
    return;
  }

  const jid = `${to}@s.whatsapp.net`;
  const attachment = safeAttachment(req.body.attachment);
  try {
    const registrations = await withTimeout(socket.onWhatsApp(jid), 8000, 'WhatsApp recipient check timed out');
    if (Array.isArray(registrations) && registrations[0] && registrations[0].exists === false) {
      res.status(422).json({ ok: false, error: 'Recipient number is not registered on WhatsApp.' });
      return;
    }
    let sent;
    if (attachment) {
      const isImage = /\.(png|jpe?g|webp)$/i.test(attachment);
      sent = await withTimeout(socket.sendMessage(jid, isImage
        ? { image: fs.readFileSync(attachment), ...(message ? { caption: message } : {}) }
        : { document: fs.readFileSync(attachment), fileName: path.basename(attachment), mimetype: 'application/pdf', ...(message ? { caption: message } : {}) }), 25000, 'WhatsApp send timed out');
    } else {
      sent = await withTimeout(socket.sendMessage(jid, { text: message }), 25000, 'WhatsApp send timed out');
    }
    archiveSent(sent, jid, attachment ? (/\.(png|jpe?g|webp)$/i.test(attachment) ? 'image' : 'document') : 'text', message, attachment, String(req.body.via || 'system'));
    res.json({ ok: true, id: sent?.key?.id || '' });
  } catch (error) {
    res.status(502).json({ ok: false, error: error.message });
  }
});

const isPassenger = Boolean(process.env.PASSENGER_APP_ENV || process.env.PASSENGER_BASE_URI);
function startAfterListen() {
  startSocket().catch((error) => {
    lastError = error.message;
  });
}

if (isPassenger) {
  // Phusion Passenger uses reverse port binding. Listening on a real port can fail on shared hosting.
  // Passenger expects a unix socket named "passenger". If the previous process crashed, the socket file can linger
  // and cause EADDRINUSE. Remove it before binding.
  try {
    const sockPath = path.resolve(process.cwd(), 'passenger');
    if (fs.existsSync(sockPath)) {
      fs.unlinkSync(sockPath);
    }
  } catch {
    // ignore
  }
  app.listen('passenger', () => {
    console.log('WhatsApp gateway listening via Passenger');
    startAfterListen();
  });
} else {
  // Only this server talks to the gateway (dashboard, invoice add-on, proxy): listen on localhost unless configured otherwise.
  const listenHost = process.env.LISTEN_HOST || gwConf.listen_host || '127.0.0.1';
  const srv = app.listen(port, listenHost, () => {
    console.log(`WhatsApp gateway listening on ${listenHost}:${port}`);
    startAfterListen();
  });
  // another copy of this number's gateway already holds the port: this one leaves at once (two copies waste memory and fight for the session)
  srv.on('error', (e) => { console.error(`gateway not started: ${e.code || e.message} on port ${port}`); process.exit(0); });
}

// Join requests that came while the gateway was offline: approved every 10 minutes (groups set to approve automatically).
setInterval(() => { if (!connected || !socket) return; for (const g of approveGroups()) approveRequests(socket, g, ''); }, 600000);
