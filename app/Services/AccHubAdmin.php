<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * RULES_E_FINANCE the Accountancy Hub around the Tax & filings schedule:
 * R-ACC-16 credentials masked "••••••" + last 3, reveal for the Owner or a Hub member with can_reveal, every attempt logged SHOWN / DENIED (user, IP, time), history on the page;
 * R-ACC-18 person UTR / NI: checked, encrypted (Laravel Crypt = AES-256-CBC + HMAC-SHA256), last 4 kept, refused without an app key, "Remove" clears, a wrong key → "cannot be read";
 * R-ACC-20 Hub settings (ranges, save regenerates), team (is_accountant / can_reveal, Owner only), close before a date (bulk, logged BULK_CLOSE);
 * R-ACC-21 company page: area badges, last 10 tax periods, a tax period status ticks the matching filing (R-TAX-4);
 * R-ACC-22 the reminder job: one sys_job_runs row per run with the messages queued, sender from the settings, a missing sender → FAILED + SYS_ERROR, nothing sent.
 */
class AccHubAdmin
{
    /** [key => [default, min, max]] of the Hub settings (acc_settings). */
    public const SETTINGS = ['due_soon_days' => [30, 1, 120], 'history_months' => [12, 1, 72], 'horizon_months' => [15, 3, 36], 'acc_hub_upload_max_mb' => [10, 1, 50]];
    public const HUB_SECRETS = ['cat_company_house' => ['authentication_code', 'gateway_id', 'gateway_pass', 'hmrc_activation_code', 'company_utr']];
    public const BULK_NOTE = 'Closed in bulk: handled before the Hub was used.';

    /* ---------------- team ---------------- */

    public static function member(int $ent, int $userId): ?object { return DB::table('acc_hub_members')->where('fk_id_ent', $ent)->where('user_id', $userId)->first(); }

    public static function canReveal(?object $u): bool
    {
        if (! $u) return false; if (($u->role_code ?? '') === 'OWNER') return true;
        return (bool) (self::member((int) ($u->fk_id_ent ?: Context::ent()), (int) $u->id)->can_reveal ?? false);
    }

    /** Hub accountants: the members marked is_accountant; while nobody is marked, every login that may change filings (money.EDIT). */
    public static function accountantIds(int $ent): ?array
    {
        $ids = DB::table('acc_hub_members')->where('fk_id_ent', $ent)->where('is_accountant', 1)->pluck('user_id')->map(fn ($x) => (int) $x)->all();
        return $ids ?: null;
    }

