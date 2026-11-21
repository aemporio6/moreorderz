<?php

namespace App\Services;

use App\Services\Connect\ApiError;
use App\Services\Connect\Connections;
use App\Services\Connect\Messenger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * R-G_CORE-46 the task message queue tm_messages – the sender (every minute, mo:send-messages):
 * - only rows that are QUEUED and due (send_after empty or passed – quiet hours, R-G_CORE-47) are taken;
 * - claim before send: UPDATE … SET status = SENDING WHERE id = ? AND status = QUEUED – a row is sent only by the run that claimed it (web, cron and webhook never double-send);
 * - WhatsApp: inside the 24-hour window (the person wrote to us) free text, outside it only an APPROVED template of the register (R-G_CORE-53), else FAILED
 *   "Outside the 24-hour window – template needed"; provider = the company's WhatsApp connection (Meta, 360dialog or the log provider);
 * - no connection / no mail server yet → back to QUEUED with the reason, no try counted (it goes as soon as one is set up);
 * - a failed send goes back to QUEUED after send_retry_minutes (10); FAILED with the provider error after send_retry_max (3) retries or at once when the error is permanent;
 * - status only moves forward SENT → DELIVERED → READ (a late "delivered" after "read" is ignored).
 */
class TmQueue
{
    public const ORDER = ['QUEUED' => 0, 'SENDING' => 1, 'SENT' => 2, 'DELIVERED' => 3, 'READ' => 4];

    /** Did this person write to us in the last 24 hours (WhatsApp lets us answer with free text)? */
    public static function inWindow(?int $accountId, ?\DateTimeInterface $now = null): bool
    {
        if (! $accountId) return false;
        $since = (\DateTimeImmutable::createFromInterface($now ?? new \DateTimeImmutable()))->modify('-24 hours')->format('Y-m-d H:i:s');
        return DB::table('wa_inbound')->where('fk_id_account', $accountId)->where('received_at', '>=', $since)->exists();
    }

