<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Cases on a sale (RULES_A_SALES R-A_SALES-71; old name "sale reminders"). A seller raises a case with a type and a reason; a manager answers with remarks;
 * the case is resolved or reopened. Each case is also a task SALE_QUERY linked to the order, with a reply-by date and a thread.
 * The general type REM-GEN is for managers only: other people never see it.
 */
class SaleCases
{
    public const GENERAL = 'REM-GEN';

    private static function ref(): int { return (int) DB::table('cat_references')->where('ref_code', 'REMINDER_TYPE')->value('id'); }
    public static function admin(?object $u): bool { return (bool) ($u && Perm::has($u, 'orders.saleCaseAdmin')); }
    public static function generalId(): int { return (int) DB::table('reference_items')->where('fk_reference_id', self::ref())->where('item_code', self::GENERAL)->value('id'); }
    public static function replyDays(): int { return max(0, (int) Settings::get('SALE_CASE_REPLY_DAYS', Context::ent(), 1)); }

    /** Types a seller may choose (never REM-GEN). */
    public static function types(): array
    {
        return DB::table('reference_items')->where('fk_reference_id', self::ref())->where('f_active', 1)->where(fn ($w) => $w->whereNull('item_code')->orWhere('item_code', '<>', self::GENERAL))->whereIn('fk_id_ent', [0, (int) Context::ent()])->orderBy('sort_no')->orderBy('reference_name')->get(['id', 'reference_name as name'])->all();
    }

    private static function sale(int $id, ?object $u): object
    {
        $s = DB::table('t_sales')->where('id', $id)->where('fk_id_ent', Context::ent())->first() ?? abort(404, 'Sale line not found.');
        if (! Perm::shopAllowed($u, (int) $s->fk_id_branch)) abort(403, "You don't have rights for this shop."); return $s;
    }

    private static function fieldError(array $errors): never { throw \Illuminate\Validation\ValidationException::withMessages($errors); }

    private static function note(int $case, string $kind, string $text, ?object $u): void
    {
        DB::table('mo_sale_case_notes')->insert(['fk_id_case' => $case, 'kind' => $kind, 'note' => mb_substr($text, 0, 1000), 'user_id' => $u->id ?? null, 'user_name' => (string) ($u->display_name ?? $u->login_id ?? ''), 'created_at' => now()]);
    }

    private static function taskOpen(object $c, object $s, string $type, string $why): void
    {
        $shop = DB::table('t_branches')->where('id', $c->fk_id_branch)->first(); $label = $shop ? SalesImport::label($shop) : 'shop ' . $c->fk_id_branch;
        $t = TaskEngine::open('SALE_QUERY', [$c->id], "Case on order {$c->ebay_order_no} ($label): $type – " . mb_substr($why, 0, 120), ['ent' => (int) $s->fk_id_ent, 'module' => 't_sales', 'record_id' => (int) $s->id, 'fix_url' => '#/sale-cases']);
        if ($t && ! empty($c->d_reply_by)) DB::table('tasks')->where('id', $t)->update(['due_at' => $c->d_reply_by]);      // the task is due on the reply-by date
    }

    /** The shared add: dedupe by shop + order no + SKU (+ type for a seller). */
    private static function add(object $s, int $type, string $reason, bool $withType, ?object $u): array
    {
        return DB::transaction(function () use ($s, $type, $reason, $withType, $u) {
            $q = DB::table('t_sale_order_reminders')->where('fk_id_branch', $s->fk_id_branch)->where('ebay_order_no', $s->order_no)->where('sku_ebay', (string) $s->sku); if ($withType) $q->where('fk_id_rem_type', $type);
            if ($have = $q->lockForUpdate()->first()) {
                if ((int) $have->fk_id_t_sale_orders === (int) $s->id) throw new \InvalidArgumentException('Order already added in reminders');
                DB::table('t_sale_order_reminders')->where('id', $have->id)->update(['fk_id_t_sale_orders' => $s->id]); Audit::log('t_sale_order_reminders', (int) $have->id, 'UPDATE', ['sale' => [$have->fk_id_t_sale_orders, $s->id]]);
                return ['message' => 'Order Exist , but Sale id updated in reminders', 'id' => (int) $have->id, 'relinked' => true];      // the line was imported again under a new id
            }
            $by = date('Y-m-d', strtotime('+' . self::replyDays() . ' days')); $typeName = (string) DB::table('reference_items')->where('id', $type)->value('reference_name');
            $id = (int) DB::table('t_sale_order_reminders')->insertGetId(RecordService::fillNotNull('t_sale_order_reminders', ['fk_id_ent' => (int) $s->fk_id_ent, 'fk_id_branch' => (int) $s->fk_id_branch, 'fk_id_rem_type' => $type, 'sku_ebay' => mb_substr((string) $s->sku, 0, 100), 'ebay_order_no' => mb_substr((string) $s->order_no, 0, 30), 'fk_id_t_sale_orders' => (int) $s->id,
                'd_entered' => now(), 'fk_id_t_users' => (int) $u->id, 'e_status' => 'PENDING', 'va_remarks' => mb_substr($reason, 0, 500), 'fk_id_customer' => $s->fk_id_customer ?? null, 'd_reply_by' => $by]));
            self::note($id, 'RAISED', $reason, $u); Audit::log('t_sale_order_reminders', $id, 'INSERT', ['order' => $s->order_no, 'type' => $typeName, 'reason' => $reason]);
            self::taskOpen(DB::table('t_sale_order_reminders')->where('id', $id)->first(), $s, $typeName, $reason);
            Notify::toRight('orders.saleCaseAdmin', 'SALE_CASE', "New case on order {$s->order_no}: $typeName. Reply by " . date('d-m-Y', strtotime($by)) . '.', '#/sale-cases', (int) $u->id);
            return ['message' => "Case added on order {$s->order_no}. A reply is due by " . date('d-m-Y', strtotime($by)) . '.', 'id' => $id];
        });
    }

