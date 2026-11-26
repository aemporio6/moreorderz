<?php

namespace App\Services\Connect;

use App\Services\Audit;
use App\Services\Choices;
use App\Services\Context;
use App\Services\TaskEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Messages that leave the tool: WhatsApp and e-mail, all in the one queue tm_messages (R-G_CORE-46; the old wa_outbound / email_outbound / fin_messages are history).
 * Engines only queue; the worker (mo:send-messages, every minute, TmQueue) sends. Nothing is sent without a working channel: the message waits QUEUED with the reason
 * and goes out as soon as one is set up. A failed send is tried again (send_retry_minutes) up to send_retry_max times, then FAILED and the Admin gets SYS_WA_FAILING.
 */
class Messenger
{
    /** $optional = a message the person may switch off (t_account.wa_enabled / email_enabled); statements are never optional. */
    public static function whatsappOf(int $accountId, bool $optional = false): ?string
    {
        if ($optional && ! (int) (DB::table('t_account')->where('id', $accountId)->value('wa_enabled') ?? 1)) return null;
        $n = preg_replace('/\D/', '', (string) DB::table('t_account')->where('id', $accountId)->value('whatsapp_number'));
        return strlen($n) >= 8 ? $n : null;
    }

    public static function emailOf(int $accountId, bool $optional = false): ?string
    {
        if ($optional && ! (int) (DB::table('t_account')->where('id', $accountId)->value('email_enabled') ?? 1)) return null;
        $e = DB::table('t_account_emails')->where('fk_id_account', $accountId)->where('f_active', 1)->orderBy('id')->value('email')
            ?? DB::table('t_users')->where('fk_id_account', $accountId)->where('f_active', 1)->value('login_id');
        return $e && filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : null;
    }

    /** Fill a template body: {name} … ; the values in the order the body uses them are the WhatsApp template parameters. */
    public static function render(string $code, array $vars): array
    {
        $t = DB::table('wa_templates')->where('code', $code)->where('f_active', 1)->first() ?? throw new \InvalidArgumentException("Message template $code is missing or switched off.");
        preg_match_all('/\{(\w+)\}/', $t->body, $m); $params = array_map(fn ($k) => (string) ($vars[$k] ?? ''), $m[1]);
        return [str_replace(array_map(fn ($k) => '{' . $k . '}', array_keys($vars)), array_map('strval', array_values($vars)), $t->body), $params, $t->title];
    }

    /**
     * R-G_CORE-46 the one door: every engine queues into tm_messages (one row per person × channel, UNIQUE event key + channel – a key given twice is one row).
     * The person is the account; the login (user_id) is filled when the person has one. Quiet hours of the person apply (not to a reply inside a conversation).
     */
    private static function put(int $ent, ?int $accountId, string $channel, string $to, ?string $code, string $text, array $more, ?string $eventKey, bool $quiet = true): int
    {
        $user = $accountId ? DB::table('t_users')->where('fk_id_account', $accountId)->where('f_active', 1)->orderBy('id')->value('id') : null;
        $later = $quiet && $accountId ? \App\Services\PersonChannels::quietUntil(DB::table('t_account')->where('id', $accountId)->first(['id', 'fk_id_ent', 'timezone', 'quiet_from', 'quiet_to'])) : null;
        $key = mb_substr($eventKey ?? ('Q:' . ($code ?: $channel) . ':' . bin2hex(random_bytes(8))), 0, 120);
        DB::table('tm_messages')->insertOrIgnore($more + ['fk_id_ent' => $ent, 'fk_id_account' => $accountId, 'user_id' => $user, 'channel' => $channel, 'direction' => 'OUT', 'to_address' => mb_substr($to, 0, 200),
            'template_key' => $code, 'rule_code' => $code ? mb_substr($code, 0, 40) : null, 'text' => $text, 'event_key' => $key, 'status' => \App\Services\Sandbox::is($ent) && $channel !== 'INAPP' ? 'IGNORED' : 'QUEUED', 'send_after' => $later[0] ?? null,
            'note' => \App\Services\Sandbox::is($ent) && $channel !== 'INAPP' ? 'Practice company – nothing leaves the tool (MR-1541)' : ($later[1] ?? null), 'retry_count' => 0, 'created_at' => now()]);
        return (int) DB::table('tm_messages')->where('event_key', $key)->where('channel', $channel)->value('id');
    }

    public static function queueWa(int $ent, ?int $accountId, string $phone, string $code, array $vars, ?string $linkTable = null, ?int $linkId = null, ?string $eventKey = null): int
    {
        [$body, $params] = self::render($code, $vars);
        return self::put($ent, $accountId, 'WHATSAPP', $phone, $code, $body, ['template_params' => json_encode($params), 'link_table' => $linkTable, 'link_id' => $linkId], $eventKey);
    }

    /** A message that must go at once (a sign-in code): quiet hours do not hold it. */
    public static function queueWaNow(int $ent, ?int $accountId, string $phone, string $code, array $vars, ?string $linkTable = null, ?int $linkId = null): int
    {
        [$body, $params] = self::render($code, $vars);
        return self::put($ent, $accountId, 'WHATSAPP', $phone, $code, $body, ['template_params' => json_encode($params), 'link_table' => $linkTable, 'link_id' => $linkId], null, false);
    }

