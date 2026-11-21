<?php

namespace App\Services\Connect;

use App\Models\User;
use App\Services\Approvals;
use App\Services\Context;
use App\Services\FinanceExtras;
use App\Services\Guide;
use App\Services\TaskFlow;
use App\Services\TaskScore;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * WhatsApp inbound commands (R-G_CORE-52, K7). One message in → one action, checked with the same rules as the web (TaskFlow, TaskScore, Approvals, capital calls),
 * and a free-text reply inside the 24-hour window. wa_inbound keeps action (ACK, START, DONE, REFUSED_DONE, STUCK, APPROVE, REWORK, EVIDENCE_PHOTO / _FILE / _VOICE,
 * COMMENT, REFUSED, NO_TASK, NOT_FOUND, MEDIA_FAILED, IGNORED, APPROVAL_OK / _NO, CALL_OK / _NO, CHOOSE) and status ACTED / RECEIVED / IGNORED.
 * The task = the task of the message replied to (context id) or "T-0012" in the text. A photo / file with a caption: the media is saved first as evidence, then the caption is a command.
 * An ambiguous OK (more than one open item it could answer) gets a numbered question and nothing is done until the number arrives.
 */
class WaCommands
{
    public const ACK = ['OK', 'ACK', 'OKAY', 'SEEN', 'ACKNOWLEDGED', '👍'];
    public const MEDIA_TYPES = [...\App\Services\TaskEvidence::IMAGE, ...\App\Services\TaskEvidence::AUDIO, ...\App\Services\TaskEvidence::FILE];      // R-G_CORE-29 the same kinds as the web
    public const MIME_EXT = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf', 'audio/ogg' => 'ogg', 'audio/mpeg' => 'mp3', 'audio/aac' => 'aac', 'audio/mp4' => 'm4a', 'video/mp4' => 'mp4', 'text/plain' => 'txt', 'text/csv' => 'csv',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx', 'application/vnd.ms-excel' => 'xls', 'application/msword' => 'doc'];
    public const MAX_BYTES = 15 * 1024 * 1024;
    private const QUALITY = ['1' => 'POOR', '2' => 'OK', '3' => 'GOOD', '4' => 'EXCELLENT', 'POOR' => 'POOR', 'OK' => 'OK', 'GOOD' => 'GOOD', 'EXCELLENT' => 'EXCELLENT'];

    /** Handle one inbound message. Returns [action, status, note, reply|null, task id|null, pending json|null]. */
    public static function handle(object $conn, array $msg, string $phone, ?int $acc): array
    {
        $ent = (int) $conn->fk_id_ent;
        $user = $acc ? User::where('fk_id_account', $acc)->where('f_active', 1)->orderBy('id')->first() : null;
        if (! $user) return ['IGNORED', 'IGNORED', 'Unknown number - ignored (R13)', null, null, null];
        $prevUser = Auth::user(); Auth::setUser($user); Context::set($ent, (int) $user->id);
        try { return self::run($conn, $msg, $phone, (int) $acc, $user, $ent); }
        finally { if ($prevUser) Auth::setUser($prevUser); else Auth::forgetUser(); }
    }

    private static function run(object $conn, array $msg, string $phone, int $acc, User $u, int $ent): array
    {
        $type = (string) ($msg['type'] ?? 'text');
        $media = in_array($type, ['image', 'document', 'audio', 'video', 'voice', 'sticker'], true) ? ($msg[$type] ?? []) : null;
        $text = trim((string) ($msg['text']['body'] ?? $msg['button']['text'] ?? $msg['interactive']['button_reply']['title'] ?? ($media['caption'] ?? '')));
        $ctx = (string) ($msg['context']['id'] ?? ''); $out = $ctx !== '' ? self::outOf($ctx) : null;

        // a number answering our numbered question
        if ($media === null && preg_match('/^\d{1,2}$/', $text) && ($q = DB::table('wa_inbound')->where('fk_id_account', $acc)->where('action', 'CHOOSE')->whereNotNull('pending_json')->where('received_at', '>=', now()->subDay())->orderByDesc('id')->first())) {
            $opts = json_decode((string) $q->pending_json, true) ?: []; $pick = $opts[(int) $text - 1] ?? null;
            DB::table('wa_inbound')->where('id', $q->id)->update(['pending_json' => null]);
            if (! $pick) return ['REFUSED', 'RECEIVED', 'Not one of the numbers', 'Reply with one of the numbers 1 to ' . count($opts) . '.', null, null];
            return self::answerOk($pick, $u, $ent, $q->body ?? 'OK');
        }

        // approvals and capital calls answered by OK / NO
        $first = strtoupper((string) strtok($text, " \t\n")); $rest = trim((string) substr($text, strlen((string) strtok($text, " \t\n"))));
        if ($out && in_array($out->link_table, ['sys_approvals', 'fin_capital_calls'], true) && in_array($first, ['OK', 'YES', 'NO'], true))
            return self::answerOk(self::itemOf($out, $acc), $u, $ent, $text);

        // which task
        $taskId = null; $ref = null;
        if (preg_match('/\bT-?0*(\d{1,7})\b/i', $text, $m)) { $taskId = (int) $m[1]; $ref = TaskFlow::ref($taskId); $text = trim(preg_replace('/\bT-?0*' . $m[1] . '\b/i', '', $text, 1)); $first = strtoupper((string) strtok($text, " \t\n")); $rest = trim((string) substr($text, strlen((string) strtok($text, " \t\n")))); }
        elseif ($out && $out->link_table === 'tasks') { $taskId = (int) $out->link_id; $ref = TaskFlow::ref($taskId); }

        // an OK with no task named: answer the one open item it can mean, or ask which
        if (! $taskId && $media === null && in_array($first, array_merge(self::ACK, ['YES', 'NO']), true)) {
            $items = self::openItems($u, $acc, $ent, $first === 'NO' ? ['approval', 'call'] : ['task', 'approval', 'call']);
            if (count($items) === 1) return self::answerOk($items[0], $u, $ent, $text);
            if (count($items) > 1) {
                $q = 'Which one? ' . implode(' ', array_map(fn ($i, $n) => ($n + 1) . ' ' . $i['label'], $items, array_keys($items))) . ' Reply ' . implode(' or ', range(1, count($items))) . '.';
                return ['CHOOSE', 'RECEIVED', 'Asked which item', $q, null, json_encode($items)];
            }
        }
        if (! $taskId) return ['NO_TASK', 'RECEIVED', 'No task named', 'Which task? Reply to the task message (swipe right on it), or start with its number, e.g. "T-0012 DONE".', null, null];
        $t = DB::table('tasks')->where('id', $taskId)->where(fn ($w) => $w->where('fk_id_ent', $ent)->orWhereNull('fk_id_ent'))->first();
        if (! $t || ! TaskFlow::canSee($t, $u)) return ['NOT_FOUND', 'RECEIVED', "Task $ref not found", "Task $ref not found for you.", null, null];

        // media first (saved as evidence), then the caption as a command
        $mediaReply = null; $mediaAction = null;
        if ($media !== null) {
            [$mediaAction, $mediaReply] = self::saveMedia($conn, $type, $media, $t, $u, $ref);
            if ($mediaAction === 'MEDIA_FAILED' || $text === '' || ! self::isCommand($first)) {
                if ($text !== '' && $mediaAction !== 'MEDIA_FAILED') self::comment($t->id, $u, $text);
                return [$mediaAction, $mediaAction === 'MEDIA_FAILED' ? 'RECEIVED' : 'ACTED', $mediaReply, $mediaReply, $t->id, null];
            }
        }
        [$action, $status, $reply] = self::command($first, $rest, $text, $t, $u, $ref);
        return [$action, $status, $reply, ($mediaReply ? $mediaReply . ' ' : '') . $reply, $t->id, null];
    }

    private static function isCommand(string $w): bool { return in_array($w, array_merge(self::ACK, ['START', 'DONE', 'STUCK', 'APPROVE', 'APPROVED', 'REWORK']), true); }

    /** One task command. Returns [action, status, reply]. */
    private static function command(string $first, string $rest, string $text, object $t, User $u, string $ref): array
    {
        try {
            switch (true) {
                case in_array($first, self::ACK, true):
                    if ((int) $t->owner_user_id !== (int) $u->id) return ['REFUSED', 'RECEIVED', "Only the owner acknowledges $ref."];
                    try { return ['ACK', 'ACTED', \App\Services\TaskActions::acknowledge((int) $t->id, $u, 'WHATSAPP')]; }      // R-G_CORE-10: one path for web, phone and WhatsApp
                    catch (\InvalidArgumentException $e) { return ['REFUSED', 'RECEIVED', $e->getMessage()]; }
                case $first === 'START':
                    TaskFlow::move($t->id, $u, 'DOING', null, 'WHATSAPP'); return ['START', 'ACTED', "$ref is now In progress."];
                case $first === 'DONE':
                    try { TaskFlow::move($t->id, $u, 'DONE', null, 'WHATSAPP'); }
                    catch (\InvalidArgumentException $e) {
                        $m = $e->getMessage();
                        $why = str_starts_with($m, 'Refused: attach evidence') || str_starts_with($m, 'Refused: a voice note') ? 'attach a photo or file first (send it here as a reply to the task message).' : preg_replace('/^Refused:\s*/', '', $m);
                        return ['REFUSED_DONE', 'RECEIVED', 'Not marked done: ' . $why];
                    }
                    $by = DB::table('t_users')->where('id', DB::table('tasks')->where('id', $t->id)->value('assigned_by_user_id'))->value('display_name');
                    return ['DONE', 'ACTED', "$ref marked done - waiting for approval" . ($by ? " by $by" : '') . '.'];
                case $first === 'STUCK':
                    if ($rest === '') return ['REFUSED', 'RECEIVED', 'Say who or what it is stuck on, e.g. "STUCK waiting for supplier PI".'];
                    Guide::stuck($t->id, $u, 'OTHER', null, $rest); self::comment($t->id, $u, 'STUCK: ' . $rest);
                    return ['STUCK', 'ACTED', "$ref is now Stuck: $rest"];
                case in_array($first, ['APPROVE', 'APPROVED'], true):
                    $q = self::QUALITY[strtoupper($rest)] ?? ($rest === '' ? 'GOOD' : null);
                    if (! $q) return ['REFUSED', 'RECEIVED', 'Reply APPROVE 1-4 (1 Poor, 2 OK, 3 Good, 4 Excellent).'];
                    TaskScore::approve($t->id, $u, $q); return ['APPROVE', 'ACTED', "$ref approved ($q)."];
                case $first === 'REWORK':
                    if ($rest === '') return ['REFUSED', 'RECEIVED', 'Say what needs to be redone, e.g. "REWORK photo is blurry".'];
                    TaskScore::rework($t->id, $u, $rest); return ['REWORK', 'ACTED', "$ref sent back for rework."];
                default:
                    self::comment($t->id, $u, $text); return ['COMMENT', 'ACTED', "Comment added to $ref."];
            }
        } catch (\InvalidArgumentException $e) { return ['REFUSED', 'RECEIVED', str_starts_with($e->getMessage(), 'Refused') ? $e->getMessage() : 'Refused: ' . $e->getMessage()]; }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { return ['REFUSED', 'RECEIVED', 'Refused: ' . $e->getMessage()]; }
    }

    private static function comment(int $task, User $u, string $text): void
    {
        \App\Services\TaskComments::add($task, $u, mb_substr($text, 0, \App\Services\TaskComments::max()), null, 'WHATSAPP');      // R-G_CORE-28 mentions, watcher, N9, last update
    }

    /** Download and keep the media as task evidence: image → PHOTO, audio → VOICE, other → FILE. Returns [action, reply]. */
    private static function saveMedia(object $conn, string $type, array $media, object $t, User $u, string $ref): array
    {
        try {
            [$bytes, $mime, $size] = Connections::adapter($conn)->media((string) ($media['id'] ?? ''));
            $mime = strtolower(trim(explode(';', (string) ($media['mime_type'] ?? $mime))[0]));
            $ext = self::MIME_EXT[$mime] ?? strtolower(pathinfo((string) ($media['filename'] ?? ''), PATHINFO_EXTENSION));
            if (! in_array($ext, self::MEDIA_TYPES, true)) throw new \InvalidArgumentException("this file type is not accepted ($mime)");
            if ($size > self::MAX_BYTES || strlen($bytes) > self::MAX_BYTES) throw new \InvalidArgumentException('the file is larger than 15 MB');
            $kind = str_starts_with($mime, 'image/') ? 'PHOTO' : (str_starts_with($mime, 'audio/') || $type === 'voice' ? 'VOICE' : 'FILE');
            $path = 'uploads/task_evidence/' . $t->id . '/' . bin2hex(random_bytes(8)) . '.' . $ext; Storage::disk('local')->put($path, $bytes);
            DB::table('task_evidence')->insert(\App\Services\TaskEvidence::exif(Storage::disk('local')->path($path), $ext) + ['task_id' => $t->id, 'type' => $kind, 'file_path' => $path, 'file_name' => mb_substr((string) ($media['filename'] ?? basename($path)), 0, 255), 'mime' => mb_substr($mime, 0, 100), 'uploaded_by' => $u->id, 'via' => 'WHATSAPP', 'created_at' => now()]);
            return $kind === 'VOICE' ? ['EVIDENCE_VOICE', "Voice note saved on $ref. It does not count as proof - send a photo or file too."]
                : ['EVIDENCE_' . $kind, ($kind === 'PHOTO' ? 'Photo' : 'File') . " saved as proof on $ref. Reply DONE when the task is finished."];
        } catch (\Throwable $e) { return ['MEDIA_FAILED', "The file could not be saved on $ref: " . mb_substr($e->getMessage(), 0, 120) . '. Please send it again.']; }
    }

    /** The approval / capital call a message was about, as an item. */
    private static function itemOf(object $out, int $acc): ?array
    {
        if ($out->link_table === 'sys_approvals') return ['kind' => 'approval', 'id' => (int) $out->link_id, 'label' => 'Approval #' . $out->link_id];
        $r = DB::table('fin_capital_call_responses')->where('call_id', $out->link_id)->where('investor_id', $acc)->first();
        return $r ? ['kind' => 'call', 'id' => (int) $r->id, 'label' => 'Capital call #' . $out->link_id] : null;
    }

    /** The message we sent that a reply quotes (R-G_CORE-46 one queue; old outbox rows are still read). A task alert counts as linked to its task. */
    private static function outOf(string $providerId): ?object
    {
        $m = DB::table('tm_messages')->where('provider_id', $providerId)->first(['link_table', 'link_id', 'task_id']);
        if ($m) return (object) ['link_table' => $m->link_table ?: ($m->task_id ? 'tasks' : null), 'link_id' => $m->link_id ?: $m->task_id];
        return DB::table('wa_outbound')->where('provider_msg_id', $providerId)->first(['link_table', 'link_id']);
    }

    /** Messages sent to this person about rows of $table (one queue + the old outbox): the linked ids. */
    private static function sentAbout(string $table, int $acc): array
    {
        $uid = (int) DB::table('t_users')->where('fk_id_account', $acc)->value('id');
        $ids = DB::table('tm_messages')->where(fn ($w) => $w->where('fk_id_account', $acc)->when($uid, fn ($x) => $x->orWhere('user_id', $uid)))
            ->where(fn ($w) => $w->where('link_table', $table)->when($table === 'tasks', fn ($x) => $x->orWhereNotNull('task_id')))->get(['link_table', 'link_id', 'task_id'])
            ->map(fn ($m) => $m->link_table === $table ? (int) $m->link_id : (int) $m->task_id)->all();
        return array_values(array_unique(array_merge($ids, DB::table('wa_outbound')->where('fk_id_account', $acc)->where('link_table', $table)->pluck('link_id')->map(fn ($x) => (int) $x)->all())));
    }

    /** What an OK / NO with no task can answer: own tasks not yet acknowledged, pending approvals and open capital calls sent to this number. */
    private static function openItems(User $u, int $acc, int $ent, array $kinds): array
    {
        $out = [];
        if (in_array('task', $kinds, true)) foreach (DB::table('tasks as t')->whereIn('t.id', self::sentAbout('tasks', $acc) ?: [0])->where('t.owner_user_id', $u->id)
            ->whereNull('t.acknowledged_at')->whereIn('t.status', TaskFlow::OPEN)->orderBy('t.id')->limit(5)->get(['t.id', 't.title']) as $t) $out[] = ['kind' => 'task', 'id' => (int) $t->id, 'label' => TaskFlow::ref((int) $t->id) . ' ' . mb_substr($t->title, 0, 40)];
        if (in_array('approval', $kinds, true)) foreach (DB::table('sys_approvals as a')->whereIn('a.id', self::sentAbout('sys_approvals', $acc) ?: [0])->where('a.status', 'PENDING')->where('a.fk_id_ent', $ent)
            ->orderBy('a.id')->limit(5)->get(['a.id', 'a.action_code', 'a.amount_gbp']) as $a) $out[] = ['kind' => 'approval', 'id' => (int) $a->id, 'label' => 'Approval ' . $a->action_code . '-' . $a->id . ' ' . number_format((float) $a->amount_gbp, 2)];
        if (in_array('call', $kinds, true)) foreach (DB::table('fin_capital_call_responses as r')->join('fin_capital_calls as c', 'c.id', '=', 'r.call_id')->where('r.investor_id', $acc)->where('r.status', 'SENT')->where('c.status', 'OPEN')->where('c.company_id', $ent)
            ->orderBy('r.id')->limit(5)->get(['r.id', 'c.id as call', 'r.amount_asked']) as $c) $out[] = ['kind' => 'call', 'id' => (int) $c->id, 'label' => 'Capital call #' . $c->call . ' ' . number_format((float) $c->amount_asked, 2)];
        return $out;
    }

    /** OK / NO (+ reason) on one item. Returns the handle() tuple. */
    private static function answerOk(?array $item, User $u, int $ent, string $text): array
    {
        if (! $item) return ['NOT_FOUND', 'RECEIVED', 'Item not found', 'This request was not found for you.', null, null];
        $no = (bool) preg_match('/^\s*NO\b/i', $text); $reason = trim((string) preg_replace('/^\s*(OK|YES|NO|OKAY)\b[\s:,-]*/i', '', $text)) ?: null;
        try {
            if ($item['kind'] === 'task') { $t = DB::table('tasks')->where('id', $item['id'])->first(); [$a, $s, $r] = self::command('OK', '', 'OK', $t, $u, TaskFlow::ref($item['id'])); return [$a, $s, $r, $r, $item['id'], null]; }
            if ($item['kind'] === 'approval') {
                Approvals::decide($item['id'], ! $no, $reason);
                $r = ($no ? 'Rejected: ' : 'Approved: ') . $item['label'] . '.'; return [$no ? 'APPROVAL_NO' : 'APPROVAL_OK', 'ACTED', $r, $r, null, null];
            }
            FinanceExtras::callAnswer($ent, $item['id'], $no ? 'DECLINED' : 'ACCEPTED');
            $r = $no ? 'Noted: you will not put money in for ' . $item['label'] . '.' : 'Thank you – ' . $item['label'] . ' accepted. Finance books it when the money is on the bank.';
            return [$no ? 'CALL_NO' : 'CALL_OK', 'ACTED', $r, $r, null, null];
        } catch (\InvalidArgumentException $e) { $r = str_starts_with($e->getMessage(), 'Refused') ? $e->getMessage() : 'Refused: ' . $e->getMessage(); return ['REFUSED', 'RECEIVED', $r, $r, null, null]; }
    }
}
