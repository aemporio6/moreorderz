<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Generic list / read / save for every registry form. One code path, so every form gets the same
 * company filter, validation, auto-correct, defaults, history and event.
 */
class RecordService
{
    /** Columns never shown or saved through the generic path. */
    private const BLOCKED = ['pass', 'password_hash', 'remember_token', 'totp_secret'];

    public static function list(string $formKey, array $p = []): array
    {
        $table = Registry::table($formKey) ?? abort(404, "Form $formKey has no table.");
        $pk = Registry::pk($table);
        $fields = self::storedFields($formKey, $table);
        $allCols = array_slice(array_values(array_unique(array_map(fn ($f) => $f['column_name'], $fields))), 0, 80);
        // the user's own choice of columns (a saved view keeps it); without one the first 12 show
        $want = array_values(array_intersect(is_array($p['cols'] ?? null) ? $p['cols'] : array_filter(explode(',', (string) ($p['cols'] ?? ''))), $allCols));
        $cols = $want ?: array_slice($allCols, 0, (int) ($p['max_cols'] ?? 12));
        $q = DB::table($table);
        self::scope($q, $table);
        if (! empty($p['q'])) {
            $text = array_filter($fields, fn ($f) => in_array($f['type'], ['text', 'textarea', 'email', 'phone', 'link'], true));
            // the same search as the top box: every typed word must be somewhere in the row, in any order; synonyms count
            $ws = array_slice(preg_split('/\s+/u', trim((string) $p['q']), -1, PREG_SPLIT_NO_EMPTY), 0, 8); [$alts] = Finder::synonyms((string) $p['q'], array_map('mb_strtolower', $ws));
            foreach ($ws as $word) $q->where(function ($w) use ($text, $word, $alts) {
                foreach ($text as $f) { $w->orWhere($f['column_name'], 'like', '%' . $word . '%'); foreach ($alts[mb_strtolower($word)] ?? [] as $alt) $w->orWhere($f['column_name'], 'like', '%' . $alt . '%'); }
            });
        }
        self::narrow($q, $table, $fields, $p);
        $where = ListFilter::clean($p['where'] ?? null, self::byCol($fields));
        $total = (clone $q)->count();
        // totals row: every number / money column that shows, over ALL filtered rows (not only this page)
        $sumCols = array_values(array_filter($cols, fn ($c) => in_array(self::fieldByCol($fields, $c)['type'], ['number', 'money'], true) && ! str_starts_with($c, 'fk_') && ! str_ends_with($c, '_id')));
        $totals = [];
        if ($sumCols && $total && empty($p['no_totals'])) { $t = (array) (clone $q)->selectRaw(implode(', ', array_map(fn ($c) => "SUM(`$c`) as `$c`", $sumCols)))->first(); foreach ($sumCols as $c) $totals[$c] = round((float) ($t[$c] ?? 0), 2); }
        $sort = Registry::hasColumn($table, (string) ($p['sort'] ?? '')) ? $p['sort'] : $pk;
        $per = max(1, min(250, (int) ($p['per_page'] ?? 100)));
        $page = max(1, (int) ($p['page'] ?? 1));
        $rows = $q->orderBy($sort, ($p['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc')->offset(($page - 1) * $per)->limit($per)->get(array_merge([$pk . ' as id'], $cols));
        $labels = self::labels($fields, $rows);
        $col = fn ($c) => ['name' => $c, 'label' => self::fieldByCol($fields, $c)['label'], 'type' => self::fieldByCol($fields, $c)['type'], 'group' => ListFilter::group(self::fieldByCol($fields, $c)['type'])];
        return [
            'form_key' => $formKey, 'title' => Registry::fields($formKey)[0]['form_title'], 'table' => $table, 'total' => $total, 'page' => $page, 'per_page' => $per, 'all_columns' => array_map($col, $allCols),
            'columns' => array_map($col, $cols), 'totals' => $totals, 'where' => $where, 'where_text' => ListFilter::words($where, self::byCol($fields)),
            'rows' => $rows->map(function ($r) use ($labels, $table) {
                $a = Vault::maskRow($table, (array) $r);
                foreach ($a as $k => $v) if (isset($labels[$k][(string) $v])) $a[$k . '__label'] = $labels[$k][(string) $v];
                return $a;
            })->all(),
        ];
    }

    private static function byCol(array $fields): array
    {
        $out = [];
        foreach ($fields as $f) $out[$f['column_name']] ??= $f;
        return $out;
    }

    /** Every filter of a list request on the query: quick filters per column, "(empty)", From / To of a date column, and the filter builder's tree. */
    private static function narrow($q, string $table, array $fields, array $p): void
    {
        foreach (($p['filter'] ?? []) as $col => $val) {
            if ($val === '' || $val === null || ! Registry::hasColumn($table, $col)) continue;
            $type = self::fieldByCol($fields, $col)['type'];
            if (is_array($val)) $q->whereIn($col, $val);
            elseif ($val === '(empty)') $q->where(fn ($w) => in_array($type, ['number', 'money', 'date'], true) ? $w->whereNull($col) : $w->whereNull($col)->orWhere($col, ''));
            elseif (in_array($type, ListFilter::TEXT, true)) str_starts_with((string) $val, '^') ? $q->where($col, 'like', substr($val, 1) . '%') : (str_starts_with((string) $val, '=') ? $q->where($col, substr($val, 1)) : $q->where($col, 'like', "%$val%"));
            else $q->where($col, $val);
        }
        // date range kept in the address: <field>_start / <field>_end
        foreach ($fields as $f) if ($f['type'] === 'date') {
            $c = $f['column_name'];
            if (! empty($p[$c . '_start']) && strtotime((string) $p[$c . '_start'])) $q->where($c, '>=', date('Y-m-d 00:00:00', strtotime((string) $p[$c . '_start'])));
            if (! empty($p[$c . '_end']) && strtotime((string) $p[$c . '_end'])) $q->where($c, '<=', date('Y-m-d 23:59:59', strtotime((string) $p[$c . '_end'])));
        }
        ListFilter::apply($q, ListFilter::clean($p['where'] ?? null, self::byCol($fields)), self::byCol($fields));
    }

    /** The different values of one column (with how many rows each), under the list's other filters – for the "is any of" picker. "" = empty. */
    public static function values(string $formKey, string $col, array $p = [], int $limit = 300): array
    {
        $table = Registry::table($formKey) ?? abort(404); $fields = self::storedFields($formKey, $table); $by = self::byCol($fields);
        if (! isset($by[$col])) abort(404, 'This list has no such column.');
        if (! empty($by[$col]['secret']) || Vault::maskRow($table, [$col => 'x'])[$col] !== 'x') return ['values' => [], 'more' => false, 'secret' => true];
        $q = DB::table($table); self::scope($q, $table);
        unset($p['filter'][$col]); self::narrow($q, $table, $fields, $p);
        if (! empty($p['term'])) $q->where($col, 'like', '%' . addcslashes((string) $p['term'], '%_\\') . '%');
        $rows = $q->selectRaw("COALESCE(`$col`, '') as v, COUNT(*) as n")->groupBy('v')->orderBy('v')->limit($limit + 1)->get();
        $labels = self::labels([$by[$col]], $rows->map(fn ($r) => (object) [$col => $r->v]));
        return ['more' => $rows->count() > $limit, 'values' => $rows->take($limit)->map(fn ($r) => ['v' => (string) $r->v, 'label' => (string) $r->v === '' ? '(empty)' : ($labels[$col][(string) $r->v] ?? (string) $r->v), 'n' => (int) $r->n])->all()];
    }

    public static function get(string $formKey, int $id): array
    {
        $table = Registry::table($formKey) ?? abort(404);
        $q = DB::table($table)->where(Registry::pk($table), $id);
        self::scope($q, $table);
        $row = (array) ($q->first() ?? abort(404, 'Record not found.'));
        foreach (self::BLOCKED as $b) unset($row[$b]);
        foreach (Registry::fields($formKey) as $f) if ($f['secret'] && $f['column_name'] && isset($row[$f['column_name']]) && Context::role() !== 'OWNER') $row[$f['column_name']] = '••••';
        $row = Vault::maskRow($table, $row);   // secrets are masked for every role; the Owner reveals one with "Reveal" (logged)
        if ($table === 't_product_vendors') $row = ProductFamily::forForm($row);      // R-D-1: the Parent / Alone switch from the stored code
        return $row;
    }

    /** Values of a new record: registry defaults + anything passed in the context (e.g. the parent record). */
    public static function blank(string $formKey, array $ctx = []): array
    {
        $out = [];
        $c = ['ent' => Context::ent(), 'user' => Context::userId()] + $ctx;
        foreach (Registry::fields($formKey) as $f) {
            $v = Registry::defaultValue($f, $c);
            if ($v !== null && $f['column_name']) $out[$f['column_name']] = $v;
        }
        return array_merge($out, $ctx['values'] ?? []);
    }

    /** Insert or update. Returns ['id' => .., 'created' => bool]. Throws ValidationException with a message per field. */
    /** R-B_SOURCING-54 required fields of the sourcing module with the old messages (table.column => message). */
    public const SYSTEM_USER_FIELDS = ['t_src_cycles.requested_by', 't_src_po.requested_by'];
    public const SPEC_REQUIRED = [
        'cat_src_lov.lov_group' => 'LOV Group is required', 'cat_src_lov.lov_value' => 'LOV Value is required',
        't_product_vendors.product_name_vendor' => 'Product Name is required', 't_src_products.product_name' => 'Product Name is required', 't_src_cycle_products.product_name' => 'Product Name is required',
        't_src_cycles.sourcing_date' => 'Sourcing Date is required',
        't_src_documents.doc_name' => 'Document Name is required', 't_src_documents.drive_url' => 'Google Drive URL is required',
        't_src_forwarders.forwarder_name' => 'Forwarder Name is required', 't_src_investments.fk_id_investor' => 'Investor is required', 't_src_investors.investor_name' => 'Investor Name is required',
        't_src_payment_terms.milestone' => 'Trigger / Milestone is required', 't_src_suppliers.supplier_name' => 'Supplier Name is required',
    ];

    public static function save(string $formKey, array $input, ?int $id = null, array $opt = []): array
    {
        $table = Registry::table($formKey) ?? abort(404);
        $pk = Registry::pk($table);
        $quick = ! empty($opt['quick']);
        $data = []; $errors = [];
        $old = $id ? (array) (DB::table($table)->where($pk, $id)->first() ?? abort(404)) : [];
        foreach (self::storedFields($formKey, $table, true) as $f) {
            $col = $f['column_name'];
            if (in_array($col, self::BLOCKED, true)) continue;
            if ($f['readonly']) { if (! $id && str_starts_with((string) $f['default_rule'], 'const:')) $data[$col] = substr((string) $f['default_rule'], 6); continue; }      /* a fixed value of a quick form (kind WAREHOUSE / ZONE) is stored on a new row */
            $given = array_key_exists($f['field_name'], $input) || array_key_exists($col, $input);
            $v = $input[$f['field_name']] ?? $input[$col] ?? null;
            if (! $given && $id) continue;                        // edit: untouched fields stay as they are
            if (! $given && ! $id) $v = Registry::defaultValue($f, ['ent' => Context::ent(), 'user' => Context::userId()]);
            $v = self::clean($f, $v);
            $numbered = str_starts_with((string) $f['default_rule'], 'numbering:');
            $must = $f['required'] && (! $quick || $f['in_quick']) && ! $numbered;
            if (! $must && $f['required_if']) $must = self::cond($f['required_if'], $input + $old);
            $spec = self::SPEC_REQUIRED[$table . '.' . $col] ?? null;      /* R-B_SOURCING-54 the old messages, checked on the server for every form of the table */
            if ($spec && ! $numbered && ($given || ! $id)) { $must = true; if (in_array(trim((string) $v), ['https://', 'http://'], true)) $v = ''; }      /* an untouched link prefix is empty */
            if ($must && ($v === null || $v === '')) { $errors[$f['field_name']] = $spec ?? ($f['label'] . ' is required.'); continue; }
            if ($v !== null && $v !== '') {
                if ($msg = self::invalid($f, $v)) { $errors[$f['field_name']] = $msg; continue; }
            }
            if ($v === '' ) $v = null;
            $data[$col] = is_array($v) ? implode(',', $v) : $v;
        }
        /* R-B_SOURCING-62 requested by = the person logged in (a system field: what the form posts is ignored, an edit never changes it); a PRODUCT line needs its product */
        if (in_array($table . '.requested_by', self::SYSTEM_USER_FIELDS, true)) { if (! $id) $data['requested_by'] = Context::userId(); else unset($data['requested_by']); }
        if ($table === 't_src_cycle_products' && array_key_exists('fk_id_product', $data + $old) && empty($data['fk_id_product'] ?? $old['fk_id_product'] ?? null))
            $errors['fk_id_product'] = 'Pick the product (or + Add product) for this line.';      /* PO lines: FormRules */
        if ($table === 'cat_src_lov' && ($why = SrcLists::guard($data, $old))) $errors['f_active'] = $why;      /* R-B_SOURCING-52 */
        if ($table === 'cat_src_lov' && ! $id && SrcLists::isSystem((string) ($data['lov_group'] ?? ''), (string) ($data['lov_value'] ?? ''))) $data['f_system'] = 1;
        $errors = FormRules::check($formKey, $data, $old, $id, $errors) + $errors;      // a form's own message (old texts) wins over the general one      // typed codes, shares, uniqueness of this form (R-F_SETUP-35)
        if ($errors) throw ValidationException::withMessages($errors);

        try { $data = Fx::fillRow($table, $data, $old, (int) Context::ent()); } catch (\RuntimeException $e) { throw ValidationException::withMessages(['exchange_rate' => $e->getMessage()]); }      /* R-B_SOURCING-32 */
        if ($table === 'drp_items') foreach (['ship_address', 'ship_phone'] as $mc) if (isset($data[$mc]) && Vault::isMasked($data[$mc])) unset($data[$mc]);      // a masked value shown to the person is never saved back over the real one
        $vault = Vault::prepare($table, $data, $id);   // secrets go to the vault, card numbers keep 4 digits, CVV / PIN are dropped
        try {
            $res = self::write($table, $pk, $id, $data, $old, $formKey, $opt);
            if ($vault['put']) Vault::store($table, (int) $res['id'], $vault['put']);
            if ($vault['dropped']) $res['note'] = 'Not kept on purpose (CVV / PIN are never stored): ' . implode(', ', $vault['dropped']) . '.';
            return $res;
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            throw ValidationException::withMessages(['_form' => 'A record with the same code or name already exists. Change it and save again.']);
        } catch (\Illuminate\Database\QueryException $e) {
            report($e);
            throw ValidationException::withMessages(['_form' => 'This could not be saved because a value does not fit its field. Check the marked values and try again.']);
        }
    }

    private static function write(string $table, string $pk, ?int $id, array $data, array $old, string $formKey, array $opt): array
    {
        return DB::transaction(function () use ($table, $pk, $id, $data, $old, $formKey, $opt) {
            $ent = Context::ent();
            if ($id) {
                $changes = [];
                foreach ($data as $k => $v) if ((string) ($old[$k] ?? '') !== (string) ($v ?? '')) $changes[$k] = [$old[$k] ?? null, $v];
                if ($changes) {
                    if (Registry::hasColumn($table, 'updated_at')) $data['updated_at'] = now();
                    if (Registry::hasColumn($table, 'updated_by')) $data['updated_by'] = Context::userId();
                    DB::table($table)->where($pk, $id)->update($data);
                    Audit::log($table, $id, 'UPDATE', $changes, $opt['reason'] ?? null, $ent);
                }
                $created = false;
            } else {
                if (Registry::hasColumn($table, 'fk_id_ent') && ! isset($data['fk_id_ent'])) $data['fk_id_ent'] = $ent ?? 0;
                if (Registry::hasColumn($table, 'created_at') && ! isset($data['created_at'])) $data['created_at'] = now();
                if (Registry::hasColumn($table, 'created_by') && ! isset($data['created_by'])) $data['created_by'] = Context::userId();
                foreach (Registry::fields($formKey) as $f) {     // own codes: PO-…, RES-…, taken when the record is first saved
                    if ($f['column_name'] && str_starts_with((string) $f['default_rule'], 'numbering:') && empty($data[$f['column_name']]) && Registry::hasColumn($table, $f['column_name'])) {
                        $data[$f['column_name']] = Numbering::next(substr($f['default_rule'], 10), (int) ($data['fk_id_ent'] ?? $ent ?? 0));
                    }
                }
                $data = self::fillNotNull($table, array_filter($data, fn ($v) => $v !== null));   // an empty field of a new record takes the column's own default
                $id = (int) DB::table($table)->insertGetId($data, $pk);
                Audit::log($table, $id, 'INSERT', $data, null, $ent);
                $created = true;
            }
            SearchIndex::put($formKey, $table, $id, $ent);
            if (! empty($opt['event'])) EventBus::fire($opt['event'], $table, $id, ['form' => $formKey, 'created' => $created], $ent);
            FormAssist::remember($formKey, $data);   // for the "you used this last time" chips
            if ($table === 't_src_po_lines') PoLines::sync((int) $id);
            if ($table === 't_src_payments') { PaymentTerms::sync((int) DB::table($table)->where($pk, $id)->value('fk_id_term')); if (! empty($old['fk_id_term']) && (int) $old['fk_id_term'] !== (int) ($data['fk_id_term'] ?? $old['fk_id_term'])) PaymentTerms::sync((int) $old['fk_id_term']); }      /* R-B_SOURCING-30 both terms of a moved payment */
            if ($table === 't_src_payment_terms' && $id) PaymentTerms::sync((int) $id);
            if ($table === 'tax_periods') AccHubAdmin::periodSaved((int) $id);      // R-ACC-21 / R-TAX-4 a tax period status ticks the matching filing
            if ($table === 't_product_vendors' && $created) ProductFamily::created((int) $id, (int) ($data['fk_id_ent'] ?? $ent ?? 0));      // R-D-1: a 0.00 price row per marketplace
            if ($table === 'fin_partner_users') PartnerOnboarding::refresh((int) DB::table('fin_partner_users')->where($pk, $id)->value('investor_id'), 'Partner login linked');      // R-FIN-3 login_created
            if ($table === 't_account_branches') ShopAssignments::saved((int) $id, $created);      // R-C-41: event + VA cache      // FEE / COST freight-cost row, SAMPLE row, PO total (R-B_SOURCING-6)
            if (str_starts_with($table, 't_src_') && ($uid = (int) (\Illuminate\Support\Facades\Auth::id() ?? 0))) WorkflowContext::remember($table, (int) $id, $uid);      // R-B_SOURCING-18: the cycle's carried context
            if (str_starts_with($table, 't_src_') || $table === 'fin_lots') CycleAlerts::touched();      // sourcing alerts run again after the save (R-B_SOURCING-26)
            return ['id' => $id, 'created' => $created];
        });
    }

    /** Record page: values shown as words, parents (links out), children (links in), tasks and history. */
    public static function page(string $formKey, int $id): array
    {
        $table = Registry::table($formKey) ?? abort(404);
        $row = self::get($formKey, $id);
        $sections = []; $parents = [];
        foreach (Registry::fields($formKey) as $f) {
            $c = $f['column_name'];
            if (! $c || ! array_key_exists($c, $row) || ! Registry::visible($f, Context::role())) continue;
            $v = $row[$c]; $label = null;
            if ($table === 't_account' && $c === 'whatsapp_number' && $v && (int) (\Illuminate\Support\Facades\Auth::user()->fk_id_account ?? 0) !== (int) $id) { $sections[$f['section'] ?: 'Details'][] = ['label' => $f['label'], 'type' => 'text', 'value' => PersonChannels::mask((string) $v), 'shown' => PersonChannels::mask((string) $v)]; continue; }      // R-G_CORE-48 masked to others
            if ($v !== null && $v !== '' && in_array($f['type'], ['select', 'multiselect'], true) && $f['source']) {
                foreach (Registry::options($f, Context::ent(), null, 2000) as [$ov, $ol]) if ((string) $ov === (string) $v) { $label = $ol; break; }
                [$kind, $arg] = array_pad(explode(':', (string) $f['source'], 2), 2, '');
                if ($label !== null && in_array($kind, ['table', 'party'], true)) {
                    $pt = $kind === 'party' ? 't_account' : explode('?', $arg)[0];
                    $pf = self::formOfTable($pt);
                    if ($pf) $parents[] = ['label' => $f['label'], 'title' => $label, 'url' => "#/rec/$pf/$v"];
                }
            }
            $sections[$f['section'] ?: 'Details'][] = ['label' => $f['label'], 'type' => $f['type'], 'value' => $v, 'shown' => $label];
        }
        $children = [];
        $pk = Registry::pk($table);
        $refs = DB::table('sys_registry')->where('source', 'like', "table:$table%")->whereNotNull('column_name')->get(['form_key', 'form_title', 'table_name', 'column_name']);
        foreach ($refs as $r) {
            $ct = Registry::baseTable($r->table_name);
            if (! $ct || $ct === $table || isset($children[$ct]) || ! Registry::hasColumn($ct, $r->column_name) || Registry::table($r->form_key) !== $ct) continue;
            $n = DB::table($ct)->where($r->column_name, $id)->count();
            if ($n) $children[$ct] = ['form_key' => $r->form_key, 'title' => $r->form_title, 'count' => $n, 'url' => "#/list/{$r->form_key}?f[{$r->column_name}]=$id"];
        }
        $tasks = DB::table('tasks')->where('linked_module', $table)->where('linked_record_id', $id)->orderByDesc('id')->limit(20)->get(['id', 'title', 'status', 'priority', 'due_at', 'rule_code', 'depends_on_task_id'])->map(fn ($t) => (object) ((array) $t + ['health' => TaskHealth::of($t)]))->all();      // R-G_CORE-21
        $hist = DB::table('sys_audit_log as a')->leftJoin('t_users as u', 'u.id', '=', 'a.user_id')->where('a.table_name', $table)->where('a.record_id', $id)
            ->orderByDesc('a.id')->limit(50)->get(['a.action', 'a.changes_json', 'a.reason', 'a.created_at', 'u.display_name'])
            ->map(fn ($h) => ['action' => $h->action, 'at' => $h->created_at, 'by' => $h->display_name ?: 'System', 'reason' => $h->reason, 'changes' => $h->action === 'UPDATE' ? Mask::history($formKey, json_decode($h->changes_json ?: '[]', true), \Illuminate\Support\Facades\Auth::user()) : null])->all();      // R-G_CORE-62 hidden fields read "(hidden)"
        if ($table === 'cat_company_house') $sections['Accountancy Hub (read-only here)'] = AccHub::display($id);      // kept once, in the Hub (IC-17, R-F_SETUP-35)
        $lc = Registry::labelColumn($table);
        return ['form_key' => $formKey, 'table' => $table, 'id' => $id, 'form_title' => Registry::fields($formKey)[0]['form_title'], 'title' => (string) ($row[$lc] ?? "#$id"),
            'sections' => $sections, 'parents' => $parents, 'children' => array_values($children), 'tasks' => $tasks, 'history' => $hist];
    }

    /** The form that owns a table (the master form with the most fields on it). */
    public static function formOfTable(string $table): ?string
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            foreach (DB::table('sys_registry')->whereNotNull('table_name')->selectRaw('form_key, table_name, COUNT(*) n, MAX(is_master) m')->groupBy('form_key', 'table_name')->orderByDesc('m')->orderByDesc('n')->get() as $r) {
                $map[Registry::baseTable($r->table_name)] ??= $r->form_key;
            }
        }
        return $map[$table] ?? null;
    }

