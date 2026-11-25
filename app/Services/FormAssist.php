<?php

namespace App\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What makes a form "smart": options that follow the field above them (cascades), "+ Add" on every dropdown,
 * suggestion chips (last used by you · most used · from the linked record), look-alike warning before a duplicate is saved,
 * and the plain sentence "what happens when I save".
 */
class FormAssist
{
    public static function field(string $formKey, string $name): ?array
    {
        foreach (Registry::fields($formKey) as $f) if ($f['field_name'] === $name) return $f;
        return null;
    }

    /** The table a dropdown reads, or null (fixed list / reference list). */
    /**
     * R-B_SOURCING-23 a saved value never disappears: the names of saved references that are no longer in the option list
     * (inactive, another role, deleted) – looked up without the list's filters; an id that cannot be found comes back as the id.
     */
    public static function savedLabels(string $formKey, string $field, array $ids): array
    {
        $f = collect(Registry::fields($formKey))->firstWhere('field_name', $field); if (! $f) return [];
        $ids = array_values(array_unique(array_filter(array_map('strval', $ids), fn ($x) => $x !== ''))); $out = array_combine($ids, $ids) ?: [];
        $t = self::sourceTable($f); if (! $t || ! $ids) return $out;
        if ($t === 't_account') { foreach (DB::table('t_account')->whereIn('id', $ids)->get(['id', 'first_name', 'sur_name', 'account_code']) as $a) $out[(string) $a->id] = trim($a->first_name . ' ' . $a->sur_name) ?: (string) $a->account_code; return $out; }
        $label = $t === 't_src_suppliers' ? 'supplier_name' : Registry::labelColumn($t); $pk = Registry::pk($t);
        if ($label && Registry::hasColumn($t, $label)) foreach (DB::table($t)->whereIn($pk, $ids)->pluck($label, $pk) as $id => $name) if ((string) $name !== '') $out[(string) $id] = (string) $name;
        return $out;
    }

    public static function sourceTable(array $f): ?string
    {
        [$kind, $arg] = array_pad(explode(':', (string) $f['source'], 2), 2, '');
        if ($kind === 'party') return 't_account';
        if ($kind === 'table' || $kind === 'own') return explode('?', $arg)[0];
        return null;
    }

    /**
     * How "+ Add" works for a dropdown: ['form' => key] opens that form; ['inline' => true] adds a value to the field's own list;
     * null = a fixed list of the system (statuses and the like – they drive the rules, so they are not added by hand).
     */
    public static function addWay(array $f): ?array
    {
        if (! in_array($f['type'], ['select', 'multiselect'], true)) return null;
        if ($f['add_form'] && Registry::exists((string) $f['add_form'])) return ['form' => $f['add_form']];
        [$kind] = explode(':', (string) $f['source'], 2);
        if ($kind === 'list') return FormStandard::fixedList((string) (explode(':', (string) $f['source'], 2)[1] ?? '')) ? null : ['inline' => true];      // a fixed list drives the program: no Add
        if ($kind === 'ref' && Registry::exists('reference_value')) return ['form' => 'reference_value'];
        if (($t = self::sourceTable($f)) && ($form = RecordService::formOfTable($t))) return ['form' => $form];
        return null;
    }

    /** Add a value to a field's own list (sys_field_options). Returns [value, label]; the same label twice gives the existing one. */
    public static function addOption(string $formKey, string $name, string $label): array
    {
        $f = self::field($formKey, $name) ?? abort(404);
        [$kind, $code] = array_pad(explode(':', (string) $f['source'], 2), 2, '');
        if ($kind !== 'list' || $code === '' || FormStandard::fixedList($code)) throw new \InvalidArgumentException('This list is fixed by the system – values cannot be added here.');
        $label = trim(preg_replace('/\s+/u', ' ', $label));
        if ($label === '' || mb_strlen($label) > 120) throw new \InvalidArgumentException('Type the new value (up to 120 characters).');
        $have = DB::table('sys_field_options')->where('list_code', $code)->where(fn ($w) => $w->whereRaw('LOWER(label) = ?', [mb_strtolower($label)])->orWhereRaw('LOWER(value) = ?', [mb_strtolower($label)]))->first();
        if ($have) { if (! $have->f_active) DB::table('sys_field_options')->where('id', $have->id)->update(['f_active' => 1]); return [(string) $have->value, (string) $have->label, false]; }
        $upper = DB::table('sys_field_options')->where('list_code', $code)->limit(20)->pluck('value')->every(fn ($v) => $v === mb_strtoupper($v));
        $value = $upper ? mb_strtoupper(preg_replace('/[^\p{L}\p{N}]+/u', '_', $label)) : $label;
        DB::table('sys_field_options')->insert(['list_code' => $code, 'value' => mb_substr($value, 0, 120), 'label' => $label, 'sort' => (int) DB::table('sys_field_options')->where('list_code', $code)->max('sort') + 1, 'f_active' => 1]);
        Audit::log('sys_field_options', 0, 'INSERT', ['list' => $code, 'value' => $value, 'from_form' => $formKey]);
        return [(string) $value, $label, true];
    }

