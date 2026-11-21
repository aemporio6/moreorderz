<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-FIN-33 accountant year export, R-FIN-36 stock valuation at a date, R-FIN-25 report status board per shop × report type.
 */
class FinanceExports
{
    public const LEDGER_COLS = ['date', 'bank', 'description', 'counterparty', 'category', 'reference', 'amount', 'currency', 'amount_gbp', 'matched_to', 'proof_link', 'match_status', 'matched_by'];
    public const VALUATION_COLS = ['sku', 'lot_id', 'source', 'received_date', 'units', 'unit_cost_gbp', 'value_gbp'];
    /** R-FIN-25: a report "needs upload" when its last data date is older than this many days (settlement 17, bank 8). */
    public const OVERDUE = ['SETTLEMENT' => 17, 'BANK' => 8];

    /** Owner or Accountant only (R-FIN-33). */
    public static function mayExport(?object $u): bool { return $u && in_array($u->role_code, ['OWNER', 'ACCOUNTANT'], true); }

    /** Companies of a request: 0 = every company the person may see. */
    private static function companies(int $company, ?object $u): array
    {
        $all = array_column(BankLedger::companies($u), 'id');
        return $company === 0 ? $all : array_values(array_intersect($all, [$company]));
    }

    /** One file body: CSV with a UTF-8 BOM, or XLSX with the logo. */
    private static function file(array $head, array $rows, string $format, string $title): string
    {
        if ($format === 'xlsx') {
            $b = new \PhpOffice\PhpSpreadsheet\Spreadsheet(); $sh = $b->getActiveSheet(); $sh->setTitle(mb_substr($title, 0, 31)); $sh->fromArray(array_merge([$head], $rows), null, 'A1', true);
            $sh->getStyle('1:1')->getFont()->setBold(true); $sh->freezePane('A2'); Brand::xlsx($b);
            $tmp = tempnam(sys_get_temp_dir(), 'fx'); (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($b))->save($tmp); $x = (string) file_get_contents($tmp); @unlink($tmp); return $x;
        }
        $o = fopen('php://temp', 'w+'); fwrite($o, "\xEF\xBB\xBF"); fputcsv($o, $head);
        foreach ($rows as $r) fputcsv($o, array_map(fn ($v) => is_string($v) && $v !== '' && in_array($v[0], ['=', '+', '-', '@'], true) && ! is_numeric($v) ? "'" . $v : $v, $r));
        rewind($o); $x = (string) stream_get_contents($o); fclose($o); return $x;
    }

    /**
     * R-FIN-33: every bank line (Ignored included) of a company (0 = all) between two dates (default the calendar year), columns in the
     * spec's order; proof_link opens the line's record (history + documents). File finance_ledger_<company>_<year>.csv; audited.
     */
    public static function ledger(int $company, string $from, string $to, string $format, ?object $u): array
    {
        if (! self::mayExport($u)) throw new \InvalidArgumentException('Refused: Owner or Accountant only.');
        $format = $format === 'xlsx' ? 'xlsx' : 'csv'; $cos = self::companies($company, $u);
        $base = rtrim((string) (Settings::get('APP_URL', null, '') ?: config('app.url') ?: url('/')), '/');
        $form = RecordService::formOfTable('fin_bank_transactions') ?: 'fin_bank_line';
        $cats = DB::table('fin_categories')->pluck('name', 'code'); $users = DB::table('t_users')->pluck('display_name', 'id');
        $rows = DB::table('fin_bank_transactions as t')->leftJoin('fin_bank_accounts as b', 'b.id', '=', 't.bank_account_id')->whereIn('t.company_id', $cos ?: [0])->whereBetween('t.txn_date', [$from, $to])
            ->orderBy('t.txn_date')->orderBy('t.id')->get(['t.*', 'b.bank_name', 'b.account_label'])
            ->map(fn ($l) => [$l->txn_date, trim($l->bank_name . ' ' . $l->account_label), $l->description, $l->counterparty, $l->category_code ? ($cats[$l->category_code] ?? $l->category_code) : '',
                $l->reference_no ?: $l->bank_txn_id, round((float) $l->amount, 2), $l->currency, $l->amount_gbp === null ? '' : round((float) $l->amount_gbp, 2), $l->matched_to_type ? trim($l->matched_to_type . ' ' . $l->matched_to_id) : '',
                "$base/#/rec/$form/{$l->id}", $l->match_status, $l->matched_by ? ($users[$l->matched_by] ?? '#' . $l->matched_by) : ''])->all();
        $year = substr($from, 0, 4) === substr($to, 0, 4) && substr($from, 5) === '01-01' && substr($to, 5) === '12-31' ? substr($from, 0, 4) : "{$from}_{$to}";
        $name = "finance_ledger_{$company}_{$year}.$format";
        Audit::log('fin_bank_transactions', 0, 'EXPORT', ['file' => $name, 'rows' => count($rows), 'companies' => $cos], 'Accountant export', (int) Context::ent());
        return ['name' => $name, 'type' => $format === 'xlsx' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'text/csv; charset=utf-8', 'body' => self::file(self::LEDGER_COLS, $rows, $format, 'Ledger'), 'rows' => count($rows)];
    }

