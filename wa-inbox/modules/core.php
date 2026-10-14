<?php
// WhatsApp Inbox — core (split out of features.php in v3.8, code unchanged)

declare(strict_types=1);
/*
 * WhatsApp Inbox — feature module (v1.21+):
 * AI reply suggestions, quoted replies & reactions, scheduled messages, groups, case follow-up,
 * WhatsApp Status, number health, customer profile, treatment programs, daily report, Google Drive backup.
 * API handlers live in wi_features_api(); shared helpers are also used by cron_features.php.
 */

function wf_in(): array { return json_decode(wi_input(), true) ?: []; }
function wf_post(): array {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') wi_json(['ok' => false, 'error' => 'POST only.'], 405);
    wi_check_csrf();
    return wf_in();
}
function wf_jid($v): string { return preg_replace('/[^0-9A-Za-z@.:_-]/', '', (string) $v); }
function wf_phone($v): string { return preg_replace('/\D/', '', (string) $v); }
function wf_admin(): void { if (!wi_is_admin() && !((string) wi_ctx('api_page') !== '' && wi_page_perm((string) wi_ctx('api_page')) === 'edit')) wi_json(['ok' => false, 'error' => 'Admins only.'], 403); }
function wf_aid(): string { return (string) (wi_current()['id'] ?? ''); }
/** Arabic-friendly normalisation for matching (no tashkeel, unified alef/ya/ta marbuta). */
function wf_norm(string $t): string {
    $t = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}\x{06D6}-\x{06ED}]/u', '', $t);
    $t = strtr($t, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', 'ی' => 'ي', 'ک' => 'ك', 'ۀ' => 'ه']);
    return mb_strtolower(trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $t)));
}
function wf_words(string $t): array {
    static $stop = null;
    $stop ??= array_flip(['في', 'من', 'على', 'الى', 'عن', 'ما', 'لا', 'يا', 'هو', 'هي', 'انا', 'انت', 'ده', 'دي', 'اللي', 'و', 'او', 'ثم', 'ان', 'كان', 'مع', 'هل', 'كده', 'بس', 'عشان', 'لو', 'كل', 'the', 'and']);
    $w = array_filter(explode(' ', wf_norm($t)), fn($x) => mb_strlen($x) > 2 && !isset($stop[$x]));
    return array_values(array_unique($w));
}

/** Studio theme palette: accent, its chart partner (each pair checked for contrast and colour blindness), name. */
const WF_UI_PALETTE = [
    ['Blue', '#2563eb', '#d97706'], ['Violet', '#7c3aed', '#0d9488'], ['Indigo', '#4338ca', '#ca8a04'], ['Green', '#15803d', '#7c3aed'], ['Amber', '#b45309', '#1d4ed8'],
    ['Ocean', '#0369a1', '#c026d3'], ['Purple', '#9333ea', '#16a34a'], ['Teal', '#0d9488', '#9333ea'], ['Pink', '#db2777', '#0284c7'], ['Orange', '#ea580c', '#4f46e5'],
];
const WF_UI_CANVAS = [['Cool grey', '#f7f8fb'], ['Pure white', '#ffffff'], ['Warm sand', '#faf7f2'], ['Mint', '#f4faf6'], ['Lilac', '#f8f6fd'], ['Sky', '#f3f8fd']];