    /**
     * Options of a dropdown: narrowed by the typed text and by the fields it follows (cascades_from + the company chosen in the form).
     * $parents = [field name => value] of the form as it is now.
     */
    public static function options(string $formKey, string $name, ?string $q, array $parents = [], int $limit = 200): array
    {
        $f = self::field($formKey, $name) ?? abort(404);
        if (($c = Chains::options($formKey, $f, $parents, $q)) !== null) return $c + ['more' => false, 'recent' => self::recent($formKey, $name)];      // person chains (R-C-21)
        $ent = Context::ent(); $filters = []; $t = self::sourceTable($f);
        if ($t && Schema::hasTable($t)) {
            $names = array_filter(array_map('trim', explode(',', (string) $f['cascades_from'])));
            foreach (Registry::fields($formKey) as $p) {
                $v = $parents[$p['field_name']] ?? null; if ($v === null || $v === '' || $p['field_name'] === $name) continue;
                $isParent = in_array($p['field_name'], $names, true) || in_array($p['column_name'], $names, true) || ($p['column_name'] === 'fk_id_ent' && array_intersect($names, ['company', 'companies']));
                if (! $isParent) continue;
                if ($p['column_name'] === 'fk_id_ent' || in_array($p['field_name'], ['company', 'fk_id_ent'], true)) { $ent = (int) $v; continue; }
                // the list is narrowed when its table has the parent's column (fk_id_carrier → services of that carrier)
                foreach (array_unique([(string) $p['column_name'], (string) $p['field_name']]) as $col) if ($col !== '' && Registry::hasColumn($t, $col)) { $filters[$col] = $v; break; }
            }
        }
        if (! $filters) $opts = Registry::options($f, $ent, $q, $limit);
        else {
            $pk = Registry::pk($t); $lc = Registry::labelColumn($t) ?: $pk;
            $x = DB::table($t)->select($pk . ' as id', $lc . ' as lbl'); foreach ($filters as $c => $v) $x->where($c, $v);
            if ($q) $x->where($lc, 'like', '%' . addcslashes($q, '%_\\') . '%');
            if ($ent && Registry::hasColumn($t, 'fk_id_ent')) $x->where(fn ($w) => $w->where('fk_id_ent', $ent)->orWhere('fk_id_ent', 0)->orWhereNull('fk_id_ent'));
            if (Registry::hasColumn($t, 'f_active')) $x->where('f_active', 1);
            $opts = $x->orderBy($lc)->limit($limit)->get()->map(fn ($r) => [(string) $r->id, (string) ($r->lbl ?? $r->id)])->all();
        }
        if ($t === 't_src_suppliers' && $opts) {      /* R-B_SOURCING-66 a supplier rated D carries the warning in every supplier dropdown (shown, never blocked) */
            $bad = DB::table('t_src_suppliers')->whereIn('id', array_map(fn ($o) => (int) $o[0], $opts))->whereRaw("UPPER(LEFT(TRIM(COALESCE(rating, '')), 1)) = 'D'")->pluck('id')->map(fn ($x) => (string) $x)->all();
            if ($bad) $opts = array_map(fn ($o) => in_array((string) $o[0], $bad, true) ? [$o[0], $o[1] . ' ⚠ ' . BuyingService::RATED_D] + $o : $o, $opts);
        }
        if ($q && ! $filters && ! in_array(explode(':', (string) $f['source'])[0], ['ref', 'table', 'own', 'party'], true)) $opts = array_values(array_filter($opts, fn ($o) => mb_stripos($o[1], $q) !== false || mb_stripos($o[0], $q) !== false));
        return ['options' => $opts, 'more' => count($opts) >= $limit, 'narrowed_by' => array_keys($filters), 'recent' => self::recent($formKey, $name)] + ($q && ! $opts && ! $filters ? ['did_you_mean' => self::closest($f, $ent, $q)] : []);
    }