    /** All rows of a list for export (same filters), max 50,000. Returns [header, rows]. */
    public static function export(string $formKey, array $p = []): array
    {
        $out = []; $head = [];
        $p['per_page'] = 200; $page = 1; $p['no_totals'] = 1;
        do {
            $d = self::list($formKey, $p + ['page' => $page, 'max_cols' => 60]);
            $head = array_map(fn ($c) => $c['label'], $d['columns']);
            foreach ($d['rows'] as $r) $out[] = array_map(fn ($c) => $r[$c['name'] . '__label'] ?? $r[$c['name']], $d['columns']);
            $page++;
        } while (count($d['rows']) === 200 && count($out) < 50000);
        Audit::log(Registry::table($formKey), 0, 'EXPORT', ['rows' => count($out), 'form' => $formKey]);
        return [$head, $out];
    }

    // ---- helpers ----

    private static function storedFields(string $formKey, string $table, bool $forWrite = false): array
    {
        return array_values(array_filter(Registry::fields($formKey), fn ($f) => $f['column_name'] && Registry::baseTable($f['table_name']) === $table
            && Registry::hasColumn($table, $f['column_name']) && ! in_array($f['column_name'], self::BLOCKED, true) && Registry::visible($f, Context::role())
            && (! $forWrite || Perm::fieldMode(\Illuminate\Support\Facades\Auth::user(), $formKey, (string) $f['field_name']) !== 'READONLY')));
    }