    /**
     * R-FIN-36: per lot received on or before the date: units on that date = units left now + what left the lot after the date
     * (every later stock movement of the lot reversed); lots with 0 units are left out; value = units × unit cost. Company 0 = every company.
     */
    public static function valuationRows(int $company, string $date, ?object $u): array
    {
        $cos = self::companies($company, $u); $end = $date . ' 23:59:59';
        $later = DB::table('t_stock_movements')->where('created_at', '>', $end)->whereNotNull('fk_id_lot')->groupBy('fk_id_lot')->selectRaw('fk_id_lot, SUM(qty_change) q')->pluck('q', 'fk_id_lot');
        $out = [];
        foreach (DB::table('fin_lots')->whereIn('company_id', $cos ?: [0])->where('received_date', '<=', $date)->orderBy('sku')->orderBy('received_date')->orderBy('id')->get() as $l) {
            $units = round((float) $l->qty_left - (float) ($later[$l->id] ?? 0), 4); if (abs($units) < 0.00005) continue;
            $out[] = [$l->sku, (int) $l->id, $l->source, $l->received_date, $units + 0, $l->unit_cost_gbp === null ? '' : round((float) $l->unit_cost_gbp, 4), round($units * (float) $l->unit_cost_gbp, 2)];
        }
        return $out;
    }

    public static function valuation(int $company, string $date, string $format, ?object $u): array
    {
        if (! $u || ! Perm::has($u, 'money.SEE') || ! Perm::has($u, 'see_money')) throw new \InvalidArgumentException('Refused: this export needs the right to see money.');
        $format = $format === 'xlsx' ? 'xlsx' : 'csv'; $rows = self::valuationRows($company, $date, $u);
        $name = "stock_valuation_{$company}_{$date}.$format";
        Audit::log('fin_lots', 0, 'EXPORT', ['file' => $name, 'rows' => count($rows), 'value' => round(array_sum(array_column($rows, 6)), 2)], 'Stock valuation', (int) Context::ent());
        return ['name' => $name, 'type' => $format === 'xlsx' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'text/csv; charset=utf-8', 'body' => self::file(self::VALUATION_COLS, $rows, $format, 'Valuation ' . $date), 'rows' => count($rows)];
    }

    /**
     * R-FIN-25 report status board: per shop × report type the last import, the data until date and "up to date / needs upload"
     * (settlement older than 17 days, bank older than 8 days; other types show their dates only – owner to confirm their limits).
     */
    public static function board(int $ent, ?string $today = null): array
    {
        $today ??= date('Y-m-d'); $age = fn (?string $d) => $d ? (int) floor((strtotime($today) - strtotime(substr($d, 0, 10))) / 86400) : null;
        $state = fn (string $type, ?string $d) => isset(self::OVERDUE[$type]) ? ($d === null || $age($d) > self::OVERDUE[$type] ? 'needs upload' : 'up to date') : ($d ? 'loaded' : 'nothing yet');
        $rows = [];
        foreach (DB::table('t_branches')->where('fk_id_ent', $ent)->where('f_active', 1)->orderBy('shop_no')->get(['id', 'shop_no', 'shop_name']) as $s) {
            $st = DB::table('mo_settlements')->where('fk_id_ent', $ent)->where('fk_id_branch', $s->id)->selectRaw('MAX(statement_date) d, MAX(created_at) at')->first();
            $or = DB::table('t_sale_imports')->where('fk_id_ent', $ent)->where('fk_id_branch', $s->id)->selectRaw("MAX(LAST_DAY(STR_TO_DATE(CONCAT(period_year,'-',LPAD(period_month,2,'0'),'-01'),'%Y-%m-%d'))) d, MAX(imported_at) at")->first();
            $orD = $or && $or->d ? min((string) $or->d, $today) : null;
            foreach ([['SETTLEMENT', $st->d ?? null, $st->at ?? null], ['ORDERS', $orD, $or->at ?? null]] as [$type, $d, $at])
                $rows[] = ['shop' => trim($s->shop_no . ' ' . $s->shop_name), 'shop_id' => (int) $s->id, 'type' => $type, 'data_until' => $d ? substr((string) $d, 0, 10) : null, 'last_import' => $at ? substr((string) $at, 0, 16) : null, 'days' => $age($d), 'state' => $state($type, $d)];
        }
        foreach (DB::table('fin_bank_accounts')->where('company_id', $ent)->where('is_active', 1)->get(['id', 'bank_name', 'account_label']) as $b) {
            $x = DB::table('fin_bank_transactions')->where('bank_account_id', $b->id)->selectRaw('MAX(txn_date) d')->first();
            $rows[] = ['shop' => trim($b->bank_name . ' ' . $b->account_label), 'shop_id' => null, 'type' => 'BANK', 'data_until' => $x->d ?? null, 'last_import' => null, 'days' => $age($x->d ?? null), 'state' => $state('BANK', $x->d ?? null)];
        }
        return ['rows' => $rows, 'limits' => self::OVERDUE, 'needs_upload' => count(array_filter($rows, fn ($r) => $r['state'] === 'needs upload'))];
    }
}