    /** A free-text reply inside the 24-hour window (WhatsApp commands, R-G_CORE-52) – goes at once, quiet hours do not hold a reply. */
    public static function queueText(int $ent, ?int $accountId, string $phone, string $text, ?string $linkTable = null, ?int $linkId = null): int
    {
        return self::put($ent, $accountId, 'WHATSAPP', $phone, 'FREE_TEXT', mb_substr($text, 0, 4000), ['link_table' => $linkTable, 'link_id' => $linkId], null, false);
    }

    public static function queueEmail(int $ent, ?int $accountId, string $to, string $subject, string $body, ?string $file = null, ?string $code = null, ?string $linkTable = null, ?int $linkId = null, ?string $eventKey = null): int
    {
        return self::put($ent, $accountId, 'EMAIL', $to, $code, $body, ['subject' => mb_substr($subject, 0, 200), 'attachment' => $file, 'link_table' => $linkTable, 'link_id' => $linkId], $eventKey);
    }

    /** A message that could not be queued (no number / switched off) is still one row, FAILED with the reason (R-G_CORE-46 (5)). */
    public static function notSent(int $ent, ?int $accountId, ?int $userId, string $channel, string $code, string $text, string $why, ?string $eventKey = null): int
    {
        $key = mb_substr($eventKey ?? ('Q:' . $code . ':' . bin2hex(random_bytes(8))), 0, 120);
        DB::table('tm_messages')->insertOrIgnore(['fk_id_ent' => $ent, 'fk_id_account' => $accountId, 'user_id' => $userId, 'channel' => $channel, 'direction' => 'OUT', 'rule_code' => mb_substr($code, 0, 40), 'text' => $text,
            'event_key' => $key, 'status' => $channel === 'INAPP' ? 'SENT' : 'FAILED', 'error' => $channel === 'INAPP' ? null : $why, 'failed_at' => $channel === 'INAPP' ? null : now(), 'sent_at' => $channel === 'INAPP' ? now() : null, 'retry_count' => 0, 'created_at' => now()]);
        return (int) DB::table('tm_messages')->where('event_key', $key)->where('channel', $channel)->value('id');
    }

    private static function link(string $hash): string { return rtrim((string) config('app.url'), '/') . '/' . $hash; }

    /** A partner statement: WhatsApp and / or e-mail to the partner, by the partner's own contact details. Returns what was queued. */
    public static function statement(int $ent, int $statementId): array
    {
        $s = DB::table('fin_statements as s')->join('aud_runs as r', 'r.id', '=', 's.period_id')->where('s.id', $statementId)->where('r.fk_id_ent', $ent)->first(['s.*', 'r.period_month', 'r.run_code']) ?? throw new \InvalidArgumentException('Statement not found.');
        if (! $s->investor_id) throw new \InvalidArgumentException('The company statement is not sent to anybody.');
        $acc = \App\Services\PartnerService::account($ent, (int) $s->investor_id);
        $vars = ['month' => date('F Y', strtotime($s->period_month . '-01')), 'profit' => number_format((float) $s->partner_profit, 2), 'balance' => number_format((float) ($acc['balance'] ?? $s->partner_balance ?? 0), 2), 'link' => self::link('#/partners/' . $s->investor_id)];
        $out = [];
        if ($p = self::whatsappOf((int) $s->investor_id)) $out['whatsapp'] = self::queueWa($ent, (int) $s->investor_id, $p, 'STATEMENT', $vars, 'fin_statements', $statementId);
        if ($e = self::emailOf((int) $s->investor_id)) {
            [$body] = self::render('STATEMENT', $vars);
            $out['email'] = self::queueEmail($ent, (int) $s->investor_id, $e, 'Your statement ' . $vars['month'] . ' (' . $s->run_code . ', version ' . $s->version . ')', $body, $s->file_type === 'PDF' ? $s->file_path : null, 'STATEMENT', 'fin_statements', $statementId);
        }
        if (! $out) throw new \InvalidArgumentException('The partner has no WhatsApp number and no e-mail address in People & companies: nothing could be sent.');
        Audit::log('fin_statements', $statementId, 'UPDATE', ['sent' => array_keys($out)], 'Statement queued to the partner', $ent);
        return $out;
    }

    /** At the lock, when the Owner chose to send statements by themselves. */
    public static function statementsOfRun(int $ent, int $runId): int
    {
        if (Choices::get('STATEMENT_SEND', $ent, (int) (DB::table('aud_runs')->where('id', $runId)->value('fk_id_branch') ?? 0) ?: null) !== 'AUTO') return 0;
        $n = 0;
        foreach (DB::table('fin_statements')->where('period_id', $runId)->whereNotNull('investor_id')->where('superseded', 0)->pluck('id') as $id) { try { self::statement($ent, (int) $id); $n++; } catch (\InvalidArgumentException) {} }
        return $n;
    }