    /** Form standard (d): nothing matches the typed text → the closest names of the same list (spelling), at most 3. */
    public static function closest(array $f, ?int $ent, string $q): array
    {
        $q = mb_strtolower(trim($q)); if (mb_strlen($q) < 3) return [];
        $out = [];
        foreach (Registry::options($f, $ent, null, 1000) as $o) {
            $l = mb_strtolower((string) $o[1]); $best = 0;
            foreach (array_merge([$l], preg_split('/[\s·,\-]+/u', $l) ?: []) as $part) if ($part !== '') { similar_text($q, $part, $pct); $best = max($best, $pct, levenshtein(substr($q, 0, 255), substr($part, 0, 255)) <= 2 ? 80 : 0); }
            if ($best >= 70) $out[] = [$o[0], $o[1], $best];
        }
        usort($out, fn ($a, $b) => $b[2] <=> $a[2]);
        return array_map(fn ($x) => [(string) $x[0], (string) $x[1]], array_slice($out, 0, 3));
    }

    // ---------- suggestions ----------
    /** The last 3 values this person saved in this field. */
    public static function recent(string $formKey, string $name): array
    {
        $u = Auth::id(); if (! $u) return [];
        return DB::table('sys_recent_values')->where(['fk_id_account' => $u, 'form_key' => $formKey, 'field_key' => $name])->orderByDesc('used_at')->orderByDesc('id')->limit(3)->pluck('value')->all();
    }

    /** After a save: remember what this person typed, per field (3 newest per field; secrets and long text never). */
    public static function remember(string $formKey, array $values): void
    {
        $u = Auth::id(); if (! $u) return;
        foreach (Registry::fields($formKey) as $f) {
            $n = $f['field_name']; $v = $values[$n] ?? ($f['column_name'] ? ($values[$f['column_name']] ?? null) : null);
            if ($v === null || $v === '' || is_array($v) || $f['secret'] || in_array($f['type'], ['textarea', 'file', 'check'], true) || str_starts_with((string) $f['default_rule'], 'numbering:') || mb_strlen((string) $v) > 255) continue;
            if ($f['column_name'] && Vault::maskRow((string) Registry::table($formKey), [$f['column_name'] => 'x'])[$f['column_name']] !== 'x') continue;
            $w = ['fk_id_account' => $u, 'form_key' => $formKey, 'field_key' => $n];
            DB::table('sys_recent_values')->where($w)->where('value', (string) $v)->delete();
            DB::table('sys_recent_values')->insert($w + ['value' => (string) $v, 'used_at' => now()]);
            $old = DB::table('sys_recent_values')->where($w)->orderByDesc('used_at')->orderByDesc('id')->skip(3)->take(50)->pluck('id');
            if ($old->isNotEmpty()) DB::table('sys_recent_values')->whereIn('id', $old)->delete();
        }
    }

