<?php
declare(strict_types=1);
/*
 * WhatsApp Inbox — versions. Newest first. Every update adds an entry here (and a snapshot is kept in
 * the versions backup folder (<version>.tar.gz with wa-inbox + the gateway code).
 */
return [
    ['v' => '3.113.3', 'date' => '2026-10-20', 'title' => 'Facebook: Messenger works with the messaging permissions alone', 'changes' => [
        'Connecting a page no longer stops when Facebook gave only the messaging permissions: the page is known from the sign-in, it is subscribed to the messages (comments too when allowed), and its card lists the permissions Facebook did not give (checked with Facebook\'s token check), with what to do.',
    ]],
    ['v' => '3.113.2', 'date' => '2026-10-20', 'title' => 'Fix: saving the Facebook app', 'changes' => [
        'Saving the Facebook app, picking pages and adding a page by hand stopped with a server error (500): those forms ran before the Facebook part of the system was loaded. They load it now.',
    ]],
    ['v' => '3.113.1', 'date' => '2026-10-20', 'title' => 'The calm design layer really loads; clean legal addresses', 'changes' => [
        'pro.css (the calm look of every page, moved there in 3.105) was refused by the folder\'s access rules, so the new design never reached the browser. It is now allowed.',
        'Clean public addresses: /privacy, /terms and /data-deletion (Facebook asks for a plain page for the data deletion instructions).',
    ]],
    ['v' => '3.113.0', 'date' => '2026-10-20', 'title' => 'Public privacy policy and terms pages', 'changes' => [
        'New public pages index.php?privacy and index.php?terms (no sign-in), asked for by Facebook and Google when an app is set up. Their text is written in Settings → System (empty = a built-in general text in English and Arabic, filled with the product name, the owner\'s name and the contact e-mail set there).',
    ]],
    ['v' => '3.112.1', 'date' => '2026-10-20', 'title' => 'Facebook Login for Business', 'changes' => [
        'Facebook app settings: optional "Configuration ID". New Business-type apps use Facebook Login for Business, which asks for the permissions of a saved configuration instead of a list; when the ID is set, "Continue with Facebook" uses it.',
    ]],
    ['v' => '3.112.0', 'date' => '2026-10-19', 'title' => 'Facebook: a button per number and per client', 'changes' => [
        'Every WhatsApp number\'s card has its own "Facebook" button: the pages picked after it are linked to that number, belong to the same owner, and the bot answers on them with that number\'s knowledge (business profile, library, learning, rules). Each client\'s button connects pages to that client only.',
        'The bot now answers Messenger chats too (they were skipped), at once when the message arrives, with the page\'s own bot settings.',
        'The connected pages show which number they are linked to.',
    ]],
    ['v' => '3.111.2', 'date' => '2026-10-19', 'title' => 'Facebook: one button to connect', 'changes' => [
        'Accounts → Facebook pages: a "Continue with Facebook" button on top; the person signs in, picks the pages, and everything comes in by itself (like the Google sign-in). The connected pages show as chips (red when a page needs to be connected again).',
        'The app settings (App ID, secret, addresses to paste) are folded under "Facebook app settings", done once by the administrator; a client sees only the button, with an optional app of their own.',
    ]],
    ['v' => '3.111.1', 'date' => '2026-10-19', 'title' => 'Facebook pages card: one clean card', 'changes' => [
        'Accounts: the Facebook part is one card across the page with three steps (the app, the addresses to paste with Copy buttons, Connect with Facebook) and a state badge; adding a page by hand is folded under "Advanced".',
        'The browser no longer fills a saved e-mail into App ID / Page ID or a saved password into the secret fields; the titles show in Arabic.',
    ]],
    ['v' => '3.111.0', 'date' => '2026-10-19', 'title' => 'Facebook: connect with Facebook', 'changes' => [
        'Accounts → "Connect Facebook pages": save the Facebook app once (App ID and App secret), then "Connect with Facebook" opens Facebook\'s sign-in; the pages the person manages are listed to pick from, and each becomes an account with its token stored encrypted and its webhook subscribed. A client may save an app of their own; otherwise the system\'s app is used.',
        'The app has one webhook address for all its pages (events are routed by the page ID, checked with the app secret); the card shows it with the OAuth redirect address, the verify code, and the deauthorize and data-deletion addresses to paste in the app settings. Adding a page by hand with a token stays available.',
        'A page whose token expired or was revoked shows "Connect the page again", and "Reconnect with Facebook" renews it.',
    ]],
    ['v' => '3.110.1', 'date' => '2026-10-19', 'title' => 'Facebook: page stories, Messenger mark', 'changes' => [
        'Posts tab: "Publish as a story" posts a photo or an MP4 video as a story of the page (shown for 24 hours). A photo is uploaded unpublished and then published as a story; a video goes through Facebook\'s three steps (start, upload, finish).',
        'Conversations from a Facebook page carry a "Messenger" mark in the chat list.',
    ]],
    ['v' => '3.110.0', 'date' => '2026-10-19', 'title' => 'Facebook: posts and the page\'s numbers', 'changes' => [
        'The Facebook page now has three tabs: Comments, Posts and Numbers.',
        'Posts: write a post (text, link, photo or MP4 video), publish now or schedule it (at least 10 minutes ahead), see the published and scheduled posts with their reactions, comments and shares, edit the text and delete.',
        'Numbers: followers and page likes, and the last 28 days of media views, people who viewed, post engagements, page visits and new followers. Only the metrics Meta still offers are asked, one by one, so a retired metric shows "not available" and does not hide the others.',
        'Built from Meta\'s documentation and tested with simulated answers; check it with a real page.',
    ]],
    ['v' => '3.109.0', 'date' => '2026-10-19', 'title' => 'Facebook: comments', 'changes' => [
        'New page "Facebook comments" (Communication): the comments on the page\'s posts in one list with the post they belong to, search and filters (new, bot drafts, replied, hidden). Reply under the comment, reply privately (one per comment, within 7 days; it also shows in the person\'s Messenger chat), hide or show, delete and like.',
        'The bot can answer comments: off, suggest only (a draft waits for a person) or automatic, under the comment, privately, or both; it stays silent when it is not sure. The page is subscribed to the "feed" webhook field when it is checked.',
        'Built from Meta\'s documentation and tested with simulated events; check it with a real page before relying on it.',
    ]],
    ['v' => '3.108.4', 'date' => '2026-10-19', 'title' => 'Faster group replies', 'changes' => [
        'A System-help group no longer waits for an admin who is talking with the member (up to 10 minutes) and waits at most 10 seconds between two replies to the same person.',
    ]],
    ['v' => '3.108.3', 'date' => '2026-10-19', 'title' => 'Visitors: remove on the Nth entry, tags show the mobile number', 'changes' => [
        'Visitors list: "Remove the person on this entry number" (0 = never). On that entry the person is accepted (join requests approved by the group setting) and removed again at once, with an optional text. Team members are never removed.',
        'The tag of a returning visitor, and of the person a group reply answers, now shows the mobile number instead of the long WhatsApp ID, when the group\'s member list knows it.',
    ]],
    ['v' => '3.108.2', 'date' => '2026-10-19', 'title' => 'Returning visitor: any join after a leave', 'changes' => [
        'A person who leaves a group with a visitors list and comes back to the same group is a returning visitor at once (the returning text is posted with the person and the admins tagged). The "days" setting now only decides when joining another of our groups after leaving one is a move and not a new visit.',
    ]],
    ['v' => '3.108.1', 'date' => '2026-10-19', 'title' => 'Farewell: until the recording is sent', 'changes' => [
        'The farewell was skipped for anyone who had written anything in the reception group. It is now posted for a person who leaves before sending the recording (and was not moved on).',
    ]],
    ['v' => '3.108.0', 'date' => '2026-10-19', 'title' => 'Visitors list, farewell and returning visitors', 'changes' => [
        'Bot → Groups: a group can keep a visitors list built from its joins and leaves (also the past ones). The list is shown under "Visitors of the groups".',
        'Farewell: a person who leaves the group before answering the reception questions gets the farewell text of the group posted in it.',
        'Returning: a person who comes in again (after the days set between two visits; a move between our groups on the same day is one visit) gets the returning text, with the person and the group admins tagged. All texts are written in the group settings.',
    ]],
    ['v' => '3.107.5', 'date' => '2026-10-19', 'title' => 'Bot: no hourly cap when set to 0', 'changes' => [
        'Bot settings: "Max automatic replies per hour" and "Most bot replies in one chat per hour" accept 0 = no limit (they stopped at 1, and the first was capped at 60).',
        'New setting "Most replies to one person in a group per hour" (0 = no limit); it was fixed at 4 inside the code. The gap between two replies to the same person (2 minutes) is unchanged.',
        'This number\'s three limits are set to 0.',
    ]],
    ['v' => '3.107.4', 'date' => '2026-10-19', 'title' => 'Help group teaches the bot\'s commands', 'changes' => [
        'In a System-help group, a "#…" message that no command of the system handles (a typo, a wrong word) is answered with the exact correct command and an example, taken from the number\'s own command list (the "#menu" text). Commands that work are left to the system as before.',
    ]],
    ['v' => '3.107.3', 'date' => '2026-10-19', 'title' => 'Help group answers statements of need', 'changes' => [
        'In a System-help group set to "Questions only", a message such as "I cannot open the page" is also considered (the model decides whether it is a request for help); one- and two-word messages are still ignored.',
    ]],
    ['v' => '3.107.2', 'date' => '2026-10-19', 'title' => 'Help manual you can edit; cleaner change log', 'changes' => [
        'Bot → Groups → Help manual: every page text the help groups explain can be rewritten per number (empty = the built-in text).',
        'Reception gate: the words that mean a woman or a man are now saved in the group\'s own settings. They used to come only from the spiritual-healing business type, so after a number was switched to another type the gate stopped recognising the answer.',
        'Old change-log lines no longer name people, numbers or organisations.',
    ]],
    ['v' => '3.107.1', 'date' => '2026-10-19', 'title' => 'Welcome messages no longer get lost', 'changes' => [
        'The welcome of a group was marked as "already welcomed" before it was sent, and it built the whole chat list just to find the group\'s name; on a busy server that step could run out of time, so the member was marked and never welcomed (nobody was welcomed again for 7 days). Members are now marked only after the welcome is sent (or saved as a draft), and the saved group name is used.',
    ]],
    ['v' => '3.107.0', 'date' => '2026-10-19', 'title' => 'System help group', 'changes' => [
        'Bot → Groups: a group can be marked "System help". Members who ask how to do something in the system get step-by-step answers from the product manual.',
        'The asker is found by their mobile number among the users; only the pages their permissions open are explained (view-only pages say so), and a page closed to them is named with "ask the owner for access". An unknown number gets the general manual.',
        'The group card has a notes box for the owner\'s own rules and page names. The Facebook channel now calls Graph API v26.0.',
    ]],
    ['v' => '3.106.0', 'date' => '2026-10-19', 'title' => 'Facebook Messenger', 'changes' => [
        'New channel: connect a Facebook page (Accounts → Add Facebook page). Its Messenger chats arrive in the same inbox and the reply box, bot, library, tags, notes, team assignment and search work on them like on a number.',
        'The page card shows the webhook address and verify code to paste in the app; the page is subscribed to messages, postbacks, echoes, reads and reactions. Photos, videos, voice notes and files are saved with the chat.',
        'Messenger rules are kept: a page answers within 24 hours of the person\'s last message, and a person (not the bot) can still reply up to 7 days later; a clear message says when the window has closed.',
    ]],
    ['v' => '3.105.1', 'date' => '2026-10-19', 'title' => 'Bot: fewer made-up details', 'changes' => [
        'The bot\'s quick model is chosen from reliable families first (DeepSeek flash, Gemini flash, GLM flash); the "lite" models, which were measured weakest, come last. Measured on 14 real questions the four candidates were within noise of each other (overall 3.99–4.19), so the fix is not one model.',
        'Experimental or unnamed models of the providers (beam, space-bunny, big-pickle, omen, jev, test, argon, contributor) are listed but never picked automatically.',
        'New reply rule: never mention a symptom, family member, place, step or past event about the person unless it is in their card or the recent messages; a greeting, thanks or a short comment gets a short natural reply, with no health questions and no new instructions.',
    ]],
    ['v' => '3.105.0', 'date' => '2026-10-19', 'title' => 'One calm look for every page', 'changes' => [
        'Design: the same flat, quiet look on every page — cards with a thin line and no shadow, lighter weights, headers without the coloured gradient, outline buttons with one solid colour for the main action, flat number tiles (no coloured blobs), thin row dividers in lists and tables. The dashboard follows the approved mockup: a plain greeting card, four number tiles, the chart, the items that need attention, and the numbers and bot status.',
    ]],
    ['v' => '3.104.0', 'date' => '2026-10-19', 'title' => 'AI providers: every key is its own', 'changes' => [
        'A provider\'s extra keys are no longer one text box: each key is its own card with its own input box, its own model and its own plan (auto / free / paid), exactly like the main key. "Add another key" adds one, "Remove this key" removes it, and a saved key stays hidden (type only to replace it).',
        'New choice "When there are several keys": one after the other (the next key takes over when one reaches its limit — the old behaviour) or all together (each request starts at the next key, so the keys share the work and fall through to the others if one is resting).',
        'The Test button saves what is typed in the card first. Keys written the old way ("paid:KEY") are read as keys on their plan.',
    ]],
    ['v' => '3.103.1', 'date' => '2026-10-19', 'title' => 'Anthropic: workspace id field', 'changes' => [
        'Anthropic Claude: a key of an organisation was refused ("not scoped to a workspace"). The card now has a "Workspace ID" field — write the wrkspc_… id alone — and the address typed there is saved before "Test & refresh models" (it used to be lost when only the test button was pressed). The error now says what to do.',
    ]],
    ['v' => '3.103.0', 'date' => '2026-10-19', 'title' => 'AI providers: Anthropic Claude', 'changes' => [
        'New provider "Anthropic Claude" (Messages API): paste the key in its card, turn it on, press "Test & refresh models". Its Claude models are listed, read images, and are billed per token, so its plan is always paid. Nothing is sent with a temperature (current models refuse it).',
        'A key of an organisation needs its workspace: write the address as https://api.anthropic.com?workspace=wrkspc_… in the new "Address" field of the card. Extra keys, the daily limit and the quick "Reload models" work as for the other providers.',
    ]],
    ['v' => '3.102.1', 'date' => '2026-10-19', 'title' => 'AI providers: a free key and a paid key together', 'changes' => [
        'A provider with several keys had one plan for all of them: with a paid key the free key was asked paid models (refused, and the key rested for a day for nothing), with a free plan the paid key never used its models. Every extra key can now carry its own plan — write "paid:KEY" or "free:KEY" on its line (a plain key follows the provider\'s plan).',
        'Free keys are tried first, each only with free models; a paid key is used last, when the free ones are resting, with all models.',
    ]],
    ['v' => '3.102.0', 'date' => '2026-10-19', 'title' => 'Users editor: clearer permissions', 'changes' => [
        'Users → edit: each page\'s access is now three labelled pills — None, View, Edit — coloured grey / blue / green, and the whole row takes the colour of its state (the icons-only control was hard to read).',
        'The feature cards (bot, meetings, cases…) are real switches: off is grey, on is green with the knob moved, and the card is tinted.',
        'Role cards show the chosen role clearly; the dialog no longer leaves a blank area under the buttons, and the Save buttons stay in view. "Inbox" and "Analytics" in the page list are translated.',
    ]],
    ['v' => '3.101.2', 'date' => '2026-10-19', 'title' => 'AI providers: every model is listed', 'changes' => [
        'OpenCode Zen: with a key the service lists only 32 of its 85 models. The page now shows the whole published catalogue (free and paid) next to the key\'s own list; models not in the key\'s list say so.',
        'OpenCode Go: two models that were hidden (another API route / experimental) are listed with a ⚠️ note; they are never picked automatically, only by hand.',
        'The stored list was cut at 300 models (OpenRouter has 470+, Kilo 400+): the limit is now 1500.',
        'Model lists are reloaded once a day for every provider (the list used to change only when the whole key test was run — OpenCode Zen showed a 5-day-old list of 45). Each provider card has a quick "Reload models" button that reloads only the list, with no key or model test.',
    ]],
    ['v' => '3.101.1', 'date' => '2026-10-19', 'title' => 'Neutral examples and wording', 'changes' => [
        'Examples, placeholders and notes no longer use a real person\'s name or a real address: the shortcut example is now "#shortcut-name", the sample name is "A short name", and the release notes name no domain, number or tool.',
    ]],
    ['v' => '3.101.0', 'date' => '2026-10-19', 'title' => 'Staff chat prefix and welcome message', 'changes' => [
        'A team member\'s replies from the dashboard now go out with their name in front — "name: the message" — in private chats and groups, also in the caption of a file. The format is a message template (Messages settings: staff_prefix).',
        'The first time a team member writes in a chat — even an old chat — a welcome message that introduces them (name and business) is sent before their message. It is sent once per team member per private chat; the text is a template (staff_welcome).',
        'Users → each user: "Name shown in chats", "Add their name before every reply" and "A welcome message before their first reply in each chat". Employees and supervisors have both on unless changed; new users start with both ticked; owners and admins are not affected unless you turn them on.',
    ]],
    ['v' => '3.100.6', 'date' => '2026-10-19', 'title' => 'Library editors can add texts and upload files', 'changes' => [
        'Fixed: saving a text in the Library showed "Not allowed for this number" for a team member who could edit the library. Adding, editing and deleting your texts and uploading files now follow the library permission (edit); the number\'s owner and admins keep full access, view-only users are still refused.',
    ]],
    ['v' => '3.100.5', 'date' => '2026-10-18', 'title' => 'Leave full screen from anywhere', 'changes' => [
        'Chats: full screen mode hides the top menu, and the only way out was a button in the chat list — which can be hidden too. While full screen is on, a "Show the menu" button now stays in the corner of the screen.',
    ]],
    ['v' => '3.100.4', 'date' => '2026-10-18', 'title' => 'Chat list button always in the header', 'changes' => [
        'Chat header: the "Chat list" button (hide / show the list of chats) moved next to the fold button in the name row, so it is there also when the rest of the header is folded.',
    ]],
    ['v' => '3.100.3', 'date' => '2026-10-18', 'title' => 'Speed: the chat screen opens much faster', 'changes' => [
        'Opening a chat asked WhatsApp for the contact on every click (about 0.8 s, and again for the profile). The contact data is now kept for 6 hours (10 minutes when WhatsApp did not answer): the profile call went from 2.4 s to 0.2 s.',
        'Gateway: a chat\'s messages were found by reading and scanning every archive file (35 MB for the main number) on every request. The files are now kept in memory with an index by chat, and only new lines are read: a thread request went from about 160 ms to 5–10 ms. Applies after the gateway restarts (the main number was restarted).',
        'A speed check for developers: start a request with WI_PROF=1 and every gateway call and its time is written to /tmp/wi_prof.log.',
    ]],
    ['v' => '3.100.2', 'date' => '2026-10-18', 'title' => 'Chat header: fold button; "Who sees it" fixed', 'changes' => [
        'Fixed: pressing "Who sees it" (🔒) in a chat header showed "Unknown request". The request was sent with its parameter glued to its name; it is now sent properly.',
        'Chat header: a ⌃ button at the end of the name row folds everything under the name (the action buttons, the details and the tools) and ⌄ brings it back. The choice is remembered.',
        'The hide-chat button keeps its label after it is pressed.',
    ]],
    ['v' => '3.100.1', 'date' => '2026-10-18', 'title' => 'Chat header: actions in their own row', 'changes' => [
        'Fixed: with the icon names added, the action buttons took the whole header and squeezed the chat name into a thin column. The actions now sit in their own row under the name; the name, number and badges keep their room.',
    ]],
    ['v' => '3.100.0', 'date' => '2026-10-18', 'title' => 'Chat screen: group members, member card, named icons', 'changes' => [
        'Groups: a "Members" button in the chat header lists everyone in the group (admins first, with customer and chat marks) with a search box.',
        'Groups: the name of a sender above a message is now a button. It (and every member in the list) opens the member card: name, number, admin, customer / case / program / message count, and buttons to message them privately (or start a private chat), mention them in the group, copy the number, open WhatsApp or their profile.',
        'Chat screen: the header icons and the composer icons now carry their names (Chat list, Hide chat, Who sees it, Members, Group settings, Profile, Program, Add to group, Media, Delete chat, Export, Attach, Library, Quick replies). On phones only the icons show, with tooltips.',
    ]],
    ['v' => '3.99.3', 'date' => '2026-10-18', 'title' => 'Forward dialog: the whole message, two columns', 'changes' => [
        'Forward to chats: the full message is shown — the box grows with the text (up to about half the screen, then scrolls) with a character count. On a wide screen the message is on one side and the chats on the other; on a phone they stack.',
        'Chats have a round tick that turns green when chosen, the chosen ones are chips above the list, and the Send button stays at the bottom.',
    ]],
    ['v' => '3.99.2', 'date' => '2026-10-18', 'title' => 'Forward dialog: readable message box', 'changes' => [
        'Forward to chats: the message box was a narrow strip showing two lines; it now uses the full width, is taller (resizable), and the chosen chats are outlined.',
    ]],
    ['v' => '3.99.1', 'date' => '2026-10-18', 'title' => 'Private folder: files are encrypted on disk', 'changes' => [
        'Private folder: a hidden file is now encrypted (AES-256-GCM, a key of its own for each number kept encrypted in the secrets table) and its plain copy is deleted. The encrypted copy lives in storage/vault/ and is decrypted only while the folder is open (password or WhatsApp code), streamed with seeking for audio and video. A tampered or swapped file refuses to open.',
        'Taking a file out decrypts it back to where it was. Files hidden with the first version (only flagged) are encrypted the first time the folder is opened. Files up to 128 MB.',
        'Backups must keep the installation key (.data/app.key) together with the database: without it the private files cannot be opened.',
    ]],
    ['v' => '3.99.0', 'date' => '2026-10-17', 'title' => 'Media: private folder opened with a password or a WhatsApp code', 'changes' => [
        'Media: select files → "Hide in the private folder". They disappear from the library, the zip, the shortcuts and the bot commands until the folder is opened.',
        'The "🔐 Private folder" entry (with its count) is in the folders list. Opening it asks for the password, or a 6-digit code sent on WhatsApp to the signed-in user\'s phone (valid 5 minutes). The first time, a password is set. It stays open for 30 minutes or until "Lock".',
        'Five wrong tries lock the attempts for 15 minutes; every unlock, bad password and hide is written to the log.',
    ]],
    ['v' => '3.98.2', 'date' => '2026-10-17', 'title' => 'Media: the owner opens the chat at the file', 'changes' => [
        'Media: the sender name and the chat name on every card (and in the popup) are links — they open the chat and scroll to the message that carried the file, which flashes briefly. The inbox accepts #chat|message links.',
    ]],
    ['v' => '3.98.1', 'date' => '2026-10-17', 'title' => 'Media cards: the settings button is visible', 'changes' => [
        'Media cards: the four actions (Name, Send, Download, Settings) wrap inside the card instead of running off its edge — the settings button was cut off, so the details popup could not be opened. It is now labelled "Settings". Clicking the picture or the name still opens it.',
    ]],
    ['v' => '3.98.0', 'date' => '2026-10-17', 'title' => 'Media: details as a popup, who sent each file', 'changes' => [
        'Media: the details of a file (title, shortcut, caption, folder, tags…) open as a popup over the page — close with ✕, outside click or Esc — instead of a permanent column. Saving closes it.',
        'Media: every card and the popup show who sent the file (the contact, the group member, or "You"). Needs the gateway restarted once to start returning the sender.',
    ]],
    ['v' => '3.97.4', 'date' => '2026-10-17', 'title' => 'The Media link is back', 'changes' => [
        'Fixed: the "Media" and "Deleted" links in the library navigation were hidden by the page-permission script (the page "media" is not in the permission list, it follows the library). They show again, and "Deleted" is translated.',
    ]],
    ['v' => '3.97.3', 'date' => '2026-10-17', 'title' => 'Labels with "&" are translated', 'changes' => [
        'Fixed: every label written with "&" (for example "Sort & check with AI", "Selection & AI tools") stayed in English because the page spells it "&amp;" and the dictionary "&". They now translate everywhere.',
    ]],
    ['v' => '3.97.2', 'date' => '2026-10-17', 'title' => 'Library and Audio: calmer layout', 'changes' => [
        'Fixed: "Add text", "Upload files", "Manage categories", "Statistics", "Move to a team" and "Delete" showed in English (the emoji shared their text) — now translated.',
        'Library and Audio: the section tiles are compact pills instead of big squares, the selection and AI buttons are folded into one "Selection & AI tools" box, the side panel buttons are tidy, and the cards are lighter (no box inside a box).',
    ]],
    ['v' => '3.97.1', 'date' => '2026-10-17', 'title' => 'Google sign-in works again; YouTube on its own', 'changes' => [
        'Fixed: signing in with Google failed ("scopes that cannot be requested together: drive.file and youtube.upload"). Drive, Calendar and Meet are asked together; YouTube is now its own sign-in with its own saved connection, started from the YouTube upload window (Media → upload to YouTube → Connect YouTube).',
        'A Google account already connected keeps working for Drive, Calendar and Meet; only the YouTube upload needs the new sign-in.',
    ]],
    ['v' => '3.97.0', 'date' => '2026-10-17', 'title' => 'Users: move a viewer to a team, clear delete', 'changes' => [
        'Users page: "Move to a team" on a viewer — pick the owner of the number and the role (employee / supervisor); the person keeps their number, pages and phone and then sees the owner\'s numbers and data. A viewer could never see a private number before, whatever their permissions.',
        'The super admin can now be the owner of a team (for the numbers they own), like a client.',
        'Users page: the delete button is now labelled "Delete" (it was only an icon).',
    ]],
    ['v' => '3.96.3', 'date' => '2026-10-17', 'title' => 'Library has its own menu group', 'changes' => [
        'Menu: the Library (library, audio, files, media) is now its own group, no longer inside "Automation & content" with the bot. Names and icons set in the menu settings still apply.',
    ]],
    ['v' => '3.96.2', 'date' => '2026-10-17', 'title' => 'Written text command finds the right text', 'changes' => [
        'Fixed: "#رقية العين والحسد" did not find "رقية العين و الحسد …" (a space after the و, and the search stopped at the first phrase that returned anything). A leading "و" is now the word "and", and the whole name plus its main words are all searched.',
        'A single text that is not of the kind the command names (no "رقية" in its title) is shown as the closest, never sent.',
    ]],
    ['v' => '3.96.1', 'date' => '2026-10-17', 'title' => 'Dream wait is a setting', 'changes' => [
        'Bot settings: "A dream told in pieces — wait this many seconds after the last piece, then answer once" (0 = answer each piece) is now editable next to the other wait times.',
    ]],
    ['v' => '3.96.0', 'date' => '2026-10-17', 'title' => 'Library commands: the nearest tags, recordings, no wrong text', 'changes' => [
        'When a name is not found, the bot now says which tags to try — the nearest ones to what was written, then the section tags (#المكتبة #صوتيات #البرامج #رقيات) — instead of only "not found".',
        'A text found only by words inside its body is no longer sent as the answer: the bot lists the closest texts and asks for the exact name.',
        '"#رقية name مسموعة" (an audio word — a setting in the business profile) looks for the recording of it instead of the written text.',
        'New section tag #رقيات (the written texts); the list of tags is a setting and can be changed or extended.',
        'Fixed: a tag such as #صوتيات was answered twice when the same message reached the bot by two paths or was delivered twice; it is answered once.',
    ]],
    ['v' => '3.95.1', 'date' => '2026-10-17', 'title' => 'The bot in groups: commands and dreams told in pieces', 'changes' => [
        'A case command written in a group that does not run commands (e.g. "#الحالة ID 23 #اتحذفت") is no longer answered as chat: the bot stays quiet.',
        'A dream told in several short messages: the bot waits until the person stops (setting "dream_wait", 120 seconds by default), then answers all of it once. A tagged message, a question or a long message is answered at once.',
    ]],
    ['v' => '3.95.0', 'date' => '2026-10-17', 'title' => 'Meetings: guests accepted after a host enters, customizable pages', 'changes' => [
        'Meetings: a new option "Accept guests automatically once any host has entered". Several hosts can be set (dashboard users and WhatsApp group members); when any one of them enters, everyone who is waiting is accepted, and later guests are accepted as soon as they give their details.',
        'Meetings: every public page can be customized per meeting — waiting page, details page (text, field titles, button), approval page, accepted, rejected, full, wrong link and host page — plus button and background colours and a logo. Empty fields keep the built-in wording; a preview link opens each page.',
    ]],
    ['v' => '3.94.0', 'date' => '2026-10-17', 'title' => 'Library: add, edit, delete texts, upload files, list view', 'changes' => [
        'Library: "Add text" — a written ruqyah, a price list or any answer, with a section, title, topic, tag, keywords and a cover image. Edit and delete your texts; any collected text can be turned into one with "Edit as my text".',
        'Writing a text\'s tag in a chat or group sends it (with its cover); "#رقية name" also finds it by title or keywords. A tag cannot repeat across files, programs, texts and quick replies.',
        'Library: "Upload files" (photos, audio, PDF, MP4) straight into the library — they show in Media.',
        'Library: the list view is now a real list — title, tag and counts on one side, the text in the middle, the actions on the other.',
    ]],
    ['v' => '3.93.3', 'date' => '2026-10-17', 'title' => 'Program tag: the text always arrives', 'changes' => [
        'Fixed: the confirmation after a program tag ended with "Day one:" and no text when the program had no day 1 (its first step was another day). The first step is now always sent, labelled with its own day number.',
    ]],
    ['v' => '3.93.2', 'date' => '2026-10-17', 'title' => 'Program tag: day one at once', 'changes' => [
        'Fixed: writing a program\'s tag in a private chat only answered "you joined, its days arrive one by one" — confusing, and the first day came hours later. Now the person gets the program\'s first day right away with the confirmation (and, if it has more days, a line that the rest arrive daily). Day one is not sent twice.',
    ]],
    ['v' => '3.93.1', 'date' => '2026-10-17', 'title' => 'Program tag links the subscriber', 'changes' => [
        'When a program\'s tag is written in a private chat, that person is linked to the program automatically (its days arrive one by one) and is told so; written again, they are told they already follow it. In a group the tag still sends the whole program.',
        'Programs page: each program lists its active subscribers by name under it.',
    ]],
    ['v' => '3.93.0', 'date' => '2026-10-17', 'title' => 'Tags for programs', 'changes' => [
        'Programs now have a tag (e.g. #برنامج-الحسد): write it in any chat or group and the program is sent. Set it when you edit the program; the #البرامج list shows each program with its tag.',
        'A tag cannot repeat across files, programs, section tags and quick-reply keywords.',
    ]],
    ['v' => '3.92.1', 'date' => '2026-10-17', 'title' => 'Library text command does not guess', 'changes' => [
        'Fixed: "#رقية العقد" sent a cleaning session (the only text with "العقد" in its title). A written text is now sent directly only when it is of the kind the command names; otherwise the bot lists the closest texts and asks which one.',
    ]],
    ['v' => '3.92.0', 'date' => '2026-10-17', 'title' => 'The bot stays in its place', 'changes' => [
        'Healers are no longer treated as cases: whoever writes a case or a case command in a group joins the team automatically (removable from the team list), and when the bot does answer a team member it talks to them as a colleague, never about "their condition". The rule is an editable instruction (AI instructions).',
        'Short acknowledgements ("تمام", "حاضر", "الحمد لله" …) no longer get a reply inside a talk that is already going; a brand-new chat is still welcomed. The words are a setting (AI instructions → short replies that end a talk).',
        'Section tags work with or without "ال" (#مكتبة = #المكتبة).',
    ]],
    ['v' => '3.91.1', 'date' => '2026-10-17', 'title' => 'Enter saves', 'changes' => [
        'Pressing Enter in a one-line field now saves: it presses the main button of its dialog or card (the only one), and a field that saves when you leave it just saves. Search boxes, forms and fields with their own Enter action are left as they were.',
    ]],
    ['v' => '3.91.0', 'date' => '2026-10-17', 'title' => 'Categories control and no duplicate tags', 'changes' => [
        'Audio page: a "Manage categories" button — add, rename, change the icon and colour, or delete the categories of the audio library (recordings of a deleted category stay, uncategorised).',
        'A shortcut or section tag can no longer repeat: it is refused when another file, a section tag or a quick reply keyword already has it, and the same tag cannot be written twice in the section tags.',
    ]],
    ['v' => '3.90.3', 'date' => '2026-10-17', 'title' => 'Audio cards: the player always shows', 'changes' => [
        'Audio page: the player now has its own width and height on the card (it no longer depends on the page styles), and the selection box is always visible.',
    ]],
    ['v' => '3.90.2', 'date' => '2026-10-17', 'title' => 'Audio cards: the player fills the card', 'changes' => [
        'Audio page: the player was squeezed into a tiny box under a question mark. It now spans the whole card, and recordings without a category show a headphones icon instead of a question mark.',
    ]],
    ['v' => '3.90.1', 'date' => '2026-10-17', 'title' => 'Audio cards like the library', 'changes' => [
        'Audio page: the recordings are now cards like the media library, with their controls on every card: name & shortcut, send to chats, download, archive and category. The name and shortcut saved there show on the card.',
    ]],
    ['v' => '3.90.0', 'date' => '2026-10-17', 'title' => 'Section tags', 'changes' => [
        'New: write #المكتبة (every file), #صوتيات (recordings) or #البرامج (programs) in a chat or group and the bot lists that section with each file\'s name and shortcut; write a shortcut to get the file.',
        'The tags are settings, not code: Learning → AI instructions → "Section tags" (one line per tag: #tag | library, audio, video, images, documents or programs | title). Empty = off. The two replies are editable in Messages.',
    ]],
    ['v' => '3.89.8', 'date' => '2026-10-17', 'title' => 'Name and shortcut show at once', 'changes' => [
        'Saving "Name & shortcut" on a library card now updates the card right away, without refreshing the page.',
    ]],
    ['v' => '3.89.7', 'date' => '2026-10-17', 'title' => 'One player at a time', 'changes' => [
        'Playing a recording or a video now pauses the one that was already playing, on every page.',
    ]],
    ['v' => '3.89.6', 'date' => '2026-10-17', 'title' => 'Library cards show the name and shortcut', 'changes' => [
        'Fixed: a recording named with "Name & shortcut" still showed the generic title on its library card. The card now shows the name you saved and the shortcut under it.',
    ]],
    ['v' => '3.89.5', 'date' => '2026-10-17', 'title' => 'Audio page: the library first', 'changes' => [
        'The Audio page now opens on the audio library itself: the category tiles, then the recordings with their players, names, categories and download. The statistics (space, listening time, duplicates, months) moved into a folded "Statistics" box under the list.',
    ]],
    ['v' => '3.89.4', 'date' => '2026-10-17', 'title' => 'Audio and video open on their own', 'changes' => [
        'Fixed: opening a voice note, audio or video file on its own (the open links) showed an empty player at 0:00. Those files were sent with a sandbox header, so the player asked for its own file as a stranger and the session cookie (same-site only) was not sent. Audio and video now use a header without the sandbox; photos, PDFs and other files keep the strict one.',
    ]],
    ['v' => '3.89.3', 'date' => '2026-10-17', 'title' => 'Library matches the design', 'changes' => [
        'Library cards: tinted backgrounds per kind (photo, sticker, GIF, document, video, voice), a document card with its extension, and the library keeps the teal palette of the design in every dashboard theme (light and dark).',
    ]],
    ['v' => '3.89.2', 'date' => '2026-10-17', 'title' => 'Library page in English fixed', 'changes' => [
        'Fixed: on the Library page every text drawn by the page script showed in English (All topics, copies, chats, Best, Send to chats and groups…). The library state variable L overwrote the translation dictionary L of the shared script; the dictionary is now WI_DICT.',
        'Library menu group titles and the Library header are translated (an emoji in front of the text stopped the match).',
    ]],
    ['v' => '3.89.1', 'date' => '2026-10-17', 'title' => 'Library cards as designed', 'changes' => [
        'Library cards carry the approved design: labelled actions on every card (Name, Send to chats, Download, Edit), a clear accent frame on selected cards; in Deleted each card has a Put back button.',
    ]],
    ['v' => '3.89.0', 'date' => '2026-10-17', 'title' => 'Library navigation redesigned', 'changes' => [
        'Library pages: the top menu is two clear groups — Media and files (Media, Audio, Files, 🗑 Deleted) and Texts (All and the sections) — with the open one filled; Deleted has its own link (?p=media&bin=1).',
        'Media sidebar in groups (Show, Folders, Tools) and drawn while viewing Deleted too.',
    ]],
    ['v' => '3.88.2', 'date' => '2026-10-17', 'title' => 'Deleted always in sight', 'changes' => [
        'Media library: 🗑 Deleted moved up next to Favourites, with how many files wait there; the sidebar is no longer cut to the screen height.',
    ]],
    ['v' => '3.88.1', 'date' => '2026-10-17', 'title' => 'Library cards that play and act', 'changes' => [
        'Media library: new cards — voice notes and audio play with their own player, videos play in place, readable names instead of file ids, size, date, chat and folder on every card in every view, ✏️ rename and ⚙️ edit buttons; the list view shows the player in the row; the edit panel only takes room when open.',
        'Files page: each card plays videos and audio in place and has download, open, 📤 send to chats, 💬 open the chat and 🔗 copy link.',
        'Media is served in byte ranges: audio and video can jump to any point.',
    ]],
    ['v' => '3.88.0', 'date' => '2026-10-17', 'title' => 'Media library redesigned', 'changes' => [
        'The media library is redesigned as a file manager: a sidebar with the space used and the folders; one header card with the path, the total, view buttons (icons / large cards / list), details, search, chat, dates, sort and the kinds; lifted tiles with a clear selection; a real list with columns; a sticky details panel; a dark bar for the selected files. Every tool works as before.',
    ]],
    ['v' => '3.87.0', 'date' => '2026-10-17', 'title' => 'Aurora theme', 'changes' => [
        'New dashboard theme «Aurora» (Settings → Dashboard theme): the home becomes a dashboard — a gradient hero with the numbers as live status cards and quick links, large KPI tiles, glass cards, two-column grid; light and dark.',
    ]],
    ['v' => '3.86.0', 'date' => '2026-10-17', 'title' => 'Offline work and moving to a new number', 'changes' => [
        'A reply (text or file) written while WhatsApp is disconnected is kept in the number\'s outbox and sent automatically, oldest first, as soon as the number is back (checked every minute). The chats page shows an offline banner with how many replies wait; chats, files and search keep working offline.',
        'Numbers page → 🔁 Move the chats of an old number to a new one: the old messages, files, notes, assignments, summaries and cases are added to the new number once (the old number keeps its copy); the new number reconnects to show them.',
    ]],
    ['v' => '3.85.0', 'date' => '2026-10-17', 'title' => 'Media library: file manager', 'changes' => [
        'Media library: filter by chat or group (each file knows the chat it came from) and by dates; views: icons, large cards, list; ℹ️ details (name, size, date, chat, folder) on/off; the selected files download as one .zip. Remembered in this browser.',
    ]],
    ['v' => '3.84.0', 'date' => '2026-10-17', 'title' => 'Hide a chat from chosen team members', 'changes' => [
        'Chat header → 🔒 Who sees this chat (for those who manage users): tick the team members who must not see it. It is removed from every answer of the server for them (chat list, search, groups, reports, messages), and opening it by link is refused.',
    ]],
    ['v' => '3.83.0', 'date' => '2026-10-17', 'title' => 'Chats page: view options and all numbers', 'changes' => [
        'Chats page buttons: ⛶ full screen (hides the header and menus), 🖼️ show/hide photo and video previews, 📋 show/hide the chat list (also in the chat header), 🙈 show hidden chats; each chat can be hidden from the list from its header. Remembered in this browser.',
        '🔀 All my numbers in one list (when the user has more than one): each chat carries a coloured mark with its number; a chat of another number opens on that number.',
    ]],
    ['v' => '3.82.2', 'date' => '2026-10-17', 'title' => 'Each business sees its own words', 'changes' => [
        'Business settings → Interface words: a business other than healing sees only its own words (the healing wording of the base dictionary is no longer shown on the left), and the word search shows words as they read on its own panel. Neutral search hint.',
        'Link page: a linked number shows its mobile number.',
    ]],
    ['v' => '3.82.1', 'date' => '2026-10-17', 'title' => 'No hard-coded rules', 'changes' => [
        'The warm-up rule and the reply-rewrite instruction moved from the code to the prompt list (bot_fun, bot_fix), editable per number.',
        'The wait between two group replies to the same person is a bot setting (default 120 seconds).',
    ]],
    ['v' => '3.82.0', 'date' => '2026-10-17', 'title' => 'Warm-up mode (healing groups)', 'changes' => [
        'The owner types #هزار in any private chat on the phone: for 2 hours the bot chats and jokes lightly with everyone in the groups that allow it (group setting "Warm-up mode"), team included; #جد stops it. The note goes to the owner\'s own number, never to that chat. Healing business only.',
        'The verse/dhikr check and the reception words are now part of the healing business only; other businesses do not get them.',
        'Fixed: the one-reply-per-2-minutes rule read the wrong time.',
    ]],
    ['v' => '3.81.0', 'date' => '2026-10-17', 'title' => 'Strict group rules', 'changes' => [
        'Groups: the bot answers the team (healers, admins) only when they tag it or reply to it — no more answers to their chat and jokes.',
        'Groups: a message that tags or quotes another member is not for the bot; one reply per person per 2 minutes (more waits).',
        'Group replies tag only the person answered: other @numbers and "يا <another member>" are removed; the prompt asks for no names and no nicknames; nicknames (يا صاحبي، يا باشا…) are removed (business profile "nicknames").',
        'Reception: "woman / man" inside a longer greeting counts (not in a question); one move notice per member even when two joins arrive together; reminders and removal only for members still in the group, removed by their real id (removal failed in LID groups).',
        'Fixed: Google Drive linking lost its access token (code inside a comment). New QA check: no code inside comments.',
    ]],
    ['v' => '3.80.5', 'date' => '2026-10-17', 'title' => 'Gateway page: a new number shows starting', 'changes' => [
        'A number added in the last 3 minutes shows «Starting…» instead of «Stopped» while the watchdog starts its gateway.',
        'Fixed: a new number lost its creation time and its auto-reply flag (they sat inside a code comment).',
    ]],
    ['v' => '3.80.4', 'date' => '2026-10-15', 'title' => 'Stage changes noted in the diagnosis group', 'changes' => [
        'A stage change written in another group (#الحالة … #اتحسنت …) is also posted in the diagnosis group as a reply to the case report (template case_diag_note); the report message is remembered, and an older one is found by the number in it.',
        'Form messages read only the labelled lines ("#الحالة: …", "#السبب: …"); a title line is no longer taken as the reason.',
    ]],
    ['v' => '3.80.3', 'date' => '2026-10-15', 'title' => 'Case ID on every diagnosis report', 'changes' => [
        'Every report posted to the diagnosis group starts with the case ID (🆔 ID n), from the group keyword and from the Cases form; a new case gets its ID before its report is posted.',
        'Fixed: details added to an existing case were noted as "case opened".',
    ]],
    ['v' => '3.80.2', 'date' => '2026-10-15', 'title' => 'Case not found: look up the ID', 'changes' => [
        'When no case matches the name or number, the reply names the diagnosis group and asks to take the case ID from there and write it instead of the name (template case_not_found, new {diag}).',
    ]],
    ['v' => '3.80.1', 'date' => '2026-10-15', 'title' => 'Case follow-up commands work again', 'changes' => [
        'Fixed: every case command in a group (#الحالة …, #اتحسنت …) stopped with an error since 3.6x because a line of code had slipped into a comment; the bot did not answer and nothing changed.',
        'A stage word wins over the lookup word: "#الحالة … #اتحسنت …" changes the stage instead of only showing the file.',
        'Filled forms are understood: "#الحالة: name", "#رقم الموبايل: +212…", "#السبب: …" — a phone number anywhere is the person, the other values the reason. The case ID is never taken as the reason.',
    ]],
    ['v' => '3.80.0', 'date' => '2026-10-15', 'title' => 'SaaS: the client\'s own address', 'changes' => [
        'A client may have their own address: name.<SaaS domain> (Clients → Plans and sign-up) and/or their own domains (CNAME to this server). On it the panel shows their brand name and logo, and only they, their team and the super admin may sign in; anyone else signed in there is signed out.',
        'Sign-up asks for a password (the panel signs in with email + password, then the WhatsApp code); before, the new client could not sign in.',
    ]],
    ['v' => '3.79.0', 'date' => '2026-10-15', 'title' => 'SaaS: plans, sign-up, limits', 'changes' => [
        'Plans with real limits (WhatsApp numbers, team seats, bot, meetings, cases, backups, monthly AI calls on the operator keys), edited by the super admin on the Clients page; choosing a plan for a client applies them.',
        'A client may link as many numbers as their plan gives (hand-made clients: one).',
        'A client with no AI keys of their own uses the plan monthly allowance on the operator keys; when it runs out, their own keys are needed.',
        'Public page pricing.php: the shown plans and, when sign-up is on, a free trial: the visitor gets a client panel on the trial plan and signs in with the code sent to their WhatsApp. Limits: 3 sign-ups per connection a day, one account per email and number, a hidden field against robots.',
    ]],
    ['v' => '3.78.1', 'date' => '2026-10-15', 'title' => 'Join requests approved automatically', 'changes' => [
        'Group setting "Join requests: approve automatically": the gateway approves each new request at once (the number must be an admin), and every 10 minutes the ones that came while it was offline. Approved members then get the welcome and the reception follow-up.',
    ]],
    ['v' => '3.78.0', 'date' => '2026-10-15', 'title' => 'Reception: follow-up of silent members', 'changes' => [
        'A new member of a reception group who writes nothing gets a reminder after 3 hours (template gate_remind), a last notice 3 hours later (gate_final), and is removed from the group 30 minutes after it. Any message from them stops it. Hours, minutes and on/off are group settings; members who joined before this are left alone. The gateway can remove a member (/groups/remove).',
    ]],
    ['v' => '3.77.2', 'date' => '2026-10-15', 'title' => 'View-once messages shown', 'changes' => [
        'View-once photos, videos and voice notes are kept as their own kind and shown in the chat as a note (WhatsApp sends their file to the phone only, never to linked devices); before, they were dropped as empty messages.',
    ]],
    ['v' => '3.77.1', 'date' => '2026-10-15', 'title' => 'Reception gate: open the case after the move', 'changes' => [
        'When a member who went through the reception gate is added to another of our groups, the healer who added them (else the reviewers) is tagged in the chosen cases group with the member\'s name, the group and "#case +number" to open the file (template gate_moved; group setting gate_notify). Once per member.',
    ]],
    ['v' => '3.77.0', 'date' => '2026-10-15', 'title' => 'Reception gate and one welcome', 'changes' => [
        'Reception gate (group setting): a new member who writes "woman" / "man" is asked once, quoting them, to record Al-Fatiha or Ayat Al-Kursi in their own voice (templates gate_ask_f / gate_ask_m); their recording tags the reviewers to check it and move them (template gate_voice). Words and reviewers are editable per group; the team is never asked. The gateway passes every message of a gate group.',
        'Members who join close together get one welcome with all their mentions, and a member welcomed in the last 7 days is not welcomed again.',
    ]],
    ['v' => '3.76.9', 'date' => '2026-10-14', 'title' => 'Emoji replies in private chats', 'changes' => [
        'Private chats: the last message id is taken from the thread when the chat list has none, so emoji-only messages get their reaction (and short line) again, and file shortcuts quote the message.',
    ]],
    ['v' => '3.76.8', 'date' => '2026-10-14', 'title' => 'Groups: role thresholds', 'changes' => [
        'In groups the model\'s chosen mode is used, and healer, support and dreams replies are sent from 0.65 as in private chats (they used the general threshold only).',
    ]],
    ['v' => '3.76.7', 'date' => '2026-10-14', 'title' => 'Bot: no made-up verses or adhkar; keeps talking', 'changes' => [
        'After each reply, a verse, surah or dhikr found in no team answer and no source is removed by one rewrite; if it stays the reply is not sent. The words that start them are a business setting (sacred_markers).',
        'Only questions the customer left unanswered count toward the clarify limit: a real back-and-forth is no longer handed to the team after the first question.',
        'The number\'s own name after "the sheikh" is removed from replies.',
    ]],
    ['v' => '3.76.6', 'date' => '2026-10-14', 'title' => 'Bot brain: short, like the healer', 'changes' => [
        'The bot copies the closest team answer: its length, directness and firmness; no greeting unless greeted, no thanks, closing lines or fillers; usually 1-3 lines (replies 20% shorter). Advice only from approved sources or team answers; adhkar and duas only those the team already used; no tafsir without a source; dreams get two short readings (spiritual and general) then one question.',
    ]],
    ['v' => '3.76.5', 'date' => '2026-10-14', 'title' => 'File shortcuts with spaces', 'changes' => [
        'A file shortcut is found however it is written: with a dash or spaces (#رقية-عامة / #رقية عامة) and after the send word (#ارسال #رقية عامة), in groups and from the phone. The shortcut is checked before the other library commands, so #رقية … with a file code sends the file.',
    ]],
    ['v' => '3.76.4', 'date' => '2026-10-14', 'title' => 'Cases: one file per person', 'changes' => [
        '#case for a number that already has a file: no second file and no questions. Nothing new → a short reply with the file number and follower (template case_intake_exists). New details or a recording → added to the same file, the update goes to the diagnosis group (template case_intake_updated).',
    ]],
    ['v' => '3.76.3', 'date' => '2026-10-14', 'title' => 'Gemini limits per key', 'changes' => [
        'With several Gemini keys, each key keeps its own count for each model (every key is its own Google project): the keys are used one after the other, and when a model reaches its limit on one key the next key still uses it.',
    ]],
    ['v' => '3.76.2', 'date' => '2026-10-14', 'title' => 'Gemini free limits per model', 'changes' => [
        'Gemini: each model\'s free limits (a minute and a day, as on the AI Studio page) are counted before sending; a model at its limit is skipped until the next minute or the next Pacific day, instead of waiting for Google\'s 429. Flash-Lite (500 a day) is the default; Flash (20 a day) next; image, audio, live and agent models are not used for text.',
    ]],
    ['v' => '3.76.1', 'date' => '2026-10-14', 'title' => 'Bot brain: no made-up counts', 'changes' => [
        'After each reply, a count or a duration (times, days, hours…) that appears in no team example and no source is treated as made up: the model rewrites the reply once without it; if that fails the reply\'s confidence drops so it is not sent. Count words per business profile (count_units).',
    ]],
    ['v' => '3.76.0', 'date' => '2026-10-14', 'title' => 'New bot brain', 'changes' => [
        'Healing numbers: one reply prompt (bot_brain) where the model picks the mode itself (healer, support, dreams, teacher, assistant) instead of keyword routing, takes the healers\' closest real answers as the main truth (same method and counts, nothing added), answers first and hands over only when nothing covers it. Danger words still stop the bot before the AI. Switch per number: bot setting brain.',
        'Measured with a new bot test (the bot test suite: 24 real questions with the healer\'s answer, graded by a model): correct 2.79 → 4.13, helpful 3.88 → 4.67, style 4.13 → 4.75, grounded 4.25 → 4.46, overall 4.01 → 4.60, median reply 11 s → 7 s.',
    ]],
    ['v' => '3.75.0', 'date' => '2026-10-14', 'title' => 'Data, media and WhatsApp sessions private to the account', 'changes' => [
        'Checked on the panel itself that PHP-FPM runs as the account user (it read a file private to the account). Then: the data folder, the media folders (inbox_media, uploads, payment receipts) and the WhatsApp session folders are private to the account (0700 / 0600); the request-time loop that opened the data folders to everyone is gone and everything the app creates is private. Panel, install lock, bot hook, gateways, minute jobs and QA checked after each step.',
    ]],
    ['v' => '3.74.2', 'date' => '2026-10-14', 'title' => 'List commands from the bot\'s phone', 'changes' => [
        'Typed from the bot\'s own phone, "#قائمة #المكتبة" (and #الحالة, #الاختصارات, #البرامج) did nothing — only the menu and file shortcuts were handled there. The typed message now turns into that list (wf_intake_list_text). In groups the list replies tag the person who asked. "#ارسال #رقية-عامة" sends the file of that shortcut.',
    ]],
    ['v' => '3.74.1', 'date' => '2026-10-14', 'title' => 'The commands list answers to any close spelling', 'changes' => [
        '#menu works in any letter case (#Menu, #mEnu, #MENU), with its other names (#منيو, #مينيو, #الأوامر, #help, #قائمه…; preset words.menu_alias), with a small typo (#mnue, #menuu) and for the list word alone or with a word it does not know (#قائمة, #قائمة #حاجة) — in groups and from the bot\'s phone (wf_is_menu). A real list command (#قائمة #الحالة) still gives that list.',
    ]],
    ['v' => '3.74.0', 'date' => '2026-10-14', 'title' => 'Library inventory', 'changes' => [
        'Media library → 📋 Inventory: every file (kind, shortcut, name, folder, size, times sent; shortcuts first, searchable), the counts per kind and how many are named / have a shortcut, the quick replies and the programs — built on request, so always the latest; Download (Excel) gives the whole list as a CSV sheet (API media_inventory, fmt=csv).',
        'In WhatsApp: "#قائمة #المكتبة" sends the inventory (counts, every file shortcut with its name, quick replies, programs; template lib_inventory); #menu lists it.',
    ]],
    ['v' => '3.73.0', 'date' => '2026-10-14', 'title' => 'New forward / send dialog', 'changes' => [
        'Forward and send from the library: a card of what is sent (thumbnail or player, name, size, caption; several files: count and total size; text: editable), search with All / Chats / Groups, rows with photo, name and number or group mark and a tick, the chosen ones as removable chips on top, a sticky footer with the count and Send (full width on phones).',
    ]],
    ['v' => '3.72.5', 'date' => '2026-10-14', 'title' => 'File size on recordings', 'changes' => ['Recordings library cards show each recording\'s size next to its duration.']],
    ['v' => '3.72.4', 'date' => '2026-10-14', 'title' => 'Data permissions back as before', 'changes' => [
        'Making the data private (3.72.2) assumed the website already ran as the account user; the panel still runs as the old web user, could not read its data and showed the install page. Folder and file modes and the code that sets them are back as before until the host confirms PHP-FPM for wa-inbox.',
    ]],
    ['v' => '3.72.3', 'date' => '2026-10-14', 'title' => 'Gemini free plan used within its limits', 'changes' => [
        'Gemini on the free plan (Flash ~5 a minute / 20 a day, Flash-Lite 15 / 500): its Flash-Lite models go first (catalog free_prefer), and background work (bot learning, file checks) takes the first working provider that is not on a small free daily quota (wf_fast_model(true), catalog free_daily_small), so Gemini\'s free quota is kept for the bot\'s replies.',
    ]],
    ['v' => '3.72.2', 'date' => '2026-10-14', 'title' => 'Data private to the account (PHP-FPM)', 'changes' => [
        'PHP now runs as the account user (PHP-FPM, checked by the session files), so the website and the background jobs share one user. The data folder and everything the account owns in it are private (0700 / 0600); the request-time loop that opened the data folders to everyone (0777 / 0666) is gone, and files and folders the app creates are private. 17 old files and 4 folders still owned by the former web user sit inside the private folder (unreachable from outside).',
    ]],
    ['v' => '3.72.1', 'date' => '2026-10-14', 'title' => 'A system message no longer hides the customer\'s message', 'changes' => [
        'When a login code or an alert arrived after a customer\'s message, the whole chat was skipped and marked answered, so the customer\'s message (a dream) never got a reply. System messages are now left out and the customer\'s messages before them are answered; only a chat with nothing but system messages is skipped.',
    ]],
    ['v' => '3.72.0', 'date' => '2026-10-14', 'title' => 'The bot behaves like a person; one commands list', 'changes' => [
        'General rule: the bot stops only while a real person from the team is talking in that chat — for human_wait minutes (10 on the healing number) after the team\'s last message that came after the bot\'s last reply; with no team activity in that time it carries on. Only bot and customer: it never waits. The hold message step is gone. Groups: a member an admin is talking with waits until the admin has been quiet that long. Gathering, per-chat and per-hour limits keep it from flooding.',
        '#menu also lists the files\' shortcuts (with their names) and the quick replies\' keywords (30 each, then a count); commands of the healers (group admins) with # are answered as before.',
    ]],
    ['v' => '3.71.2', 'date' => '2026-10-14', 'title' => 'Role replies are sent from 0.65', 'changes' => [
        'Dream interpretations and caring replies rarely reach the 0.8 confidence of factual answers (a 0.7 dream reply stayed a draft). In the healer, dreams and support roles a reply is sent from 0.65 (or the number\'s own threshold if lower).',
    ]],
    ['v' => '3.71.1', 'date' => '2026-10-14', 'title' => 'Browsers load the latest screens after every update', 'changes' => [
        'The page linked app.js, features.js and app.css with an empty version (?v=), so phones kept old copies after updates (English words, missing buttons). The links now carry each file\'s change time.',
    ]],
    ['v' => '3.71.0', 'date' => '2026-10-14', 'title' => 'File shortcuts', 'changes' => [
        'A library file (recordings first) can have a name and a shortcut such as #shortcut-name (media_meta code, unique per number; spelling variants and -/space alike). Writing the shortcut alone in a group, in a private chat (the customer) or from the bot\'s own phone in any chat sends the file there with its caption — no AI. Set it from ✏️ Name & shortcut on the recordings/library cards or in the media library editor.',
    ]],
    ['v' => '3.70.4', 'date' => '2026-10-14', 'title' => 'Security: meetings and Google separated per client', 'changes' => [
        'Meetings: a meeting is reachable only through numbers the user may use (wf_meet_acc_ok); being listed as host or creator no longer opens another tenant\'s meeting (Meet link, guest names and phones, open/close, approvals); a meeting can no longer be saved without a number; a client can open/close its own meetings. The settings overview (operator\'s Google, users, AI, backups) is super admin only.',
        'Google: a client\'s sign-in no longer overwrites the operator\'s Google connection (the global refresh token is written only for the operator); the callback takes the workspace only from its one-time state; a team user always uses their client\'s Google, and a user without a record gets none (never the operator\'s). The operator\'s connection was checked and is still the operator\'s own.',
    ]],
    ['v' => '3.70.3', 'date' => '2026-10-14', 'title' => 'Bot: our own group names, only approved knowledge', 'changes' => [
        'The bot is told the real names of this number\'s groups (wf_our_groups: bot groups, case registry, the number\'s groups; cached 10 min) and that any other center or group name in the sources belongs to someone else (a circulated session text signed by another center made it send people to "مركز بسم الله الشافي").',
        'Learned knowledge items feed replies only once approved (by the team, or automatically when several healers said it); pending items no longer speak for the team.',
    ]],
    ['v' => '3.70.2', 'date' => '2026-10-14', 'title' => 'Fix: endless loop in the business profile default', 'changes' => [
        'The 3.70.1 default (healing cases → ruqyah) asked the case settings, which ask the business profile again: numbers without a saved profile looped (pages and bot runs failed for some minutes). It now reads the saved case setting directly.',
    ]],
    ['v' => '3.70.1', 'date' => '2026-10-14', 'title' => 'Bot: roles back, no name mix-ups, case files filled from the talk', 'changes' => [
        'The spiritual-healing number had no business profile saved; its default turned to CRM once it got an owner, which switched off the roles (healer, dreams, support) and the library commands — every message got the plain bot. The profile is pinned to ruqyah, and a number whose cases are set up for healing defaults to ruqyah.',
        'Group replies only see this person\'s own messages and the bot\'s replies to them (the last 6 group messages of everyone mixed people and names up). A rule tells the model that the number\'s WhatsApp name is the team\'s number, never a sheikh to send people to, and to use only names the person or their card gave.',
        'Healer, dreams and support talks in private chats fill the empty fields of the person\'s case file (AI extraction of their own messages; never overwrites what the team entered; a bot note lists what was added).',
    ]],
    ['v' => '3.70.0', 'date' => '2026-10-13', 'title' => 'The bot keeps the conversation going; waits when the team steps in', 'changes' => [
        'Private chats: while only the bot and the customer talk, it keeps answering without waiting. When a team member stepped in after the bot\'s last reply and stopped, the bot waits human_wait minutes, sends the hold message once (template bot_hold, quoting the customer), waits the same again, then carries the conversation on (its own hold message does not count as taking the chat back).',
        'Groups: when an admin talked with a member (a reply or a tag) in the last half hour, that member\'s new message waits the same way (queued in the bot state, looked at every run), then the hold message tagging them, then the bot answers.',
    ]],
    ['v' => '3.69.3', 'date' => '2026-10-13', 'title' => 'Group admins get answers when they ask', 'changes' => [
        'In groups every admin counted as team and was never answered; in a group where the healers are the members (all 36 messages today were from admins) the bot stayed silent. An admin is now answered when they ask a question or tag the bot, and still not while they answer someone else (a reply or a tag of another member).',
    ]],
    ['v' => '3.69.2', 'date' => '2026-10-13', 'title' => 'Background learning keeps going', 'changes' => [
        'A learning job that started at the end of a worker run had no time left, did nothing and was not queued again, so learning stopped. It now carries on in the next run whenever the run was out of time.',
    ]],
    ['v' => '3.69.1', 'date' => '2026-10-13', 'title' => 'Faster bot replies', 'changes' => [
        '⚡ Fast replies used a fixed Gemini Flash-Lite model even when Gemini was at its limit (then a slow fallback: 20–90 s). wf_fast_model now takes the first working provider in the AI order and its quickest model that is not resting (flash-lite, deepseek flash, glm flash, flash, mini…). With OpenCode Go a normal reply went from 23.5 s to 4.8 s and a dream interpretation from 47 s to 21 s. Roles use the quick model too while fast replies are on (strong model when off).',
    ]],
    ['v' => '3.69.0', 'date' => '2026-10-13', 'title' => 'The bot answers emojis like a person', 'changes' => [
        'A message with no words (emojis) is no longer skipped: private chats get a reaction and, for some, a short line (🤲 → آمين يارب, 😢 → a caring line, 👋 → a welcome); groups that the bot answers fully get a reaction only. No AI is used. Rules per business profile (emoji_replies: "emojis => reaction | reply", * = any other), same hours and limits as replies.',
    ]],
    ['v' => '3.68.1', 'date' => '2026-10-13', 'title' => 'A model at its limit gives way to the next model', 'changes' => [
        'The model chain took the best 4 ranked models and only then removed the ones resting after their limit; when all 4 were resting (Gemini Flash models at 20 requests a day) only the old default model was left and Flash-Lite (500 a day) was never tried. Resting and already-tried models are now skipped before the chain is filled.',
    ]],
    ['v' => '3.68.0', 'date' => '2026-10-13', 'title' => 'Several keys per AI provider', 'changes' => [
        'An AI provider can have extra keys (up to 10, one per line, stored encrypted). A request uses the first key that is not resting; when a key reaches its limit (429, after the per-model fallbacks), has no credit (402 / quota 0) or is refused (401/403), it rests (15 min / 6 h / 24 h, ai_key_cool.json) and the next key takes over in the same request. The card shows how many extra keys are saved and can remove them.',
    ]],
    ['v' => '3.67.3', 'date' => '2026-10-13', 'title' => 'AI keys readable by the website and the background jobs', 'changes' => [
        'The AI key file (.data/ai.key) was readable only by the account user while the website runs as another user: keys saved from the website were encrypted with an empty key the bot jobs could not open (OpenCode Go looked saved but was never used), and the website could not open keys saved by the jobs. The file is now readable by both, an unreadable key file stops the save instead of using an empty key, and keys saved the broken way are still opened.',
    ]],
    ['v' => '3.67.2', 'date' => '2026-10-13', 'title' => 'Learning accepts a plain list from the model', 'changes' => [
        'Some models (Gemini Flash-Lite) answer the learning prompt with the list itself instead of {"items": […]}; every such batch was counted as failed and nothing was saved. Both shapes are accepted now.',
    ]],
    ['v' => '3.67.1', 'date' => '2026-10-13', 'title' => 'Keyless Kilo really sends; background learning waits out AI outages', 'changes' => [
        'The request step still refused a provider without a key ("API key is missing") although Kilo\'s free models need none (key_optional); fixed. Background learning (kb_learn) waits 1, 2, 3… minutes after an AI failure, stops only after 6 in a row, and continues next run whenever it made progress.',
    ]],
    ['v' => '3.67.0', 'date' => '2026-10-13', 'title' => 'Bot learns from the healers in every group; files checked against the groups', 'changes' => [
        'Learning collects the question → answer exchanges from every group of the number too (learning setting all_groups, on by default), not only the bot groups marked "learn"; the answers of the team and the group admins (the healers) count. Each exchange is tagged with its bot role (healer, support, dreams) and a role reply takes its style examples from that role first.',
        'Collect and learn are functions (wf_learn_collect, wf_learn_distill); Bot learning has "Learn everything in the background (all groups)": worker job kb_learn reads everything again and distills it 20 by 20 until done (continues next run).',
        'Uploaded learning files are checked against the healers\' real answers (job doc_verify, prompt doc_verify, after every upload or on demand): each passage is agree / conflict / unrelated; conflicting passages are never used, agreeing ones rank first and are labelled "matches the team"; rule for every role: if a file and a team answer differ, follow the team. The file list shows ✅ / ⛔ / ⏳ per file.',
    ]],
    ['v' => '3.66.1', 'date' => '2026-10-13', 'title' => 'Clearer #menu', 'changes' => [
        '#menu is grouped (cases, lists, library) and every command shows what it does, how to write it and a real example; wording in the case presets (words.help: title, how, example; {case}/{many}/{cmd}) and the frame in template cmd_menu.',
    ]],
    ['v' => '3.66.0', 'date' => '2026-10-13', 'title' => 'Group replies tag the person; #menu', 'changes' => [
        'Every bot reply in a group starts with @ the person it answers and tags them (AI replies, case questions and confirmations, #الحالة / stage / list commands, library commands — a file keeps its own caption), so replies to different people never get mixed up (wf_group_tag).',
        'New command #menu (case presets words.menu, help texts words.help, template cmd_menu): in a group the bot answers with every command this number has and what it does; typed from the bot\'s own phone in any chat, the message turns into the list.',
    ]],
    ['v' => '3.65.5', 'date' => '2026-10-13', 'title' => 'Dead code removed', 'changes' => [
        'Removed functions nothing called: wf_backup_run and wf_backup_build (the old single-file Drive backup, replaced by the incremental backup job) and wf_row_is.',
    ]],
    ['v' => '3.65.4', 'date' => '2026-10-13', 'title' => 'Two gateway fixes from the review', 'changes' => [
        'Busy groups: the bot waits for a 3 s pause before answering, but now at most 20 s after the first unanswered message (it never answered while messages kept coming).',
        'A message that arrives late (phone offline) keeps when it reached us (got); a chat deleted before that no longer hides it, in the chat and in the recycle bin count.',
    ]],
    ['v' => '3.65.3', 'date' => '2026-10-13', 'title' => 'Error messages in the user\'s language', 'changes' => [
        'wi_json translates every error through the dictionary in one place, so API and gateway errors ("Choose a conversation.", "WhatsApp is not connected.", …) show in Arabic on Arabic panels and stay English on English ones. 98 missing Arabic error texts added to i18n.php; messages built with a code (HTTP …) stay as they are.',
    ]],
    ['v' => '3.65.2', 'date' => '2026-10-13', 'title' => 'Chat notes, labels and assignment saved under the lock', 'changes' => [
        'Adding or deleting a chat note, toggling a chat label and assigning a chat now change the stored data inside wi_update (row lock) instead of read → change → write of the whole set, so two people working on the same chat at the same moment never undo each other.',
    ]],
    ['v' => '3.65.1', 'date' => '2026-10-13', 'title' => 'Restructuring step 3: one request context', 'changes' => [
        'The three loose request globals (the number served on the shared Cases page, the number whose AI keys are used, the page an API call belongs to) are set with wi_ctx_set and read with wi_ctx in one place (lib.php); wi_current, wi_ai_scope, wf_admin, the API router, the bot runner and the background jobs use it. unit.php checks the switch and that no loose globals come back.',
        'Measured before changing: the learning pairs (1.7 MB) are only written by the Learn action, and the gateway answers its archive requests in 0.17–0.54 s on the 31 MB archive — left as they are for now.',
    ]],
    ['v' => '3.65.0', 'date' => '2026-10-13', 'title' => 'Restructuring step 4b: bot log and AI activity in their own tables', 'changes' => [
        'Bot log (415 KB, rewritten on every bot action) and AI activity (rewritten twice per AI call) are rows in wi_bot_log and wi_ai_tasks: wi_bot_log_add / wi_bot_log and wi_ai_task_put / wi_ai_tasks. Appending is one insert; the newest 500 / 400 are kept (trimmed now and then). The kept entries moved over on first use (282 and 400); without a database the old sets are used.',
        'All readers (home, bot page, chat history, roles stats, learning, clients, AI activity) use the new functions; the data backup copies both tables.',
    ]],
    ['v' => '3.64.2', 'date' => '2026-10-13', 'title' => 'Restructuring step 4a: cases saved row by row', 'changes' => [
        'Database cases store: a save wrote every case and rewrote every note of every number. It now writes only the cases this request changed (compared with what it read) and deletes only cases it read and dropped; a case or a change another request saved meanwhile is never overwritten or removed. Without a prior read it falls back to the full write.',
    ]],
    ['v' => '3.64.1', 'date' => '2026-10-13', 'title' => 'Cases store: every writer goes through its owner', 'changes' => [
        'The last direct cases.json reads/writes outside modules/cases.php now use wf_case_get / wf_case_update / wf_cases_update: private-chat case linking by the bot, report labels to cases, group stage commands, the case opened from a group, role notes, the ID backfill (wf_cases_number_backfill). The labels write no longer rewrites the whole store it read earlier.',
        'unit.php checks that only the owner touches cases.json.',
    ]],
    ['v' => '3.64.0', 'date' => '2026-10-13', 'title' => 'Restructuring steps 1 and 2: one owner for cases, explicit closures', 'changes' => [
        'Cases store has one owner (modules/cases.php): wf_case_get, wf_case_update (one case, under the lock), wf_cases_update (one number). The long cases sync no longer writes back the whole store it read minutes earlier: only the fields it counts (messages, media, groups, first/last, LIDs, name, customer) are applied to the stored cases under the lock, so notes, stages and new cases saved meanwhile are kept. Case edit (case_set), tracking from a group and registry cases change the stored case under the lock.',
        'All 21 closures that captured every local variable (get_defined_vars + extract) in the bot runner, bot learning, guide growth, library cleaning and the worker now name exactly what they use.',
    ]],
    ['v' => '3.63.5', 'date' => '2026-10-13', 'title' => 'Restructuring step 0: tests without the gateways', 'changes' => [
        'wi_gateway accepts a stand-in in CLI tests ($GLOBALS[WI_FAKE_GATEWAY]): calls are recorded and answered locally, nothing reaches WhatsApp. A new unit test suite runs the domain functions in-process in under a second (keywords and spelling, case targets, role words, library matching, case IDs without saving, follower, sending through the stand-in). wi_case_numbers can run without saving the counter.',
    ]],
    ['v' => '3.63.4', 'date' => '2026-10-13', 'title' => 'Library sends are queued in the gateway', 'changes' => [
        'The host kept the page waiting until every file was sent (the early reply was buffered). media_send and forward_text now hand the list to the gateway (new POST /chat/send-batch, max 30, queue of 300) and answer at once; the gateway sends them one by one 3–6 s apart, retrying once after the per-minute limit.',
        'Library page (audio and texts): ↪ Send to chats and groups on every card. Picker: an unnamed audio shows its date; the size badge no longer covers the name.',
    ]],
    ['v' => '3.63.3', 'date' => '2026-10-13', 'title' => 'Private bot answers group admins', 'changes' => [
        'Group admins counted as team made the bot silent in their private chats too (a client who is an admin of a group never got an answer, e.g. a dream to interpret). They are team inside groups only now; real team members and own numbers are still never answered.',
    ]],
    ['v' => '3.63.2', 'date' => '2026-10-13', 'title' => 'Bot roles: case notes and stats', 'changes' => [
        'A reply sent by the healer, support or dreams role is added to the person\'s case file as a bot note (role, question, reply), in private chats and groups, when they have a case. Bot log rows carry the role.',
        'Bot roles card shows the last 7 days per role: replies sent and handed to a person (API bot_roles returns stats).',
    ]],
    ['v' => '3.63.1', 'date' => '2026-10-13', 'title' => 'Group role: healer and ruqyah teacher; no invention, no repeating', 'changes' => [
        'New role teacher (healer and teacher of the lawful ruqyah and spiritual healing) is the default role in groups (preset group_role, Bot roles card "Role in groups"); a person\'s words can still call another role (support, dreams, healer), kept per person in the group. Danger words in a group: support reply quoting the person and an alert, the group keeps its bot.',
        'Rules for every role and for the regular bot (preset roles_common, editable): facts (verses, hadith, adhkar, ruqyahs, programs, prices, names) only from the sources, else a short question or handoff; never resend or rephrase an earlier reply. Groups now pass the bot\'s last replies there as "avoid".',
    ]],
    ['v' => '3.63.0', 'date' => '2026-10-13', 'title' => 'Bot roles: healer, emotional support, dream interpreter, danger safeguard', 'changes' => [
        'New module bot_roles: the private-chat bot picks a role per message from its words (healer, emotional support, dream interpretation; whole-word match for short words), the chat keeps it for sticky_h hours, else the regular bot answers. The role\'s instructions go before the owner rules, a first-reply note is added once, role answers skip the answer cache.',
        'Roles needing care use a strong model: the first enabled provider of the list (default opencode_go, then gemini) with its chosen model. Danger words stop the bot in that chat, send the fixed support message (template crisis_reply), alert the team (crisis_alert, default the diagnosis group) and pause the bot there.',
        'Defaults live in the spiritual-healing business preset (roles, crisis); Bot page → 🎭 Bot roles changes them per number (API bot_roles). Other profiles have no roles.',
    ]],
    ['v' => '3.62.1', 'date' => '2026-10-13', 'title' => 'Case from a group: the follower is the healer', 'changes' => [
        'The follower of a case opened from a group is the healer named in the details ("المتابع: …", the follower field is now read by the AI), else the healer who wrote it — never this number itself. Written from the bot\'s own phone (gateway sends from_me) with no healer named: the bot asks who follows, like any missing field.',
    ]],
    ['v' => '3.62.0', 'date' => '2026-10-13', 'title' => 'Group intake fixes and library commands', 'changes' => [
        'Case from a group, from the real group history: a message that only mentions the keyword (an explanation) no longer opens an empty case — a new case needs a tagged person or a phone number; spelling variants match (#حاله = #حالة, أ/ا, ى/ي); "#قائمة #حالة" is a list, not a case; sending the details again for the same person continues the waiting case instead of saving it twice; a waiting case with nothing in it is dropped, never saved as an empty file; the voice question is in the group\'s language (preset words.voice).',
        'Library commands in groups, spiritual-healing profile only (business preset lib_cmds): #ارسال <name> sends a media library file (title, sent name, caption or tags), #برنامج <name> a program with its days, #رقية <name> a written ruqyah copied exactly from the text library, #الأوامر the list. Several matches: the bot lists them; templates lib_menu, lib_many, lib_not_found, lib_program_day.',
    ]],
    ['v' => '3.61.1', 'date' => '2026-10-13', 'title' => 'Sending from the library does not wait on the user\'s connection', 'changes' => [
        'media_send and forward_text answer the browser at once (wi_json_then: reply, close the connection, keep working) and send the files from the server one by one; a weak or closed connection no longer matters. The dialogs say it is being sent from the server.',
        'Library grids load video and GIF still frames only when they come on screen, and GIFs no longer play in the grid (much less mobile data).',
    ]],
    ['v' => '3.61.0', 'date' => '2026-10-13', 'title' => 'Chat media, forwarding and phone layout', 'changes' => [
        'Media library and picker: audio and files show their name (title, else the name they were sent with from the archive file_name, else the caption) on a file card instead of a bare icon; the picker plays audio before sending. Gateway /archive/media-kinds also returns names.',
        'Each chat has 📁 Media of this chat: its photos, videos, recordings and files by kind, to open, download (with the real name) or forward. Forwarding: any message, also text (new API forward_text, reply permission, paced, max 10 chats), from a tap on the phone or hover on a computer; the forward dialog has All / Chats / Groups.',
        'Phone: the chat header keeps the name and number readable; actions, chips and tools scroll on their own row; library dialogs are full screen.',
    ]],
    ['v' => '3.60.6', 'date' => '2026-10-12', 'title' => 'Review fixes: security and data safety', 'changes' => [
        'Security: api=media sends the type of the file name, never of the bytes, with nosniff and a sandbox CSP (an HTML file named .pdf/.jpg from a sender can no longer run on the site); profile photo type limited to images. Gateway /chat/send checks the attachment folder after resolving the path (no "inbox_uploads/../").',
        'Case IDs: given also in the database store branch of wi_update, under a named lock (no duplicates); wi_update refuses to write when its lock is not obtained.',
        'Case from a group: the waiting case is saved merged with answers that arrived meanwhile and never brought back after it was finished; a keyword message delivered twice opens one case; an answer with "#" that is not a command is kept; stage commands and the opened case are changed under the lock (dashboard notes are kept); name lookup matches whole words; a recording is used for one case only. Recycle bin hides chats taken by colleagues.',
    ]],
    ['v' => '3.60.5', 'date' => '2026-10-12', 'title' => 'Archive a case', 'changes' => [
        'New stage archived (every preset) and group command "#ارشف <ID | name | number | tag>" (no reason needed, an optional one is kept as a note). Archived cases leave "All" and "#قائمة #الحالة"; they stay under their own stage chip.',
    ]],
    ['v' => '3.60.4', 'date' => '2026-10-12', 'title' => 'OpenCode Go: session header', 'changes' => [
        'OpenCode Go refuses requests without x-opencode-session (HTTP 400). Catalog flag session_header: a stable id per number and day is sent with each request.',
    ]],
    ['v' => '3.60.3', 'date' => '2026-10-12', 'title' => 'AI provider: OpenCode Go (subscription)', 'changes' => [
        'New provider OpenCode Go (opencode.ai/zen/go/v1, OpenAI-compatible): a subscription, so every model of the plan is usable on the free/auto plan setting; default deepseek-v4-flash. Catalog flag skip_models hides models served on another route (GPT Luna, responses API) and experimental ones.',
    ]],
    ['v' => '3.60.2', 'date' => '2026-10-12', 'title' => 'OpenCode Zen: free tier refusal is not a wrong key', 'changes' => [
        'A 403 that refuses the plan (free tier only inside the app, no credit/billing) is read as "no credit" (402), not "invalid key". OpenCode Zen says its free models only work inside the OpenCode app: tagline and hint now say it needs Zen credit (plan Paid) or use Kilo Code.',
    ]],
    ['v' => '3.60.1', 'date' => '2026-10-12', 'title' => 'Every case has an ID', 'changes' => [
        'Case ID (no): 1, 2, 3… per number in opening order, never reused (case_seq.json). Given on every write of cases.json (wi_write), older cases get theirs from the every-minute cron.',
        'Shown on the case card and page; group commands accept it ("#الحالة 12", "#اتحسنت 12 السبب") and the replies and lists show it.',
    ]],
    ['v' => '3.60.0', 'date' => '2026-10-12', 'title' => 'Group commands for case follow-up and lists', 'changes' => [
        'In the groups (and the diagnosis group): "#الحالة <name | number | tag>" posts the case report and stage; "#اتحسنت / #اتأجلت / #اتحذفت / #اتقفلت <who> <reason>" (treatment preset cmds, else "#" + the stage name) moves the case and saves the reason as a note by the writer; without a reason the bot asks for it. Several matches: the bot lists them.',
        'New stages postponed and removed in every preset. "#قائمة #الحالة" lists the cases by stage, "#قائمة #الاختصارات" the shortcuts, "#قائمة #البرامج" the programs. Words in the presets (words), replies are templates (case_status_done, case_not_found, case_many, case_no_reason, list_cases, list_shortcuts, list_programs).',
    ]],
    ['v' => '3.59.0', 'date' => '2026-10-12', 'title' => 'Case from a group: the voice recording', 'changes' => [
        'The case recording is part of the intake: a voice/audio the writer sends while the bot waits, the recording quoted with the keyword, or their recording of the last 10 minutes before it. Missing = asked like any field (ask item "voice", on by default).',
        'Saved on the case (voices: file, by, when, group), shown with players on the case page, and sent to the diagnosis group right after the report. Gateway passes kind/file to caseIntake; a recording alone skips the AI.',
    ]],
    ['v' => '3.58.4', 'date' => '2026-10-12', 'title' => 'Case from a group works without setup', 'changes' => [
        'Group case intake is on by default (only messages with the keyword start a case); a number can still turn it off in Case settings.',
    ]],
    ['v' => '3.58.3', 'date' => '2026-10-12', 'title' => 'Case from a group: the keyword alone is enough', 'changes' => [
        'Intake setting "mention" is off by default: #keyword opens a case with or without tagging the bot; tagging the person still gives the number and their known data. The setting can still require the tag.',
    ]],
    ['v' => '3.58.2', 'date' => '2026-10-12', 'title' => 'Case from a group: tag the bot, then the keyword and the person', 'changes' => [
        'New intake setting "mention" (on by default): a case opens only when the message tags this number (@bot #keyword @person). Gateway resolves tagged LIDs to phone numbers and sends their WhatsApp names (people) and tagged_me.',
        'The tagged person\'s known data fills the form first (their case file, else their WhatsApp name), so the writer is asked only for what is really missing. The writer (healer) stays the follower.',
    ]],
    ['v' => '3.58.1', 'date' => '2026-10-12', 'title' => 'Kilo free models without a key', 'changes' => [
        'Kilo Code gateway answers free models (kilo-auto/free routes to the free ones) without an API key: catalog flag key_optional lets the provider be turned on and checked with no key; no empty Authorization header is sent. Free-plan filter keeps it on free models only.',
    ]],
    ['v' => '3.58.0', 'date' => '2026-10-12', 'title' => 'Recycle bin for deleted chats and messages', 'changes' => [
        'Chats page: "🗑️ Deleted" lists chats and messages deleted from the dashboard (who, when, last text) with Put back. Gateway: GET /trash, POST /trash/restore (markers unhidden / chat_restored, readArchive honours them); delete and put back can repeat (no id dedupe for these markers).',
        'A deleted chat keeps its assignment and labels in chat_trash.json and gets them back on restore. API trash / trash_restore needs the delete_chat (chats) or delete_msg (messages) permission. Media library already had its own recycle bin (Deleted).',
    ]],
    ['v' => '3.57.1', 'date' => '2026-10-12', 'title' => 'AI providers: OpenCode Zen and Kilo Code', 'changes' => [
        'New providers (OpenAI-compatible): OpenCode Zen (opencode.ai/zen/v1, free models ending in -free and Big Pickle) and Kilo Code gateway (api.kilo.ai/api/gateway, OpenRouter-style model list, free ":free" models and kilo-auto/free).',
        'Catalog flags instead of provider ids: free_pattern (free-plan model filter), models_format (reuse a model-list parser) and per_model_limits (429 cools one model, OpenRouter now uses it too).',
    ]],
    ['v' => '3.57.0', 'date' => '2026-10-12', 'title' => 'Open a case from a group with a keyword', 'changes' => [
        'Groups: a member writes the keyword (default "#" + what one case is called, e.g. #حالة) with the details; the AI fills the case form fields (prompt case_intake), asks the writer once in the group what is missing (template case_intake_ask, quoting them) and reads their next messages for the wait time.',
        'Answered or not when the time is up (cron), the case is saved (stage 2), its report goes to the diagnosis group (chosen, else the first case-registry group) and the writer is told (template case_intake_done). Number: written in the details or the first person tagged; without one the case gets its own record.',
        'Cases → Case settings: on/off, keyword, wait minutes, diagnosis group, groups where it works, which fields are asked, confirmation. Off by default. Gateway: caseIntake (only "#" messages or a writer being waited for reach the Inbox); messages the gateway sends are never read back as answers. Case report hides an empty number line.',
    ]],
    ['v' => '3.56.2', 'date' => '2026-10-12', 'title' => 'Media library: rename files and captions, correct statistics', 'changes' => [
        'Media library: ✏️ on every file renames it (name + caption) in one step; several selected files can be renamed at once (name 1, name 2…, optional same caption). Downloads use the file name.',
        'media_meta only changes the fields sent, so a quick rename keeps folder, tags and favourite.',
        'Statistics: folder counts showed NaN (a text passed to the number formatter) — fixed; folder counts no longer change with the kind filter; the space card shows files and % per kind and the size waiting in Deleted; tiles without a name show their caption.',
    ]],
    ['v' => '3.56.1', 'date' => '2026-10-12', 'title' => 'Media library: find and remove duplicates', 'changes' => [
        'Media library: 🧬 Duplicates finds the same file saved more than once (same bytes; only same-size files are read, hashes cached in media_hash.json).',
        'One healthy copy is kept per file (opens, not empty; favourite, most sent, with caption/title/folder, else the oldest) and takes over the others\' tags, caption and send count; the extra copies go to Deleted (can be put back). Employees cannot remove.',
    ]],
    ['v' => '3.56.0', 'date' => '2026-10-12', 'title' => 'Upload videos to YouTube from the media library', 'changes' => [
        'Media library: ▶️ Upload to YouTube on a video (title, description, tags, who can watch) or on several selected videos (each with its library title and caption). Uploaded in the background to the channel of the connected Google account (yours, or the client\'s own from My account); the status and the youtu.be link are kept on the file, with "Copy link"; uploaded videos carry a red ▶ in the grid.',
        'Google sign-in now also asks for YouTube upload; connect Google again to allow it (and enable the YouTube Data API v3 in Google Cloud). Clear messages for a missing permission and for YouTube\'s daily limit.',
        'Fix: saving a file\'s title / caption no longer drops its other data (send count, YouTube status).',
    ]],
    ['v' => '3.55.7', 'date' => '2026-10-12', 'title' => 'Media library: space used', 'changes' => [
        'A "Space used" panel above the folders: the library\'s total and each kind (stickers, photos, videos, voice notes, audio…) with a bar; every folder shows its file count and size; each file shows its size.',
        'Sort the media by newest, oldest, largest or most sent (remembered), with the count and size of what is shown.',
    ]],
    ['v' => '3.55.6', 'date' => '2026-10-12', 'title' => 'Bot ending emojis follow the type of business', 'changes' => [
        'The starting ending-emoji words of the message style keep 🤲 (يشفي، اللهم…) and 📖 (سورة، آية…) for the spiritual type only; CRM, IT support and general numbers start with 🙏 ✅ ⏰ 📌. A number that saved its own style keeps it.',
    ]],
    ['v' => '3.55.5', 'date' => '2026-10-12', 'title' => 'Media library: bulk actions', 'changes' => [
        'With files selected: 📤 Send to chats (several files to several chats; each file goes with its own caption; at most 30 messages at once, paced one by one), 🏷️ Add tags, ✍️ Same caption, ⬇️ Download (one .zip, kept in their folders), besides Move, Favourite and Delete.',
    ]],
    ['v' => '3.55.4', 'date' => '2026-10-12', 'title' => 'Media library: folders, sub-folders, move and delete', 'changes' => [
        'A folder tree beside the media: All, Favourites, Not in a folder, your folders with sub-folders (create inside any folder, rename, delete — files of a deleted folder move up, never lost) and Deleted.',
        'Select files (tick on each, or "Select all shown") and act on all of them: Move to folder (or a new one, e.g. Offers/2026), Favourite, Delete.',
        'Delete only takes a file out of the library — it stays in the chats — and «Deleted» puts it back.',
    ]],
    ['v' => '3.55.3', 'date' => '2026-10-12', 'title' => 'Media library sorted by kind', 'changes' => [
        'The media library uses the real kind of each file from WhatsApp and is sorted into Stickers, Photos, GIFs, Videos, Voice notes, Audio and Documents: one tab per kind with its count, and under "All" a section per kind (24 shown, "Show all" for the rest).',
        'Stickers show on a checkered background; GIFs play silently in the grid.',
        'Sending from the library: a sticker goes as a WhatsApp sticker and a GIF as a GIF (before they went as a photo / a video).',
    ]],
    ['v' => '3.55.2', 'date' => '2026-10-12', 'title' => 'Opening a big chat is faster', 'changes' => [
        'The gateway skips the lines of other chats without reading them when one chat is opened: a group with 12 000 messages opens in about 165 ms instead of 255 ms, with exactly the same messages.',
        'QA: a check that fails when new Arabic wording is typed into the code instead of a setting (324 existing literals recorded as the starting point).',
    ]],
    ['v' => '3.55.1', 'date' => '2026-10-12', 'title' => 'Payment bot words are settings', 'changes' => [
        'The payment bot\'s approve / reject commands and the money words used to find amounts are no longer fixed in the gateway: Settings → Gateway rules → Payment bot (same starting values: موافق، اعتماد، رفض، approve… / جنيه، ريال، EGP، $…).',
    ]],
    ['v' => '3.55.0', 'date' => '2026-10-12', 'title' => 'Media library: captions, folders, send again and forward', 'changes' => [
        'Library → 🖼️ Media: every photo, video, recording and file of the number (once each) in a grid with search, type filter, folders and favourites. Each item gets a title, a caption (sent with it), a folder and tags; it shows how many times it was sent; it can be hidden from the library.',
        'Send again: from the Media page («Send to chats…», up to 10 chats, paced one by one) or from a chat with the 🖼️ button next to 📎 (pick, edit the caption, send).',
        'Forward: ↪ on any photo, video or file in a chat sends it to other chats with a caption.',
        'Gateway: a number may send files from its own media folder only (never another number\'s); videos are sent as videos with their caption.',
    ]],
    ['v' => '3.54.2', 'date' => '2026-10-12', 'title' => 'Bot: never answers the system\'s own messages, never invents names', 'changes' => [
        'The bot recognises the messages the system sends by itself (login code, alerts, reports, reminders, subscription notices — from the message templates, in both languages) and never answers them, even from a test number. Before, login codes sent between two numbers of the system got bot replies.',
        'New message-style rule: call the person only by the name the system knows (or the one they gave in the chat); never guess or reuse another name (the bot had called a person by another person\'s name).',
    ]],
    ['v' => '3.54.1', 'date' => '2026-10-12', 'title' => 'Chat list from memory', 'changes' => [
        'The gateway keeps the chat list ready and rebuilds it only after a new message is archived (or every 2 minutes for names): on the busiest number it went from about 380 ms to a few ms.',
    ]],
    ['v' => '3.54.0', 'date' => '2026-10-12', 'title' => 'Chats: faster, and made for phones', 'changes' => [
        'A conversation loads its newest 300 messages instead of everything (a busy group went from about 4 MB to 130 KB per load); "Older messages" at the top loads more and keeps your place. A new message no longer reloads thousands of old ones.',
        'On a phone the chat list and the open chat each take the whole screen, like WhatsApp: tap a chat to open it, ‹ or the phone\'s back button to return. The header and the box to write stay in view, the tools row scrolls sideways, and the contact panel opens full screen.',
    ]],
    ['v' => '3.53.1', 'date' => '2026-10-12', 'title' => 'Bot log back where it was', 'changes' => [
        'The bot log is kept as before (one protected data set, saved with the no-lost-changes merge) — its separate table was removed after moving all 536 entries back unchanged.',
    ]],
    ['v' => '3.53.0', 'date' => '2026-10-12', 'title' => 'Data: no lost changes, bot log in its own table', 'changes' => [
        'Every save of a data set now checks whether someone else changed it after it was read; if so, both changes are merged (each side keeps what it changed) instead of the later save wiping the other. This covers the ~120 places that save settings, notes, bot settings, meetings, learning and the rest.',
    ]],
    ['v' => '3.52.1', 'date' => '2026-10-12', 'title' => 'Structure: the API split by topic', 'changes' => [
        'app/api.php (887 lines) is now a short router (permissions, chat permissions, module APIs) that includes one file per topic in app/api/: chats, teamwork, audio, invoices, summary, library AI review, library tools, AI providers, library and lists. 48 answers compared before / after: identical (only live data such as new messages differs).',
    ]],
    ['v' => '3.52.0', 'date' => '2026-10-12', 'title' => 'Structure: front-end split into parts', 'changes' => [
        'features.js (2 160 lines) and app.js (1 400 lines) are now built from one source part per page: src/features/*.js (36 parts) and src/app/*.js (25 parts). build-js.php joins them; the built files were checked byte-for-byte identical to the old ones, so nothing changes in the browser.',
        'Rule: edit the part in src/, then run build-js.php; the QA agents check that the built files are up to date.',
    ]],
    ['v' => '3.51.2', 'date' => '2026-10-12', 'title' => 'Spiritual (ruqyah) type: super admin only', 'changes' => [
        'The spiritual (ruqyah) business type is never offered to clients or other owners: Business settings lists it only for the super admin, and the server refuses it otherwise.',
        'The super admin can give it to a client by hand: Clients → Edit plan → «Allow the spiritual type».',
    ]],
    ['v' => '3.51.1', 'date' => '2026-10-12', 'title' => 'Test suite (QA agent) and two fixes it found', 'changes' => [
        'An automated tester (outside the product) tests every page and API as every person — syntax, private numbers, employee limits — and reports failures with step, cause and fix. No test code ships with the product.',
        'Fix (found by the tests): the "What\'s new" page broke the cache-busting of app.js / features.js (a loop variable overwrote it), so browsers could keep old scripts after an update.',
        'Fix (found by the tests): the Clients page warned for a client without an end date.',
        'JSON APIs read the request body through one helper (wi_input) so the tests can call them.',
    ]],
    ['v' => '3.51.0', 'date' => '2026-10-12', 'title' => 'Security and structure review', 'changes' => [
        'Backups: private numbers of other owners are left out of the operator\'s backups (incremental backup, one-shot backup, chat / system / full packages and per-number data); every owner of a private number (not only clients) can back up to their own Google Drive from My account.',
        'The gateway token travels in the Authorization header instead of the address.',
        'Cases: each case is written on its own under a database lock — no more lost changes when the bot and a person edit cases at the same time.',
        'Fix: the case record now saves every field of the number\'s case form (join date, marital status, story, owner, source…); before, only nine old fields were kept.',
        'Structure: index.php (1 900 lines) split into app/api.php, app/actions.php and app/page.php; pages and API answers checked identical before and after.',
        'Settings instead of fixed values: local phone numbers follow Settings → System (country code, local prefix); the busy-hours chart shows the installation\'s time zone; the reasons in number alerts are message templates.',
    ]],
    ['v' => '3.50.1', 'date' => '2026-10-11', 'title' => 'Private numbers: closing the remaining gaps (structure review)', 'changes' => [
        'Daily report: each person gets the report of the numbers they may see (an owner: their own number only), instead of every number going to the admins.',
        'Number alerts (disconnected, back, sending failures) go to the number\'s owner; numbers without an owner keep alerting the admins.',
        'Settings overview, meetings (list, edit, host), the home page meetings and AI counter no longer show private numbers to the super admin.',
        'The shared cases number is never someone else\'s private number; files sent from a chat open only for people of the number that sent them.',
    ]],
    ['v' => '3.50.0', 'date' => '2026-10-11', 'title' => 'Private numbers (fixed production rule)', 'changes' => [
        'A number that has an owner is private: only its owner and the owner\'s own team see it — its chats, files, cases, library, settings and every word of it. Not the super admin, not other admins. "Sign in as" refuses users of a private number the person may not see.',
        'Owners: every number now has an owner, and an owner\'s access to another owner\'s number was removed.',
        'Quick replies, label names and the people a chat can be assigned to are private per owner; a new number starts with its own empty list.',
        'The main number can have an owner (config main_owner).',
    ]],
    ['v' => '3.49.1', 'date' => '2026-10-11', 'title' => 'Business settings: library sections', 'changes' => [
        'Business settings → Library sections: a number with its own library (client, IT support, CRM) edits its sections — icon, name and the words that put a message there. The gateway uses them within a minute, no restart.',
    ]],
    ['v' => '3.49.0', 'date' => '2026-10-11', 'title' => 'IT support business type; numbers set to their real business', 'changes' => [
        'New business type «IT — technical support»: tickets (🎫 التذاكر) new → being solved → waiting for the customer → solved, support wording (problem, details, cause / fix, support group, maintenance plans) and a support library (fixes, guides, network and internet, policies and prices, FAQ).',
        'The main number has its own rules file (wa-local/rules.main.json), so the other owners keep their shared library.',
        'Switching the type in Business settings also rewrites that number\'s library and content rules (healing goes back to the shared ones); restart its gateway to apply.',
    ]],
    ['v' => '3.48.1', 'date' => '2026-10-11', 'title' => 'Client numbers: their own library and content rules', 'changes' => [
        'Each client number gets its own gateway rules (wa-local/accounts/<id>/rules.json) once: the shared protections (login codes, stripped links), no Quran / ruqyah tags, and a sales library — offers, prices, delivery and payment, policies and warranty, FAQ.',
        'The gateway reads a number\'s own rules when it has them; the library page and the AI library sorting use them too. The operator\'s numbers keep the shared rules.',
    ]],
    ['v' => '3.48.0', 'date' => '2026-10-11', 'title' => 'CRM for client panels + Business settings', 'changes' => [
        'New business type «CRM — sales and customer service»: records are customers (🤝 العملاء) with the stages new → contacting → offer / negotiation → done and the fields company, interest, deal value, source and owner; 60+ interface words switched from healing to sales wording (symptoms → needs, diagnosis → assessment, reception group → new-customers group…).',
        'Every client (SaaS) number starts as CRM by itself — including numbers already linked that never chose a type. The operator\'s numbers keep their own type.',
        'New page Administration → 🏢 Business settings (client, admins): pick the type of business (CRM / general / healing), the names (business, customer, team, one / many records, icon, follow-up plans) and rename any word of the interface by searching for it.',
        'The records item of the menu follows the open number\'s own name and icon.',
    ]],
    ['v' => '3.47.4', 'date' => '2026-10-10', 'title' => 'Members\' keywords: per person, "# word" accepted', 'changes' => [
        'The waiting time between two answers to the same keyword now counts per person: every member who asks gets the reply; only the same person asking again within keyword_cooldown_min is skipped.',
        '"# تحصين" (with a space after #) is understood like "#تحصين".',
    ]],
    ['v' => '3.47.3', 'date' => '2026-10-10', 'title' => '#تحصين works in the groups', 'changes' => [
        'The members\' keyword is set on the daily program (main number and cases number).',
        'A members\' keyword also turns your own message typed on the phone (#تحصين) into the full reply.',
    ]],
    ['v' => '3.47.2', 'date' => '2026-10-10', 'title' => 'Marital status list without repeats', 'changes' => [
        'The marital status choices of the healing-follow-up profile are four (single, married, divorced, widowed) — the repeated feminine-only entries are gone. A record that already holds one of them still shows it.',
    ]],
    ['v' => '3.47.1', 'date' => '2026-10-10', 'title' => 'Case record: marital status as a choice', 'changes' => [
        'Marital status is an optional choice in the case record (as in the new case form). The choices come from the business profile; a number without its own list gets single / married / divorced / widowed.',
    ]],
    ['v' => '3.47.0', 'date' => '2026-10-10', 'title' => 'Members\' keywords in groups; case fields easier', 'changes' => [
        'Quick replies get a "Members\' keyword": when anyone sends #keyword (e.g. #تحصين) in a group or a private chat, the number answers them with that reply, quoting their message, as if you wrote it. One reply per keyword per number; limits: Settings → System → keyword_per_hour (30) and keyword_cooldown_min (10 — same keyword in the same chat).',
        'Case record and new case form: dates are picked from a calendar; the country is chosen from a list or typed, and filled by itself from the phone number\'s country code; gender is an optional choice; age is a number.',
    ]],
    ['v' => '3.46.1', 'date' => '2026-10-10', 'title' => 'Case record: new design', 'changes' => [
        'Header with the person, their number and counts; the stages as a clickable progress line (one click changes the stage); main actions in front (AI analysis, private chat), export and "not a case" in the ⋯ menu.',
        'Case data uses this number\'s case form fields (Cases → Settings), short fields in a grid and long ones as text areas, saved automatically.',
        'Follow-up notes as a timeline (bot / person, time), notes written by the bot shown in Arabic; Ctrl+Enter adds a note.',
        'AI analysis in a side column that stays in view; conversations with All / Private / Groups filters and a search.',
    ]],
    ['v' => '3.46.0', 'date' => '2026-10-10', 'title' => 'Clients page: plans, end dates, usage', 'changes' => [
        'New page Administration → Clients (super admin): every client with status (active / ends soon / ended / paused), plan, end date, WhatsApp number and its connection, team seats, features, this month\'s messages (in / out / bot), AI calls and tokens, stored media and last activity. Filters, search, "Edit plan", renew +30 / +90 / +1 year, pause / resume and "Sign in as".',
        'End date: when it passes (or the panel is paused) the client and their team cannot sign in (they see why), and the number\'s bot, scheduled messages, programs and backups stop. The archive keeps recording and nothing is deleted. A banner warns the client during the last 7 days.',
        'Reminders 7, 3 and 1 days before the end and the day after it ended: to the admins and (Settings → System → notify_clients) to the client. Message templates "Clients: …".',
        'Usage is counted every hour per number (six months kept). AI usage is counted from today on.',
        '"New client" opens the user dialog with the Client role chosen.',
    ]],
    ['v' => '3.45.1', 'date' => '2026-10-10', 'title' => 'Joining groups: new design', 'changes' => [
        'Bot → Joining groups redesigned: a clear on/off switch, groups as selectable cards with search, "Chosen" filter and choose-all / clear, two option cards for people an admin removed, a short "How it works", counters (chosen, left today) and automatic saving.',
        'The tab now has a short name (it carried the whole description before) and its own address (?p=botrun&tab=join); the other tabs keep their addresses.',
    ]],
    ['v' => '3.45.0', 'date' => '2026-10-10', 'title' => 'Bot adds people to groups', 'changes' => [
        'Bot → Joining groups: choose the groups the bot may add people to. When someone asks to join or rejoin, or says the link does not work, the bot adds them itself (automatic mode, working hours) or sends WhatsApp\'s personal invitation when their privacy blocks it, then tells them what happened.',
        'It knows the difference between someone who left by themselves and someone an admin removed (from the group events history): removed people are added back or left to the team (your choice) and never get the public link, which WhatsApp refuses for them.',
        'Which group: the one they were removed from, else the only chosen group, else the only chosen group they are not in; otherwise the team is asked. Replies are message templates (Bot: …). Daily limit shared with the chat button.',
    ]],
    ['v' => '3.44.2', 'date' => '2026-10-10', 'title' => 'Add to group: removed members never get the public link', 'changes' => [
        'The group events history tells if the person was removed from that group by an admin. Then the public invite link (which WhatsApp refuses for removed members) is never sent: the panel adds them directly, or sends WhatsApp\'s personal invitation when their privacy blocks the add.',
    ]],
    ['v' => '3.44.1', 'date' => '2026-10-10', 'title' => 'Add to group: personal invitation when privacy blocks it', 'changes' => [
        'When the person\'s privacy does not allow adding them, WhatsApp\'s personal group invitation (from the admin) is sent to them automatically — unlike the public link it also works for someone removed from the group earlier.',
    ]],
    ['v' => '3.44.0', 'date' => '2026-10-10', 'title' => 'Add a person to a group from the chat', 'changes' => [
        'Chat header 👥➕ (private chats): add the person to a group this number administers, without saving their number on the phone. If their privacy does not allow it (or they left recently), send the group invite link in the chat with one click (template "Group invite link").',
        'New chat permission "Add people to groups" (supervisor on, employee off by default). Daily limit per number: Settings → System → group_add_day (20).',
        'Fix: groups where this number is an admin were not recognised in groups that use hidden-number ids (LID) — 39 and 46 admin groups now found.',
        'Shortcuts typed on the phone work in groups too (the message is edited into the full reply); {name} stays empty in a group.',
    ]],
    ['v' => '3.43.0', 'date' => '2026-10-10', 'title' => 'Quick replies from WhatsApp on the phone', 'changes' => [
        'Type a shortcut in WhatsApp itself (#تحصين-المكان or /المكان, alone in the message): within a second the gateway edits that message into the full quick reply of this number, with {name} and the other fields filled.',
        'Same matching as the dashboard: the exact name or whole words pointing to one reply only; a shortcut that fits several replies is left as typed.',
    ]],
    ['v' => '3.42.1', 'date' => '2026-10-10', 'title' => 'Quick replies: shortcut by any part of the name', 'changes' => [
        'A shortcut is written by itself as soon as the typed words point to one reply only — whole words of its name or of a trigger, with "-" or "_" taken as spaces (#تحصين-المكان, #المكان, #رش البيت). When several replies fit, the list stays open to pick one.',
    ]],
    ['v' => '3.42.0', 'date' => '2026-10-10', 'title' => 'Client backup to their own Google Drive', 'changes' => [
        'My account → Backup to my Google Drive (clients with the Backup feature): every chat of their numbers plus cases, notes, bot, learning, scheduled messages, meetings and team (no passwords) in one .tar.gz on their own Drive.',
        'Daily at the hour they choose or "Back up now"; photos and recordings optional; the last N copies are kept and older ones removed from Drive.',
        'Runs in the background worker with the client\'s own Google account; nothing of other clients or the operator is included.',
    ]],
    ['v' => '3.41.1', 'date' => '2026-10-10', 'title' => 'Bot: respectful greetings, no unverified claims', 'changes' => [
        'Message style (starting rules, editable in Learning → Rules → Message style): greetings stay neutral — no flattery or emotional words (منورة، نورتي، يا أميرة…), extra formal with women; the bot never says something happened or did not happen without proof in the chat or sources, and never swears to it.',
        'More words removed automatically (يا أميرة، منورة، نورتي، يا ست البنات…). A short phrase built around such a word is removed whole, so no broken sentence is left; a reply that would become empty is not sent.',
    ]],
    ['v' => '3.41.0', 'date' => '2026-10-10', 'title' => 'Each client connects their own Google account', 'changes' => [
        'My account → Google account (client): the client signs in with their own Google; learning imports from Drive (also the background folder sync) and Meet links / recording in their panel use that account, never the administrator\'s.',
        'The client\'s Google token is kept only in the encrypted secrets table; sign-in uses the installation\'s Google app and a one-time state.',
        'The administrator\'s Google account (Settings) stays for the administrator\'s own numbers and the system backup.',
    ]],
    ['v' => '3.40.0', 'date' => '2026-10-10', 'title' => 'Videos and documents downloaded automatically; copy quick replies to a client', 'changes' => [
        'Videos and documents are now downloaded automatically in the background (photos, stickers and recordings as before). Which kinds and the size limit of each: Settings → Gateway rules → Download automatically.',
        'Downloaded videos play in the chat; PDFs, Word, Excel and other documents from the archive open or download (before: only photos and audio could be served).',
        'Duplicates: recordings and videos are matched by length, everything else by content — the same file is kept once.',
        'Super admin on a client\'s number: "Copy my quick replies to this number" in the quick replies window.',
    ]],
    ['v' => '3.39.4', 'date' => '2026-10-10', 'title' => 'Quick replies: easier shortcuts', 'changes' => [
        'A shortcut starts with "/" or "#" (# is easy on an Arabic or phone keyboard) and may have several words.',
        'Typing the full name of a quick reply turns it into its text by itself; Arabic spelling variants (ى/ي, ة/ه, hamza) match.',
        'Suggestions appear while a trigger word is still being typed, not only after it is complete.',
    ]],
    ['v' => '3.39.3', 'date' => '2026-10-10', 'title' => 'Media: photos downloaded automatically, recordings never twice', 'changes' => [
        'Photos and stickers from the history sync are now downloaded in the background too (before: recordings only).',
        'A live download that fails is retried in the background; after every gateway start anything missing is queued again. Files WhatsApp no longer keeps are remembered and not retried.',
        'Live recordings and photos: a file already on disk (same recording, or identical content) is linked instead of downloaded again.',
        'Saved recordings keep their media reference, so the library groups every copy of the same recording into one item.',
    ]],
    ['v' => '3.39.2', 'date' => '2026-10-10', 'title' => 'Privacy between clients (isolation review)', 'changes' => [
        'A client\'s team now works on the client\'s own cases, never on the operator\'s shared cases number.',
        'The invoice system\'s customers (names, invoices, amounts) are never shown in a client panel.',
        'The administrator\'s Google account (Drive import, automatic Meet links, recording) cannot be used from a client panel.',
        'Lists of people (meeting hosts, bot team, clean-up names) show only the people of the open number; page sharing is off inside client panels.',
        'Home page: meetings and AI usage counted for your own numbers only. Reminders can only be closed on their own number.',
    ]],
    ['v' => '3.39.1', 'date' => '2026-10-10', 'title' => 'Team activity and client clean-up', 'changes' => [
        'Users → Team activity (client): who replied, sent files, deleted, assigned, signed in… with a filter per team member and links to the chat.',
        'Deleting a client also deletes their team.',
        'Conversations open directly from a link (?p=inbox&jid=…).',
    ]],
    ['v' => '3.39.0', 'date' => '2026-10-10', 'title' => 'Client teams: supervisors and employees (SaaS step 1)', 'changes' => [
        'A client is the super admin of their own panel: Users → My team adds supervisors and employees, within the number of team users the super admin allows (new field on the client).',
        'Each team user has page permissions (the menu) and chat permissions: reply, files, notes, labels, assigning, quick replies, export, delete messages, delete chats. Role defaults per client ("Save as the role default").',
        'Employee: sees only the chats given to them and the chats nobody has taken; can take a free chat or give back their own; never deletes chats or messages.',
        'Delete a message (for everyone when this number sent it and WhatsApp still allows it, otherwise from this number) and delete a whole chat — from WhatsApp and from the dashboard.',
        'Privacy: quick replies, label names and the people a chat can be assigned to are now separate per client; invoices are never offered inside a client panel.',
        'The menu shows only pages the user may open.',
    ]],
    ['v' => '3.38.2', 'date' => '2026-10-10', 'title' => 'Fix: pages stuck on "Loading…"', 'changes' => [
        'Bot style & model (and other parts using profile examples, case fields or the refresh pace) stayed on "Loading…": three helper functions had landed inside a comment at the top of features.js. Fixed.',
        'Bot settings: "case" now reads "case (complaint)".',
    ]],
    ['v' => '3.38.1', 'date' => '2026-10-10', 'title' => 'Fix: case details', 'changes' => [
        'Opening a case gave "Server error (500)" after 3.38.0; fixed. The Cases page title and first tab use the number\'s own name for cases.',
    ]],
    ['v' => '3.38.0', 'date' => '2026-10-10', 'title' => 'Case settings per number', 'changes' => [
        'Cases → ⚙️ Case settings (client, manager, admin of the number): what an item is called (case, ticket, follow-up…), its icon, the names of the 4 stages, and which form fields show with which names. Ready starts: patient follow-up, customer-support tickets, general follow-up.',
        'The menu, the stage filter, the new-item form and the report posted to the group all use these words. Numbers with the ruqyah profile keep their current wording; others start as general follow-up.',
    ]],
    ['v' => '3.37.1', 'date' => '2026-10-10', 'title' => 'Menu above the viewing-as bar; own numbers are re-linked, not deleted', 'changes' => [
        'The top menu (Studio) opens above the "viewing as" bar: its first item (Conversations) was hidden behind it.',
        'A client or manager cannot delete their own WhatsApp number (deleting and re-adding left duplicate sessions); they use Re-link.',
    ]],
    ['v' => '3.37.0', 'date' => '2026-10-10', 'title' => 'Users & permissions redesigned', 'changes' => [
        'Users as cards: role in colour, numbers (including their own), client features, number of pages; Edit, Sign in as, Delete.',
        'One add/edit window in steps: who → role (cards with a plain explanation) → numbers → features (client) or pages (viewer, grouped, with None / View / Edit for all) → extras, and a live summary of what the user will be able to do.',
        'The name can be changed when editing; a client always keeps the right to link and run their own number.',
    ]],
    ['v' => '3.36.0', 'date' => '2026-10-10', 'title' => 'Client features and their own AI keys; general wording', 'changes' => [
        'Client features chosen by the super admin: Bot (the bot, learning and AI providers), Meetings, Cases, Backup (exports).',
        'A client with the Bot gets their own AI providers page and key store: their number\'s bot and AI tasks use only their keys (never the system\'s).',
        'The word "treatment" was removed from the interface (menu, case form, programs) — the product is general.',
    ]],
    ['v' => '3.35.0', 'date' => '2026-10-10', 'title' => 'Client role: a super admin for their own number', 'changes' => [
        'New role "Client" (super admin creates it): links one number of their own and runs everything of it — chats, groups, broadcast, status, cases, health, library, analytics, numbers, meetings, scheduling.',
        'Never: adding users, changing their own permissions, system settings, AI providers, backup or the global report. The bot and learning pages only when the super admin ticks "Can use the bot".',
    ]],
    ['v' => '3.34.3', 'date' => '2026-10-10', 'title' => 'A subscriber runs everything of their own number', 'changes' => [
        'A manager or admin who linked their own number also gets Cases and Number health for it (cases of their own number, not the shared cases database).',
        'Private chats of a subscriber\'s number no longer join the owner\'s shared case records; number health shows only the user\'s numbers and server resources only to the super admin.',
    ]],
    ['v' => '3.34.2', 'date' => '2026-10-10', 'title' => 'Payment bot option for the owner only', 'changes' => [
        'The "Payment bot" option on a WhatsApp number is shown and changed only by the super admin and is off by default for new numbers (it sends receipts to the owner\'s invoice system).',
    ]],
    ['v' => '3.34.1', 'date' => '2026-10-10', 'title' => 'My account', 'changes' => [
        'Every user (also managers and viewers) has "My account" — click your avatar: change your name, the WhatsApp number for login codes and your password (current password required).',
    ]],
    ['v' => '3.34.0', 'date' => '2026-10-10', 'title' => 'Subscribers with their own number', 'changes' => [
        'Users → Add user → WhatsApp number: "Their own number" — the user (e.g. an Admin subscriber) links one number of their own after signing in; it is private to them and the super admin.',
        'A user without a number yet starts on the WhatsApp numbers page with a short welcome; the numbers list in the form offers only the creator\'s own numbers.',
    ]],
    ['v' => '3.33.4', 'date' => '2026-10-10', 'title' => 'One AI key store for the bot and the invoice system', 'changes' => [
        'The invoice system\'s AI library (payment-receipt checks) now reads and saves the same encrypted store and state/usage tables as the bot; its old copy stays as a fallback. Edit the keys in one place: AI providers.',
    ]],
    ['v' => '3.33.3', 'date' => '2026-10-10', 'title' => 'AI keys stored here, encrypted', 'changes' => [
        'The 5 AI provider keys, their order and mode moved into this system\'s database, encrypted with its own key; the bot and every AI task now use the local engine (Settings → System → AI engine = local).',
        'The AI providers page now edits this store. The invoice system keeps its own copy for payment-receipt checks.',
    ]],
    ['v' => '3.33.2', 'date' => '2026-10-10', 'title' => 'Customers\' What\'s new page; old data files archived', 'changes' => [
        'What\'s new shows a neutral product version (Arabic / English, version_product.php); the super admin can switch to this full development log (Settings → System → changelog).',
        'The 40 JSON files whose data is now in the database were moved to .data/_old_json_20261010 (kept, not deleted).',
    ]],
    ['v' => '3.33.1', 'date' => '2026-10-10', 'title' => 'Size limits and refresh pace are settings', 'changes' => [
        'Settings → System: largest file sent from a chat, learning document, status upload and media in a chat export (MB).',
        'Settings → System: page refresh pace in % — every automatic refresh of the dashboard follows it (useful on a busy server).',
    ]],
    ['v' => '3.33.0', 'date' => '2026-10-10', 'title' => 'Bot status and chats waiting for a reply', 'changes' => [
        'Bot page: a status card (working / off / number not connected / not running / hourly limit, replies this hour, last run, last reply).',
        'New tab "Waiting for a reply": every chat where the customer wrote and the bot did not answer, with the reason; "Answer now" un-pauses the chat and answers it once past the limits.',
        'The anti-repeat / anti-block settings now also appear on the Bot page (they were only on Bot: learning & test). Default replies per chat per hour raised from 4 to 12.',
    ]],
    ['v' => '3.32.2', 'date' => '2026-10-10', 'title' => 'Faster bot replies', 'changes' => [
        'The bot no longer waits a fixed 45 s: the gateway watches the customer typing and asks for the reply once they have been quiet for 6 s (setting), still gathering all their messages into one reply; 45 s is now only the longest wait while they keep typing.',
        'Measured before: about 52-55 s per reply (45 s waiting, ~1 s AI). Expected now: about 8-15 s including the typing indicator.',
    ]],
    ['v' => '3.32.1', 'date' => '2026-10-10', 'title' => 'Quick replies window redesigned', 'changes' => [
        'Two columns: the list (search, count, trigger chips, delete) and the editor; each scrolls on its own and the Save bar stays visible; one column on phones.',
        'Click a reply to edit it (highlighted); the trigger-words help is a clear line under the box; the example comes from the business profile.',
    ]],
    ['v' => '3.32.0', 'date' => '2026-10-10', 'title' => 'All dynamic data in the database', 'changes' => [
        'Every data set that was a JSON file in .data (bot logs, drafts, pauses, learning state, AI providers and tasks, quick replies, notes, programs, meetings, reminders, gateway rules…) now lives in MySQL (wi_kv); cases, reports, users and secrets keep their own tables.',
        'Changes to shared data run in a database transaction, so the web, the cron and the worker no longer overwrite each other.',
        'Only start-up files stay files (database login, keys, install lock) plus copies the gateway reads (config, numbers, bot settings, gateway rules); caches stay files.',
        'Backups export the database data sets next to the table snapshots and import them again on restore.',
    ]],
    ['v' => '3.31.0', 'date' => '2026-10-10', 'title' => 'Business wording out of the code', 'changes' => [
        'Library: topics, sections and their tabs come from Settings → Gateway rules; audio categories, program name and extra summary categories from the business profile.',
        'Business profile "ui_terms": change any interface wording for a number (e.g. Treatment group => Follow-up group); the General profile already uses neutral words.',
        'New-case form: examples, marital-status options, reception-group detection and placement text come from the profile; the preview is the exact report the server posts.',
        'Public meeting pages follow the language of the system messages (Arabic / English) instead of fixed Egyptian text.',
    ]],
    ['v' => '3.30.0', 'date' => '2026-10-10', 'title' => 'Security and reliability review', 'changes' => [
        'Security: permissions can only be passed on within the granter\'s own rights; Drive backup, message templates and the main number are super-admin only; every API checks its page permission and the number it belongs to; real visitor IP behind Cloudflare; the WhatsApp gateway listens only on this server with a constant-time token check; links open only as web pages; the installer cannot run again.',
        'Bot: instant replies right after the gather time, /start works again, a greeting is no longer taken for a repeat, groups respect working hours, the introduction is recorded only after it was sent.',
        'Jobs: learning jobs survive the database idle timeout and never stay "running" after a crash.',
        'Settings instead of fixed values: currency, number format, Gemini safety filter, Node path (gateway.json node_bin), cookie paths; no home-folder paths in the code; neutral wording for learning and file clean-up.',
    ]],
    ['v' => '3.29.1', 'date' => '2026-10-10', 'title' => 'Tokens stored encrypted in the database', 'changes' => [
        'Gateway tokens (every number), the bridge token and the Google client secret / refresh token are kept in MySQL (wi_secrets), encrypted with this installation\'s key (.data/app.key). The code reads them from there; the old copies in the .data files are kept unchanged.',
        'New tokens (a newly added number, a new Google connection or client secret) are written to the encrypted table too.',
        'The Drive backup never uploads the encryption keys or the database login next to the data.',
    ]],
    ['v' => '3.29.0', 'date' => '2026-10-10', 'title' => 'Menu registry, shared requests, examples from the profile', 'changes' => [
        'The menu is built from one page registry (sections, pages, icons, who sees what); the Studio top menu and page highlights follow it.',
        'Settings → Menu: rename sections and pages, change icons, hide pages that are not used.',
        'Example texts in the inputs (questions, concepts, linked words, searches) come from the business profile instead of the code.',
        'One request helper for the whole dashboard (WI.get / WI.post): the copies in each page now use it.',
    ]],
    ['v' => '3.28.0', 'date' => '2026-10-10', 'title' => 'Installer for new installations', 'changes' => [
        'install.php: checks the server, connects the database (writes .data/db.php), creates every table, saves brand / address / time zone / country / language / business profile, creates the first super admin and the main gateway token, then locks itself.',
        'A fresh copy opens the installer by itself; the installer\'s admin can sign in with the password alone until the first WhatsApp number connects (no number = no login code).',
        'Security: the main gateway token is no longer written in wa-local/index.php and watchdog.php (read from the dashboard settings); the gateway port is a setting (main_port); the gateway alert to the invoice add-on uses gateway.json.',
    ]],
    ['v' => '3.27.0', 'date' => '2026-10-10', 'title' => 'Gateway rules are settings', 'changes' => [
        'Settings → Gateway rules: texts never stored (login codes, passwords), link domains removed from stored messages, and text tags (patterns, Quran index, diacritics ratio) — saved in wa-local/rules.json, picked up by every number within a minute.',
        'Removed from the gateway code: the Quran / ruqyah patterns and the YouTube / Facebook rule (current rules kept as this installation\'s settings).',
        'The Quran check in the dashboard (cache, file clean-up) now follows the business profile (quran_check).',
    ]],
    ['v' => '3.26.0', 'date' => '2026-10-10', 'title' => 'Independent of the invoice system', 'changes' => [
        'AI: own engine (same provider logic, own encrypted keys and usage tables). Settings → System → AI engine: "local" (own keys, added on the AI providers page) or "bridge" (through the invoice system, as now).',
        'Database: the app reads its own .data/db.php when present (written by the installer); older installs keep using the invoice config.',
        'Invoices are now an add-on (Settings → System): off = no customer names, invoices or reminders; its database path is a setting.',
        'Gateway: its addresses (dashboard hook, invoice webhook) come from wa-local/gateway.json; an empty invoice webhook turns the add-on off there too.',
    ]],
    ['v' => '3.25.0', 'date' => '2026-10-10', 'title' => 'Message templates', 'changes' => [
        'Settings → Message templates: login code, server and number alerts, backup failures, follow-up reminder, meeting host link, payment methods and every line of the daily report — editable, with {placeholders}.',
        'Payment methods for invoice reminders are no longer written in the code (current text kept). Starting language of the templates: Settings → System.',
    ]],
    ['v' => '3.24.0', 'date' => '2026-10-10', 'title' => 'System settings instead of fixed values', 'changes' => [
        'Settings → System (super admin): product name and logo, device name, dashboard address, cookie path, time zone, country code and local prefix, sign-out / login-code / lock limits, Drive backup folder, AI bridge address.',
        'Removed from the code: the fixed installation address (alerts, reminders, report, meetings, Google sign-in), the server IP of the bridge, Africa/Cairo, the Egypt-only phone rule, "WhatsApp Inbox" in titles, login code, exports and packages.',
        'This installation keeps its current values (saved as its settings).',
    ]],
    ['v' => '3.23.0', 'date' => '2026-10-10', 'title' => 'All AI instructions are editable', 'changes' => [
        'The last 8 instructions left the code: quick reply suggestions, chat summary, library sort / compare / format, bot learning from chats, business detection, case extraction from group reports — 14 in total under Learning → AI instructions.',
        'Business profile gained: library kind and sections, text quality and errors, quick-reply example, summary categories, knowledge types, case labels.',
        'Bot reply: a customer who cannot describe their problem now gets numbered choices instead of an open question.',
    ]],
    ['v' => '3.22.0', 'date' => '2026-10-10', 'title' => 'Business profile + editable AI instructions; human-like replies', 'changes' => [
        'Learning → AI instructions: a business profile (ready profiles: General, Ruqyah) fills the {{placeholders}} of every AI instruction; each instruction can be edited per number or put back.',
        'Moved out of the code: bot reply, suggest a reply, case analysis, file clean-up, guide growth, guide suggestions.',
        'Bot reply now asks for human behaviour: reacts to what was said, short natural sentences, no canned service phrases, varied wording, one idea and at most one question per message.',
    ]],
    ['v' => '3.21.0', 'date' => '2026-10-10', 'title' => 'Bot: no repeating, safer against blocks', 'changes' => [
        'Before sending, the reply is compared with the bot\'s last 3 replies in that chat; a repeat is retried once with a stronger model, otherwise it goes to the team as a draft.',
        'Only one clarifying question in a row (setting); the customer\'s messages are gathered for 45 s and answered once; at most 4 bot replies per chat per hour.',
        'Stop words pause the bot in that chat; typing time follows reply length with a random spread; warm-up limit for a new number until a chosen date.',
        'New starting style lines: never deny being an automated assistant; never repeat a sentence already sent. All of it is editable in Bot settings and Learning → Rules.',
    ]],
    ['v' => '3.20.0', 'date' => '2026-10-10', 'title' => 'Message style is editable', 'changes' => [
        'Learning → Rules → Message style: prefix (🤖), first-message introduction (private / groups), ending emoji rules with their words, removed endearment words, romantic emoji filter and the AI style instructions — per number, no longer in the code.',
    ]],
    ['v' => '3.19.2', 'date' => '2026-10-10', 'title' => 'Bot introduces itself', 'changes' => [
        'First bot message in a private chat starts with «أنا المساعد الرقمي بالذكاء الاصطناعي، وسأبدأ معك الحوار.» (once per chat).',
        'Bot messages start with 🤖; an emoji at the end stays only when it fits what was said (🤲 after a dua), decorative ones are removed.',
    ]],
    ['v' => '3.19.1', 'date' => '2026-10-10', 'title' => 'Guide filter', 'changes' => [
        'Approved concepts are hidden by default; filter buttons: Needs review / Approved / Off / All (the choice is remembered).',
    ]],
    ['v' => '3.19.0', 'date' => '2026-10-10', 'title' => 'The guide grows from chats', 'changes' => [
        'Learning → Guide: AI reads the new chats, cache answers and cleaned files and finds new symptoms, related words and remedies for the approved concepts.',
        'Runs every day at the hour you choose, or now with "Collect now"; new items wait on the concept card for accept / reject (optional automatic approval when an item repeats).',
        'Cache answers are linked to their concept; changing a concept meaning marks its answers "needs review".',
    ]],
    ['v' => '3.18.0', 'date' => '2026-10-10', 'title' => 'Clean & refine library files', 'changes' => [
        'Learning → Files: AI keeps only useful paragraphs and removes outside healers, sheikhs, references, groups, sites, links and phone numbers; verses never change.',
        'Per-file clean, undo, clean again; removed passages can be kept again; names allowed to stay (group admins + your list).',
    ]],
    ['v' => '3.17.8', 'date' => '2026-10-10', 'title' => 'Approved concepts fold away', 'changes' => [
        'Approved concept cards show only the title until opened; Edit opens them. Linked-word rows fixed (the word box was squeezed).',
    ]],
    ['v' => '3.17.6', 'date' => '2026-10-10', 'title' => 'Smart quick-reply suggestions', 'changes' => [
        'Quick replies have trigger words; typing them in a chat suggests the reply above the box. "Suggest from repeated messages" turns messages the team keeps sending into quick replies.',
    ]],
    ['v' => '3.17.5', 'date' => '2026-10-10', 'title' => 'Proper editing instead of small pop-ups', 'changes' => [
        'Cached answers are edited in place in a full-size box (Save / Cancel, Ctrl+Enter, Esc).',
        'A real form dialog replaces every small browser prompt: scheduling time (date-time picker), case labels, knowledge-base topic, and adding a question with a multi-line answer.',
    ]],
    ['v' => '3.17.4', 'date' => '2026-10-10', 'title' => 'Drive: select all, import a whole folder', 'changes' => [
        'Google Drive browser: select all files shown; import a whole folder (with or without its subfolders, up to 3 levels / 300 files) in the background, with progress; files already imported and unchanged are skipped.',
    ]],
    ['v' => '3.17.3', 'date' => '2026-10-10', 'title' => 'Chat summary as a slim strip', 'changes' => [
        'The AI chat summary no longer pushes the messages down: it is a one-line strip floating over the top of the chat (category + first words); click it to read it all, ✕ to close.',
    ]],
    ['v' => '3.17.2', 'date' => '2026-10-10', 'title' => 'Studio top menu in sections', 'changes' => [
        'Studio theme: the top menu shows the 5 sections as menus (each opens its pages), so no page is hidden off-screen; the open section shows the current page.',
        'General settings lists the Google permissions that were not granted, with the steps to add them.',
    ]],
    ['v' => '3.17.1', 'date' => '2026-10-10', 'title' => 'Guide: editable words, meaning first', 'changes' => [
        'Concept editor: each linked word can be edited in place, its relation changed or removed; add many words at once; click a word on the card to edit it.',
        'The concept\'s meaning is the core of the bot\'s reply; the team guidance is optional and only adds advice.',
        'General settings shows each Google permission (Drive read for learning, Meet recording).',
    ]],
    ['v' => '3.17.0', 'date' => '2026-10-10', 'title' => 'Learning from Google Drive', 'changes' => [
        'File library → From Google Drive: browse folders, search by name, tick files and import them (Google Docs and Sheets are exported as text).',
        'Link a Drive folder: its files are pulled every hour in the background — new files added, changed ones re-read, removed ones switched off.',
        'Needs Google to be reconnected once with permission to see Drive files (read-only).',
    ]],
    ['v' => '3.16.1', 'date' => '2026-10-10', 'title' => 'Automatic answer cache', 'changes' => [
        'Answers to complete questions are saved and reused whatever the length of the conversation (before, only near the start of a chat — so nothing was saved).',
        'Approved knowledge-base answers enter the cache automatically every hour.',
    ]],
    ['v' => '3.16.0', 'date' => '2026-10-10', 'title' => 'Learning: file library and editable rules', 'changes' => [
        'Learning → File library: upload Word, text, .csv, .html or .json files; their text is split into passages the bot searches and answers from; on/off, view passages, delete, search test.',
        'Learning → Rules: every owner rule on its own line — edit, add, switch off, reorder, delete; kept in step with the Bot settings box.',
    ]],
    ['v' => '3.15.5', 'date' => '2026-10-10', 'title' => 'JavaScript reliability', 'changes' => [
        'Bulk actions send ONE request (they used to fire one request per chat at once, hitting the hosting limit and overwriting each other).',
        '"Select all shown" shows its real state; a second click clears the selection.',
        'Every request in the dashboard answers with an error message instead of failing silently, so no button or page stays stuck.',
        'The error log ignores browser-extension errors.',
    ]],
    ['v' => '3.15.4', 'date' => '2026-10-10', 'title' => 'Bot: multi-select', 'changes' => [
        'Chats are grouped by number (one row per person; a switch covers all their WhatsApp IDs).',
        'Multi-select in Chats, Drafts and Paused chats: a checkbox per row, select all shown, and bulk actions (bot on / off, dismiss drafts, resume).',
    ]],
    ['v' => '3.15.3', 'date' => '2026-10-10', 'title' => 'Bot: every chat with an on/off switch', 'changes' => [
        'Bot page → Chats tab: every private chat of the last 3 months with a switch to turn the bot on or off for it; search, filter (on / off / waiting for a reply), sort, pages, and on/off for all shown.',
        'Paused chats and drafts now show the person\'s name and real number (or that WhatsApp hides it).',
    ]],
    ['v' => '3.15.2', 'date' => '2026-10-10', 'title' => 'Invoices on/off per number', 'changes' => [
        'General settings → Invoices: one switch per number for the payment-receipt bot, sending invoices from a chat, customer invoices and overdue reminders.',
        'When off, the dashboard refuses invoice actions for that number and the reminder job skips it.',
    ]],
    ['v' => '3.15.1', 'date' => '2026-10-10', 'title' => 'Bot page in tabs', 'changes' => [
        'Bot page sections are tabs: Settings, Groups, Team, History, Drafts waiting, Paused chats — each with its count; the open tab is kept in the address.',
        'History: search, result, where (private / groups / cache), date range, sort and pages.',
        'Drafts and paused chats have their own lists with open-chat, dismiss and resume buttons.',
    ]],
    ['v' => '3.15.0', 'date' => '2026-10-10', 'title' => 'Learning section: guide and answer cache', 'changes' => [
        'New Learning page: a guide of concepts with linked words and their relationship (e.g. «أغاني» is one of «منقضات التحصين»); the bot answers through that relationship.',
        'Answer cache: the same general question answered well before is reused with no AI call; personal messages are never answered from the cache.',
        'Answers that quote the Quran are never reused automatically — they wait for a person to check them.',
        'AI suggestions for concepts from the knowledge base (approve before use); a savings view of tokens saved.',
    ]],
    ['v' => '3.14.0', 'date' => '2026-10-10', 'title' => 'Bot: steady private replies, owner rules first, chats into cases', 'changes' => [
        'Private chats: when the team was active, the bot waits a few minutes (setting) for them, then answers — no message is left unanswered.',
        'The rules box is now a numbered list at the very top of the bot instructions and overrides everything else; the inbox reply suggestions follow it too.',
        'New options: add private chats to the case record; open a new case when someone describes symptoms in a private chat.',
    ]],
    ['v' => '3.13.0', 'date' => '2026-10-10', 'title' => 'Studio theme with your own colours', 'changes' => [
        'Fourth theme "Studio": no side menu — page tabs across the top, full-width content, coloured greeting banner, cards with a coloured top edge, Readex Pro font.',
        'Settings → Studio colours: 10 main colours (each with a chart partner checked for colour blindness) and 6 background tones, with a live preview.',
    ]],
    ['v' => '3.12.1', 'date' => '2026-10-10', 'title' => 'Server resources card', 'changes' => [
        'Health page: memory, processes and threads against the plan limits, disk use by area, and the biggest memory users — checked every minute.',
        'The super admin gets a WhatsApp alert when memory or threads pass 85% of the plan.',
    ]],
    ['v' => '3.12.0', 'date' => '2026-10-10', 'title' => 'Orchid theme', 'changes' => [
        'Third theme "Orchid": floating menu and top bar cards, lavender canvas, deep violet with teal, amber and pink accents, IBM Plex Sans Arabic.',
        'Chart colours violet (received) and teal (sent), checked for contrast and colour blindness in light and dark.',
        'Choose it in Settings → Dashboard theme.',
    ]],
    ['v' => '3.11.0', 'date' => '2026-10-10', 'title' => 'Backup packages, theme choice, faster gateways', 'changes' => [
        'Backup packages on Drive: full portable system, database only, conversations & numbers only, full general — each one file you can download and move to another server, with an install guide.',
        'Settings → Dashboard theme: Google (Material) or Classic.',
        'The "New case" button above the menu was removed.',
        'Gateways run with more threads after the hosting limits were raised.',
    ]],
    ['v' => '3.10.0', 'date' => '2026-10-10', 'title' => 'Phase 4: Google look on every page', 'changes' => [
        'Menu regrouped into 5 sections: Today, Communication, Cases & treatment, Automation & content, Administration.',
        'Every page uses the same Material 3 parts: page headers, underline tabs, filter chips, tables, labels, dialogs, notifications.',
        'Conversations in the Gmail style: rounded list rows, light-blue selected chat, rounded bubbles and message box.',
    ]],
    ['v' => '3.9.1', 'date' => '2026-10-10', 'title' => 'Restore from Google Drive', 'changes' => [
        'Restore from the latest copy or any archive: by type or chosen files; safe mode (missing files only) or replace.',
        'Before replacing a file the server keeps its own copy (backups/restore-safety); database tables are updated to match restored data.',
        'Fixed: backup tasks waiting because of a time-zone mismatch; files being written during upload; purple accent left from the old design.',
    ]],
    ['v' => '3.9.0', 'date' => '2026-10-10', 'title' => 'Google desktop look', 'changes' => [
        'New theme in the style of Google desktop apps (Material 3): pale canvas, pill menu and search, white rounded cards, matching dark mode.',
        'A "New case" button at the top of the menu for anyone who can edit cases.',
        'Chart colours: Google blue = received, Google green = sent (checked for contrast and colour blindness).',
        'Fixed: General settings page stuck on loading after the module split.',
    ]],
    ['v' => '3.8.0', 'date' => '2026-10-09', 'title' => 'Phase 3: modules, task queue, incremental Drive backup', 'changes' => [
        'features.php split into 19 modules (same functions and answers).',
        'Task queue (wi_jobs) and a background worker: Drive backup and case sync no longer hold up the every-minute jobs.',
        'Google Drive backup: choose data / messages / media or full; incremental uploads; deleted files go to dated archives you can open or delete; live progress.',
    ]],
    ['v' => '3.7.0', 'date' => '2026-10-09', 'title' => 'Phase 2: shared cases database in MySQL', 'changes' => [
        'Cases, case reports and case notes are stored in MySQL (wi_cases, wi_case_reports, wi_case_events); the JSON files stay as a mirror.',
        'Every page sees exactly the same data as before; a case added by one user appears to everyone with access.',
    ]],
    ['v' => '3.6.0', 'date' => '2026-10-09', 'title' => 'Phase 1: users and permissions in MySQL', 'changes' => [
        'Users, page permissions and share invitations are stored in MySQL (wi_users, wi_grants); users.json stays as a mirror.',
        'The audit trail is also written to MySQL (wi_audit), with the 171 earlier entries imported.',
        'Manager role: everything except the technical admin pages; shared pages need the person to accept.',
        'Super admin can sign in as another user; group bot gives everyone a fair turn.',
    ]],
    ['v' => '3.5.0', 'date' => '2026-10-09', 'title' => 'Clean cream design', 'changes' => [
        'One clean style layer replaces the earlier menu and colour experiments.',
        'Light cream throughout (menu, top bar, cards) with gentle gradients; matching warm dark mode.',
        'Chart colours: teal = received, burnt orange = sent (checked for contrast and colour blindness).',
    ]],
    ['v' => '3.4.0', 'date' => '2026-10-09', 'title' => 'Roles and page sharing', 'changes' => [
        'New Manager role: every page and action, except seeing or editing users.',
        'Viewers get per-page access: none, view, or view & edit.',
        'Share a page with another user by email or WhatsApp number (owner, or users allowed to share).',
        'Meetings: several hosts from WhatsApp groups, host approval waiting room, auto-close, send link to groups and contacts.',
    ]],
    ['v' => '3.3.1', 'date' => '2026-10-09', 'title' => 'Meetings section and General settings', 'changes' => [
        'Menu: Meetings has its own section.',
        'New ⚙️ General settings page: the Google account (status, Drive + Calendar access, connect / disconnect, steps with direct links), and cards for WhatsApp numbers (connection, payments, bot), users, AI providers, bot, number health, daily report, Drive backup, meetings and version.',
        'Connecting Google now returns to General settings.',
    ]],
    ['v' => '3.2.0', 'date' => '2026-10-09', 'title' => 'Meet links created automatically', 'changes' => [
        'Meeting editor: “✨ Create automatically” makes a new Google Meet link through your Google Calendar (no need to open meet.new and copy).',
        'The Google connection (Health, report & backup → Google Drive backup) now also asks for calendar access; connect once and it serves both backups and Meet links.',
    ]],
    ['v' => '3.1.1', 'date' => '2026-10-09', 'title' => 'Meetings on phones', 'changes' => [
        'On phones, after joining, the page shows two big buttons: open in the Google Meet app (Android opens the app directly) or in the browser. Computers go straight to Meet as before.',
    ]],
    ['v' => '3.1.0', 'date' => '2026-10-09', 'title' => 'Meetings (Google Meet gateway)', 'changes' => [
        'New 🎥 Meetings section in the dashboard: each meeting gets a fixed link (…/wa-inbox/?m=name).',
        'People see a waiting page until a host opens the meeting; then they enter their name (and phone if asked), automatically or with a password, and are sent to Google Meet — the real Meet link is never shown before that.',
        'Choose who can open each meeting (hosts), a maximum number of participants, open / close, see who joined and when, copy the link or send it on WhatsApp.',
        'Wrong passwords are limited (8 per device per 15 minutes).',
    ]],
    ['v' => '3.0.0', 'date' => '2026-10-09', 'title' => 'Separate Bot page, a page for every group', 'changes' => [
        'New 🤖 Bot page in the menu: General settings (auto-saved), a Groups table with an on/off switch per group, search, filter (on/off, interactive, fixed, learning, welcome), sort and pagination, the Team and the bot log — each section folds.',
        'Each group has its own bot page: a header with the group, its on/off switch and links; its settings (reply type, when, learning, welcome, fixed message) auto-saved; its admins; the group chat underneath with bot replies highlighted, search, filter (bot / team / members / questions) and pagination; and the bot log for that group.',
        'Switching a group on adds groups to the bot’s scope automatically.',
    ]],
    ['v' => '2.9.0', 'date' => '2026-10-09', 'title' => 'Bot answers much faster', 'changes' => [
        'Waits 4 seconds after the customer’s last message (3 in groups) instead of 8 (6).',
        'The library and the team list are kept in server memory (library refreshed every 5 minutes) — 0.03 s instead of ~1.7 s per reply.',
        '⚡ Fast replies (on by default when the bot model is “Automatic”): a light fast model answers first (~1.5–3 s), the others take over if it fails.',
        'Shorter “typing…” before bot replies (up to 2 s instead of 4.5 s).',
        'Fix: saving “Bot style & model” no longer erases the number’s on/off settings and detected description.',
    ]],
    ['v' => '2.8.4', 'date' => '2026-10-09', 'title' => 'Bot offers choices when the person is unsure', 'changes' => [
        'When someone cannot describe what they feel (vague words, “I don’t know”, typos), the bot offers 3–7 numbered choices (e.g. common symptoms from your sources) to answer with a number, more than one allowed — and understands the numbers they send back.',
    ]],
    ['v' => '2.8.3', 'date' => '2026-10-09', 'title' => 'Bot settings save themselves; admins tagged on hand-over', 'changes' => [
        'Bot → 🚦: the Save bar stays visible on both tabs (General and Groups bot), and every change is saved automatically after a second (switching ON automatic replies still asks first).',
        'In groups, when the bot hands a question over to the team, it @mentions the group’s admins so they are notified.',
    ]],
    ['v' => '2.8.2', 'date' => '2026-10-09', 'title' => 'Team: group admins by name', 'changes' => [
        'Groups bot → 👥 Team: the admins of each chosen group with their names and numbers, our numbers, Inbox users, and people added by hand (add / remove).',
        'Fix: the bot now treats the admins of the chosen groups as the team when answering (it never answers them; their answers are learned).',
    ]],
    ['v' => '2.8.1', 'date' => '2026-10-09', 'title' => 'Groups bot settings easier to find', 'changes' => [
        'Bot → 🚦 Switch on / off now has two tabs: ⚙️ General and 👥 Groups bot (with the number of chosen groups).',
        'Chosen groups are listed first; the welcome message box only appears when the welcome is switched on.',
    ]],
    ['v' => '2.8.0', 'date' => '2026-10-09', 'title' => 'Learn from groups', 'changes' => [
        'Per group: “📚 Learn from this group” — members’ questions and the answers of this number, the sheikh and the group admins are added to the bot’s learning (linked by WhatsApp “reply to”, or the closest question before the answer). The bot’s own messages are never learned from.',
        '“الجروب العام للفرقان”: answers questions only, and learns from the group.',
    ]],
    ['v' => '2.7.1', 'date' => '2026-10-09', 'title' => 'Bot keeps the conversation going in groups', 'changes' => [
        'In groups the bot now also sends its polite “the sheikh / the team will answer you” reply and short clarifying questions (before, those stayed as drafts, so the conversation seemed to stop).',
    ]],
    ['v' => '2.7.0', 'date' => '2026-10-09', 'title' => 'Bot in groups, welcome with mention, pagination', 'changes' => [
        'Bot → where it answers: private chats only, private chats and groups, or groups only; all groups or only the groups you choose.',
        'Per group: ✨ interactive (AI replies to the member’s message as a reply) or 📌 fixed message (with {name} / {group}, at most once per member every N hours); trigger: every message, questions only, or only when someone mentions / replies to the number.',
        '👋 Welcome new members: when someone joins a chosen group, the welcome message is sent with an @mention of them ({mention}, {group}).',
        'Pagination (20/50/100/200 per page) with search and filters on Groups, Numbers, Cases, Case registry, Knowledge (cards and table), Bot log and Scheduled messages.',
    ]],
    ['v' => '2.6.0', 'date' => '2026-10-09', 'title' => 'Payment bot on/off per number', 'changes' => [
        'WhatsApp accounts: “Payment bot (receipts + your approval)” can be switched on or off for each number, the main number included.',
        'When it is off, that number’s messages are only archived (and answered by the chat bot if it is on) — they are not sent to the invoice system and receipts are not checked.',
        'The gateway now really follows this switch (before, every number was sent to the payment bot).',
    ]],
    ['v' => '2.5.0', 'date' => '2026-10-09', 'title' => 'Bot knows who it is talking to', 'changes' => [
        'Before answering, the bot gets a card about the person: new or existing, since when, how many messages, groups they are in, programs, case and customer record.',
        'New people are welcomed and given the first step; the bot never tells them to continue adhkar, programs or ruqyah water they never received — if a dhikr is the answer it sends its text.',
        'Existing people get answers that build on their groups, program and case.',
        'Test chat: “Test as a new person / an existing person”.',
    ]],
    ['v' => '2.4.4', 'date' => '2026-10-09', 'title' => 'Bot: /start overrides, skipped messages explained', 'changes' => [
        '/start in a chat makes the bot answer there even while the team is talking in it (overrides the “stay out” hours); /stop stops it.',
        'Every message the bot does not answer is listed in its log with the reason (team replied X min ago, photo/voice, emoji only…).',
    ]],
    ['v' => '2.4.3', 'date' => '2026-10-09', 'title' => 'Bot sends its clarifying questions', 'changes' => [
        'When the bot does not understand and only asks the customer to explain (e.g. “what do you mean by …?”), the question is sent even if its confidence is low — before it stayed as a draft and the customer got nothing.',
    ]],
    ['v' => '2.4.2', 'date' => '2026-10-09', 'title' => 'Bot answers even when a person is needed', 'changes' => [
        'When a message needs a person (e.g. “I want the sheikh”), the bot in automatic mode now sends its polite “a team member will reply soon” answer instead of only leaving a draft, then steps back from that chat for the “stay out” hours so the team takes over (test numbers excepted).',
        'Bot log shows “Sent and handed over to a person”.',
    ]],
    ['v' => '2.4.1', 'date' => '2026-10-09', 'title' => 'Bot keeps up with the conversation', 'changes' => [
        'When a message arrives while the bot is still finishing the previous answer, it now waits its turn and answers (before, that trigger was dropped and the reply came a minute later).',
        'Backup check waits 15 seconds after the last message instead of 40.',
        'Data files are writable by both the website and the background jobs (the AI activity log was missing the bot’s tasks).',
    ]],
    ['v' => '2.4.0', 'date' => '2026-10-09', 'title' => 'Bot: /stop and /start per chat', 'changes' => [
        'Type /stop in a private chat to stop the bot answering that chat, /start to let it answer again — from the Inbox (not sent to the customer) or from the phone (removed from the customer’s chat at once). A customer typing /stop also stops it.',
        'Chat button shows “Bot on here — /stop” or “Bot paused here — /start” and switches it.',
        'Pauses and resumes appear in the bot log.',
    ]],
    ['v' => '2.3.0', 'date' => '2026-10-09', 'title' => 'Bot answers in seconds', 'changes' => [
        'The WhatsApp gateway now wakes the bot the moment a private message arrives (8 seconds after the customer’s last message, so several quick messages get one answer) — before it waited for the every-minute check plus 40 seconds.',
        'The every-minute check stays as a backup; one bot run per number at a time.',
    ]],
    ['v' => '2.2.2', 'date' => '2026-10-09', 'title' => 'Bot test numbers', 'changes' => [
        'Bot → 🚦 Switch on / off: “Test numbers” — the bot always answers them (even your own or team numbers, and even right after a person replied), so you can try it from your phone.',
    ]],
    ['v' => '2.2.1', 'date' => '2026-10-09', 'title' => 'Narrow windows fixed', 'changes' => [
        'In narrow windows / tablets / phones (up to 1000px) the menu moves to a bar at the bottom and the content uses the full width — the old menu-docking and small-screen rules no longer squeeze the page.',
    ]],
    ['v' => '2.2.0', 'date' => '2026-10-09', 'title' => 'New layout: icon rail + top bar', 'changes' => [
        'Slim icon rail on the side (opens with names when you point at it) — more room for the content; on phones it moves to the bottom.',
        'Top bar on every page: page name, search all messages, number switcher with a connection dot, language, version and user.',
        'Colours: white and WhatsApp green with soft violet-blue accents; page headers with a soft gradient.',
        'Faster Conversations page (no heavy blur / selectors that slowed the browser).',
    ]],
    ['v' => '2.1.0', 'date' => '2026-10-09', 'title' => 'Switch the bot on — per number', 'changes' => [
        'Bot → 🚦 Switch on / off, for every number (the main number too): Off · Suggest only (a draft appears in the chat to send with one click) · Automatic (replies by itself when confident, otherwise leaves a draft).',
        'Settings: minimum confidence, working hours, max replies per hour, and how long to stay out of a chat after a person from the team replied.',
        'Safety: only messages that arrive after switching on; private chats only; never team members or your own numbers; photos/voice notes (payment receipts) and emoji-only messages are left to people.',
        'Log of everything the bot did (sent / draft and why). Accounts page links to each number’s bot.',
    ]],
    ['v' => '2.0.0', 'date' => '2026-10-09', 'title' => 'New design for every page', 'changes' => [
        'One design system for all pages (light and dark): compact page headers with an accent bar, consistent cards, buttons, tabs, segmented switches, forms, tables (sticky headers, hover rows), tags and KPIs.',
        'Side menu: clearer groups, active item marked with a bar, hover states.',
        'Older pages (Dashboard, Search, Accounts, Link, Broadcast) use the same header style.',
        'Filter bars: each label sits next to its control; registry texts without big empty gaps.',
        'Browser always gets the latest scripts and styles after an update.',
    ]],
    ['v' => '1.33.0', 'date' => '2026-10-09', 'title' => 'Match % and recommended answer', 'changes' => [
        'Question / answers table: every team answer shows its match % with the bot (AI) answer (green ≥75, yellow ≥50, red below) and a score.',
        'Score = 50% match with the bot answer + 30% agreement with the other team answers + 20% how often it was said.',
        'When a team answer is close (≥60%), the best-scoring one is marked ⭐ Recommended; use it per question or “Apply all recommended” (replaces the bot answer and approves).',
    ]],
    ['v' => '1.32.0', 'date' => '2026-10-09', 'title' => 'Knowledge table: question and its answers', 'changes' => [
        'Knowledge → “Question / answers table”: each question with the different real replies your team gave to it (with how often and when), the one the bot uses on top; “Use this answer” switches and approves it.',
        'Duplicates are found letter by letter too (spacing and Persian/Arabic letter variants no longer hide them).',
    ]],
    ['v' => '1.31.0', 'date' => '2026-10-09', 'title' => 'Knowledge: no duplicates, bulk actions, auto-approve', 'changes' => [
        'The same question asked in other words or another dialect (with the same answer) is merged into one item; merged count shown. Runs after every learning batch, and with “Merge duplicates”.',
        'Select one, many or all shown, then approve, send back to review, disable, change topic or delete.',
        'Auto-approve: answers your team gave at least 2 / 3 / 5 / 10 times are approved automatically (marked ⚡); off by default.',
    ]],
    ['v' => '1.30.1', 'date' => '2026-10-08', 'title' => 'Learning: collected data is kept', 'changes' => [
        'Collecting exchanges is incremental: what was collected and learned is saved; each collect only adds new exchanges (6-hour overlap so late replies are not missed).',
        'The page shows how many are saved and when they were last collected; “Re-read everything” is still available and keeps what was learned.',
    ]],
    ['v' => '1.30.0', 'date' => '2026-10-08', 'title' => 'One bot per number', 'changes' => [
        'Each WhatsApp number has its own bot: its own description, audience, language, tone, knowledge, examples, model and rules — nothing from another number is used.',
        '“Detect from this number’s chats”: the AI describes what the number does, who writes to it, their language and tone, common requests and what to leave to a human.',
        'The bot page shows which number you are testing; learning, reply suggestions and tests use that number’s description (the ruqyah wording was hard-coded before).',
        'The bot never invents a personal name for itself.',
    ]],
    ['v' => '1.29.0', 'date' => '2026-10-08', 'title' => 'Bot replies like a person', 'changes' => [
        'New reply style: in the customer’s dialect, natural and warm, learned from the closest real replies of your team; sources used as knowledge instead of pasted; asks one question when something is unclear; WhatsApp-sized.',
        'Bot style & model: choose the bot’s model (Meta Llama models appear with 🦙 when a provider with credit has them), tone, name, about the service and rules.',
        'Test chat can compare two models side by side.',
        'Gemini content filter: permissive safety settings for religious treatment texts; a blocked request moves to another provider and is retried with less material.',
        'AI errors now say why an answer was empty (blocked, out of tokens…).',
    ]],
    ['v' => '1.28.0', 'date' => '2026-10-08', 'title' => 'Bot: learning & test', 'changes' => [
        'Learn from conversations: every customer message with the reply your team actually sent (private chats) is collected, then the AI turns them into reusable knowledge by topic — names and numbers removed, Quran and supplications kept exactly.',
        'Knowledge: review, edit, approve, disable or add answers; see how often each came up.',
        'Test chat: write as a customer and see the bot’s reply, its confidence, whether it would hand over to a human, and which sources it used — nothing is sent on WhatsApp.',
        'Test on real messages: a real past question with your team’s reply next to the bot’s; rate it, or write the right answer (saved as approved knowledge).',
        'Readiness: the bot shows “Ready to switch on” only after 10+ approved items and 10+ rated tests with at least 80% good. The bot stays OFF.',
    ]],
    ['v' => '1.27.0', 'date' => '2026-10-08', 'title' => 'Groups you left', 'changes' => [
        'Groups you left (or were removed from) now appear in Groups, marked ⟲, with all their archived messages; filter "Groups I left / Groups I am in".',
        'Numbers: people who wrote, were @mentioned or replied to in groups you left are included (source ⟲).',
        'The member list of every group is now saved on each read, so if you leave a group later its members stay available.',
    ]],
    ['v' => '1.26.2', 'date' => '2026-10-08', 'title' => 'Numbers: correct results', 'changes' => [
        'Re-reading numbers while WhatsApp was busy dropped whole groups (419 instead of 839). Failed groups are now retried, and if still busy their previous members are kept and named in a warning.',
        'Local numbers without a country code (e.g. 06…) are left out — only WhatsApp format is kept.',
        'The page shows how many numbers came from members, admins, chats and messages.',
        'People with no name show 👤 instead of a digit.',
    ]],
    ['v' => '1.26.1', 'date' => '2026-10-08', 'title' => 'Layout fixes', 'changes' => [
        'The side menu scrolls inside its dark panel (its last items were showing faded below it).',
        'The conversations box fills the screen height exactly — no empty space below and no page scrolling.',
    ]],
    ['v' => '1.26.0', 'date' => '2026-10-08', 'title' => 'New chat header', 'changes' => [
        'Chat header redesigned: big photo, name with badges (customer, group, case), number, message count and last message time.',
        'Info line under the name: invoice-system customer, amount due, invoices, complaint and labels of the case, active treatment programs, AI summary category, first contact date, scheduled messages.',
        'Quick buttons: customer profile, enrol in a program (or the group page for groups), export.',
    ]],
    ['v' => '1.25.0', 'date' => '2026-10-08', 'title' => 'Photos fixed, sort & filter everywhere', 'changes' => [
        'Profile and group photos show again: loaded when visible, served from the server cache, kept a week in the browser.',
        'Groups: sort by last activity (newest/oldest), messages, members, name; filter by date range, case groups, groups where you are admin, inactive groups.',
        'Numbers: sort by groups, number or name; filter by group, source and country code.',
        'Case registry and cases: sort by date, name or number; filter by group, label and date range.',
        'Old duplicate files removed (each had an identical copy in use) and old version snapshots made small.',
    ]],
    ['v' => '1.24.0', 'date' => '2026-10-08', 'title' => 'Numbers page, team exclusions, list/icons views', 'changes' => [
        'Numbers page: pull numbers from group members, private chats and numbers written in messages — each once, in WhatsApp format (e.g. 201001234567), with name and groups; export CSV / TXT / contacts (VCF) or copy.',
        'Group admins, our own numbers, Inbox users and anyone marked "Not a case (team)" are never turned into cases automatically.',
        '"جروب للحالات وتشخيصها" moved from "follow everyone" to Case registry: cases come from the number written in each post, not from who posted it.',
        'Groups and Numbers can be shown as a list or as icons (remembered).',
        'Numbers written as @mentions are no longer taken as phone numbers.',
    ]],
    ['v' => '1.23.0', 'date' => '2026-10-08', 'title' => 'Case registry, AI activity & stronger AI fallback', 'changes' => [
        'Case registry: mark the group where cases are posted; every post with a phone number (plus the voice notes/photos sent with it) becomes one case entry. AI extracts name, number, complaint, symptoms, diagnosis and labels.',
        'Labels on every case; filter by label; select one, many or all, then follow them, add/remove labels or re-analyse.',
        'Following a case collects ALL their messages from every group (own posts, replies quoting them, @mentions) and their private chat, matched by phone and WhatsApp ID.',
        'AI activity bar at the top of every page: each AI task with its model, tokens used, time, and progress for batches.',
        'If a model fails (busy, rate-limited, empty answer) the same task automatically tries other models and a second round.',
    ]],
    ['v' => '1.22.0', 'date' => '2026-10-08', 'title' => 'Groups redesigned + follow one person', 'changes' => [
        'Groups page: cards with members, messages and last activity; search and sort.',
        'Open a group: people list (who wrote, how much, last time) next to the messages; click a person to see only their messages and the replies to them.',
        '🩺 Follow on any person (or from any message) creates their case page: their messages in that group, replies that quote them, messages that @mention them, and their private chat — matched by phone and WhatsApp ID, so nothing from other cases is mixed in.',
        'The gateway now records which message a reply quotes and who is mentioned (for new messages, and for older ones on the next history sync).',
    ]],
    ['v' => '1.21.2', 'date' => '2026-10-08', 'title' => 'Fix: pages stuck on loading', 'changes' => [
        'Profile photos load two at a time and only when visible (many at once could hit the hosting request limit and freeze every page).',
        'The new features script was blocked by the server rules — now allowed.',
        'Group chats no longer wait while member numbers are resolved.',
    ]],
    ['v' => '1.21.1', 'date' => '2026-10-08', 'title' => 'Staying inside the hosting process limit', 'changes' => [
        'Profile photos are looked up in the background — web requests never wait for them.',
        'The every-minute jobs run one after another in a single process instead of four at once.',
        'Gateways run with 6 threads each instead of about 35.',
    ]],
    ['v' => '1.21.0', 'date' => '2026-10-08', 'title' => 'Cases, groups, scheduling, programs, status, health, report & backup', 'changes' => [
        'Chat: 🤖 Suggest a reply (from the chat + the best library texts, you review before sending), ↩️ reply to a specific message, emoji reactions, ⏰ schedule a message, 👤 customer profile.',
        'Cases: choose case groups; every member who writes gets a case record (all messages archived), AI analysis (complaint, symptoms, indications, severity, progress, next step), status, notes, export. Under follow-up = all their conversations copied into the record.',
        'Groups: members, active/silent members, daily activity, joins & leaves.',
        'Scheduled messages and WhatsApp Status (text/photo/video, now or later).',
        'Treatment programs: daily messages per enrolled person at their hour, pause/stop, adherence from their replies.',
        'Number health page + WhatsApp alerts to admins when a number is down 10 minutes or logged out.',
        'Daily WhatsApp report to admins; Google Drive daily backup (connect with your Google OAuth client).',
        'Gateways use far fewer threads (the hosting plan limit was stopping them).',
    ]],
    ['v' => '1.20.0', 'date' => '2026-10-08', 'title' => 'Profile photos & old duplicate files', 'changes' => [
        'WhatsApp profile photos in the chat list, chat header, search results and analytics lists (cached on the server, looked up gently).',
        'One-time clean-up of files downloaded earlier: identical files keep one copy, the others are re-linked and moved to a backup folder.',
    ]],
    ['v' => '1.19.0', 'date' => '2026-10-08', 'title' => 'Identical copies', 'changes' => [
        'Library: copies with the same words as the best version are marked 🟰 Identical, and "Select identical copies" picks them all at once.',
        'Downloads: a file identical to one already downloaded (same content fingerprint, or same size/length/type) is linked to the existing file and never stored twice — for audio, PDFs, photos and videos, including manual "Fetch from WhatsApp".',
    ]],
    ['v' => '1.18.0', 'date' => '2026-10-08', 'title' => 'AI content tools', 'changes' => [
        'Each library group: 🏆 Compare & pick best (AI compares all versions, explains why and moves the best to the top) and ✨ Format with AI (clean layout; rejected automatically if any word or diacritic changes).',
        '🏆 Pick the best version for all groups in one run (stoppable).',
        'AI results are kept separately — original messages are never changed.',
    ]],
    ['v' => '1.17.3', 'date' => '2026-10-08', 'title' => 'Select all in library groups', 'changes' => [
        'Library: "Select all" picks every version of the groups shown; "Select all duplicates" picks every copy except the best one — then archive or delete in one go.',
    ]],
    ['v' => '1.17.2', 'date' => '2026-10-08', 'title' => 'Custom provider removed', 'changes' => [
        'Removed the "Custom (OpenAI-compatible)" provider slot; FlexAI stays as its own provider.',
    ]],
    ['v' => '1.17.1', 'date' => '2026-10-08', 'title' => 'FlexAI provider', 'changes' => [
        'FlexAI added as its own provider (api.flex.ai/v1) with key, model, credit (HTTP 402) and image checks.',
    ]],
    ['v' => '1.17.0', 'date' => '2026-10-08', 'title' => 'Parallel AI & custom provider', 'changes' => [
        'Choose how providers run: one after another (saves quota) or all at once (fastest answer wins).',
        'Sequential mode now moves on after an empty reply or a timeout instead of stopping.',
        'Custom provider slot for FlexAI or any OpenAI-compatible service: name, Base URL, key — with the same key/plan/model checks.',
    ]],
    ['v' => '1.16.0', 'date' => '2026-10-08', 'title' => 'AI providers: real checks', 'changes' => [
        'Each check tests the key, loads all models (text and image), detects the plan and limits, and tests text and image reading separately.',
        'Gemini plan is detected (free vs billing); OpenRouter shows credits; Groq/Mistral show remaining limits.',
        'A model that hits its limit rests alone — the provider is no longer blocked for the whole day.',
        'Text tasks can use text-only models; receipts still use only models that passed the image test.',
    ]],
    ['v' => '1.15.1', 'date' => '2026-10-08', 'title' => 'Activate / deactivate AI providers', 'changes' => [
        'AI providers: one-click switch to activate or deactivate each provider (saved at once, shared with the invoice system).',
    ]],
    ['v' => '1.15.0', 'date' => '2026-10-08', 'title' => 'Library tabs, files, AI sorting & AI providers', 'changes' => [
        'Library is one menu item with tabs: All, Audio, Files, Lessons, Treatment programs, Sessions, Ruqyah, Verses, Prayers.',
        'New Files page: PDFs, documents, photos and videos — each file once with its share count, download or fetch from WhatsApp.',
        'New section: Treatment programs.',
        'Every clip shows who sent it, the day and date, and the group.',
        'Click a card to show its full text.',
        'Sort & check with AI: section, topic, title, quality stars, mistakes flagged, near-duplicates merged.',
        'AI providers page (admins): keys, models, order, limits, test — shared with the invoice system.',
    ]],
    ['v' => '1.14.0', 'date' => '2026-10-08', 'title' => 'Versions & audio library statistics', 'changes' => [
        'Version number in the sidebar and a "What\'s new" page; a code snapshot is kept for every version.',
        'Audio library: categorised statistics (by category, download status, most repeated, by month, total listening time, space saved).',
        'Big recordings up to 300 MB, written to disk while downloading.',
        'Duplicate recordings are counted and linked to one file, never downloaded again.',
    ]],
    ['v' => '1.13.0', 'date' => '2026-10-08', 'title' => 'Audio library', 'changes' => [
        'New "Audio library" page: every recording once, rename, category, archive/restore, named downloads.',
        'Background download queue for audio from the history sync; the guard never starts a second copy of a busy gateway.',
        'Re-link is instant (session moved, not copied); pages never freeze while a gateway is busy.',
    ]],
    ['v' => '1.12.0', 'date' => '2026-10-08', 'title' => 'Grouped menu', 'changes' => [
        'Sidebar in groups: Main · Insights & content · Settings.',
        'WhatsApp (accounts, link/QR, gateway & guard) and Conversations (chats, search) merged with page tabs.',
    ]],
    ['v' => '1.11.0', 'date' => '2026-10-08', 'title' => 'Invoices, reminders, AI, Quran', 'changes' => [
        'Send an invoice image from the chat; overdue reminders chosen per number.',
        'AI summary, category and next step per chat; classify recent chats.',
        'Quran verses matched against the full Mushaf text (surah and ayah shown).',
        'Library: each version in its own card with date and time; archive / delete duplicates.',
    ]],
    ['v' => '1.10.0', 'date' => '2026-10-08', 'title' => 'Library sections & best versions', 'changes' => [
        'Sections: audio, sessions, lessons, ruqyah, verses, prayers, instructions, announcements.',
        'Similar texts grouped with a ranked best version (diacritics and emojis kept).',
        'Library and Analytics pages redesigned.',
    ]],
    ['v' => '1.9.0', 'date' => '2026-10-08', 'title' => 'Analytics & data', 'changes' => [
        'Busy hours, reply speed, waiting chats, most active, numbers / emails / links / amounts found in messages, CSV export.',
    ]],
    ['v' => '1.8.0', 'date' => '2026-10-08', 'title' => 'Teamwork', 'changes' => [
        'Quick replies with variables, labels, assignment and "who is viewing", follow-up reminders on WhatsApp.',
    ]],
    ['v' => '1.7.0', 'date' => '2026-10-08', 'title' => 'Live inbox & broadcast', 'changes' => [
        'Live updates, typing, ticks, unread counters; safe broadcast lists; notes, profile photo, copy name/number; bot shown offline.',
    ]],
    ['v' => '1.6.0', 'date' => '2026-10-08', 'title' => 'Chat mode', 'changes' => [
        'Reply from the page with anti-ban rules; send photos, audio and files up to 70 MB; export chats (TXT, CSV, HTML); contact info panel.',
    ]],
    ['v' => '1.5.0', 'date' => '2026-10-08', 'title' => 'Multiple numbers', 'changes' => [
        'Several WhatsApp numbers, each with its own gateway, archive and bot; per-user access; Gateway & Guard page; device name WhatsGuard.',
    ]],
    ['v' => '1.0.0', 'date' => '2026-10-08', 'title' => 'WhatsApp Inbox', 'changes' => [
        'Separate app with its own login (password + WhatsApp code), dashboard, conversations, search and users; Arabic / English.',
    ]],
];