    /** A seller raises a case: type and reason both required; errors come back per field. */
    public static function raise(int $saleId, mixed $type, ?string $reason, ?object $u): array
    {
        Perm::need($u, 'orders.saleCase'); $s = self::sale($saleId, $u); $reason = trim((string) $reason); $type = (int) $type; $e = [];
        if (! $type) $e['type'] = 'Choose the reminder type.'; elseif (! in_array($type, array_column(self::types(), 'id'), true)) $e['type'] = 'Choose a reminder type from the list.';
        if ($reason === '') $e['reason'] = 'Write the reason.'; if ($e) self::fieldError($e);
        return self::add($s, $type, $reason, true, $u);
    }

    /** Manager quick add: the general type, the reason of the line (its latest case reason, else its note). */
    public static function quickAdd(int $saleId, ?object $u): array
    {
        if (! self::admin($u)) throw new \InvalidArgumentException('user has no rights'); $s = self::sale($saleId, $u); $gen = self::generalId() ?: throw new \InvalidArgumentException('The general reminder type (REM-GEN) is missing. Run the migration 2026_11_21_000033_sale_cases.');
        $reason = (string) (DB::table('t_sale_order_reminders')->where('fk_id_t_sale_orders', $s->id)->orderByDesc('id')->value('va_remarks') ?: trim((string) ($s->ds_note ?? '')) ?: 'Added by a manager');
        return self::add($s, $gen, $reason, false, $u);
    }

    private static function one(int $id, ?object $u): object
    {
        $c = DB::table('t_sale_order_reminders as r')->join('t_branches as b', 'b.id', '=', 'r.fk_id_branch')->leftJoin('reference_items as t', 't.id', '=', 'r.fk_id_rem_type')->where('r.id', $id)->where('b.fk_id_ent', Context::ent())->first(['r.*', 't.item_code', 't.reference_name as type_name']) ?? abort(404, 'Case not found.');
        if (! Perm::shopAllowed($u, (int) $c->fk_id_branch) || ($c->item_code === self::GENERAL && ! self::admin($u))) abort(403, "You don't have rights for this case."); return $c;
    }

    /** Edit = the manager's remarks only (required). */
    public static function remark(int $id, ?string $text, ?object $u): array
    {
        if (! self::admin($u)) throw new \InvalidArgumentException('user has no rights'); $c = self::one($id, $u); $text = trim((string) $text); if ($text === '') self::fieldError(['admin_remarks' => 'Write the admin remarks.']);
        DB::table('t_sale_order_reminders')->where('id', $id)->update(['admin_remarks' => mb_substr($text, 0, 500)]); self::note($id, 'REMARK', $text, $u); Audit::log('t_sale_order_reminders', $id, 'UPDATE', ['admin_remarks' => $text]);
        if ((int) $c->fk_id_t_users !== (int) $u->id) Notify::push((int) $c->fk_id_t_users, 'SALE_CASE', "Reply on your case for order {$c->ebay_order_no}: " . mb_substr($text, 0, 120), '#/sale-cases');
        return ['message' => 'Admin remarks saved.'];
    }

    /** The person who raised it (or anyone with the right on that shop) adds to the thread. */
    public static function reply(int $id, ?string $text, ?object $u): array
    {
        Perm::need($u, 'orders.saleCase'); $c = self::one($id, $u); $text = trim((string) $text); if ($text === '') self::fieldError(['note' => 'Write the message.']); if ($c->e_status !== 'PENDING') throw new \InvalidArgumentException('This case is resolved. Revert it to write again.');
        self::note($id, 'REPLY', $text, $u); Notify::toRight('orders.saleCaseAdmin', 'SALE_CASE', "New message on the case for order {$c->ebay_order_no}: " . mb_substr($text, 0, 120), '#/sale-cases', (int) $u->id);
        return ['message' => 'Message added.'];
    }

