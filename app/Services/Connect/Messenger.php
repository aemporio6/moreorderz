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
 * Messages that leave the tool: WhatsApp (wa_outbound) and e-mail (email_outbound). Engines only queue; the worker (mo:send-messages, every minute) sends.
 * Nothing is sent without a working channel: without a WhatsApp connection / an e-mail server the message waits as HELD and goes out as soon as one is set up.
 * A message that fails is tried again 3 times (15 / 30 / 45 minutes), then the Admin gets task SYS_WA_FAILING.
 */
class Messenger
{
    public static function whatsappOf(int $accountId): ?string
    {
        $n = preg_replace('/\D/', '', (string) DB::table('t_account')->where('id', $accountId)->value('whatsapp_number'));
        return strlen($n) >= 8 ? $n : null;
    }

    public static function emailOf(int $accountId): ?string
    {
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

    public static function queueWa(int $ent, ?int $accountId, string $phone, string $code, array $vars, ?string $linkTable = null, ?int $linkId = null): int
    {
        [$body] = self::render($code, $vars);
        return DB::table('wa_outbound')->insertGetId(['fk_id_ent' => $ent, 'fk_id_account' => $accountId, 'phone' => mb_substr($phone, 0, 30), 'template_code' => $code, 'body' => $body . "\n\x1e" . json_encode($vars), 'link_table' => $linkTable, 'link_id' => $linkId, 'status' => 'QUEUED', 'queued_at' => now()]);
    }

    public static function queueEmail(int $ent, ?int $accountId, string $to, string $subject, string $body, ?string $file = null, ?string $code = null, ?string $linkTable = null, ?int $linkId = null): int
    {
        return DB::table('email_outbound')->insertGetId(['fk_id_ent' => $ent, 'fk_id_account' => $accountId, 'to_address' => mb_substr($to, 0, 190), 'subject' => mb_substr($subject, 0, 200), 'body' => $body . ($file ? "\n\x1e" . $file : ''),
            'template_code' => $code, 'link_table' => $linkTable, 'link_id' => $linkId, 'status' => 'QUEUED', 'queued_at' => now()]);
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

    /** Morning digest for each user with late or urgent tasks (choice TASK_DIGEST). */
    public static function digests(): int
    {
        $n = 0;
        foreach (DB::table('tasks as t')->join('t_users as u', 'u.id', '=', 't.owner_user_id')->whereNotIn('t.status', ['DONE', 'APPROVED', 'CANCELLED'])->where('u.f_active', 1)
            ->groupBy('u.id', 'u.fk_id_account', 'u.display_name', 't.fk_id_ent')->selectRaw("u.id, u.fk_id_account, u.display_name, t.fk_id_ent ent, SUM(t.due_at < CURDATE()) late, SUM(t.priority = 'URGENT') urgent")->get() as $u) {
            if (Choices::get('TASK_DIGEST', (int) $u->ent) !== 'WHATSAPP' || (! $u->late && ! $u->urgent) || ! $u->fk_id_account || ! ($p = self::whatsappOf((int) $u->fk_id_account))) continue;
            if (DB::table('wa_outbound')->where('template_code', 'DIGEST')->where('fk_id_account', $u->fk_id_account)->where('queued_at', '>=', today())->exists()) continue;
            self::queueWa((int) $u->ent, (int) $u->fk_id_account, $p, 'DIGEST', ['name' => $u->display_name, 'late' => (int) $u->late, 'urgent' => (int) $u->urgent, 'link' => self::link('#/tasks')]); $n++;
        }
        return $n;
    }

    private static function waConnection(int $ent): ?object
    {
        return DB::table('api_connections')->where('adapter', 'WHATSAPP_CLOUD')->whereIn('fk_id_ent', [$ent, 0])->whereNotIn('status', ['OFF', 'EXPIRED'])->orderByDesc('fk_id_ent')->first();
    }

    public static function emailReady(): bool { return ! in_array(config('mail.default'), ['log', 'array', null, ''], true) || app()->bound('mail.fake'); }

    /** The worker: send what is due. */
    public static function sendDue(int $max = 100): array
    {
        $job = DB::table('sys_job_runs')->insertGetId(['job_code' => 'MSG_SEND', 'started_at' => now(), 'run_status' => 'RUNNING']);
        $done = ['wa_sent' => 0, 'wa_held' => 0, 'wa_failed' => 0, 'mail_sent' => 0, 'mail_held' => 0, 'mail_failed' => 0];
        $due = fn ($q) => $q->where(fn ($w) => $w->where('status', 'QUEUED')->orWhere('status', 'HELD')->orWhere(fn ($f) => $f->where('status', 'FAILED')->where('attempts', '<', 3)))->where(fn ($w) => $w->whereNull('next_try_at')->orWhere('next_try_at', '<=', now()))->orderBy('id')->limit($max);
        foreach ($due(DB::table('wa_outbound'))->get() as $m) {
            $c = self::waConnection((int) $m->fk_id_ent);
            if (! $c) { if ($m->status !== 'HELD') DB::table('wa_outbound')->where('id', $m->id)->update(['status' => 'HELD', 'error' => 'No WhatsApp connection is set up (System › API connections). It goes out as soon as one is.']); $done['wa_held']++; continue; }
            $vars = json_decode((string) (explode("\n\x1e", $m->body, 2)[1] ?? '[]'), true) ?: [];
            try {
                [, $params] = self::render((string) $m->template_code, $vars);
                $ad = Connections::adapter($c); $id = $ad->send($m->phone, (string) $m->template_code, $params);
                DB::table('wa_outbound')->where('id', $m->id)->update(['status' => 'SENT', 'provider_msg_id' => $id, 'sent_at' => now(), 'error' => null, 'attempts' => $m->attempts + 1]); $done['wa_sent']++;
                TaskEngine::close('SYS_WA_FAILING', [(int) $m->fk_id_ent], 'WhatsApp sends again');
            } catch (\Throwable $e) {
                $a = $m->attempts + 1;
                DB::table('wa_outbound')->where('id', $m->id)->update(['status' => 'FAILED', 'attempts' => $a, 'error' => mb_substr($e->getMessage(), 0, 255), 'next_try_at' => now()->addMinutes(15 * $a)]); $done['wa_failed']++;
                if ($a >= 3) TaskEngine::open('SYS_WA_FAILING', [(int) $m->fk_id_ent], 'WhatsApp messages are not going out: ' . mb_substr($e->getMessage(), 0, 150), ['ent' => (int) $m->fk_id_ent, 'fix_url' => '#/messages']);
                if ($e instanceof ApiError && $e->auth) DB::table('api_connections')->where('id', $c->id)->update(['status' => 'EXPIRED', 'last_error' => mb_substr($e->getMessage(), 0, 255)]);
            }
        }
        foreach ($due(DB::table('email_outbound'))->get() as $m) {
            if (! self::emailReady()) { if ($m->status !== 'HELD') DB::table('email_outbound')->where('id', $m->id)->update(['status' => 'HELD', 'error' => 'No e-mail server is set up (MAIL_… lines of the .env file). It goes out as soon as one is.']); $done['mail_held']++; continue; }
            [$body, $file] = array_pad(explode("\n\x1e", $m->body, 2), 2, null);
            try {
                Mail::raw($body, function ($msg) use ($m, $file) {
                    $msg->to($m->to_address)->subject($m->subject);
                    if ($file && Storage::disk('local')->exists($file)) $msg->attach(Storage::disk('local')->path($file), ['as' => basename($file), 'mime' => 'application/pdf']);
                });
                DB::table('email_outbound')->where('id', $m->id)->update(['status' => 'SENT', 'sent_at' => now(), 'error' => null, 'attempts' => $m->attempts + 1]); $done['mail_sent']++;
            } catch (\Throwable $e) {
                $a = $m->attempts + 1; DB::table('email_outbound')->where('id', $m->id)->update(['status' => 'FAILED', 'attempts' => $a, 'error' => mb_substr($e->getMessage(), 0, 255), 'next_try_at' => now()->addMinutes(15 * $a)]); $done['mail_failed']++;
            }
        }
        DB::table('sys_job_runs')->where('id', $job)->update(['finished_at' => now(), 'run_status' => 'OK', 'items_done' => $done['wa_sent'] + $done['mail_sent'], 'messages_queued' => $done['wa_held'] + $done['mail_held']]);
        return $done;
    }

    /**
     * WhatsApp webhook (POST). Only a body signed with the app secret of one of the WhatsApp connections is read.
     * A reply is kept in the inbox; "DONE" answering a task message from the task owner's number closes a hand-made task.
     * "OK" / "NO" to an approval is NOT acted on (an approval needs the person signed in) – it is kept and the person is sent the link.
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
                if (isset($map[$st['status'] ?? ''])) DB::table('wa_outbound')->where('provider_msg_id', $st['id'] ?? '')->update(['status' => $map[$st['status']]] + ($st['status'] === 'failed' ? ['error' => mb_substr((string) ($st['errors'][0]['title'] ?? 'failed'), 0, 255)] : []));
            }
            foreach ($ch['value']['messages'] ?? [] as $msg) {
                $id = (string) ($msg['id'] ?? ''); if ($id === '' || DB::table('wa_inbound')->where('provider_msg_id', $id)->exists()) continue;
                $phone = preg_replace('/\D/', '', (string) ($msg['from'] ?? '')); $text = trim((string) ($msg['text']['body'] ?? $msg['button']['text'] ?? $msg['image']['caption'] ?? ''));
                $acc = DB::table('t_account')->whereRaw("REGEXP_REPLACE(whatsapp_number, '[^0-9]', '') = ?", [$phone])->value('id');
                $ctx = (string) ($msg['context']['id'] ?? ''); $out = $ctx !== '' ? DB::table('wa_outbound')->where('provider_msg_id', $ctx)->first() : null;
                $status = 'NEW'; $note = null;
                if ($out && $out->link_table === 'tasks' && preg_match('/^(done|ok done|completed)\b/i', $text) && $acc) {
                    $t = DB::table('tasks as t')->join('t_users as u', 'u.id', '=', 't.owner_user_id')->where('t.id', $out->link_id)->first(['t.*', 'u.fk_id_account']);
                    if ($t && (int) $t->fk_id_account === (int) $acc && $t->source !== 'RULE' && ! in_array($t->status, ['DONE', 'CANCELLED'], true)) {
                        DB::table('tasks')->where('id', $t->id)->update(['status' => 'DONE', 'done_at' => now(), 'last_update_at' => now()]);
                        DB::table('task_history')->insert(['task_id' => $t->id, 'at' => now(), 'user_id' => 0, 'field' => 'status', 'old_value' => $t->status, 'new_value' => 'DONE', 'reason' => mb_substr('Closed by a WhatsApp reply from the owner\'s number' . (isset($msg['image']['id']) ? ' with a photo (media ' . $msg['image']['id'] . ')' : ''), 0, 255)]);
                        $status = 'HANDLED'; $note = 'Task #' . $t->id . ' closed'; $closed++;
                    } else $note = $t && $t->source === 'RULE' ? 'Rule task: it closes by itself when the problem is gone' : 'Not the task owner, or the task is already closed';
                } elseif ($out && $out->link_table === 'sys_approvals' && preg_match('/^(ok|no|yes)\b/i', $text)) $note = 'Approvals are decided in the tool, signed in – not by a WhatsApp reply';
                DB::table('wa_inbound')->insert(['fk_id_ent' => (int) $conn->fk_id_ent, 'provider_msg_id' => mb_substr($id, 0, 120), 'phone' => mb_substr($phone, 0, 30), 'fk_id_account' => $acc, 'body' => $text, 'media_id' => $msg['image']['id'] ?? $msg['document']['id'] ?? null,
                    'reply_to' => $ctx ?: null, 'status' => $status, 'note' => $note, 'received_at' => now()]);
                $n++;
            }
        }
        return ['messages' => $n, 'tasks_closed' => $closed];
    }
}
