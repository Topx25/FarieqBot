<?php
/* ---------- Library texts written by hand (Topx1.Host) ----------
 * A written text, a price list, an answer: added, edited and deleted from the Library page, with a cover, a tag,
 * a title, a topic and keywords. Writing its tag in a chat or group sends it (with the cover). Stored per number in lib_texts.json. */

function wf_lib_texts(string $aid): array { return array_values((array) (wi_read('lib_texts.json', [])[$aid] ?? [])); }
function wf_lib_text_get(string $aid, string $id): ?array { foreach (wf_lib_texts($aid) as $t) if (($t['id'] ?? '') === $id) return $t; return null; }
/** The text whose tag this message is (the tag alone), or null. */
function wf_lib_text_code_in(string $aid, string $text): ?array {
    $t = trim($text); if ($t === '' || $t[0] !== '#' || mb_strlen($t) > 70) return null; $want = str_replace(['-', ' '], '', wf_intake_norm(wf_media_code_clean($t)));
    foreach (wf_lib_texts($aid) as $x) if (trim((string) ($x['code'] ?? '')) !== '' && str_replace(['-', ' '], '', wf_intake_norm(wf_media_code_clean((string) $x['code']))) === $want) return $x;
    return null;
}
/** Sends a library text to a chat: its cover with the text as the caption (or the text alone). */
function wf_lib_text_send(array $acc, string $jid, array $t, string $quote = ''): bool {
    $cover = (string) ($t['cover'] ?? ''); $body = mb_substr((string) ($t['text'] ?? ''), 0, 3900);
    $r = wi_gateway('/chat/send', ['jid' => $jid, 'text' => $body, 'via' => 'bot'] + ($cover !== '' ? ['attachment' => $cover] : []) + ($quote !== '' ? ['quote_id' => $quote] : []), $acc);
    wi_log('lib_text_send', ['account' => (string) $acc['id'], 'jid' => $jid, 'sent' => !empty($r['ok'])]);
    return !empty($r['ok']);
}
/** The hand-written texts as library groups (same shape the library cards read). */
function wf_lib_text_groups(string $aid): array {
    $out = [];
    foreach (wf_lib_texts($aid) as $t) { $at = (string) ($t['updated'] ?? $t['created'] ?? date('c'));
        $out[] = ['section' => (string) ($t['section'] ?? ''), 'topic' => (string) ($t['topic'] ?? ''), 'title' => (string) ($t['title'] ?? ''), 'audio' => false, 'manual' => true, 'mid' => (string) $t['id'], 'code' => (string) ($t['code'] ?? ''),
            'keywords' => (string) ($t['keywords'] ?? ''), 'cover' => (string) ($t['cover'] ?? ''), 'versions' => 1, 'total' => 1, 'chats' => 0, 'refs' => [], 'others' => [], 'sig' => 'm:' . $t['id'],
            'best' => ['key' => 'text:' . $t['id'], 'text' => (string) ($t['text'] ?? ''), 'time' => $at, 'last' => $at, 'count' => 1, 'score' => 0, 'jid' => '', 'dir' => 'out', 'attachment' => (string) ($t['cover'] ?? '')]]; }
    return $out;
}