    /**
     * Up to 2 chips for a field: [value, label, why]. Sources in this order: the linked record chosen earlier in the form,
     * what you used last, what is used most. A chip never overwrites what was typed – the form only shows it on an empty field.
     */
    public static function chips(string $formKey, string $name, array $form = []): array
    {
        $f = self::field($formKey, $name); $table = Registry::table($formKey);
        if (! $f || $f['secret'] || ! $f['column_name'] || in_array($f['type'], ['file', 'check', 'textarea'], true) || str_starts_with((string) $f['default_rule'], 'numbering:')) return [];
        if ($table && Vault::maskRow($table, [$f['column_name'] => 'x'])[$f['column_name']] !== 'x') return [];
        $out = []; $add = function ($v, string $why) use (&$out) { $v = (string) $v; if ($v !== '' && ! isset($out[$v]) && count($out) < 2) $out[$v] = $why; };
        if ($f['column_name'] === 'fk_id_supplier' && in_array($table, SourcingInherit::SUPPLIER_TABLES, true) && ($inh = SourcingInherit::chips($table, self::byColumn($formKey, $form)))) return array_slice($inh, 0, 4);      // R-B_SOURCING-21: the cycle's suppliers, never the product-master one
        // an HS code: what earlier products of the same category use (R-G_CORE-91)
        if ($f['column_name'] === 'hs_code' && $table === 't_src_products' && ! empty($form['category']))
            foreach (DB::table('t_src_products')->where('category', $form['category'])->whereNotNull('hs_code')->where('hs_code', '<>', '')->when(Context::ent(), fn ($q) => $q->where('fk_id_ent', Context::ent()))->groupBy('hs_code')->orderByRaw('COUNT(*) DESC')->limit(2)->pluck('hs_code') as $v) $add($v, 'used by other products of this category');
        // a listing's price: the Sale of the product on the marketplace of the listing's shop (R-D-22)
        if ((string) $f['default_rule'] === 'linked:product.sale_price' && ($prod = (int) ($form['product'] ?? $form['fk_id_product'] ?? 0)) && ($shop = (int) ($form['shop'] ?? $form['fk_id_branch'] ?? 0))) {
            $mp = (int) DB::table('t_branches')->where('id', $shop)->value('fk_id_marketplace'); $v = $mp ? DB::table('t_product_marketplace_prices')->where('fk_id_product', $prod)->where('fk_id_marketplace', $mp)->value('sale_price') : null;
            if ($v !== null && (float) $v > 0) $add(number_format((float) $v, 2, '.', ''), 'the Sale of this product on the shop\'s marketplace');
        }
        // linked: the record picked in another field of this form has a column of the same name (product → its supplier, its HS code …)
        foreach (Registry::fields($formKey) as $p) {
            $pv = $form[$p['field_name']] ?? null; if (! $pv || $p['field_name'] === $name || ! ($pt = self::sourceTable($p)) || ! Schema::hasTable($pt)) continue;
            if (! Registry::hasColumn($pt, (string) $f['column_name']) || in_array($f['column_name'], ['fk_id_ent', 'f_active', 'created_by', 'updated_by'], true)) continue;
            $val = DB::table($pt)->where(Registry::pk($pt), $pv)->value($f['column_name']);
            if ($val !== null && $val !== '' && (string) $val !== '0') $add($val, 'from the ' . mb_strtolower((string) $p['label']) . ' you chose');
        }
        if (in_array($name, self::lookalikeFields($formKey), true)) return [];   // a name / code / e-mail is one of a kind – offering another record's would make duplicates
        foreach (self::recent($formKey, $name) as $v) $add($v, 'you used this last time');
        if ($table && Registry::hasColumn($table, $f['column_name']) && count($out) < 2) {
            $q = DB::table($table)->whereNotNull($f['column_name'])->where($f['column_name'], '<>', '');
            if (Registry::hasColumn($table, 'fk_id_ent') && Context::ent()) $q->where('fk_id_ent', Context::ent());
            if (in_array($f['type'], ['number', 'money', 'date'], true)) { $last = (clone $q)->orderByDesc(Registry::pk($table))->value($f['column_name']); if ($last !== null && (string) $last !== '0' && (float) $last != 0.0 || ($f['type'] === 'date' && $last)) $add($f['type'] === 'date' ? substr((string) $last, 0, 10) : rtrim(rtrim((string) $last, '0'), '.'), 'same as the last one saved'); }
            else foreach ($q->groupBy($f['column_name'])->orderByRaw('COUNT(*) DESC')->limit(3)->pluck($f['column_name']) as $v) $add($v, 'used most');
        }
        $labels = [];
        if (in_array($f['type'], ['select', 'multiselect'], true)) foreach (Registry::options($f, Context::ent(), null, 1000) as [$v, $l]) $labels[$v] = $l;
        $chips = [];
        foreach ($out as $v => $why) { if ($labels && ! isset($labels[$v])) continue; $chips[] = ['value' => (string) $v, 'label' => (string) ($labels[$v] ?? $v), 'why' => $why]; }
        return $chips;
    }

    /** Form values keyed by column name (the browser sends field names). */
    private static function byColumn(string $formKey, array $form): array
    {
        $out = $form; foreach (Registry::fields($formKey) as $f) if ($f['column_name'] && array_key_exists($f['field_name'], $form)) $out[$f['column_name']] = $form[$f['field_name']];
        return $out;
    }

