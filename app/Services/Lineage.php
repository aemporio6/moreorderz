<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * MR-1539 lineage: from a record back to where it came from – the import file and its row (sales files, money files, bank files), the old tool's
 * table and row, the GRN / PO a stock lot came from, the lot a split came from – and forward to the ledger journals it booked. Every figure of a
 * chart already opens its records (R-G_CORE-103); this gives each record its "Where from?" chain.
 */
class Lineage
{
    public const JOURNAL_SOURCE = ['GRN' => 't_src_grn', 'PAYMENT' => 't_src_payments', 'SALE_CONFIRM' => 't_sale_imports', 'RETURN' => 't_sales', 'EXPENSE' => 'fin_expenses', 'STOCK_ADJUST' => 'fin_lots'];

    private static function step(string $what, string $text, ?string $url = null, array $more = []): array { return ['what' => $what, 'text' => $text, 'url' => $url] + $more; }

    private static function file(string $table, int $id): ?array
    {
        if ($table === 't_sale_imports' && ($b = DB::table('t_sale_imports')->where('id', $id)->first(['id', 'file_name', 'imported_at', 'imported_by'])))
            return self::step('File', ($b->file_name ?: 'sales file') . ' · loaded ' . substr((string) $b->imported_at, 0, 16) . ' by ' . (DB::table('t_users')->where('id', $b->imported_by)->value('display_name') ?? '—'), '#/sales?import=' . $b->id);
        if ($table === 'fin_import_batches' && ($b = DB::table('fin_import_batches')->where('id', $id)->first(['id', 'file_name', 'imported_at', 'imported_by', 'file_hash'])))
            return self::step('File', ($b->file_name ?: 'file') . ' · loaded ' . substr((string) $b->imported_at, 0, 16) . ' by ' . (DB::table('t_users')->where('id', $b->imported_by)->value('display_name') ?? '—'), '#/import-history', ['file_hash' => substr((string) $b->file_hash, 0, 12)]);
        return null;
    }

    public static function of(string $table, int $id, int $ent): array
    {
        $steps = [];
        if ($table === 't_sales' && ($s = DB::table('t_sales')->where('id', $id)->where('fk_id_ent', $ent)->first())) {
            $steps[] = self::step('Record', "Sale line {$s->order_no} · {$s->sku}");
            $b = (int) ($s->fk_id_import ?: $s->fk_id_batch); if ($b && ($f = self::file('t_sale_imports', $b))) $steps[] = $f;
            if ($s->batch_row_no) $steps[] = self::step('Row', 'row ' . $s->batch_row_no . ' of the file' . (($raw = json_decode((string) $s->raw_csv, true)) ? ' · ' . count($raw) . ' columns kept (Details shows them)' : ''));
            if ($s->legacy_table) $steps[] = self::step('Old tool', "{$s->legacy_table} row #{$s->legacy_id}");
            foreach (DB::table('fin_lot_consumption')->where('fk_id_sale', $id)->limit(10)->get(['lot_id', 'qty']) as $c) $steps = array_merge($steps, array_map(fn ($x) => $x['what'] === 'Record' ? ['what' => 'Stock', 'text' => $c->qty . ' unit(s) from ' . $x['text'], 'url' => null] : $x, array_slice(self::of('fin_lots', (int) $c->lot_id, $ent)['steps'], 0, 3)));
            $j = DB::table('gl_journals')->where('fk_id_ent', $ent)->where('source_type', 'RETURN')->where('source_id', $id)->get(['id', 'journal_no', 'journal_date']);
            foreach ($j as $x) $steps[] = self::step('Booked', "journal {$x->journal_no} on {$x->journal_date}", '#/ledger?journal=' . $x->id);
        } elseif ($table === 'fin_settlement_lines' && ($l = DB::table('fin_settlement_lines')->where('id', $id)->where('company_id', $ent)->first())) {
            $steps[] = self::step('Record', "Money line {$l->order_id} · {$l->amount_type} " . $l->amount);
            if ($f = self::file('fin_import_batches', (int) $l->batch_id)) $steps[] = $f;
            $steps[] = self::step('Row', 'statement ' . $l->settlement_id . ' · row key ' . substr((string) $l->row_hash, 0, 12));
        } elseif ($table === 'fin_bank_transactions' && ($l = DB::table('fin_bank_transactions')->where('id', $id)->where('company_id', $ent)->first())) {
            $steps[] = self::step('Record', "Bank line {$l->txn_date} · {$l->amount} · " . mb_substr((string) $l->description, 0, 60));
            if ($l->batch_id && ($f = self::file('fin_import_batches', (int) $l->batch_id))) $steps[] = $f; else $steps[] = self::step('File', 'typed by hand or from a connection (no file)');
        } elseif ($table === 'fin_lots' && ($lot = DB::table('fin_lots')->where('id', $id)->where('company_id', $ent)->first())) {
            $steps[] = self::step('Record', "Lot #{$lot->id} · {$lot->sku} · " . $lot->source);
            if ($lot->source === 'GRN' && $lot->source_id && ($g = DB::table('t_src_grn_lines as gl')->join('t_src_grn as g', 'g.id', '=', 'gl.fk_id_grn')->where('gl.id', $lot->source_id)->first(['g.id', 'g.grn_code', 'gl.fk_id_po_line'])))
                $steps[] = self::step('GRN', 'received on ' . ($g->grn_code ?: 'GRN #' . $g->id), null);
            if ($lot->po_id && ($po = DB::table('t_src_po')->where('id', $lot->po_id)->first(['id', 'po_code', 'po_number']))) $steps[] = self::step('PO', 'bought on ' . ($po->po_code ?: $po->po_number), '#/po/' . $po->id);
            if ($lot->source === 'SPLIT' && $lot->source_id) $steps[] = self::step('Split', 'split from lot #' . $lot->source_id);
            if ($lot->source === 'OPENING') $steps[] = self::step('Opening', 'opening stock (old data or typed opening)');
        } else {
            $steps[] = self::step('Record', "$table #$id");
        }
        $src = array_search($table, self::JOURNAL_SOURCE, true);
        if ($src && $table !== 't_sales') foreach (DB::table('gl_journals')->where('fk_id_ent', $ent)->where('source_type', $src)->where('source_id', $id)->limit(10)->get(['id', 'journal_no', 'journal_date']) as $x)
            $steps[] = self::step('Booked', "journal {$x->journal_no} on {$x->journal_date}", '#/ledger?journal=' . $x->id);
        return ['table' => $table, 'id' => $id, 'steps' => $steps];
    }
}
