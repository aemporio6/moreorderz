<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Shop monthly reminders (RULES_A_SALES R-A_SALES-72): Shop, Month, Year, Type, Description – all required; PENDING → Resolve → RESOLVED → Revert → PENDING.
 * Templates per type make the reminder and its task by themselves on the 1st of each month.
 */
class ShopReminders
{
    public const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

    public static function types(): array
    {
        return DB::table('reference_items')->where('fk_reference_id', DB::table('cat_references')->where('ref_code', 'REMINDER_TYPE')->value('id'))->where('f_active', 1)->orderBy('sort_no')->orderBy('reference_name')->get(['id', 'reference_name as name'])->all();
    }

    private static function clean(array $d, bool $template = false): array
    {
        $shop = (int) ($d['shop'] ?? 0); $type = (int) ($d['type'] ?? 0); $text = trim((string) ($d['text'] ?? ''));
        if (! $template && ! $shop) throw new \InvalidArgumentException('Select a shop.');
        if ($shop && ! DB::table('t_branches')->where('id', $shop)->where('fk_id_ent', Context::ent())->exists()) throw new \InvalidArgumentException('Shop not found.');
        if ($shop && ! Perm::shopAllowed(\Illuminate\Support\Facades\Auth::user(), $shop)) abort(403, "You don't have rights for this shop.");
        if (! $template) { $m = (int) ($d['month'] ?? 0); $y = (int) ($d['year'] ?? 0); if ($m < 1 || $m > 12) throw new \InvalidArgumentException('Select the month.'); if ($y < 2000 || $y > 2100) throw new \InvalidArgumentException('Enter the year.'); }
        if (! $type || ! in_array($type, array_column(self::types(), 'id'))) throw new \InvalidArgumentException('Select the reminder type.');
        if ($text === '') throw new \InvalidArgumentException('Write the description.');
        if (mb_strlen($text) > 500) throw new \InvalidArgumentException('The description can have up to 500 characters.');
        return ['fk_id_branch' => $shop, 'fk_id_rem_type' => $type, 'reminder_text' => $text] + ($template ? [] : ['month' => (int) $d['month'], 'year' => (int) $d['year']]);
    }

    public static function save(array $d, ?int $id, ?object $u): array
    {
        Perm::need($u, 'orders.shopReminders'); $row = self::clean($d);
        if ($id) { $old = self::one($id); if ($old->e_status !== 'PENDING') throw new \InvalidArgumentException('A resolved reminder cannot be changed. Revert it first.'); DB::table('t_branch_reminders')->where('id', $id)->update($row); Audit::log('t_branch_reminders', $id, 'UPDATE', $row); return ['message' => 'Reminder saved.', 'id' => $id]; }
        $id = (int) DB::table('t_branch_reminders')->insertGetId($row + ['e_status' => 'PENDING', 'd_entry' => date('Y-m-d')]); Audit::log('t_branch_reminders', $id, 'INSERT', $row);
        return ['message' => 'Reminder added.', 'id' => $id];
    }

    private static function one(int $id): object
    {
        $r = DB::table('t_branch_reminders as r')->join('t_branches as b', 'b.id', '=', 'r.fk_id_branch')->where('r.id', $id)->where('b.fk_id_ent', Context::ent())->first(['r.*']) ?? abort(404, 'Reminder not found.');
        if (! Perm::shopAllowed(\Illuminate\Support\Facades\Auth::user(), (int) $r->fk_id_branch)) abort(403, "You don't have rights for this shop.");
        return $r;
    }

    public static function resolve(int $id, ?object $u): array
    {
        Perm::need($u, 'orders.shopReminders'); $r = self::one($id); if ($r->e_status === 'RESOLVED') throw new \InvalidArgumentException('This reminder is already resolved.');
        DB::table('t_branch_reminders')->where('id', $id)->update(['e_status' => 'RESOLVED', 'resolved_by' => $u->id ?? null, 'resolved_at' => now()]); TaskEngine::close('SHOP_REMINDER', [$id], 'Reminder resolved');
        Audit::log('t_branch_reminders', $id, 'UPDATE', ['e_status' => ['PENDING', 'RESOLVED']]);
        return ['message' => 'Resolved.'];
    }