    public static function team(int $ent, string $q = ''): array
    {
        $m = DB::table('acc_hub_members')->where('fk_id_ent', $ent)->get()->keyBy('user_id');
        return DB::table('t_users')->where('fk_id_ent', $ent)->where('f_active', 1)->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('display_name', 'like', "%$q%")->orWhere('login_id', 'like', "%$q%")))
            ->orderBy('display_name')->limit(200)->get()->map(fn ($u) => ['id' => (int) $u->id, 'name' => trim((string) ($u->display_name ?: $u->login_id)), 'role' => $u->role_code,
                'is_accountant' => (bool) ($m[$u->id]->is_accountant ?? 0), 'can_reveal' => $u->role_code === 'OWNER' || (bool) ($m[$u->id]->can_reveal ?? 0)])->all();
    }

    public static function saveMember(int $ent, int $userId, array $d, object $by): array
    {
        if ($by->role_code !== 'OWNER') throw new \InvalidArgumentException('Only the Owner (admin) sets the Hub team.');
        if (! DB::table('t_users')->where('id', $userId)->where('fk_id_ent', $ent)->exists()) throw new \InvalidArgumentException('Login not found.');
        $old = self::member($ent, $userId); $row = [];
        foreach (['is_accountant', 'can_reveal'] as $k) $row[$k] = array_key_exists($k, $d) ? (int) (bool) $d[$k] : (int) ($old->$k ?? 0);
        DB::table('acc_hub_members')->updateOrInsert(['fk_id_ent' => $ent, 'user_id' => $userId], $row + ['updated_by' => $by->id, 'updated_at' => now()]);
        Audit::log('acc_hub_members', $userId, 'UPDATE', $row, 'Hub team', $ent);
        return ['message' => 'Saved.'] + $row;
    }

    /* ---------------- R-ACC-20 settings ---------------- */

    public static function settings(int $ent): array
    {
        $v = []; foreach (self::SETTINGS as $k => [$def]) $v[$k] = (int) AccFilings::setting($ent, $k, (string) $def);
        $v['cs_date_is'] = AccFilings::setting($ent, 'cs_date_is', 'REVIEW'); $v['reminders_enabled'] = (int) AccFilings::setting($ent, 'reminders_enabled', '1');
        $v['sender_name'] = (string) Settings::get('EMAIL_SENDER_NAME', $ent, ''); $v['sender_address'] = (string) Settings::get('EMAIL_SENDER_ADDRESS', $ent, '');
        return $v;
    }

    public static function saveSettings(int $ent, array $d, object $u): array
    {
        $e = []; $put = [];
        foreach (self::SETTINGS as $k => [$def, $a, $b]) if (array_key_exists($k, $d)) { $x = trim((string) $d[$k]); if (! ctype_digit($x) || (int) $x < $a || (int) $x > $b) $e[$k] = "Choose a value from $a to $b."; else $put[$k] = (int) $x; }
        if (array_key_exists('cs_date_is', $d)) { if (! in_array($d['cs_date_is'], ['REVIEW', 'DUE'], true)) $e['cs_date_is'] = 'Choose Review or Due.'; else $put['cs_date_is'] = $d['cs_date_is']; }
        if (array_key_exists('reminders_enabled', $d)) $put['reminders_enabled'] = (int) (bool) $d['reminders_enabled'];
        if (array_key_exists('sender_address', $d) && ($s = trim((string) $d['sender_address'])) !== '' && ! filter_var($s, FILTER_VALIDATE_EMAIL)) $e['sender_address'] = 'Write a valid e-mail address.';
        if ($e) throw \Illuminate\Validation\ValidationException::withMessages($e);
        foreach ($put as $k => $v) DB::table('acc_settings')->updateOrInsert(['fk_id_ent' => $ent, 'setting_key' => $k], ['setting_value' => (string) $v, 'updated_by' => $u->id, 'updated_at' => now()]);
        foreach (['sender_name' => 'EMAIL_SENDER_NAME', 'sender_address' => 'EMAIL_SENDER_ADDRESS'] as $k => $key) if (array_key_exists($k, $d)) Settings::set($key, mb_substr(trim((string) $d[$k]), 0, 150), $ent, $u->id);
        Audit::log('acc_settings', $ent, 'UPDATE', $put, 'Hub settings', $ent);
        $n = AccCompliance::generate($ent);
        return ['message' => "Saved. $n new deadline(s) added.", 'added' => $n];
    }

    /** Close before a date: every open item due earlier (optional area) → FILED (FILE) or PAID (others), with the note, logged BULK_CLOSE. */
    public static function closeBefore(int $ent, string $date, ?string $area, object $u): array
    {
        if ($u->role_code !== 'OWNER' && ! Perm::has($u, 'money.EDIT')) throw new \InvalidArgumentException('You may not close filings.');
        $d = Parse::date($date); if (! $d) throw new \InvalidArgumentException('Write the date.');
        if ($d > date('Y-m-d')) throw new \InvalidArgumentException('The date cannot be in the future.');
        if ($area && ! in_array($area, AccFilings::AREAS, true)) throw new \InvalidArgumentException('Choose the area from the list.');
        $n = 0;
        DB::transaction(function () use ($ent, $d, $area, $u, &$n) {
            foreach (DB::table('acc_obligations')->where('fk_id_ent', $ent)->where('f_done', 0)->where('due_date', '<', $d)->when($area, fn ($q) => $q->where('area', $area))->lockForUpdate()->get() as $o) {
                $st = $o->completion === 'FILE' ? 'FILED' : 'PAID';
                DB::table('acc_obligations')->where('id', $o->id)->update(['status' => $st, 'f_done' => 1, 'filed_by' => (int) $o->filed_by ?: $u->id, 'notes' => mb_substr(trim(($o->notes ? $o->notes . "\n" : '') . self::BULK_NOTE), 0, 2000), 'updated_by' => $u->id, 'updated_at' => now()]);
                DB::table('acc_obligation_log')->insert(['fk_id_obligation' => $o->id, 'action' => 'BULK_CLOSE', 'details' => "status: {$o->status} → $st (before $d)", 'fk_id_account' => $u->id, 'created_at' => now()]);
                $n++;
            }
        });
        return ['closed' => $n, 'message' => "$n filing(s) closed."];
    }

    /* ---------------- R-ACC-16 credentials ---------------- */

    /** "••••••" + the last 3 characters; 4 characters or fewer → all dots. */
    public static function mask(?string $v): ?string
    {
        if ($v === null || $v === '') return null; $l = mb_strlen($v);
        return '••••••' . ($l > 4 ? mb_substr($v, -3) : '');
    }

    public static function companySecrets(int $companyId): array
    {
        $out = [];
        foreach (self::HUB_SECRETS['cat_company_house'] as $c) {
            $cell = DB::table('sys_vault')->where(['table_name' => 'cat_company_house', 'record_id' => $companyId, 'column_name' => $c])->first();
            $plain = null; if ($cell) { try { $plain = Crypt::decryptString($cell->cipher); } catch (\Throwable) { $plain = null; } }
            $out[] = ['field' => $c, 'label' => Registry::labelOf('cat_company_house', $c) ?: $c, 'masked' => $cell ? (self::mask($plain) ?? '••••••') : null];
        }
        return $out;
    }

    public static function history(string $table, int $id): array
    {
        return DB::table('sys_audit_log as a')->leftJoin('t_users as u', 'u.id', '=', 'a.user_id')->where('a.table_name', $table)->where('a.record_id', $id)->where('a.action', 'REVEAL')->orderByDesc('a.id')->limit(50)
            ->get(['a.created_at', 'a.ip', 'a.changes_json', 'u.display_name', 'u.login_id'])->map(fn ($r) => ['at' => (string) $r->created_at, 'ip' => $r->ip, 'who' => $r->display_name ?: $r->login_id,
                'field' => json_decode((string) $r->changes_json, true)['field'] ?? '', 'result' => json_decode((string) $r->changes_json, true)['result'] ?? ''])->all();
    }

    /* ---------------- R-ACC-18 person UTR / NI ---------------- */

    public static function cleanUtr(string $v): ?string { $x = preg_replace('/\s+/', '', $v); return preg_match('/^\d{10}$/', $x) ? $x : null; }
    public static function cleanNi(string $v): ?string { $x = strtoupper(preg_replace('/\s+/', '', $v)); return preg_match('/^[A-Z]{2}\d{6}[A-D]$/', $x) ? $x : null; }

    private static function keyReady(): bool { $k = (string) config('app.key'); return $k !== '' && ! str_contains(strtolower($k), 'placeholder') && ! str_contains($k, 'changeme'); }

    public static function person(int $ent, int $accountId): array
    {
        $a = DB::table('t_account')->where('id', $accountId)->first() ?? throw new \InvalidArgumentException('Person not found.');
        $s = DB::table('acc_person_settings')->whereIn('fk_id_ent', [$ent, 0])->where('fk_id_account', $accountId)->orderByDesc('fk_id_ent')->first();
        return ['id' => (int) $a->id, 'name' => trim($a->first_name . ' ' . $a->sur_name), 'sa_registered' => (int) ($s->sa_registered ?? 0), 'poa_required' => (int) ($s->poa_required ?? 0), 'first_tax_year' => $s->first_tax_year ?? null,
            'fk_accountant' => (int) ($s->fk_accountant ?? 0), 'notes' => $s->notes ?? '', 'utr' => $s && $s->utr_enc ? '••••••' . $s->utr_last4 : null, 'ni' => $s && $s->ni_enc ? '••••••' . $s->ni_last4 : null,
            'accountants' => AccFilings::accountants($ent), 'history' => $s ? self::history('acc_person_settings', (int) $s->id) : []];
    }

    public static function savePerson(int $ent, int $accountId, array $d, object $u): array
    {
        if (! DB::table('t_account')->where('id', $accountId)->exists()) throw new \InvalidArgumentException('Person not found.');
        $e = []; $row = [];
        foreach (['utr' => 'cleanUtr', 'ni' => 'cleanNi'] as $k => $fn) {
            if (! array_key_exists($k, $d)) continue; $raw = trim((string) $d[$k]);
            if ($raw === '' || str_starts_with($raw, '•')) continue;      /* unchanged */
            if ($raw === '-' || strtoupper($raw) === 'REMOVE') { $row[$k . '_enc'] = null; $row[$k . '_last4'] = null; continue; }
            $v = self::$fn($raw);
            if (! $v) { $e[$k] = $k === 'utr' ? 'The UTR is 10 digits.' : 'The NI number is 2 letters, 6 digits and A, B, C or D (e.g. QQ123456C).'; continue; }
            if (! self::keyReady()) { $e[$k] = 'The encryption key of the system is not set – the value cannot be saved safely.'; continue; }
            $row[$k . '_enc'] = Crypt::encryptString($v); $row[$k . '_last4'] = substr($v, -4);
        }
        if (array_key_exists('first_tax_year', $d) && trim((string) $d['first_tax_year']) !== '') { $y = (int) $d['first_tax_year']; if ($y < 1996 || $y > (int) date('Y') + 1) $e['first_tax_year'] = 'Choose a year from 1996 to ' . ((int) date('Y') + 1) . '.'; else $row['first_tax_year'] = $y; }
        foreach (['sa_registered', 'poa_required'] as $k) if (array_key_exists($k, $d)) $row[$k] = (int) (bool) $d[$k];
        if (array_key_exists('fk_accountant', $d)) { $a = (int) $d['fk_accountant']; if ($a && ! in_array($a, array_column(AccFilings::accountants($ent), 'id'), true)) $e['fk_accountant'] = 'Choose an accountant of the Hub.'; else $row['fk_accountant'] = $a; }
        if (array_key_exists('notes', $d)) { $n = trim((string) $d['notes']); if (mb_strlen($n) > 4000) $e['notes'] = 'At most 4000 characters.'; else $row['notes'] = $n ?: null; }
        if ($e) throw \Illuminate\Validation\ValidationException::withMessages($e);
        DB::table('acc_person_settings')->updateOrInsert(['fk_id_ent' => $ent, 'fk_id_account' => $accountId], $row + ['updated_by' => $u->id, 'updated_at' => now()]);
        $logged = $row; foreach (['utr_enc', 'ni_enc'] as $k) if (array_key_exists($k, $logged)) $logged[$k] = $logged[$k] === null ? 'removed' : '••••';
        Audit::log('acc_person_settings', $accountId, 'UPDATE', $logged, 'Hub person settings', $ent);
        if (! empty($row['sa_registered'])) AccCompliance::generate($ent);
        return ['message' => 'Saved.'] + self::person($ent, $accountId);
    }

    /** Reveal a person's UTR / NI (Owner or can_reveal); every attempt logged with user, IP and time. */
    public static function revealPerson(int $ent, int $accountId, string $field, object $u): array
    {
        if (! in_array($field, ['utr', 'ni'], true)) throw new \InvalidArgumentException('Unknown field.');
        $s = DB::table('acc_person_settings')->whereIn('fk_id_ent', [$ent, 0])->where('fk_id_account', $accountId)->orderByDesc('fk_id_ent')->first() ?? throw new \InvalidArgumentException('Nothing stored for this person.');
        if (! self::canReveal($u)) { Audit::log('acc_person_settings', (int) $s->id, 'REVEAL', ['field' => $field, 'result' => 'DENIED'], null, $ent); throw new \Illuminate\Auth\Access\AuthorizationException('You may not reveal this – the Owner can give you "can reveal" in the Hub team. The attempt was logged.'); }
        $c = $s->{$field . '_enc'}; if (! $c) return ['field' => $field, 'value' => null];
        try { $v = Crypt::decryptString($c); } catch (\Throwable) { Audit::log('acc_person_settings', (int) $s->id, 'REVEAL', ['field' => $field, 'result' => 'UNREADABLE'], null, $ent); throw new \InvalidArgumentException('The value cannot be read (the encryption key changed). Type it again.'); }
        Audit::log('acc_person_settings', (int) $s->id, 'REVEAL', ['field' => $field, 'result' => 'SHOWN'], null, $ent);
        return ['field' => $field, 'value' => $v];
    }

    /* ---------------- R-ACC-21 company page ---------------- */

    public static function companyTax(int $ent, int $companyId): array
    {
        $today = date('Y-m-d'); $soon = date('Y-m-d', strtotime('+' . (int) AccFilings::setting($ent, 'due_soon_days', '30') . ' days'));
        $badges = DB::table('acc_obligations')->where('fk_id_ent', $ent)->where('entity_type', 'COMPANY')->where('entity_id', $companyId)->where('f_done', 0)->get(['area', 'due_date'])->groupBy('area')
            ->map(fn ($g, $a) => ['area' => $a, 'open' => $g->count(), 'overdue' => $g->where('due_date', '<', $today)->count(), 'soon' => $g->whereBetween('due_date', [$today, $soon])->count()])->values()->all();
        $periods = DB::table('tax_periods')->where('fk_company_house', $companyId)->where('f_active', 1)->orderByDesc('period_end')->limit(10)
            ->get(['id', 'period_label', 'period_start', 'period_end', 'status', 'accounts_filed_date', 'ct600_filed_date', 'tax_paid_date', 'tax_paid_amount'])->all();
        return ['badges' => $badges, 'periods' => $periods, 'taxation_url' => '#/list/tax_period?f[fk_company_house]=' . $companyId, 'filings_url' => '#/compliance?view=items&tab=all&entity=C' . $companyId];
    }

    /** R-TAX-4 a tax period status ticks the filing of the same period end: ACCOUNTS_FILED → ACCOUNTS, CT600_FILED → CT600, TAX_PAID → CT_PAYMENT (later statuses include the earlier ones). */
    public static function periodSaved(int $periodId): int
    {
        $p = DB::table('tax_periods')->where('id', $periodId)->first(); if (! $p || ! $p->period_end) return 0;
        $order = ['DRAFT' => 0, 'PREPARED' => 1, 'ACCOUNTS_FILED' => 2, 'CT600_FILED' => 3, 'TAX_PAID' => 4, 'CLOSED' => 5]; $lvl = $order[$p->status] ?? 0; $n = 0;
        foreach ([['ACCOUNTS', 2, 'FILED', $p->accounts_filed_date], ['CT600', 3, 'FILED', $p->ct600_filed_date], ['CT_PAYMENT', 4, 'PAID', $p->tax_paid_date]] as [$type, $need, $st, $date]) {
            if ($lvl < $need) continue;
            $q = DB::table('acc_obligations')->where('entity_type', 'COMPANY')->where('entity_id', $p->fk_company_house)->where('type_code', $type)->where('f_done', 0)
                ->where(fn ($w) => $w->where('period_end', $p->period_end)->when($type === 'CT_PAYMENT', fn ($x) => $x->orWhere(fn ($y) => $y->where('period_end', '<=', $p->period_end)->where('period_start', '>=', $p->period_start))));
            foreach ($q->get() as $o) {
                $set = ['status' => $st, 'f_done' => 1, 'updated_at' => now()] + ($st === 'FILED' ? ['filed_date' => $date ?: date('Y-m-d')] : ['paid_date' => $date ?: date('Y-m-d'), 'amount_paid' => $p->tax_paid_amount]);
                DB::table('acc_obligations')->where('id', $o->id)->update($set);
                DB::table('acc_obligation_log')->insert(['fk_id_obligation' => $o->id, 'action' => 'TAX_PERIOD', 'details' => "status: {$o->status} → $st (tax period {$p->period_label} {$p->status})", 'fk_id_account' => (int) Context::userId(), 'created_at' => now()]);
                $n++;
            }
        }
        return $n;
    }

    /* ---------------- R-ACC-22 the reminder job ---------------- */

    /** One sys_job_runs row per run: items done, messages queued, error. A missing sender → FAILED + SYS_ERROR task, nothing sent. */
    public static function job(int $ent, ?string $today = null): array
    {
        $before = (int) DB::table('tm_messages')->where('rule_code', 'N17')->whereIn('channel', ['EMAIL', 'WHATSAPP'])->count();
        $r = JobLog::run('ACC_REMINDERS', $ent, function () use ($ent, $today) {
            $addr = trim((string) Settings::get('EMAIL_SENDER_ADDRESS', $ent, ''));
            if ($addr === '' || ! filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                TaskEngine::open('SYS_ERROR', ['acc_reminders_sender', $ent], 'Filing reminders stopped: no e-mail sender is set (Tax & filings › Settings)', ['ent' => $ent, 'description' => 'The sender name and address come from the settings, never from a file. Set them and the next run sends.']);
                throw new \RuntimeException('No e-mail sender in the settings – nothing was sent.');
            }
            TaskEngine::close('SYS_ERROR', ['acc_reminders_sender', $ent], 'Sender set');
            return AccFilings::reminders($ent, $today);
        });
        $queued = (int) DB::table('tm_messages')->where('rule_code', 'N17')->whereIn('channel', ['EMAIL', 'WHATSAPP'])->count() - $before;
        $failed = (int) DB::table('tm_messages')->where('rule_code', 'N17')->where('status', 'FAILED')->where('created_at', '>=', DB::table('sys_job_runs')->where('id', $r['id'])->value('started_at'))->count();
        DB::table('sys_job_runs')->where('id', $r['id'])->update(['messages_queued' => $queued, 'note' => "$failed failed"]);
        return $r + ['queued' => $queued, 'failed' => $failed];
    }

    public static function lastRun(int $ent): ?array
    {
        $j = DB::table('sys_job_runs')->where('job_code', 'ACC_REMINDERS')->where('fk_id_ent', $ent)->orderByDesc('id')->first(); if (! $j) return null;
        return ['at' => (string) $j->started_at, 'status' => $j->run_status, 'queued' => (int) $j->messages_queued, 'failed' => (int) preg_replace('/\D.*/', '', (string) $j->note), 'error' => $j->error_text, 'url' => '#/health'];
    }
}
