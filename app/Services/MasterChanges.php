<?php

namespace App\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * D11 / M38 / E8 maker-checker for master changes with risk: the rules in sys_approval_rules (lot ownership, price floor, company settings,
 * approval limits, expense rules …). A person who is not the rule's approver (Owner; Partner where the rule says so) saves the form as usual,
 * but the risky values are not written: they wait in sys_approvals (MASTER_CHANGE) and are written only when the approver says yes.
 * Rules that have an engine of their own (supplier bank with the phone check, old purchases, overpayments, landed-cost overrides, big stock adjustments) are left to it.
 */
class MasterChanges
{
    public const ACTION = 'MASTER_CHANGE';
    private const OWN_ENGINE = ['SUP_BANK', 'SRC_OLD_ENTRY', 'SRC_OVERPAY', 'SRC_LC_OVERRIDE', 'STOCK_ADJUST_BIG', 'PARTY_ROLE_OWNER'];

    public static function rules(string $table): array
    {
        return DB::table('sys_approval_rules')->where('entity_table', $table)->where('f_active', 1)->whereNotIn('rule_code', self::OWN_ENGINE)->get()->all();
    }

    /** Is this person the approver of the rule (then the change is written at once)? */
    public static function approver(object $rule, ?object $u): bool
    {
        if (! $u) return true;      // a job of the system
        $role = (string) ($u->role_code ?? Context::role());
        return $role === 'OWNER' || ($rule->approver_role !== 'OWNER' && $role === $rule->approver_role);
    }

    private static function matches(object $rule, string $col): bool
    {
        return (bool) preg_match('/^' . str_replace('%', '.*', preg_quote((string) $rule->column_pattern, '/')) . '$/i', $col);
    }

    /**
     * RecordService hook before the write. Takes the risky values out of $data and asks for approval.
     * Returns null (nothing held) or ['approval' => id, 'all' => true when the whole save waits (a new row of a guarded table), 'note' => text].
     */
    public static function hold(string $table, string $formKey, ?int $id, array &$data, array $old): ?array
    {
        $u = Auth::user(); $held = []; $rule = null;
        foreach (self::rules($table) as $r) {
            if (self::approver($r, $u)) continue;
            foreach ($data as $col => $v) if (self::matches($r, (string) $col) && (! $id || (string) ($old[$col] ?? '') !== (string) ($v ?? ''))) { $held[$col] = [$old[$col] ?? null, $v]; $rule ??= $r; }
        }
        if (! $held) return null;
        $ent = (int) (Context::ent() ?? 0);
        $all = ! $id && $rule->column_pattern === '%';
        $labels = array_map(fn ($c) => Registry::labelOf($formKey, $c) ?? $c, array_keys($held));
        $what = ucfirst(strtolower(str_replace('_', ' ', $rule->rule_code)));
        $title = "Approve $what: " . implode(', ', $labels) . ($id ? " of record #$id" : ' (new)');
        $aid = Approvals::request($ent, self::ACTION, $table, (int) ($id ?? 0), 0.0, ['rule' => $rule->rule_code, 'table' => $table, 'form' => $formKey, 'id' => $id, 'changes' => $all ? [] : $held, 'row' => $all ? $data : null, 'category' => $what], $title);
        DB::table('sys_approvals')->where('id', $aid)->update(['risk' => $rule->risk]);
        if ($all) $data = []; else foreach (array_keys($held) as $c) unset($data[$c]);
        return ['approval' => $aid, 'all' => $all, 'note' => ($all ? 'Saved as a request' : implode(', ', $labels) . ' waits') . ' – it is written when the ' . ($rule->approver_role === 'PARTNER' ? 'Owner or a Partner' : 'Owner') . ' approves it (Approvals).'];
    }

    /** Lot ownership through the Stock screen (rule LOT_OWNER): the split waits for the approver. */
    public static function lotOwner(int $ent, int $lot, int $qty, int $toOwner): ?int
    {
        $rule = DB::table('sys_approval_rules')->where('rule_code', 'LOT_OWNER')->where('f_active', 1)->first();
        if (! $rule || self::approver($rule, Auth::user())) return null;
        $l = DB::table('fin_lots')->where('id', $lot)->where('company_id', $ent)->first(['lot_code', 'qty_left']) ?? throw new \InvalidArgumentException('Lot not found.');
        if ($qty > (int) $l->qty_left) throw new \InvalidArgumentException("Only {$l->qty_left} unit(s) are left in this lot.");
        $name = trim((string) DB::table('t_account')->where('id', $toOwner)->selectRaw("CONCAT_WS(' ', first_name, sur_name) n")->value('n')) ?: "#$toOwner";
        return Approvals::request($ent, self::ACTION, 'fin_lots', $lot, 0.0, ['rule' => 'LOT_OWNER', 'lot' => $lot, 'qty' => $qty, 'to_owner' => $toOwner, 'category' => 'Lot ownership'], "Approve lot ownership: $qty unit(s) of {$l->lot_code} to $name");
    }

    /** Approvals::decide check: only the rule's approver decides. */
    public static function canDecide(object $a, ?object $u): void
    {
        $c = json_decode((string) $a->change_json, true) ?: [];
        $rule = DB::table('sys_approval_rules')->where('rule_code', $c['rule'] ?? '')->first();
        if ($u && $rule && ! self::approver($rule, $u)) throw new \InvalidArgumentException('Only the ' . ($rule->approver_role === 'PARTNER' ? 'Owner or a Partner' : 'Owner') . ' approves this change.');
    }

    /** Approved → the held values are written now (with the history row); refused → nothing changes. */
    public static function decided(object $a, bool $ok, ?string $reason = null): void
    {
        if (! $ok) return;
        $c = json_decode((string) $a->change_json, true) ?: [];
        $ent = (int) $a->fk_id_ent;
        if (($c['rule'] ?? '') === 'LOT_OWNER' && isset($c['lot'])) { StockService::transferOwnership($ent, (int) $c['lot'], (int) $c['qty'], (int) $c['to_owner']); return; }
        $table = (string) ($c['table'] ?? ''); if ($table === '' || ! \Illuminate\Support\Facades\Schema::hasTable($table)) return;
        $pk = Registry::pk($table);
        if (! empty($c['id'])) {
            $set = []; foreach ((array) ($c['changes'] ?? []) as $col => [$old, $new]) if (Registry::hasColumn($table, $col)) $set[$col] = $new;
            if (! $set) return;
            $before = (array) DB::table($table)->where($pk, (int) $c['id'])->first(array_keys($set));
            DB::table($table)->where($pk, (int) $c['id'])->update($set);
            $ch = []; foreach ($set as $col => $v) $ch[$col] = [$before[$col] ?? null, $v];
            Audit::log($table, (int) $c['id'], 'UPDATE', $ch, 'Approved request #' . $a->id, $ent);
        } elseif (! empty($c['row'])) {
            $row = array_filter((array) $c['row'], fn ($v, $k) => Registry::hasColumn($table, (string) $k), ARRAY_FILTER_USE_BOTH);
            if (Registry::hasColumn($table, 'fk_id_ent') && ! isset($row['fk_id_ent'])) $row['fk_id_ent'] = $ent;
            $id = (int) DB::table($table)->insertGetId(RecordService::fillNotNull($table, $row), $pk);
            Audit::log($table, $id, 'INSERT', $row, 'Approved request #' . $a->id, $ent);
        }
    }
}