    public static function revert(int $id, ?object $u): array
    {
        Perm::need($u, 'orders.shopReminders'); $r = self::one($id); if ($r->e_status === 'PENDING') throw new \InvalidArgumentException('This reminder is already pending.');
        DB::table('t_branch_reminders')->where('id', $id)->update(['e_status' => 'PENDING', 'resolved_by' => null, 'resolved_at' => null]);
        if ($r->fk_id_template) self::task($id);      // the task of a template reminder opens again
        Audit::log('t_branch_reminders', $id, 'UPDATE', ['e_status' => ['RESOLVED', 'PENDING']]);
        return ['message' => 'Pending again.'];
    }

    public static function remove(int $id, ?object $u): array
    {
        Perm::need($u, 'orders.shopReminders'); self::one($id); DB::table('t_branch_reminders')->where('id', $id)->delete(); TaskEngine::close('SHOP_REMINDER', [$id], 'Reminder removed'); Audit::log('t_branch_reminders', $id, 'DELETE', []);
        return ['message' => 'Reminder removed.'];
    }

    /** Pending: newest first. Resolved: oldest first. The text is cut to 250 characters in the list. */
    public static function list(?object $u, array $f = []): array
    {
        $q = fn (string $st) => Perm::mine(DB::table('t_branch_reminders as r'), 'r.fk_id_branch')->join('t_branches as b', 'b.id', '=', 'r.fk_id_branch')->leftJoin('reference_items as t', 't.id', '=', 'r.fk_id_rem_type')->where('b.fk_id_ent', Context::ent())->where('r.e_status', $st)->when(! empty($f['shop']), fn ($x) => $x->where('r.fk_id_branch', (int) $f['shop']));
        $map = fn ($r) => ['id' => $r->id, 'shop' => (int) $r->fk_id_branch, 'shop_label' => $r->shop_no . ' (' . $r->shop_name . ')', 'month' => (int) $r->month, 'year' => (int) $r->year, 'period' => (self::MONTHS[$r->month - 1] ?? '') . ' ' . $r->year, 'type' => (int) $r->fk_id_rem_type, 'type_name' => $r->type_name,
            'text' => mb_strlen((string) $r->reminder_text) > 250 ? mb_substr((string) $r->reminder_text, 0, 250) . '…' : (string) $r->reminder_text, 'full_text' => (string) $r->reminder_text, 'status' => $r->e_status, 'entered' => $r->d_entry, 'auto' => (bool) $r->fk_id_template];
        $cols = ['r.*', 'b.shop_no', 'b.shop_name', 't.reference_name as type_name'];
        return ['pending' => $q('PENDING')->orderByDesc('r.year')->orderByDesc('r.month')->orderByDesc('r.id')->limit(500)->get($cols)->map($map)->all(), 'resolved' => $q('RESOLVED')->orderBy('r.year')->orderBy('r.month')->orderBy('r.id')->limit(500)->get($cols)->map($map)->all(),
            'types' => self::types(), 'months' => self::MONTHS, 'shops' => array_map(fn ($s) => ['id' => $s['id'], 'name' => $s['label']], ShopLabel::list($u)), 'templates' => self::templates(), 'can_edit' => $u && Perm::has($u, 'orders.shopReminders'), 'can_templates' => $u && Perm::has($u, 'orders.shopReminderTemplates')];
    }

    // ---------- templates: a reminder + task per shop on the 1st of each month ----------
    public static function templates(): array
    {
        return DB::table('mo_reminder_templates as t')->leftJoin('t_branches as b', 'b.id', '=', 't.fk_id_branch')->leftJoin('reference_items as r', 'r.id', '=', 't.fk_id_rem_type')->where('t.fk_id_ent', Context::ent())->orderBy('t.id')
            ->get(['t.id', 't.fk_id_branch as shop', 't.fk_id_rem_type as type', 't.reminder_text as text', 't.due_day', 't.f_active', 'b.shop_no', 'b.shop_name', 'r.reference_name as type_name'])->map(fn ($t) => (array) $t + ['shop_label' => $t->shop ? $t->shop_no . ' (' . $t->shop_name . ')' : 'Every active shop'])->all();
    }