    public static function resolve(int $id, ?object $u): array
    {
        Perm::need($u, 'orders.saleCase'); $c = self::one($id, $u); if ($c->e_status === 'RESOLVED') throw new \InvalidArgumentException('This case is already resolved.');
        DB::table('t_sale_order_reminders')->where('id', $id)->update(['e_status' => 'RESOLVED', 'resolved_at' => now(), 'resolved_by' => $u->id]); self::note($id, 'RESOLVED', '', $u); TaskEngine::close('SALE_QUERY', [$id], 'The case is resolved'); Audit::log('t_sale_order_reminders', $id, 'UPDATE', ['e_status' => ['PENDING', 'RESOLVED']]);
        if ((int) $c->fk_id_t_users !== (int) $u->id) Notify::push((int) $c->fk_id_t_users, 'SALE_CASE', "Your case for order {$c->ebay_order_no} is resolved.", '#/sale-cases');
        return ['message' => 'Case resolved.'];
    }

    public static function revert(int $id, ?object $u): array
    {
        Perm::need($u, 'orders.saleCase'); $c = self::one($id, $u); if ($c->e_status === 'PENDING') throw new \InvalidArgumentException('This case is already open.');
        $by = date('Y-m-d', strtotime('+' . self::replyDays() . ' days')); DB::table('t_sale_order_reminders')->where('id', $id)->update(['e_status' => 'PENDING', 'resolved_at' => null, 'resolved_by' => null, 'd_reply_by' => $by]); self::note($id, 'REOPENED', '', $u); Audit::log('t_sale_order_reminders', $id, 'UPDATE', ['e_status' => ['RESOLVED', 'PENDING']]);
        if ($s = DB::table('t_sales')->where('id', $c->fk_id_t_sale_orders)->first()) self::taskOpen(DB::table('t_sale_order_reminders')->where('id', $id)->first(), $s, (string) $c->type_name, (string) $c->va_remarks);
        return ['message' => 'Case is open again.'];
    }

    public static function remove(int $id, ?object $u): array
    {
        Perm::need($u, 'orders.saleCase'); $c = self::one($id, $u);
        DB::transaction(function () use ($c, $id) { DB::table('mo_recycle_bin')->insert(['fk_id_ent' => (int) Context::ent(), 'table_name' => 't_sale_order_reminders', 'row_id' => $id, 'label' => mb_substr("Case on order {$c->ebay_order_no}", 0, 255), 'group_key' => 'case-' . $id . '-' . time(), 'row_json' => json_encode(array_diff_key((array) $c, ['item_code' => 1, 'type_name' => 1])), 'deleted_by' => \Illuminate\Support\Facades\Auth::id(), 'deleted_at' => now(), 'note' => 'Case removed']);
            DB::table('t_sale_order_reminders')->where('id', $id)->delete(); TaskEngine::close('SALE_QUERY', [$id], 'The case was removed'); Audit::log('t_sale_order_reminders', $id, 'DELETE', ['order' => $c->ebay_order_no]); });
        return ['message' => 'Case removed.'];
    }

    public static function thread(int $id, ?object $u): array
    {
        Perm::need($u, 'orders.saleCase'); $c = self::one($id, $u); $label = ['RAISED' => 'VA explanation', 'REPLY' => 'Message', 'REMARK' => 'Admin remark', 'RESOLVED' => 'Resolved', 'REOPENED' => 'Reopened'];
        return ['id' => $id, 'order_no' => $c->ebay_order_no, 'type' => (string) $c->type_name, 'status' => $c->e_status, 'va_remarks' => (string) $c->va_remarks, 'admin_remarks' => (string) $c->admin_remarks, 'can_remark' => self::admin($u),
            'notes' => DB::table('mo_sale_case_notes')->where('fk_id_case', $id)->orderBy('id')->get()->map(fn ($n) => ['what' => $label[$n->kind] ?? $n->kind, 'text' => $n->note, 'by' => $n->user_name, 'at' => date('d-m-Y H:i', strtotime((string) $n->created_at))])->all()];
    }