    public static function dispatch(int $max = 200, ?\DateTimeInterface $now = null): array
    {
        $now = \DateTimeImmutable::createFromInterface($now ?? new \DateTimeImmutable()); $ts = $now->format('Y-m-d H:i:s');
        $done = ['sent' => 0, 'waiting' => 0, 'retry' => 0, 'failed' => 0];
        $rows = DB::table('tm_messages')->where('status', 'QUEUED')->whereIn('channel', ['WHATSAPP', 'EMAIL'])->where(fn ($w) => $w->whereNull('send_after')->orWhere('send_after', '<=', $ts))->orderBy('id')->limit($max)->get();
        foreach ($rows as $m) {
            if (! DB::table('tm_messages')->where('id', $m->id)->where('status', 'QUEUED')->update(['status' => 'SENDING'])) continue;      // claimed by another run
            $user = $m->user_id ? DB::table('t_users')->where('id', $m->user_id)->first(['id', 'fk_id_ent', 'fk_id_account']) : null;
            $ent = (int) ($m->fk_id_ent ?? 0) ?: (int) ($user->fk_id_ent ?? 0); $acc = (int) ($m->fk_id_account ?? 0) ?: (int) ($user->fk_id_account ?? 0); $c = null;
            try {
                if ($m->channel === 'WHATSAPP') {
                    $c = Messenger::waConnection($ent);
                    if (! $c) { self::wait($m, 'No WhatsApp connection is set up (System › API connections). It goes out as soon as one is.'); $done['waiting']++; continue; }
                    $ad = Connections::adapter($c);
                    if (self::inWindow($acc ?: null, $now)) $id = $ad->sendText((string) $m->to_address, (string) $m->text);
                    elseif ($m->template_key === 'FREE_TEXT') throw new \DomainException('Outside the 24-hour window – template needed');
                    else {
                        $tpl = DB::table('wa_templates')->where('code', $m->template_key)->where('f_active', 1)->where('approval_status', 'APPROVED')->first();
                        if (! $tpl) throw new \DomainException('Outside the 24-hour window – template needed');
                        $id = $ad->send((string) $m->to_address, (string) ($tpl->provider_name ?: $tpl->code), json_decode((string) $m->template_params, true) ?: []);
                    }
                    $note = $c->adapter === 'WHATSAPP_LOG' ? 'log provider' : null;
                } else {
                    if (! Messenger::emailReady()) { self::wait($m, 'No e-mail server is set up (MAIL_… lines of the .env file). It goes out as soon as one is.'); $done['waiting']++; continue; }
                    $send = str_starts_with(ltrim((string) $m->text), '<') ? 'html' : 'raw';      // an HTML report (N14) goes as HTML
                    Mail::$send((string) $m->text, function ($msg) use ($m) {
                        $msg->to((string) $m->to_address)->subject((string) ($m->subject ?: 'MoreOrderz'));
                        $f = (string) ($m->attachment ?? '');
                        if ($f !== '' && \Illuminate\Support\Facades\Storage::disk('local')->exists($f)) $msg->attach(\Illuminate\Support\Facades\Storage::disk('local')->path($f), ['as' => basename($f)]);
                    });
                    $id = null; $note = null;
                }
                DB::table('tm_messages')->where('id', $m->id)->update(['status' => 'SENT', 'sent_at' => now(), 'provider_id' => $id, 'error' => null] + ($note ? ['note' => $note] : []));
                if ($m->channel === 'WHATSAPP') TaskEngine::close('SYS_WA_FAILING', [$ent], 'WhatsApp sends again');
                $done['sent']++;
            } catch (\Throwable $e) {
                if ($e instanceof ApiError && $e->auth && $c) DB::table('api_connections')->where('id', $c->id)->update(['status' => 'EXPIRED', 'last_error' => mb_substr($e->getMessage(), 0, 255)]);
                $tries = (int) $m->retry_count + 1; $max_ = (int) AlertRules::threshold('send_retry_max') ?: 3;
                $permanent = $e instanceof \DomainException || ($e instanceof ApiError && $e->auth);
                if ($permanent || $tries > $max_) {
                    DB::table('tm_messages')->where('id', $m->id)->update(['status' => 'FAILED', 'failed_at' => now(), 'retry_count' => $tries, 'error' => mb_substr($e->getMessage(), 0, 255)]); $done['failed']++;
                    if ($m->channel === 'WHATSAPP' && ! $e instanceof \DomainException) TaskEngine::open('SYS_WA_FAILING', [$ent], 'WhatsApp messages are not going out: ' . mb_substr($e->getMessage(), 0, 150), ['ent' => $ent, 'fix_url' => '#/messages']);
                } else {
                    DB::table('tm_messages')->where('id', $m->id)->update(['status' => 'QUEUED', 'retry_count' => $tries, 'error' => mb_substr($e->getMessage(), 0, 255),
                        'send_after' => $now->modify('+' . ((int) AlertRules::threshold('send_retry_minutes') ?: 10) . ' minutes')->format('Y-m-d H:i:s')]); $done['retry']++;
                }
            }
        }
        return $done;
    }

    private static function wait(object $m, string $why): void
    {
        DB::table('tm_messages')->where('id', $m->id)->update(['status' => 'QUEUED', 'error' => $why]);
    }

    /** A delivery receipt: moves only forward (SENT → DELIVERED → READ); FAILED from the provider is kept with its error. */
    public static function receipt(string $providerId, string $status, ?string $error = null): bool
    {
        $m = DB::table('tm_messages')->where('provider_id', $providerId)->first(); if (! $m) return false;
        $status = strtoupper($status);
        if ($status === 'FAILED') return (bool) DB::table('tm_messages')->where('id', $m->id)->whereIn('status', ['SENT', 'SENDING'])->update(['status' => 'FAILED', 'failed_at' => now(), 'error' => mb_substr((string) $error, 0, 255)]);
        if (! isset(self::ORDER[$status]) || self::ORDER[$status] <= (self::ORDER[$m->status] ?? 99)) return false;      // backwards or unknown: ignored
        return (bool) DB::table('tm_messages')->where('id', $m->id)->update(['status' => $status] + ($status === 'DELIVERED' ? ['delivered_at' => now()] : ['read_at' => now()]));
    }
}
