<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * New task form (R-G_CORE-2, old views/t_tasks/new.php).
 * - The server sets created_at, assigned_by_user_id (= the current user) and source MANUAL; a typed created date is ignored.
 * - Category = SLA preset: due = today + default_due_days, owner = the category's default owner when the owner box is empty (prefill()).
 * - Link to record: a module from LINK_MODULE, then a record PICKED from the search list (records()); typed text is refused, the record must exist.
 * - Depends on: an existing task picked by name. Absent owner → the cover (warning + history absence_cover). Over the weekly limit (t_account.weekly_task_limit,
 *   default 6) → a warning only, never a block (R-G_CORE-18). Every refusal starts with "Refused:".
 */
class TaskCreate
{
    public const PRIORITIES = ['URGENT', 'HIGH', 'NORMAL', 'LOW'];
    public const OPEN = ['TODO', 'DOING', 'STUCK', 'DONE', 'REWORK'];

    /** module => [table, label sql, search columns, company column, what the user picks] */
    public const MODULES = [
        'PRODUCT' => ['t_product_vendors', "CONCAT_WS(' · ', SKU_VENDOR, product_name_vendor)", ['SKU_VENDOR', 'product_name_vendor', 'ean'], 'fk_id_ent', 'product'],
        'CYCLE' => ['t_src_cycles', "CONCAT_WS(' · ', cycle_code, product_name)", ['cycle_code', 'product_name'], 'fk_id_ent', 'sourcing cycle'],
        'PO' => ['t_src_po', 'po_number', ['po_number'], 'fk_id_ent', 'purchase order'],
        'SHIPMENT' => ['t_src_shipments', 'shipment_code', ['shipment_code'], 'fk_id_ent', 'shipment'],
        'GRN' => ['t_src_grn', 'grn_number', ['grn_number'], 'fk_id_ent', 'GRN'],
        'SALE' => ['t_sales', "CONCAT_WS(' · ', order_no, sku)", ['order_no', 'sku'], 'fk_id_ent', 'sale line'],
        'SHOP' => ['t_branches', "CONCAT_WS(' ', shop_no, shop_name)", ['shop_no', 'shop_name'], 'fk_id_ent', 'shop'],
        'PERSON' => ['t_account', "CONCAT_WS(' ', first_name, sur_name)", ['first_name', 'sur_name', 'account_code'], 'fk_id_ent', 'person'],
        'COMPANY' => ['cat_company_house', 'company_name', ['company_name'], null, 'company'],
        'INVOICE' => ['t_invoices', 'invoice_no', ['invoice_no'], null, 'invoice'],
        'BANK_LINE' => ['fin_bank_transactions', "CONCAT_WS(' · ', txn_date, description, amount)", ['description'], 'company_id', 'bank line'],
    ];

    /** Open tasks and the weekly limit of a login (R-G_CORE-18). */
    public static function load(int $userId): array
    {
        $open = (int) DB::table('tasks')->where('owner_user_id', $userId)->whereIn('status', self::OPEN)->count();
        $acc = (int) DB::table('t_users')->where('id', $userId)->value('fk_id_account');
        $lim = (int) ($acc ? DB::table('t_account')->where('id', $acc)->value('weekly_task_limit') : 0); $lim = $lim >= 1 && $lim <= 99 ? $lim : 6;
        return ['open' => $open, 'limit' => $lim, 'state' => $open < $lim ? 'OK' : ($open === $lim ? 'Full' : 'Overloaded')];
    }

