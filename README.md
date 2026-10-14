# WhatsCRMbot

WhatsApp CRM inbox with an AI bot, team inbox, cases, media library and broadcasts.
By **Eng.Hany Ahmed — Topx1.Host**.

## Install
1. Upload `wa-inbox/` (PHP 8+, MySQL) and `wa-local/` (Node.js 16+).
2. Create an empty MySQL database.
3. Open `wa-inbox/` in the browser: the installer asks for the database and first admin, then creates all tables.
4. Gateway: `cd wa-local && npm install && node server.js`.
5. Link a number from the Accounts page by scanning the QR code.
