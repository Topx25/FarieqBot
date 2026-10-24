// WhatsApp gateway of ONE number (the per-number mode): this process runs one session of gateway_session.js.
// The hub (hub.js) runs every number of the hub mode in one process instead; both use the same session code and files.
import { startSession } from './gateway_session.js';

await startSession({ env: process.env, exit: (code) => process.exit(code), on: (ev, fn) => process.on(ev, fn), hub: false });
