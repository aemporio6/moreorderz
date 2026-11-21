<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Due engine (R-C-46): every stored renewal / expiry / payment date of sys_due_sources reminds. Nightly: a date within its source's remind days becomes one due item
 * (dedupe source table + row + date column + date), the owner gets a reminder and the event DUE_SOON fires; on the date, if not done, the item's task opens
 * (ACC_DUE_ITEM or the source's own code). The item closes by its done rule: the date was moved forward, the row is no longer active, or a person marks it done
 * (recurring sources – card bill, salary – then move their date on by their months).
 */
class DueEngine
{
    private static function owner(object $src, object $row, int $ent): int
    {
        $rule = (string) $src->owner_rule;
        if (str_starts_with($rule, 'record.')) { $col = substr($rule, 7); $v = $row->$col ?? null;
            if ($v && in_array($col, ['fk_id_account'], true)) { $u = (int) DB::table('t_users')->where('fk_id_account', $v)->where('f_active', 1)->value('id'); if ($u) return $u; }
            elseif ($v && Schema::hasTable('t_users') && DB::table('t_users')->where('id', $v)->exists()) return (int) $v; }
        if (str_starts_with($rule, 'role:')) { $role = substr($rule, 5); if ($u = TaskEngine::fewestOpen($role, $ent)) return $u; }
        return (int) (DB::table('t_users')->where('role_code', 'OWNER')->where('f_active', 1)->orderBy('id')->value('id') ?? 0);
    }

    private static function title(object $src, object $row): string
    {
        $person = isset($row->fk_id_account) ? (string) DB::table('t_account')->where('id', $row->fk_id_account)->selectRaw("TRIM(CONCAT_WS(' ', first_name, sur_name)) n")->value('n') : '';
        $mp = isset($row->fk_id_marketplace) && $row->fk_id_marketplace ? (string) DB::table('cat_marketplaces')->where('id', $row->fk_id_marketplace)->value('marketplace_short') : '';
        return mb_substr(preg_replace_callback('/\{(\w+)\}/', fn ($m) => match ($m[1]) { 'person' => $person ?: '#' . ($row->fk_id_account ?? ''), 'marketplace' => $mp ?: '—', default => (string) ($row->{$m[1]} ?? '') }, (string) $src->title_template), 0, 200);
    }

    /** The rows of one source: active by its where rule, scoped to the company. */
    private static function rows(object $src, int $ent, ?string $until = null)
    {
        $t = $src->source_table; $c = $src->date_column; if (! Schema::hasTable($t) || ! Schema::hasColumn($t, $c)) return collect();
        $q = DB::table($t)->whereNotNull($c);
        if ($src->where_rule) $q->whereRaw('(' . $src->where_rule . ')');
        if (Schema::hasColumn($t, 'fk_id_ent')) $q->where('fk_id_ent', $ent); elseif (Schema::hasColumn($t, 'company_id')) $q->where('company_id', $ent);
        elseif (Schema::hasColumn($t, 'fk_id_account')) $q->whereIn('fk_id_account', DB::table('t_account')->where('fk_id_ent', $ent)->select('id'));
        if ($until) $q->where($c, '<=', $until);
        return $q->get();
    }

    public static function key(object $src, int $rowId, string $date): string { return $src->source_table . ':' . $rowId . ':' . $src->date_column . ':' . $date; }

    public static function run(int $ent, ?string $today = null, ?array $tables = null): array
    {
        $today ??= date('Y-m-d'); $n = ['new' => 0, 'reminded' => 0, 'tasks' => 0, 'closed' => 0, 'skipped' => 0];
        foreach (DB::table('sys_due_sources')->where('f_active', 1)->when($tables, fn ($q) => $q->whereIn('source_table', $tables))->orderBy('id')->get() as $src) {
            if ($src->source_table === 'acc_obligations') continue;      // the Accountancy Hub raises its own deadlines (ACC_COMPLIANCE_DUE)
            try { $rows = self::rows($src, $ent); } catch (\Throwable $e) { report($e); continue; }
            $live = [];
            foreach ($rows as $row) {
                $date = substr((string) $row->{$src->date_column}, 0, 10); $live[(int) $row->id] = $date;
                if ($date > date('Y-m-d', strtotime("$today +{$src->remind_days} days"))) continue;
                if ($src->source_table === 'cat_company_house' && DB::table('acc_obligations')->where('entity_id', $row->id)->whereIn('entity_type', ['COMPANY', 'company', 'cat_company_house'])->where('due_date', $date)->exists()) { $n['skipped']++; continue; }      // tracked in the Hub: no double task
                $item = DB::table('sys_due_items')->where('fk_id_source', $src->id)->where('record_id', $row->id)->where('due_date', $date)->first();
                if ($item && in_array($item->status, ['DONE', 'IGNORED'], true)) continue;
                if (! $item) {
                    $owner = self::owner($src, $row, $ent); $title = self::title($src, $row);
                    $id = DB::table('sys_due_items')->insertGetId(['fk_id_ent' => $ent, 'fk_id_source' => $src->id, 'record_id' => $row->id, 'due_date' => $date, 'title' => $title, 'status' => 'REMINDED', 'source_key' => self::key($src, (int) $row->id, $date), 'owner_user_id' => $owner, 'reminded_at' => now(), 'updated_at' => now(),
                        'amount' => isset($row->price) ? $row->price : (isset($row->salary) ? $row->salary : null), 'fk_id_currency' => $row->fk_id_currency ?? null]);
                    if ($owner) Notify::push($owner, 'DUE', "$title – due $date", '#/due');
                    if ($src->source_table === 't_account_mobiles') foreach (DB::table('t_branches')->where('fk_id_mobile', $row->id)->where('f_active', 1)->pluck('id') as $shop) {      // the SIM is on a shop kit: the shop's VA is told too
                        $va = SalesImport::vaAccount((int) $shop, $today); $vu = $va ? (int) DB::table('t_users')->where('fk_id_account', $va)->where('f_active', 1)->value('id') : 0; if ($vu && $vu !== $owner) Notify::push($vu, 'DUE', "$title – due $date", '#/due'); }
                    if ($src->copy_role) foreach (DB::table('t_users')->where('role_code', $src->copy_role)->where('f_active', 1)->where('fk_id_ent', $ent)->pluck('id') as $u) if ((int) $u !== $owner) Notify::push((int) $u, 'DUE', "$title – due $date", '#/due');
                    EventBus::fire('DUE_SOON', $src->source_table, (int) $row->id, ['due_item' => $id, 'date' => $date, 'title' => $title], $ent);
                    $item = DB::table('sys_due_items')->where('id', $id)->first(); $n['new']++; $n['reminded']++;
                }
                if ($date <= $today && ! $item->fk_id_task) {      // on the date and not done: the task
                    $tid = TaskEngine::open($src->task_code ?: 'ACC_DUE_ITEM', [$item->source_key ?: self::key($src, (int) $row->id, $date)], $item->title . ' – due ' . $date, ['ent' => $ent, 'owner_user_id' => (int) $item->owner_user_id ?: null, 'module' => $src->source_table, 'record_id' => (int) $row->id, 'fix_url' => '#/due']);
                    DB::table('sys_due_items')->where('id', $item->id)->update(['fk_id_task' => $tid, 'status' => 'OVERDUE', 'updated_at' => now()]); $n['tasks']++;
                }
            }
            // done by a payment: a salary booked to the person, a card bill paid to the card provider (± 7 days of the date) – a recurring date then moves on
            if ($src->recur_months) foreach (DB::table('sys_due_items')->where('fk_id_source', $src->id)->where('fk_id_ent', $ent)->whereIn('status', ['UPCOMING', 'REMINDED', 'OVERDUE'])->get() as $it) {
                $row = DB::table($src->source_table)->where('id', $it->record_id)->first(); if (! $row) continue;
                $payee = $src->source_table === 't_account_job_history' ? ($row->fk_id_account ?? null) : ($row->fk_cc_provider_id ?? null); if (! $payee) continue;
                $paid = DB::table('fin_expenses')->where('fk_id_ent', $ent)->where('f_active', 1)->where('fk_id_payee', $payee)->whereBetween('d_expense', [date('Y-m-d', strtotime($it->due_date . ' -7 days')), date('Y-m-d', strtotime($it->due_date . ' +7 days'))])
                    ->when($src->source_table === 't_account_job_history', fn ($q) => $q->whereIn('category_code', ['SALARY', 'SALARIES', 'WAGES']))->orderBy('id')->first(['id', 'd_expense']);
                if (! $paid) continue;
                $next = date('Y-m-d', strtotime($it->due_date . ' +' . (int) $src->recur_months . ' months')); DB::table($src->source_table)->where('id', $it->record_id)->where($src->date_column, $it->due_date)->update([$src->date_column => $next]);
                $live[(int) $it->record_id] = $next; self::close($it, $src, "paid on {$paid->d_expense} (expense #{$paid->id}), next $next", $ent); $n['closed']++;
            }
            // done rule: the date was moved forward, or the row is no longer active / gone
            foreach (DB::table('sys_due_items')->where('fk_id_source', $src->id)->where('fk_id_ent', $ent)->whereIn('status', ['UPCOMING', 'REMINDED', 'OVERDUE'])->get() as $it) {
                $now = $live[(int) $it->record_id] ?? null; if ($now === $it->due_date) continue;
                self::close($it, $src, $now ? ($now > $it->due_date ? 'date moved forward' : 'date changed') : 'no longer due (inactive or removed)', $ent); $n['closed']++;
            }
        }
        return $n;
    }

    private static function close(object $it, object $src, string $how, int $ent): void
    {
        DB::table('sys_due_items')->where('id', $it->id)->update(['status' => 'DONE', 'done_at' => now(), 'done_how' => mb_substr($how, 0, 120), 'updated_at' => now()]);
        if ($it->source_key) TaskEngine::close($src->task_code ?: 'ACC_DUE_ITEM', [$it->source_key], $how);
        EventBus::fire('DUE_ITEM_CLOSED', $src->source_table, (int) $it->record_id, ['due_item' => (int) $it->id, 'rule' => $how], $ent);
    }

    /** A person marks a due item done: a recurring source (card bill, salary) moves its date on by its months. */
    public static function done(int $ent, int $itemId, ?object $u): array
    {
        $it = DB::table('sys_due_items')->where('id', $itemId)->where('fk_id_ent', $ent)->first() ?? throw new \InvalidArgumentException('Due item not found.');
        $src = DB::table('sys_due_sources')->where('id', $it->fk_id_source)->first(); if (in_array($it->status, ['DONE', 'IGNORED'], true)) return ['message' => 'Already done.'];
        $role = $u->role_code ?? null; if ($role !== 'OWNER' && (int) $it->owner_user_id !== (int) ($u->id ?? 0) && ! ($role === 'FINANCE' && str_contains((string) $src->owner_rule, 'FINANCE'))) throw new \InvalidArgumentException('Only its owner or the Owner marks this done.');
        $next = null;
        if ($src->recur_months) { $next = date('Y-m-d', strtotime($it->due_date . ' +' . (int) $src->recur_months . ' months')); DB::table($src->source_table)->where('id', $it->record_id)->update([$src->date_column => $next]); }
        self::close($it, $src, 'marked done by ' . ($u->display_name ?? 'user') . ($next ? ", next $next" : ''), $ent);
        Audit::log('sys_due_items', $itemId, 'UPDATE', ['done' => true, 'next date' => $next], null, $ent);
        return ['message' => 'Done.' . ($next ? " Next date: $next." : ''), 'next' => $next];
    }

    /** Due soon (PHASE_07): open items with their source chip; Owner sees all, Finance the card / salary / subscription items, others their own. */
    public static function list(int $ent, ?object $u, array $f = []): array
    {
        $q = DB::table('sys_due_items as i')->join('sys_due_sources as s', 's.id', '=', 'i.fk_id_source')->leftJoin('t_users as o', 'o.id', '=', 'i.owner_user_id')->where('i.fk_id_ent', $ent)
            ->whereIn('i.status', ($f['status'] ?? '') === 'done' ? ['DONE', 'IGNORED'] : ['UPCOMING', 'REMINDED', 'OVERDUE']);
        $role = $u->role_code ?? null;
        if ($role === 'FINANCE') $q->where(fn ($w) => $w->where('i.owner_user_id', $u->id)->orWhereIn('s.chip', ['Bank card', 'Card bill', 'Salary', 'Subscription']));
        elseif ($role !== 'OWNER') $q->where('i.owner_user_id', $u->id ?? 0);
        if (! empty($f['chip'])) $q->where('s.chip', $f['chip']);
        return ['rows' => $q->orderBy('i.due_date')->limit(500)->get(['i.id', 'i.title', 'i.due_date', 'i.status', 'i.amount', 's.chip', 's.source_table', 'i.record_id', 'o.display_name as owner', 's.recur_months', 'i.done_how'])->all(),
            'chips' => DB::table('sys_due_sources')->where('f_active', 1)->whereNotNull('chip')->distinct()->orderBy('chip')->pluck('chip')->all(), 'today' => date('Y-m-d')];
    }
}
