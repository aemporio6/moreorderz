<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-C-17 "Where used" guard and soft delete only.
 * whereUsed(id) counts where a person is used: shops as owner / seller, cycles, investments, stock, loans, product investors, tasks (through the person's login).
 * Removing the role OWNER or SELLER while the person is owner / seller of a shop is refused with the shop list.
 * Delete person = soft delete to the recycle bin (POST, right admin.accounts) only when nothing blocks; shops are never deleted with a person.
 */
class PartyUse
{
    /** [key => ['label', count, names[]]] – only the places where the person is used. */
    public static function whereUsed(int $id): array
    {
        $ent = (int) Context::ent(); $u = [];
        $shops = fn (string $col) => DB::table('t_branches')->where('fk_id_ent', $ent)->where($col, $id)->orderBy('shop_name')->pluck('shop_name')->all();
        $count = function (string $table, string $col) use ($id) { return Registry::hasColumn($table, $col) ? (int) DB::table($table)->where($col, $id)->count() : 0; };
        if ($o = $shops('fk_id_owner')) $u['shop_owner'] = ['Shop owner', count($o), $o];
        if ($s = $shops('fk_id_seller')) $u['shop_seller'] = ['Shop seller', count($s), $s];
        foreach (['cycles' => ['Sourcing cycles (owner)', 't_src_cycles', 'fk_id_owner'], 'investments' => ['Investments', 't_src_investments', 'fk_id_investor'], 'stock' => ['Stock lots (owner)', 'fin_lots', 'fk_id_owner'],
            'movements' => ['Stock movements (owner)', 't_stock_movements', 'fk_id_owner'], 'loans' => ['Loans', 'fin_loans', 'fk_id_account'], 'product_investors' => ['Product investors', 't_product_investors', 'fk_id_account'], 'research' => ['Research (owner)', 'src_research', 'fk_id_owner']] as $k => [$label, $t, $c])
            if ($n = $count($t, $c)) $u[$k] = [$label, $n, []];
        $users = DB::table('t_users')->where('fk_id_account', $id)->pluck('id')->all();
        if ($users && ($n = (int) DB::table('tasks')->whereIn('owner_user_id', $users)->whereIn('status', ['TODO', 'DOING', 'STUCK', 'REWORK'])->count())) $u['tasks'] = ['Open tasks', $n, []];
        if ($users && ($n = (int) DB::table('t_users')->whereIn('id', $users)->where('f_active', 1)->count())) $u['logins'] = ['Active login', $n, []];
        return $u;
    }

    public static function text(array $used): string
    {
        return implode('; ', array_map(fn ($x) => $x[0] . ': ' . ($x[2] ? implode(', ', $x[2]) : $x[1]), $used));
    }

    /** Role removal guard (a role row made inactive or its role changed): OWNER / SELLER while used in a shop. Returns the message or null. */
    public static function roleGuard(int $accountId, int $roleTypeId): ?string
    {
        $code = strtoupper((string) DB::table('cat_account_types')->where('id', $roleTypeId)->value('role_code')); if (! in_array($code, ['OWNER', 'SELLER'], true)) return null;
        $names = DB::table('t_branches')->where('fk_id_ent', Context::ent())->where($code === 'OWNER' ? 'fk_id_owner' : 'fk_id_seller', $accountId)->orderBy('shop_name')->pluck('shop_name')->all();
        return $names ? 'Cannot remove role – used in shop(s): ' . implode(', ', $names) : null;
    }

    /** Soft delete to the recycle bin; refused with the blocking list when the person is used anywhere. Shops are never touched. */
    public static function delete(int $id, object $u): array
    {
        Perm::need($u, 'admin.accounts'); $ent = (int) Context::ent();
        $a = DB::table('t_account')->where('id', $id)->where('fk_id_ent', $ent)->first() ?? throw new \InvalidArgumentException('Person not found.');
        if (! (int) $a->f_active) throw new \InvalidArgumentException('This person is already deleted (in the recycle bin).');
        if ($used = self::whereUsed($id)) return ['deleted' => false, 'message' => 'This person cannot be deleted – still used: ' . self::text($used) . '.', 'used' => array_map(fn ($x, $k) => ['key' => $k, 'label' => $x[0], 'count' => $x[1], 'names' => $x[2]], $used, array_keys($used))];
        DB::transaction(function () use ($a, $ent, $u) {
            DB::table('mo_recycle_bin')->insert(RecordService::fillNotNull('mo_recycle_bin', ['fk_id_ent' => $ent, 'table_name' => 't_account', 'row_id' => (int) $a->id, 'label' => mb_substr(trim($a->first_name . ' ' . $a->sur_name) . ' (' . $a->account_code . ')', 0, 255), 'row_json' => json_encode(['f_active' => 1]), 'deleted_by' => (int) $u->id, 'deleted_at' => now()]));
            DB::table('t_account')->where('id', $a->id)->update(['f_active' => 0]); Audit::log('t_account', (int) $a->id, 'DELETE', ['f_active' => [1, 0]], 'moved to the recycle bin', $ent);
        });
        return ['deleted' => true, 'message' => 'Person moved to the recycle bin. Shops, roles and sub-lists are kept.'];
    }

    public static function restore(int $id, object $u): array
    {
        Perm::need($u, 'admin.accounts'); $ent = (int) Context::ent();
        $b = DB::table('mo_recycle_bin')->where('fk_id_ent', $ent)->where('table_name', 't_account')->where('row_id', $id)->whereNull('restored_at')->orderByDesc('id')->first() ?? throw new \InvalidArgumentException('This person is not in the recycle bin.');
        DB::transaction(function () use ($b, $id, $ent) { DB::table('t_account')->where('id', $id)->update(['f_active' => 1]); DB::table('mo_recycle_bin')->where('id', $b->id)->update(['restored_at' => now()]); Audit::log('t_account', $id, 'RESTORE', ['f_active' => [0, 1]], 'restored from the recycle bin', $ent); });
        return ['message' => 'Person restored.'];
    }
}