    // ---------- look-alike warning ----------
    private static function norm(string $s): string { return preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($s)); }

    /** Records that look like the value typed in a name / code field (same letters ignoring case, spaces and signs; one or two letters different; same sound). */
    public static function lookalike(string $formKey, string $name, string $value, ?int $exceptId = null): array
    {
        $f = self::field($formKey, $name); $table = Registry::table($formKey); $value = trim($value);
        if (! $f || ! $table || ! $f['column_name'] || $f['secret'] || mb_strlen($value) < 3 || ! in_array($f['type'], ['text', 'email', 'phone'], true)) return [];
        $col = $f['column_name']; $pk = Registry::pk($table); $n = self::norm($value); if ($n === '') return [];
        $q = DB::table($table)->whereNotNull($col)->where($col, '<>', '');
        if (Registry::hasColumn($table, 'fk_id_ent') && Context::ent()) $q->where('fk_id_ent', Context::ent());
        if ($exceptId) $q->where($pk, '<>', $exceptId);
        // candidates: share the first 3 letters, or the first word, or the same digits (phone)
        $digits = preg_replace('/\D+/', '', $value);
        $q->where(function ($w) use ($col, $value, $digits, $f) {
            $w->where($col, 'like', mb_substr($value, 0, 3) . '%')->orWhere($col, 'like', '%' . addcslashes(explode(' ', $value)[0], '%_\\') . '%');
            if ($f['type'] === 'phone' && strlen($digits) >= 6) $w->orWhereRaw("REPLACE(REPLACE(REPLACE(REPLACE($col,' ',''),'-',''),'+',''),'(','') like ?", ['%' . substr($digits, -7) . '%']);
        });
        $out = [];
        foreach ($q->limit(300)->get([$pk . ' as id', $col . ' as v']) as $r) {
            $m = self::norm((string) $r->v); if ($m === '') continue;
            $same = $m === $n; $d = $same ? 0 : (strlen($m) < 60 && strlen($n) < 60 ? levenshtein($m, $n) : 99);
            $phone = $f['type'] === 'phone' && strlen($digits) >= 6 && str_ends_with(preg_replace('/\D+/', '', (string) $r->v), substr($digits, -7));
            if ($same || $phone || $d <= (strlen($n) >= 8 ? 2 : 1) || (strlen($n) >= 5 && metaphone($m) === metaphone($n))) $out[] = ['id' => (int) $r->id, 'value' => (string) $r->v, 'exact' => $same || $phone, 'url' => "#/rec/$formKey/{$r->id}", 'd' => $d];
        }
        usort($out, fn ($a, $b) => $a['d'] <=> $b['d']);
        return array_map(fn ($x) => array_diff_key($x, ['d' => 1]), array_slice($out, 0, 3));
    }

    /** Which fields of a form get the look-alike check: its label column and the fields that name the thing (name, title, SKU, code, e-mail, phone). */
    public static function lookalikeFields(string $formKey): array
    {
        $table = Registry::table($formKey); if (! $table) return [];
        $lc = Registry::labelColumn($table); $out = [];
        foreach (Registry::fields($formKey) as $f) {
            if (! $f['column_name'] || $f['secret'] || ! in_array($f['type'], ['text', 'email', 'phone'], true) || str_starts_with((string) $f['default_rule'], 'numbering:')) continue;
            if ($f['column_name'] === $lc || preg_match('/(^|_)(name|title|sku|email|e_mail|phone|whatsapp|mobile)(_|$)/i', (string) $f['column_name'])) $out[] = $f['field_name'];
        }
        return $out;
    }

    /** The footer of every form: where the record goes and what the system does when it is saved. */
    public static function afterSave(string $formKey): array
    {
        $fields = Registry::fields($formKey); $table = Registry::table($formKey); $lines = [];
        $lines[] = 'Saved in "' . $fields[0]['form_title'] . '"' . ($table ? '' : ' (a tool – nothing is stored)') . '. It shows in its list and in search at once.';
        foreach ($fields as $f) if (str_starts_with((string) $f['default_rule'], 'numbering:')) { $lines[] = 'The system gives it its ' . mb_strtolower((string) $f['label']) . '.'; break; }
        $def = count(array_filter($fields, fn ($f) => $f['default_rule'] && ! str_starts_with((string) $f['default_rule'], 'numbering:')));
        if ($def) $lines[] = "$def field(s) you leave empty are filled with their default.";
        $lines[] = 'Who changed what is kept in the history of the record.';
        if (DB::table('sys_forms')->where('form_key', $formKey)->value('f_approval_required')) $lines[] = 'It needs approval: it is saved as waiting and the Owner approves it.';
        if ($table && Schema::hasTable('task_rules') && ($n = DB::table('task_rules')->where('f_active', 1)->where(fn ($w) => $w->where('source_table', $table)->orWhere('module', $formKey))->count())) $lines[] = "$n task rule(s) watch this record and open a task by themselves when something is due.";
        return $lines;
    }
}