    private static function fieldByCol(array $fields, string $col): array
    {
        foreach ($fields as $f) if ($f['column_name'] === $col) return $f;
        return ['label' => $col, 'type' => 'text'];
    }

    /** Company filter: a user only sees rows of the company he works in (plus shared rows). */
    private static function scope($q, string $table): void
    {
        if ($table === ProductScope::TABLE) ProductScope::apply($q, \Illuminate\Support\Facades\Auth::user(), $table);      // R-D-21 who sees which product (instead of the product's own shop column)
        else Perm::scopeQuery($q, $table, \Illuminate\Support\Facades\Auth::user(), $table);
        $ent = Context::ent();
        if (! $ent) return;
        if (Registry::hasColumn($table, 'fk_id_ent')) $q->where(fn ($w) => $w->where("$table.fk_id_ent", $ent)->orWhere("$table.fk_id_ent", 0)->orWhereNull("$table.fk_id_ent"));
        elseif (Registry::hasColumn($table, 'company_id')) $q->where(fn ($w) => $w->where("$table.company_id", $ent)->orWhereNull("$table.company_id"));
    }

    /** Dropdown values shown as words in lists. */
    private static function labels(array $fields, $rows): array
    {
        $out = [];
        foreach ($fields as $f) {
            if (! in_array($f['type'], ['select'], true) || ! $f['source']) continue;
            $vals = $rows->pluck($f['column_name'])->filter(fn ($v) => $v !== null && $v !== '')->unique();
            if ($vals->isEmpty()) continue;
            foreach (Registry::options($f, Context::ent(), null, 1000) as [$v, $l]) $out[$f['column_name']][$v] = $l;
        }
        return $out;
    }