    /** R-G_CORE-45: the daily digest is one engine (TaskDigest, N13 at each person's digest time) – kept here for old callers. */
    public static function digests(): int { return \App\Services\TaskDigest::run(); }

    /** R-G_CORE-53 provider switch: the company's WhatsApp connection – Meta (WHATSAPP_CLOUD), 360dialog (WHATSAPP_360) or the test log provider (WHATSAPP_LOG). */
    public const WA_ADAPTERS = ['WHATSAPP_CLOUD', 'WHATSAPP_360', 'WHATSAPP_LOG'];
    public static function waConnection(int $ent): ?object
    {
        return DB::table('api_connections')->whereIn('adapter', self::WA_ADAPTERS)->whereIn('fk_id_ent', [$ent, 0])->whereNotIn('status', ['OFF', 'EXPIRED'])->orderByDesc('fk_id_ent')->first();
    }

    public static function emailReady(): bool { return ! in_array(config('mail.default'), ['log', 'array', null, ''], true) || app()->bound('mail.fake'); }

    /** The worker: send what is due – one sender for every engine (R-G_CORE-46). */
    public static function sendDue(int $max = 100): array { return \App\Services\TmQueue::dispatch($max); }

    /**
     * WhatsApp webhook (POST). Only a body signed with the app secret of one of the WhatsApp connections is read.
     * Every message is kept in the inbox and read as a command (WaCommands, R-G_CORE-52): task ACK / START / DONE / STUCK / APPROVE / REWORK, media as proof,
     * OK / NO to an approval or a capital call; a free-text reply goes back inside the 24-hour window. "tasks_closed" counts tasks moved to Waiting approval.
     */
    public static function inbound(string $raw, ?string $signature): array
    {
        $conn = null;
        foreach (DB::table('api_connections')->where('adapter', 'WHATSAPP_CLOUD')->get() as $c) { $s = Connections::secrets($c); if (WhatsAppCloud::signatureOk($raw, $signature, (string) ($s['app_secret'] ?? ''))) { $conn = $c; break; } }
        if (! $conn) throw new \Illuminate\Auth\Access\AuthorizationException('Signature does not match.');
        $j = json_decode($raw, true) ?: []; $n = 0; $closed = 0;
        foreach ($j['entry'] ?? [] as $e) foreach ($e['changes'] ?? [] as $ch) {
            foreach ($ch['value']['statuses'] ?? [] as $st) {   // delivery receipts of messages we sent
                $map = ['delivered' => 'DELIVERED', 'read' => 'READ', 'failed' => 'FAILED'];
                if (isset($map[$st['status'] ?? ''])) \App\Services\TmQueue::receipt((string) ($st['id'] ?? ''), $map[$st['status']], (string) ($st['errors'][0]['title'] ?? ''));      // R-G_CORE-46 forward only
                if (isset($map[$st['status'] ?? ''])) DB::table('wa_outbound')->where('provider_msg_id', $st['id'] ?? '')->update(['status' => $map[$st['status']]] + ($st['status'] === 'failed' ? ['error' => mb_substr((string) ($st['errors'][0]['title'] ?? 'failed'), 0, 255)] : []));
            }
            foreach ($ch['value']['messages'] ?? [] as $msg) {
                $id = (string) ($msg['id'] ?? ''); if ($id === '' || DB::table('wa_inbound')->where('provider_msg_id', $id)->exists()) continue;      // a webhook sent twice is read once
                $phone = preg_replace('/\D/', '', (string) ($msg['from'] ?? ''));
                $acc = $phone !== '' ? DB::table('t_account')->whereRaw("REGEXP_REPLACE(whatsapp_number, '[^0-9]', '') = ?", [$phone])->value('id') : null;      // "+", spaces and dashes ignored
                $type = (string) ($msg['type'] ?? 'text'); $media = $msg[$type] ?? [];
                $text = trim((string) ($msg['text']['body'] ?? $msg['button']['text'] ?? ($media['caption'] ?? '')));
                $rowId = DB::table('wa_inbound')->insertGetId(['fk_id_ent' => (int) $conn->fk_id_ent, 'provider_msg_id' => mb_substr($id, 0, 120), 'phone' => mb_substr($phone, 0, 30), 'fk_id_account' => $acc, 'body' => $text,
                    'media_id' => is_array($media) ? ($media['id'] ?? null) : null, 'reply_to' => ($msg['context']['id'] ?? null) ?: null, 'status' => 'NEW', 'received_at' => now()]);
                [$action, $status, $note, $reply, $task, $pending] = WaCommands::handle($conn, $msg, $phone, $acc ? (int) $acc : null);      // R-G_CORE-52
                DB::table('wa_inbound')->where('id', $rowId)->update(['action' => $action, 'status' => $status, 'note' => $note ? mb_substr($note, 0, 255) : null, 'fk_id_task' => $task, 'pending_json' => $pending]);
                if ($reply && $acc) self::queueText((int) $conn->fk_id_ent, (int) $acc, $phone, $reply, $task ? 'tasks' : 'wa_inbound', $task ?: $rowId);
                if ($action === 'DONE') $closed++;
                $n++;
            }
        }
        return ['messages' => $n, 'tasks_closed' => $closed];
    }
}
