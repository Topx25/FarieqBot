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
- `modules/case_intake.php` — cases from groups, reception gate, group commands, `#menu`.
- `modules/sys_guide.php` — help groups: explains the system by the asker's permissions; members who are not users only get the bot commands.
- `modules/bot_roles.php`, `modules/visitors.php`, `modules/prompts.php` (business types and AI instructions), `modules/msg_templates.php` (all messages the bot sends).

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