    /** Pending and Resolved, each ordered by order no. Filters: status, shop, type, q, overdue. */
    public static function list(array $f, ?object $u, ?string $today = null): array
    {
        Perm::need($u, 'orders.saleCase'); $today ??= date('Y-m-d'); $admin = self::admin($u); $money = (bool) ($u && Perm::has($u, 'orders.MONEY')); $status = ($f['status'] ?? '') === 'RESOLVED' ? 'RESOLVED' : 'PENDING';
        $base = fn () => tap(DB::table('t_sale_order_reminders as r')->join('t_branches as b', 'b.id', '=', 'r.fk_id_branch')->leftJoin('reference_items as t', 't.id', '=', 'r.fk_id_rem_type')->where('b.fk_id_ent', Context::ent()), function ($q) use ($u, $admin, $f) {
            $scope = Perm::shops($u); if ($scope !== null) $q->whereIn('r.fk_id_branch', $scope); if (! $admin) $q->where(fn ($w) => $w->whereNull('t.item_code')->orWhere('t.item_code', '<>', self::GENERAL));      // a non-manager never sees REM-GEN
            if (! empty($f['shop'])) $q->where('r.fk_id_branch', (int) $f['shop']); if (! empty($f['type'])) $q->where('r.fk_id_rem_type', (int) $f['type']);
            if (trim((string) ($f['q'] ?? '')) !== '') { $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($f['q'])) . '%'; $q->where(fn ($w) => $w->where('r.ebay_order_no', 'like', $like)->orWhere('r.sku_ebay', 'like', $like)->orWhere('r.va_remarks', 'like', $like)->orWhere('r.admin_remarks', 'like', $like)); } });
        $counts = ['PENDING' => $base()->where('r.e_status', 'PENDING')->count(), 'RESOLVED' => $base()->where('r.e_status', 'RESOLVED')->count(), 'overdue' => $base()->where('r.e_status', 'PENDING')->where('r.d_reply_by', '<', $today)->count()];
        $q = $base()->where('r.e_status', $status); if (! empty($f['overdue'])) $q->where('r.d_reply_by', '<', $today);
        $rows = $q->leftJoin('t_sales as s', 's.id', '=', 'r.fk_id_t_sale_orders')->leftJoin('t_carrier_pkg_svc as cv', 'cv.id', '=', 's.fk_id_car_pkg_svc')->leftJoin('t_account as v', 'v.id', '=', 's.fk_id_vendor')->leftJoin('t_users as us', 'us.id', '=', 'r.fk_id_t_users')
            ->orderBy('r.ebay_order_no')->orderBy('r.id')->limit(500)->get(['r.*', 'b.shop_no', 'b.shop_name', 't.reference_name as type_name', 't.item_code', 's.d_order', 's.cust_name', 's.cust_contacts', 's.product_info', 's.qty', 's.sale_rate', 's.total_amount', 's.buy_rate', 's.buy_amount', 's.profit', 's.d_buy', 's.ds_note', 's.fk_id_import', 'cv.pkg_svc_name',
                DB::raw("NULLIF(TRIM(CONCAT_WS(' ', NULLIF(v.first_name,''), NULLIF(v.sur_name,''))), '') as vendor_name"), 'us.display_name as by_name']);
        $d = fn ($x) => $x ? date('d-m-Y', strtotime((string) $x)) : ''; $n = fn ($x) => $x === null ? '' : number_format((float) $x, 2);
        return ['status' => $status, 'counts' => $counts, 'admin' => $admin, 'money' => $money, 'types' => $admin ? array_merge(self::types(), self::generalId() ? [['id' => self::generalId(), 'name' => 'General']] : []) : self::types(), 'shops' => array_map(fn ($s) => ['id' => $s['id'], 'name' => $s['label']], ShopLabel::list($u)), 'reply_days' => self::replyDays(),
            'rows' => $rows->map(fn ($r) => ['id' => (int) $r->id, 'sale' => (int) $r->fk_id_t_sale_orders, 'batch' => (int) ($r->fk_id_import ?? 0), 'shop' => trim((string) $r->shop_no) . ' (' . trim((string) $r->shop_name) . ')', 'order_no' => $r->ebay_order_no, 'date' => $d($r->d_order), 'customer' => (string) $r->cust_name, 'contacts' => (string) $r->cust_contacts, 'sku' => $r->sku_ebay, 'product' => (string) $r->product_info,
                'qty' => $r->qty === null ? '' : (int) $r->qty, 'rate' => $n($r->sale_rate), 'total' => $n($r->total_amount), 'carrier' => (string) $r->pkg_svc_name, 'supplier' => (string) $r->vendor_name, 'buy_rate' => $money ? $n($r->buy_rate) : null, 'buy_amount' => $money ? $n($r->buy_amount) : null, 'profit' => $money ? $n($r->profit) : null, 'profit_neg' => $money && (float) $r->profit < 0, 'buy_date' => $d($r->d_buy), 'item_note' => (string) $r->ds_note,
                'type' => (string) $r->type_name, 'reason' => (string) $r->va_remarks, 'admin_remarks' => (string) $r->admin_remarks, 'entered' => $d($r->d_entered), 'by' => (string) $r->by_name, 'reply_by' => $d($r->d_reply_by), 'overdue' => $r->e_status === 'PENDING' && $r->d_reply_by && $r->d_reply_by < $today, 'gone' => $r->d_order === null])->all()];
    }
}