    private static function clean(array $f, mixed $v): mixed
    {
        if (is_string($v)) {
            $v = trim($v);
            if ($f['auto_correct'] === 'upper') $v = mb_strtoupper($v);
            if ($f['auto_correct'] === 'lower') $v = mb_strtolower($v);
        }
        if ($f['type'] === 'check') return in_array($v, [1, '1', true, 'true', 'on', 'Y'], true) ? 1 : 0;
        if ($f['type'] === 'month_year' && is_string($v) && $v !== '') return self::monthYear($f, $v);
        if (in_array($f['type'], ['number', 'money'], true) && is_string($v) && $v !== '') $v = str_replace([',', '£', '$', '€', ' '], '', $v);
        return $v;
    }

    /** R-G_CORE-71 month_year: "0726", "07/2026", "7/26", "072026", "2026-07" → stored as a date (valid from = the 1st, an expiry = the last day) when the column is a date, else "MM/YYYY". A bad month stays "MM/YYYY" for the check. */
    public static function monthYear(array $f, string $v): string
    {
        $v = trim($v);
        if (preg_match('/^(\d{4})-(\d{2})(-\d{2})?$/', $v, $m)) [$mm, $yy] = [$m[2], $m[1]];
        elseif (preg_match('/^(\d{1,2})\s*[\/\-.]\s*(\d{2}|\d{4})$/', $v, $m)) [$mm, $yy] = [$m[1], $m[2]];
        elseif (preg_match('/^(\d{2})(\d{2}|\d{4})$/', $v, $m)) [$mm, $yy] = [$m[1], $m[2]];
        else return $v;
        $mm = str_pad($mm, 2, '0', STR_PAD_LEFT); $yy = strlen($yy) === 2 ? '20' . $yy : $yy;
        if ((int) $mm < 1 || (int) $mm > 12) return "$mm/$yy";
        if (! self::isDateColumn($f)) return "$mm/$yy";
        return self::isExpiry($f) ? date('Y-m-t', strtotime("$yy-$mm-01")) : "$yy-$mm-01";
    }

