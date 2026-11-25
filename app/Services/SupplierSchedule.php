<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-FIN-61 (v1.7, IC-10) Finance › Supplier payment schedule = a VIEW over t_src_payment_terms – no copy, no second table.
 * Line: company, supplier, PO, milestone, due date, amount and paid in base at the PO rate, remaining = amount − paid (paid capped at amount).
 * Status: PAID / OVERDUE (due date before today) / DUE (within SRC_PAY_REMIND_DAYS) / PLANNED / NO DATE. SRC_PAY_DUE is the only task (open flag shown).
 * R-FIN-64 "What we owe suppliers": scheduled / paid / owed per supplier, and the lines due in 30 days.
 */
class SupplierSchedule
{
    public static function view(int $ent, array $f = []): array
    {
        $today = date('Y-m-d'); $remind = (int) Settings::get('SRC_PAY_REMIND_DAYS', $ent, 3); $base = Fx::base($ent);
        $rows = DB::table('t_src_payment_terms as t')->join('t_src_po as p', 'p.id', '=', 't.fk_id_po')->leftJoin('t_src_suppliers as s', 's.id', '=', 'p.fk_id_supplier')
            ->where('t.fk_id_ent', $ent)->where('t.f_active', 1)->where('p.f_active', 1)->whereNotIn('p.status', ['DRAFT', 'CANCELLED'])
            ->when(! empty($f['supplier']), fn ($w) => $w->where('p.fk_id_supplier', (int) $f['supplier']))
            ->orderByRaw('t.due_date IS NULL')->orderBy('t.due_date')->orderBy('p.po_number')->orderBy('t.term_no')
            ->get(['t.id', 't.term_no', 't.milestone', 't.pct', 't.planned_amount', 't.paid_amount', 't.due_date', 't.due_rule', 't.status', 'p.id as po', 'p.po_number', 'p.fk_id_cycle', 'p.fk_id_currency', 'p.exchange_rate', 'p.po_date', 'p.fk_id_supplier', 's.supplier_name'])
            ->map(function ($t) use ($ent, $today, $remind) {
                $cur = Fx::code($t->fk_id_currency, $ent); $rate = (float) ($t->exchange_rate ?: Fx::rate($cur, $t->po_date ?: $today, $ent));
                $amt = round((float) $t->planned_amount * $rate, 2); $paid = min($amt, round((float) $t->paid_amount * $rate, 2)); $rem = round($amt - $paid, 2);
                $st = $rem <= 0.009 ? 'PAID' : (! $t->due_date ? 'NO DATE' : ($t->due_date < $today ? 'OVERDUE' : ($t->due_date <= date('Y-m-d', strtotime("$today +$remind day")) ? 'DUE' : 'PLANNED')));
                return ['id' => (int) $t->id, 'supplier_id' => (int) $t->fk_id_supplier, 'supplier' => $t->supplier_name ?: 'Supplier ' . $t->fk_id_supplier, 'po' => (int) $t->po, 'po_number' => $t->po_number, 'cycle' => $t->fk_id_cycle ? (int) $t->fk_id_cycle : null,
                    'term' => (int) $t->term_no, 'milestone' => $t->milestone, 'due_date' => $t->due_date, 'due_rule' => $t->due_rule, 'currency' => $cur, 'rate' => $rate,
                    'amount_cur' => round((float) $t->planned_amount, 2), 'amount' => $amt, 'paid' => $paid, 'remaining' => $rem, 'status' => $st,
                    'task_open' => $rem > 0.009 && TaskEngine::isOpen('SRC_PAY_DUE', CycleAlerts::key('SRC_PAY_DUE', (int) $t->id))];
            });
        $st = strtoupper((string) ($f['status'] ?? 'OPEN'));
        $shown = $rows->filter(fn ($r) => $st === 'ALL' || ($st === 'OPEN' ? $r['status'] !== 'PAID' : $r['status'] === $st))->values();
        $in30 = date('Y-m-d', strtotime("$today +30 day"));
        $owe = $rows->groupBy('supplier_id')->map(fn ($g) => ['supplier_id' => $g[0]['supplier_id'], 'supplier' => $g[0]['supplier'], 'scheduled' => round($g->sum('amount'), 2), 'paid' => round($g->sum('paid'), 2), 'owed' => round($g->sum('remaining'), 2),
            'overdue' => round($g->where('status', 'OVERDUE')->sum('remaining'), 2)])->filter(fn ($x) => $x['owed'] > 0.009)->sortByDesc('owed')->values()->all();
        return ['base' => $base, 'today' => $today, 'status' => $st, 'rows' => $shown->all(),
            'totals' => ['scheduled' => round($rows->sum('amount'), 2), 'paid' => round($rows->sum('paid'), 2), 'remaining' => round($rows->sum('remaining'), 2), 'overdue' => round($rows->where('status', 'OVERDUE')->sum('remaining'), 2),
                'due_30' => round($rows->filter(fn ($r) => $r['remaining'] > 0 && $r['due_date'] && $r['due_date'] <= $in30)->sum('remaining'), 2)],
            'owe' => $owe, 'due_30' => $rows->filter(fn ($r) => $r['remaining'] > 0 && $r['due_date'] && $r['due_date'] <= $in30)->values()->all(),
            'suppliers' => $rows->unique('supplier_id')->map(fn ($r) => ['id' => $r['supplier_id'], 'name' => $r['supplier']])->values()->all()];
    }
}