    public static function saveTemplate(array $d, ?int $id, ?object $u): array
    {
        Perm::need($u, 'orders.shopReminderTemplates'); $row = self::clean($d, true); $due = (int) ($d['due_day'] ?? 5); if ($due < 1 || $due > 28) throw new \InvalidArgumentException('The due day must be between 1 and 28.');
        $row += ['due_day' => $due, 'f_active' => array_key_exists('f_active', $d) ? (int) (bool) $d['f_active'] : 1, 'updated_at' => now()];
        if ($id) { if (! DB::table('mo_reminder_templates')->where('id', $id)->where('fk_id_ent', Context::ent())->update($row)) throw new \InvalidArgumentException('Template not found.'); }
        else $id = (int) DB::table('mo_reminder_templates')->insertGetId($row + ['fk_id_ent' => Context::ent(), 'created_by' => $u->id ?? null, 'created_at' => now()]);
        Audit::log('mo_reminder_templates', $id, 'UPDATE', $row);
        return ['message' => 'Template saved. Its reminder is made on the 1st of each month.', 'id' => $id];
    }

    public static function removeTemplate(int $id, ?object $u): array
    {
        Perm::need($u, 'orders.shopReminderTemplates'); DB::table('mo_reminder_templates')->where('id', $id)->where('fk_id_ent', Context::ent())->delete(); Audit::log('mo_reminder_templates', $id, 'DELETE', []);
        return ['message' => 'Template removed. Reminders already made stay.'];
    }

    private static function task(int $reminderId): void
    {
        $r = DB::table('t_branch_reminders as r')->join('t_branches as b', 'b.id', '=', 'r.fk_id_branch')->leftJoin('mo_reminder_templates as t', 't.id', '=', 'r.fk_id_template')->where('r.id', $reminderId)->first(['r.*', 'b.shop_no', 'b.fk_id_ent', 't.due_day']); if (! $r) return;
        $tid = TaskEngine::open('SHOP_REMINDER', [$reminderId], mb_substr($r->shop_no . ': ' . $r->reminder_text, 0, 250), ['ent' => (int) $r->fk_id_ent, 'module' => 't_branches', 'record_id' => (int) $r->fk_id_branch, 'fix_url' => '#/shop-reminders']);
        if ($tid) DB::table('tasks')->where('id', $tid)->update(['due_at' => sprintf('%04d-%02d-%02d', $r->year, $r->month, min(28, (int) ($r->due_day ?: 5)))]);
    }

    /** Night job (all companies): from the 1st of a month on, every active template makes this month's reminder for its shop(s) once, with a task due on its due day. */
    public static function monthly(?string $today = null): int
    {
        $t = strtotime($today ?: 'today'); $m = (int) date('n', $t); $y = (int) date('Y', $t); $n = 0;
        foreach (DB::table('mo_reminder_templates')->where('f_active', 1)->get() as $tpl) {
            $shops = $tpl->fk_id_branch ? [(int) $tpl->fk_id_branch] : DB::table('t_branches')->where('fk_id_ent', $tpl->fk_id_ent)->where('f_active', 1)->pluck('id')->all();
            foreach ($shops as $shop) {
                if (DB::table('t_branch_reminders')->where('fk_id_template', $tpl->id)->where('fk_id_branch', $shop)->where('month', $m)->where('year', $y)->exists()) continue;
                $id = (int) DB::table('t_branch_reminders')->insertGetId(['fk_id_branch' => $shop, 'month' => $m, 'year' => $y, 'fk_id_rem_type' => $tpl->fk_id_rem_type, 'e_status' => 'PENDING', 'd_entry' => date('Y-m-d', $t), 'reminder_text' => $tpl->reminder_text, 'fk_id_template' => $tpl->id]);
                self::task($id); $n++;
            }
        }
        return $n;
    }
}