    private static function isExpiry(array $f): bool { return (bool) preg_match('/upto|expir|valid_to|_until/i', (string) ($f['column_name'] ?? $f['field_name'] ?? '')); }

    private static function isDateColumn(array $f): bool
    {
        static $c = [];
        $k = ($f['table_name'] ?? '') . '.' . ($f['column_name'] ?? '');
        if (! isset($c[$k])) { try { $c[$k] = in_array(strtolower((string) \Illuminate\Support\Facades\Schema::getColumnType((string) $f['table_name'], (string) $f['column_name'])), ['date', 'datetime', 'timestamp'], true); } catch (\Throwable) { $c[$k] = false; } }
        return $c[$k];
    }

    private static function invalid(array $f, mixed $v): ?string
    {
        $l = $f['label'];
        switch ($f['type']) {
            case 'number': case 'money':
                if (! is_numeric($v)) return "$l must be a number.";
                if ($f['min'] !== null && $f['min'] !== '' && is_numeric($f['min']) && $v < $f['min']) return "$l cannot be less than {$f['min']}.";
                if ($f['max'] !== null && $f['max'] !== '' && is_numeric($f['max']) && $v > $f['max']) return "$l cannot be more than {$f['max']}.";
                break;
            case 'date':
                if (! preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $v) || ! strtotime((string) $v)) return "$l must be a date (YYYY-MM-DD).";
                break;
            case 'month_year':      // R-G_CORE-71
                if (preg_match('/^(\d{2})\/(\d{4})$/', (string) $v, $m)) { if ((int) $m[1] < 1 || (int) $m[1] > 12) return "$l: month 01–12."; $first = "$m[2]-$m[1]-01"; }
                elseif (preg_match('/^(\d{4})-(\d{2})-\d{2}$/', (string) $v, $m)) $first = "$m[1]-$m[2]-01";
                else return "$l must be a month and year (MM/YYYY).";
                if (self::isExpiry($f) && $first < date('Y-m-01')) return "$l is in the past.";
                break;
            case 'email':
                if (! filter_var($v, FILTER_VALIDATE_EMAIL)) return "$l is not a valid email address.";
                break;
            case 'link':
                if (! preg_match('#^https?://#i', (string) $v)) return "$l must start with http:// or https://.";
                break;
        }
        return null;
    }

    /** Conditions of the registry: "field=VALUE", "field!=VALUE", "field!=" (not empty), "field>1". */
    public static function cond(string $expr, array $vals): bool
    {
        if (! preg_match('/^([\w.]+)\s*(!=|=|>|<)\s*(.*)$/', $expr, $m)) return false;
        $have = (string) ($vals[preg_replace('/^parent\./', '', $m[1])] ?? '');
        return match ($m[2]) {
            '=' => $have === $m[3], '!=' => $have !== $m[3], '>' => (float) $have > (float) $m[3], '<' => (float) $have < (float) $m[3],
        };
    }

    /** Old tables have NOT NULL columns without defaults: give them an empty value so a quick create never fails. */
    public static function fillNotNull(string $table, array $data): array
    {
        static $meta = [];
        $meta[$table] ??= DB::select('SELECT column_name c, data_type t, column_type ct FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND is_nullable = "NO" AND column_default IS NULL AND extra NOT LIKE "%auto_increment%"', [$table]);
        foreach ($meta[$table] as $m) {
            if (array_key_exists($m->c, $data) && $data[$m->c] !== null) continue;
            $data[$m->c] = match (true) {
                in_array($m->t, ['int', 'tinyint', 'smallint', 'bigint', 'mediumint', 'decimal', 'float', 'double'], true) => 0,
                $m->t === 'date' => date('Y-m-d'),
                in_array($m->t, ['datetime', 'timestamp'], true) => now(),
                $m->t === 'enum' => trim(explode(',', substr($m->ct, 5, -1))[0], "'"),
                default => '',
            };
        }
        return $data;
    }
}