    /** What the form needs: categories (SLA), owners with their load (least loaded first), modules, priorities. */
    public static function form(int $ent): array
    {
        $owners = DB::table('t_users')->where('f_active', 1)->where(fn ($w) => $w->whereNull('fk_id_ent')->orWhere('fk_id_ent', 0)->orWhere('fk_id_ent', $ent))->orderBy('display_name')->get(['id', 'display_name'])
            ->map(fn ($u) => ['id' => (int) $u->id, 'name' => $u->display_name, 'absent' => (bool) TaskOwner::absent((int) $u->id)] + self::load((int) $u->id))->sortBy([['open', 'asc'], ['name', 'asc']])->values()->all();
        return ['categories' => DB::table('task_categories')->where('is_active', 1)->orderBy('sort')->orderBy('name')->get(['id', 'name', 'default_due_days', 'default_owner_user_id']),
            'owners' => $owners, 'priorities' => self::PRIORITIES, 'today' => date('Y-m-d'),
            'modules' => DB::table('sys_field_options')->where('list_code', 'LINK_MODULE')->where('f_active', 1)->orderBy('sort')->get(['value', 'label'])];
    }

    /** Category picked → due and (when the owner box is empty) owner. */
    public static function prefill(int $categoryId, ?string $today = null): array
    {
        $c = DB::table('task_categories')->where('id', $categoryId)->where('is_active', 1)->first() ?? throw new \InvalidArgumentException('Refused: category not found or inactive.');
        $today ??= date('Y-m-d');
        return ['due' => $c->default_due_days !== null ? date('Y-m-d', strtotime($today . ' +' . (int) $c->default_due_days . ' days')) : null, 'owner' => $c->default_owner_user_id ? (int) $c->default_owner_user_id : null];
    }

    /** The search list of a module (at least 2 characters or digits, max 20 hits). */
    public static function records(int $ent, string $module, string $q): array
    {
        $m = self::MODULES[strtoupper($module)] ?? throw new \InvalidArgumentException('Choose what the task is about.');
        [$table, $label, $cols, $entCol] = $m; $q = trim($q);
        if (mb_strlen($q) < 2 && ! ctype_digit($q)) return [];
        $x = DB::table($table)->when($entCol, fn ($w) => $w->where($entCol, $ent));
        if (\Illuminate\Support\Facades\Schema::hasColumn($table, 'f_active') && $table === 't_product_vendors') $x->where('f_active', 1);
        $x->where(function ($w) use ($cols, $q) { foreach ($cols as $c) $w->orWhere($c, 'like', '%' . addcslashes($q, '%_\\') . '%'); if (ctype_digit($q)) $w->orWhere('id', (int) $q); });
        return $x->orderByDesc('id')->limit(20)->get(['id', DB::raw("$label as label")])->map(fn ($r) => ['id' => (int) $r->id, 'label' => (string) $r->label])->all();
    }

    /** Tasks to depend on, by title or ref (T-0012). */
    public static function tasks(int $ent, string $q): array
    {
        $q = trim($q); if (mb_strlen($q) < 2) return [];
        $id = preg_match('/^T-?0*(\d+)$/i', $q, $m) ? (int) $m[1] : (ctype_digit($q) ? (int) $q : 0);
        return DB::table('tasks')->where(fn ($w) => $w->whereNull('fk_id_ent')->orWhere('fk_id_ent', $ent))->whereNotIn('status', ['CANCELLED'])
            ->where(fn ($w) => $w->where('title', 'like', '%' . addcslashes($q, '%_\\') . '%')->when($id, fn ($x) => $x->orWhere('id', $id)))->orderByDesc('id')->limit(20)->get(['id', 'title', 'status'])
            ->map(fn ($t) => ['id' => (int) $t->id, 'label' => 'T-' . str_pad((string) $t->id, 4, '0', STR_PAD_LEFT) . ' · ' . $t->title, 'status' => $t->status])->all();
    }

