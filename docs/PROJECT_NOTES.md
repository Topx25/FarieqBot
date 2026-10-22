# Project notes

Stored facts about how this product is built and the rules every change follows.
Keep this file up to date with every change that adds a rule, a setting area or a moving part.

## Parts
- `wa-inbox/` — the dashboard (PHP 8.2 + MySQL `wi_*` tables). Data through `wi_read` / `wi_write` / `wi_update`.
  - Modules are listed in `features.php`; the menu is in `lib.php` (`wi_menu_registry`); interface translations in `i18n.php` (by exact English text).
  - Front-end sources in `src/`; build with `php build-js.php`, then `node --check features.js app.js`.
  - `cron_features.php` runs every minute.
- `wa-local/` — the gateway (Node, one process per WhatsApp number). Each number's archive: `wa-local/accounts/<id>/archive/*.jsonl`.

## Bot (WhatsApp)
- `modules/bot_runner.php` — the bot run (`wf_bot_tick`): private chats, groups (`wf_bot_groups`), welcome, group settings (`wf_clean_gcfg`), owner commands from a test number (`wf_owner_command`).
- `modules/bot_learning.php` — replies (`wf_bot_answer`), model choice (`wf_fast_model`), learning from chats.
- `modules/bot_commands.php` — Bot → Commands: every `#` command of a number in one list, edited where it lives; the owner's own commands (fixed reply or AI instruction); the ask tag.
- `modules/learn_lean.php` — learning from chosen groups with few tokens: collect and clean with no AI → a free model shortens each exchange to one line → a strong model turns the lines into knowledge for review. Hourly, with daily caps.
- `modules/case_deep.php` — #تحليل / #توصيات: the case report by angle, stages, progress, recommendations; private-chat cases never shown in groups.
- `modules/case_trail.php` / `modules/case_journal.php` — where a case is followed and by whom; a lasting journal (wi_journal table) of messages, team answers and file links, filled every 10 minutes; the bot reads it before answering.
- `modules/team_watch.php` / `modules/group_watch.php` / `modules/checkin.php` — healers' activity and neglected cases; alerts for unanswered group questions and untaken cases (tagging the healer); follow-up check-ins by the bot.
- `modules/bot_quality.php`, `modules/voice.php`, `modules/recommend.php`, `modules/suggest.php` — ratings and weekly test; voice notes as text; fitting sessions; search suggestions.
- `modules/case_intake.php` — cases from groups, reception gate, group commands, `#menu`.
- `modules/sys_guide.php` — help groups: explains the system by the asker's permissions; members who are not users only get the bot commands.
- `modules/bot_roles.php`, `modules/visitors.php`, `modules/prompts.php` (business types and AI instructions), `modules/msg_templates.php` (all messages the bot sends).

## Facebook (pages, Messenger, comments, posts)
- A Facebook page is an account with channel `fb`; `wf_fb_gateway` answers the same calls as a WhatsApp number, so the inbox and the bot work unchanged.
- Files: `modules/facebook.php` (Messenger, webhooks, sending, minute sync), `modules/facebook_feed.php` (comments, posts, stories, insights, API `wf_api_fbfeed`), `modules/facebook_link.php` (the Facebook app per scope, "Continue with Facebook" sign-in, page pick, deauthorize / data deletion), `modules/legal.php` (public privacy, terms, data deletion pages), UI `src/features/34m-facebook.js`.
- The sign-in callback runs before the session starts (the session cookie is SameSite=Strict) and must never start a session.
- Every minute (cron) each page's new Messenger messages and new comments on its last 10 posts are read from the page itself, so they arrive while the app is unpublished or a webhook was missed. On the first comment read, older comments are saved without a bot answer.
- Sending: 24-hour window; HUMAN_AGENT (7 days) for people only.
- A page can answer with the knowledge of a WhatsApp number (page settings → "The page answers with the knowledge of"; stored as `parent`, used by `wf_fb_parent`).
- Graph API v26.0. Tests use `$GLOBALS['WI_FAKE_GRAPH']` (and `WI_FAKE_HTTP` for sign-in calls) instead of Facebook.
- Permissions asked: pages_show_list, pages_messaging, pages_manage_metadata, pages_read_engagement, pages_read_user_content, pages_manage_engagement, pages_manage_posts, read_insights.

## Business types
- Types: general, crm, support, ruqyah (spiritual healing). Case wording follows the type (treatment / crm / support / general).
- Spiritual healing wording, tags, roles, reception gate words and examples exist only in that type; other types never show them, and the other types' values never stick to a healing number.
- A number's own profile keeps only the values really changed (`wf_pack_own`); everything else comes from its type.

## Rules for every change
1. No hard-coding: every business text, rule, word list or limit is a setting per number or per group. Arabic wording only in `i18n.php`, presets and message templates.
2. `0` means no limit for hourly caps.
3. A comment never sits before code on the same line (it would swallow it).
4. Nothing identifying in shipped files: no real names, phone numbers, addresses, hosts or organisations.
5. Every change: `php -l`, rebuild JS when `src/` changed, run the QA suites (unit, features, comments, hardcode, smoke), test real behaviour with the stand-in gateway, clean test data, add a version entry at the top of `wa-inbox/version.php`.

## Owner commands (sent from a test number to the bot's number)
Warm-up on / off, restart the service, stop / resume the bot, delete a record (with a confirm word within 5 minutes), the commands list. All words are editable in Bot → Commands.
