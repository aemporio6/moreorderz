<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-G_CORE-122 one history: field changes go to sys_history (entity, record id, field, old → new, user, time; legacy table + row id for
 * rows that came from the old tool). The old per-module logs (t_sale_change_log, t_product_sale_change_log, t_product_inventory_child_change_log,
 * t_src_audit_log, t_inv_audit_log) are copied once at the cut-over and never written again. A record's History tab shows sys_audit_log and
 * sys_history rows in one time line, old rows with the chip "from the old tool". The sale-line "Log History" reads the view v_sale_change_log.
 */
class History
{
    public const OLD = ['t_sale_change_log', 't_product_sale_change_log', 't_product_inventory_child_change_log', 't_src_audit_log', 't_inv_audit_log'];

    /** A change of a sale line, in the old log's shape (fk_id_sale, save_key, action, field_name, field_label, old_value, new_value, fk_id_user, changed_by, changed_at). */
    public static function sale(array $r): void
    {
        DB::table('sys_history')->insert(['fk_id_ent' => (int) (DB::table('t_sales')->where('id', $r['fk_id_sale'] ?? 0)->value('fk_id_ent') ?? 0), 'entity' => 't_sales', 'record_id' => (int) ($r['fk_id_sale'] ?? 0),
            'save_key' => isset($r['save_key']) ? mb_substr((string) $r['save_key'], 0, 40) : null, 'action' => mb_substr((string) ($r['action'] ?? 'UPDATE'), 0, 60), 'field_name' => $r['field_name'] ?? null, 'field_label' => $r['field_label'] ?? null,
            'old_value' => isset($r['old_value']) ? (string) $r['old_value'] : null, 'new_value' => isset($r['new_value']) ? (string) $r['new_value'] : null, 'user_id' => $r['fk_id_user'] ?? Context::userId(),
            'changed_by' => isset($r['changed_by']) ? mb_substr((string) $r['changed_by'], 0, 150) : null, 'changed_at' => $r['changed_at'] ?? now()]);
    }

    /** The cut-over: every old log row once into sys_history (a second run adds nothing). */
    public static function cutover(): array
    {
        $out = []; $sch = DB::getSchemaBuilder(); $ents = fn () => DB::table('t_product_vendors')->pluck('fk_id_ent', 'id');
        $map = [
            't_sale_change_log' => fn ($r) => ['entity' => 't_sales', 'record_id' => $r->fk_id_sale, 'save_key' => $r->save_key, 'action' => $r->action, 'field_name' => $r->field_name, 'field_label' => $r->field_label, 'old_value' => $r->old_value, 'new_value' => $r->new_value, 'user_id' => $r->fk_id_user, 'changed_by' => $r->changed_by, 'changed_at' => $r->changed_at],
            't_product_sale_change_log' => fn ($r) => ['entity' => 't_product_vendors', 'record_id' => $r->fk_product_id, 'action' => 'UPDATE', 'field_name' => 'changed_fields', 'field_label' => 'Changed fields', 'new_value' => $r->changed_fields, 'user_id' => $r->fk_user_id, 'changed_by' => $r->changed_by, 'changed_at' => $r->created_at],
            't_product_inventory_child_change_log' => fn ($r) => ['entity' => 't_product_vendors', 'record_id' => $r->fk_product_id, 'action' => 'UPDATE', 'field_name' => 'changed_fields', 'field_label' => 'Child changes', 'new_value' => $r->changed_fields, 'user_id' => $r->fk_user_id, 'changed_by' => $r->changed_by, 'changed_at' => $r->created_at],
            't_src_audit_log' => fn ($r) => ['fk_id_ent' => $r->fk_id_ent, 'entity' => 't_src_cycles', 'record_id' => $r->fk_id_cycle, 'action' => $r->action, 'field_name' => 'details', 'field_label' => 'Details', 'new_value' => $r->details, 'user_id' => $r->created_by, 'changed_at' => $r->created_at],
            't_inv_audit_log' => fn ($r) => ['fk_id_ent' => $r->fk_id_ent, 'entity' => (string) $r->entity, 'record_id' => $r->entity_id, 'action' => $r->action, 'field_name' => 'details', 'field_label' => 'Details', 'new_value' => $r->details, 'user_id' => null, 'changed_by' => $r->fk_id_account ? 'account ' . $r->fk_id_account : null, 'changed_at' => $r->created_at],
        ];
        foreach ($map as $t => $fn) {
            if (! $sch->hasTable($t)) continue; $n = 0; $last = (int) DB::table('sys_history')->where('legacy_table', $t)->max('legacy_id');
            DB::table($t)->where('id', '>', $last)->orderBy('id')->chunk(1000, function ($rows) use ($t, $fn, &$n) {
                $ins = [];
                foreach ($rows as $r) { $x = $fn($r) + ['fk_id_ent' => 0, 'save_key' => null, 'field_name' => null, 'field_label' => null, 'old_value' => null, 'new_value' => null, 'user_id' => null, 'changed_by' => null];
                    if ($x['entity'] === 't_sales' && ! $x['fk_id_ent']) $x['fk_id_ent'] = (int) (DB::table('t_sales')->where('id', $x['record_id'])->value('fk_id_ent') ?? 0);
                    if ($x['entity'] === 't_product_vendors' && ! $x['fk_id_ent']) $x['fk_id_ent'] = (int) (DB::table('t_product_vendors')->where('id', $x['record_id'])->value('fk_id_ent') ?? 0);
                    $ins[] = ['legacy_table' => $t, 'legacy_id' => (int) $r->id, 'record_id' => (int) $x['record_id'], 'action' => mb_substr((string) $x['action'], 0, 60), 'save_key' => $x['save_key'] ? mb_substr((string) $x['save_key'], 0, 40) : null,
                        'changed_at' => $x['changed_at'] ?: now(), 'changed_by' => $x['changed_by'] ? mb_substr((string) $x['changed_by'], 0, 150) : null] + array_intersect_key($x, array_flip(['fk_id_ent', 'entity', 'field_name', 'field_label', 'old_value', 'new_value', 'user_id'])); $n++; }
                DB::table('sys_history')->insert($ins);
            });
            $out[$t] = $n;
        }
        return $out;
    }

    /** History tab rows of one record (newest first), shaped like the audit rows; old ones carry legacy = true. */
    public static function forRecord(string $table, int $id, int $limit = 50): array
    {
        return DB::table('sys_history as h')->leftJoin('t_users as u', 'u.id', '=', 'h.user_id')->where('h.entity', $table)->where('h.record_id', $id)->orderByDesc('h.changed_at')->orderByDesc('h.id')->limit($limit)
            ->get(['h.action', 'h.field_label', 'h.field_name', 'h.old_value', 'h.new_value', 'h.changed_at', 'h.changed_by', 'h.legacy_table', 'u.display_name'])
            ->map(fn ($h) => ['action' => $h->action, 'at' => (string) $h->changed_at, 'by' => $h->changed_by ?: ($h->display_name ?: 'System'), 'reason' => null, 'legacy' => (bool) $h->legacy_table,
                'changes' => [($h->field_label ?: $h->field_name ?: 'Change') => [$h->old_value, $h->new_value]]])->all();
    }
}