    /** Save. $d: title, owner, due, category, priority, link (module), record (picked id), record_text (typed text), depends_on, detail, checklist, evidence. */
    public static function create(int $ent, array $d, object $u): array
    {
        $title = trim((string) ($d['title'] ?? ''));
        if ($title === '') throw new \InvalidArgumentException('Refused: title is required.');
        if (mb_strlen($title) > 255) throw new \InvalidArgumentException('Refused: title is longer than 255 characters.');
        $pr = strtoupper((string) ($d['priority'] ?? 'NORMAL')) ?: 'NORMAL';
        if (! in_array($pr, self::PRIORITIES, true)) throw new \InvalidArgumentException('Refused: priority must be one of URGENT, HIGH, NORMAL, LOW.');
        $cat = ! empty($d['category']) ? (int) $d['category'] : null;
        if ($cat && ! DB::table('task_categories')->where('id', $cat)->where('is_active', 1)->exists()) throw new \InvalidArgumentException('Refused: category not found or inactive.');
        $pre = $cat ? self::prefill($cat) : ['due' => null, 'owner' => null];
        $owner = ! empty($d['owner']) ? (int) $d['owner'] : (int) ($pre['owner'] ?? 0);
        if (! $owner) throw new \InvalidArgumentException('Refused: owner is required.');
        if (! DB::table('t_users')->where('id', $owner)->where('f_active', 1)->exists()) throw new \InvalidArgumentException('Refused: owner is not an active user who can log in.');
        $due = trim((string) ($d['due'] ?? '')) ?: $pre['due'];
        if (! $due || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) throw new \InvalidArgumentException('Refused: due date is required.');
        if ($due < date('Y-m-d')) throw new \InvalidArgumentException('Refused: the due date cannot be in the past.');

        $mod = strtoupper(trim((string) ($d['link'] ?? ''))); $rec = ! empty($d['record']) ? (int) $d['record'] : null;
        if ($mod === '' || $mod === 'NONE') { $mod = null; $rec = null; }
        elseif ($m = self::MODULES[$mod] ?? null) {
            if (! $rec) throw new \InvalidArgumentException("Refused: pick the {$m[4]} from the search list, or choose \"No record\".");
            if (! DB::table($m[0])->where('id', $rec)->when($m[3], fn ($w) => $w->where($m[3], $ent))->exists()) throw new \InvalidArgumentException('Refused: ' . ucfirst($m[4]) . " #$rec does not exist. Search and pick the record from the list.");
        } else throw new \InvalidArgumentException('Refused: choose what the task is about from the list.');
        $dep = ! empty($d['depends_on']) ? (int) $d['depends_on'] : null;
        if ($dep && ! DB::table('tasks')->where('id', $dep)->exists()) throw new \InvalidArgumentException('Refused: the task it depends on does not exist.');
        $parent = ! empty($d['parent']) ? TaskActions::parentFor((int) $d['parent'], $ent) : null;      // R-G_CORE-12: one level, under an open parent, its link copied
        if ($parent && ! $mod && $parent->linked_module) { $mod = $parent->linked_module; $rec = $parent->linked_record_id ? (int) $parent->linked_record_id : null; }

        // R-G_CORE-6: proof is ON by default; only a boss switches it off (with a reason); amount and "confidential" are a Partner's – a value from anyone else refuses the whole save
        $boss = in_array($u->role_code ?? '', TaskScore::BOSS, true); $system = ! empty($d['system']);
        $evidence = array_key_exists('evidence', $d) && $d['evidence'] !== null && $d['evidence'] !== '' ? (bool) $d['evidence'] : true;
        if (! $evidence && ! $system && ! $boss) throw new \InvalidArgumentException('Refused: only a Partner or Manager may switch "evidence required" off.');
        if (! $evidence && ! $system && trim((string) ($d['evidence_reason'] ?? '')) === '') throw new \InvalidArgumentException('Refused: give a reason for switching evidence off.');
        $partner = in_array($u->role_code ?? '', ['OWNER', 'PARTNER'], true);
        if (isset($d['amount']) && $d['amount'] !== '' && $d['amount'] !== null && ! $partner && ! $system) throw new \InvalidArgumentException('Refused: only a Partner may enter an amount.');
        if (! empty($d['confidential']) && ! $partner && ! $system) throw new \InvalidArgumentException('Refused: only a Partner may mark a task confidential.');
        $warn = []; $hist = null; $realOwner = $owner;
        if ($a = TaskOwner::absent($owner)) {
            $cover = TaskOwner::usable($owner);
            if ($cover && $cover !== $owner) {
                $realOwner = $cover; $on = DB::table('t_users')->where('id', $owner)->value('display_name'); $cn = DB::table('t_users')->where('id', $cover)->value('display_name');
                $warn[] = "Owner is absent - task given to $cn."; $hist = "created during absence of $on ({$a->from_date} to " . ($a->to_date ?: 'open') . ')';
            } else $warn[] = 'Owner is absent and has no cover.';
        }
        return DB::transaction(function () use ($ent, $d, $u, $title, $pr, $cat, $realOwner, $due, $mod, $rec, $dep, $warn, $hist, $parent, $evidence, $system) {
            $id = (int) DB::table('tasks')->insertGetId(RecordService::fillNotNull('tasks', ['fk_id_ent' => $ent, 'title' => $title, 'description' => trim((string) ($d['detail'] ?? '')) ?: null, 'category_id' => $cat,
                'owner_user_id' => $realOwner, 'assigned_by_user_id' => (int) $u->id, 'priority' => $pr, 'status' => 'TODO',      // a task that waits for another stays TODO; N10 tells the owner when it is approved
                'created_at' => now(), 'due_at' => $due . ' 23:59:59', 'source' => 'MANUAL', 'linked_module' => $mod, 'linked_record_id' => $rec, 'depends_on_task_id' => $dep, 'parent_task_id' => $parent ? (int) $parent->id : null, 'reference_id' => $parent->reference_id ?? null,
                'evidence_required' => $evidence ? 1 : 0, 'evidence_waived_by' => $evidence ? null : (int) $u->id, 'evidence_waived_at' => $evidence ? null : now(),
                'amount_gbp' => isset($d['amount']) && $d['amount'] !== '' && $d['amount'] !== null ? round((float) $d['amount'], 2) : null, 'is_confidential' => ! empty($d['confidential']) ? 1 : 0, 'last_update_at' => now()]));
            if (! $evidence) DB::table('task_history')->insert(['task_id' => $id, 'at' => now(), 'user_id' => (int) $u->id, 'field' => 'evidence_required', 'old_value' => '1', 'new_value' => '0',
                'reason' => $system ? 'set by System' : 'switched off at create by ' . ($u->role_code ?? '') . ': ' . mb_substr(trim((string) $d['evidence_reason']), 0, 200)]);
            foreach (array_values(array_filter(array_map('trim', preg_split('/\R/', (string) ($d['checklist'] ?? ''))))) as $n => $line)
                DB::table('task_checklist')->insert(['task_id' => $id, 'text' => mb_substr($line, 0, 255), 'sort' => $n + 1, 'is_done' => 0]);
            DB::table('task_history')->insert(['task_id' => $id, 'at' => now(), 'user_id' => (int) $u->id, 'field' => 'status', 'old_value' => null, 'new_value' => 'TODO', 'reason' => 'Created by hand']);
            if ($hist) DB::table('task_history')->insert(['task_id' => $id, 'at' => now(), 'user_id' => (int) $u->id, 'field' => 'absence_cover', 'old_value' => null, 'new_value' => (string) $realOwner, 'reason' => mb_substr($hist, 0, 255)]);
            $l = self::load($realOwner);
            if ($l['open'] > $l['limit']) $warn[] = DB::table('t_users')->where('id', $realOwner)->value('display_name') . " now has {$l['open']} open tasks (limit {$l['limit']}).";
            Audit::log('tasks', $id, 'INSERT', ['title' => $title, 'owner' => $realOwner, 'due' => $due], null, $ent);
            if (empty($d['quiet'])) TaskFlow::announce($id);      // R-G_CORE-3 / N1 (a quiet CSV import tells nobody now – R-G_CORE-34)
            return ['id' => $id, 'ref' => 'T-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT), 'warnings' => $warn, 'message' => 'Task created.'];
        });
    }
}
