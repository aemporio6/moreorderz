<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The buying steps that were still plain forms: samples (R-B_SOURCING-58), quality check with a checklist (R-B_SOURCING-65),
 * customs with duty and import VAT (R-B_SOURCING-59), and Quick buy – a local purchase that is ordered, received and (optionally) paid in one save (R-B_SOURCING-1).
 */
class SourcingExtras
{
    private static function can(array $roles = ['OWNER', 'MANAGER', 'SOURCING']): void
    {
        if (! in_array(Context::role(), $roles, true)) throw new \InvalidArgumentException('Your role cannot do this in Buying.');
    }

    private static function cycle(int $ent, int $id): object
    {
        return DB::table('t_src_cycles')->where('id', $id)->where('fk_id_ent', $ent)->first() ?? throw new \InvalidArgumentException('Buying cycle not found.');
    }

    private static function supplierOf(int $cycleId): ?int
    {
        return DB::table('t_src_po')->where('fk_id_cycle', $cycleId)->where('f_active', 1)->orderByDesc('id')->value('fk_id_supplier') ?? DB::table('t_src_rfq')->where('fk_id_cycle', $cycleId)->where('f_active', 1)->orderByDesc('id')->value('fk_id_supplier');
    }

    // ───────────── samples ─────────────

    /** Shops and their share of a product's confirmed sales of the last 90 days; no sales → the cycle's main shop, else the setting DEFAULT_SHOP, else the first shop. */
    public static function shopShares(int $ent, ?int $productId, ?int $mainShop = null): array
    {
        $rows = $productId ? DB::table('t_sales')->where('fk_id_ent', $ent)->where('fk_product_id', $productId)->whereIn('status', ['Confirmed', 'Pending'])->where('d_order', '>=', date('Y-m-d', strtotime('-90 day')))->groupBy('fk_id_branch')->selectRaw('fk_id_branch b, SUM(total_amount_gbp) v')->pluck('v', 'b')->filter(fn ($v) => (float) $v > 0) : collect();
        if ($rows->isEmpty()) { $shop = $mainShop ?: ((int) Settings::get('DEFAULT_SHOP', $ent, 0) ?: (int) DB::table('t_branches')->where('fk_id_ent', $ent)->where('f_active', 1)->orderBy('id')->value('id')); return $shop ? [$shop => 1.0] : []; }
        $sum = (float) $rows->sum(); return $rows->map(fn ($v) => (float) $v / $sum)->all();
    }

    public static function saveSample(int $ent, array $d): int
    {
        self::can();
        $c = self::cycle($ent, (int) ($d['cycle'] ?? 0)); $cost = round((float) ($d['sample_cost'] ?? 0), 4); $ship = round((float) ($d['shipping_cost'] ?? 0), 4); $qty = (float) ($d['sample_qty'] ?? 1);
        if ($cost < 0 || $ship < 0 || $qty <= 0) throw new \InvalidArgumentException('Quantity must be above zero and the costs cannot be negative.');
        $date = $d['sample_date'] ?? date('Y-m-d'); $cur = strtoupper((string) ($d['currency'] ?? Fx::base($ent))); $rate = isset($d['exchange_rate']) && (float) $d['exchange_rate'] > 0 ? (float) $d['exchange_rate'] : Fx::rate($cur, $date, $ent);
        $row = ['fk_id_ent' => $ent, 'fk_id_cycle' => $c->id, 'fk_id_supplier' => $d['supplier'] ?? self::supplierOf((int) $c->id), 'fk_id_cycle_product' => $d['cycle_product'] ?? null, 'sample_date' => $date, 'sample_qty' => $qty, 'sample_cost' => $cost, 'shipping_cost' => $ship,
            'tracking_number' => $d['tracking_number'] ?? null, 'notes' => $d['notes'] ?? null, 'fk_id_currency' => BuyingService::currencyId($cur), 'exchange_rate' => $rate, 'base_amount' => round(($cost + $ship) * $rate, 2), 'updated_by' => Context::userId(), 'updated_at' => now()];
        if (! empty($d['id'])) {
            $old = DB::table('t_src_samples')->where('id', $d['id'])->where('fk_id_ent', $ent)->first() ?? throw new \InvalidArgumentException('Sample not found.');
            if (DB::table('fin_expenses')->where('source', 'SAMPLE:' . $old->id)->where('f_active', 1)->exists()) throw new \InvalidArgumentException("The cost of sample {$old->sample_code} is already booked. Cancel that expense first (Money › Expenses) to change the sample.");
            DB::table('t_src_samples')->where('id', $old->id)->update($row); return (int) $old->id;
        }
        $id = DB::table('t_src_samples')->insertGetId($row + ['sample_code' => Numbering::next('SMP', $ent, $date), 'approval_status' => 'PENDING', 'created_by' => Context::userId(), 'f_active' => 1, 'created_at' => now()]);
        Audit::log('t_src_samples', $id, 'INSERT', ['cycle' => $c->cycle_code, 'base_amount' => $row['base_amount']], null, $ent);
        return (int) $id;
    }

    public static function sampleReceived(int $ent, int $id, string $date): void
    {
        self::can(['OWNER', 'MANAGER', 'SOURCING', 'WAREHOUSE']);
        if (! DB::table('t_src_samples')->where('id', $id)->where('fk_id_ent', $ent)->update(['received_date' => $date, 'updated_at' => now()])) throw new \InvalidArgumentException('Sample not found.');
    }

    public static function decideSample(int $ent, int $id, bool $approve, ?string $reason = null): void
    {
        self::can(['OWNER', 'MANAGER']);
        $s = DB::table('t_src_samples')->where('id', $id)->where('fk_id_ent', $ent)->first() ?? throw new \InvalidArgumentException('Sample not found.');
        if (! $approve && trim((string) $reason) === '') throw new \InvalidArgumentException('Give the reason for rejecting the sample.');
        DB::table('t_src_samples')->where('id', $id)->update(['approval_status' => $approve ? 'APPROVED' : 'REJECTED', 'rejection_reason' => $approve ? null : $reason, 'approved_by' => (string) Context::userId(), 'approved_at' => date('Y-m-d'), 'updated_at' => now()]);
        Audit::log('t_src_samples', $id, 'APPROVE', ['sample' => $s->sample_code, 'result' => $approve ? 'APPROVED' : 'REJECTED'], $reason, $ent);
    }

    /** Book the sample's cost once: an expense (category SAMPLES) shared between the shops. Never part of landed cost. */
    public static function bookSample(int $ent, int $id, int $bankId): array
    {
        self::can(['OWNER', 'FINANCE']);
        return DB::transaction(function () use ($ent, $id, $bankId) {
            $s = DB::table('t_src_samples')->where('id', $id)->where('fk_id_ent', $ent)->lockForUpdate()->first() ?? throw new \InvalidArgumentException('Sample not found.');
            if ((float) $s->base_amount <= 0) throw new \InvalidArgumentException('This sample has no cost to book.');
            if (DB::table('fin_expenses')->where('source', 'SAMPLE:' . $s->id)->where('f_active', 1)->exists()) throw new \InvalidArgumentException("The cost of sample {$s->sample_code} is already booked.");
            $c = DB::table('t_src_cycles')->where('id', $s->fk_id_cycle)->first();
            $product = $s->fk_id_cycle_product ? DB::table('t_src_cycle_products')->where('id', $s->fk_id_cycle_product)->value('fk_id_product') : DB::table('t_src_cycle_products')->where('fk_id_cycle', $s->fk_id_cycle)->where('f_active', 1)->value('fk_id_product');
            $shares = self::shopShares($ent, $product ? (int) $product : null, $c->fk_id_main_branch ? (int) $c->fk_id_main_branch : null);
            if (! $shares) throw new \InvalidArgumentException('Add a shop first: the sample cost is shared between shops.');
            $total = (float) $s->base_amount; $left = $total; $out = []; $i = 0; $n = count($shares);
            foreach ($shares as $shop => $share) { $i++; $amt = $i === $n ? round($left, 2) : round($total * $share, 2); $left -= $amt; if ($amt <= 0) continue;
                $x = MoneyService::addExpense($ent, ['bank' => $bankId, 'category' => 'SAMPLES', 'amount' => $amt, 'date' => $s->sample_date ?: date('Y-m-d'), 'branch' => (int) $shop, 'note' => "Sample {$s->sample_code}" . ($n > 1 ? ' – ' . round($share * 100, 1) . '% of 90-day sales' : '')]);
                DB::table('fin_expenses')->where('id', $x['id'])->update(['source' => 'SAMPLE:' . $s->id]); $out[] = ['shop' => (int) $shop, 'amount' => $amt, 'status' => $x['status'] ?? 'BOOKED']; }
            return ['total' => $total, 'parts' => $out];
        });
    }

    // ───────────── quality check ─────────────

    /** The checklist to start from: the lines of the last QC that looked at this product, else empty. */
    public static function qcTemplate(int $ent, ?int $productId): array
    {
        if (! $productId) return [];
        $last = DB::table('t_src_qc_checks as k')->join('t_src_qc as q', 'q.id', '=', 'k.fk_id_qc')->where('q.fk_id_ent', $ent)->where('k.fk_id_product', $productId)->max('k.fk_id_qc');
        return $last ? DB::table('t_src_qc_checks')->where('fk_id_qc', $last)->where('fk_id_product', $productId)->orderBy('sort_no')->pluck('check_text')->all() : [];
    }

    public static function savePhoto(int $ent, \Illuminate\Http\UploadedFile $f): int
    {
        self::can(['OWNER', 'MANAGER', 'SOURCING', 'WAREHOUSE']);
        if (! in_array(strtolower($f->getClientOriginalExtension()), ['jpg', 'jpeg', 'png', 'webp'], true) || ! str_starts_with((string) $f->getMimeType(), 'image/')) throw new \InvalidArgumentException('A photo must be a JPG, PNG or WEBP picture.');
        $name = 'qc/' . $ent . '/' . date('Y/m') . '/' . bin2hex(random_bytes(12)) . '.' . strtolower($f->getClientOriginalExtension());
        Storage::disk('local')->put($name, file_get_contents($f->getRealPath()));
        return (int) DB::table('sys_documents')->insertGetId(RecordService::fillNotNull('sys_documents', ['fk_id_ent' => $ent, 'doc_no' => Numbering::next('DOC', $ent), 'title' => 'QC photo', 'stored_name' => $name, 'orig_name' => mb_substr($f->getClientOriginalName(), 0, 200), 'mime' => $f->getMimeType(), 'size_bytes' => $f->getSize(),
            'sha256' => hash_file('sha256', $f->getRealPath()), 'd_doc' => date('Y-m-d'), 'version' => 1, 'status' => 'ACTIVE', 'uploaded_by' => Context::userId(), 'created_at' => now(), 'tags' => 'qc']));
    }

    /**
     * Save a QC with its checklist. The result is worked out: any FAIL line → FAILED (unless the inspector overrides with a reason), else PASSED.
     * A FAIL line needs a photo and a note. A failed QC without a corrective action raises the task SRC_QC_FAILED; a passed re-inspection closes it.
     */
    public static function saveQc(int $ent, array $d): array
    {
        self::can(['OWNER', 'MANAGER', 'SOURCING', 'WAREHOUSE']);
        return DB::transaction(function () use ($ent, $d) {
            $po = DB::table('t_src_po')->where('id', $d['po'] ?? 0)->where('fk_id_ent', $ent)->first() ?? throw new \InvalidArgumentException('Choose the purchase order that is checked.');
            $ins = (float) ($d['qty_inspected'] ?? 0); $pass = (float) ($d['qty_passed'] ?? 0); $fail = (float) ($d['qty_failed'] ?? 0);
            if ($ins < 0 || $pass < 0 || $fail < 0 || $pass + $fail > $ins + 0.0001) throw new \InvalidArgumentException('Passed + failed cannot be more than the quantity inspected.');
            $checks = array_values(array_filter((array) ($d['checks'] ?? []), fn ($k) => trim((string) ($k['text'] ?? '')) !== ''));
            foreach ($checks as $k) {
                if (! in_array($k['result'] ?? 'NA', ['PASS', 'FAIL', 'NA'], true)) throw new \InvalidArgumentException('Each check is PASS, FAIL or NA.');
                if (($k['result'] ?? '') === 'FAIL' && empty($k['photo'])) throw new \InvalidArgumentException('Add a photo for each failed check.');
                if (($k['result'] ?? '') === 'FAIL' && trim((string) ($k['note'] ?? '')) === '') throw new \InvalidArgumentException('Write a note for each failed check.');
                if (! empty($k['photo']) && ! DB::table('sys_documents')->where('id', $k['photo'])->where('fk_id_ent', $ent)->exists()) throw new \InvalidArgumentException('A photo of the checklist was not found. Add it again.');
            }
            $anyFail = (bool) array_filter($checks, fn ($k) => $k['result'] === 'FAIL') || $fail > 0; $override = trim((string) ($d['override_reason'] ?? ''));
            if (! empty($d['override_pass']) && $override === '') throw new \InvalidArgumentException('To pass a QC that has a failed check, write the reason.');
            $result = $anyFail && empty($d['override_pass']) ? 'FAILED' : 'PASSED'; $date = $d['inspection_date'] ?? date('Y-m-d');
            $id = DB::table('t_src_qc')->insertGetId(['fk_id_ent' => $ent, 'qc_code' => ($code = Numbering::next('QC', $ent, $date)), 'fk_id_cycle' => $po->fk_id_cycle, 'fk_id_po' => $po->id, 'fk_id_supplier' => $po->fk_id_supplier, 'inspection_type' => $d['inspection_type'] ?? 'PRE_SHIPMENT',
                'inspector' => mb_substr((string) ($d['inspector'] ?? ''), 0, 255) ?: null, 'inspection_date' => $date, 'qty_submitted' => (float) ($d['qty_submitted'] ?? $ins), 'qty_inspected' => $ins, 'qty_passed' => $pass, 'qty_failed' => $fail, 'defect_rate' => $ins > 0 ? round($fail / $ins * 100, 2) : 0,
                'qc_result' => $result, 'qc_approval' => $result === 'PASSED' ? 'APPROVED' : 'PENDING', 'corrective_action' => $d['corrective_action'] ?? null, 'report_ref' => $d['report_ref'] ?? null, 're_inspection_of' => $d['re_inspection_of'] ?? null, 'created_by' => Context::userId(), 'f_active' => 1, 'created_at' => now()]);
            foreach ($checks as $i => $k) DB::table('t_src_qc_checks')->insert(['fk_id_qc' => $id, 'fk_id_product' => $k['product'] ?? null, 'check_text' => mb_substr(trim($k['text']), 0, 200), 'check_result' => $k['result'] ?? 'NA', 'fk_id_photo_doc' => $k['photo'] ?? null, 'note' => mb_substr((string) ($k['note'] ?? ''), 0, 255) ?: null, 'sort_no' => $i + 1]);
            Audit::log('t_src_qc', $id, 'INSERT', ['qc' => $code, 'po' => $po->po_number, 'result' => $result, 'checks' => count($checks)] + ($override !== '' ? ['override' => $override] : []), $override ?: null, $ent);
            if ($result === 'FAILED' && trim((string) ($d['corrective_action'] ?? '')) === '') TaskEngine::open('SRC_QC_FAILED', [$code], "QC $code of {$po->po_number} failed – decide the corrective action", ['fix_url' => '#/cycle-extras/' . $po->fk_id_cycle, 'ent' => $ent]);
            if ($result === 'PASSED' && ! empty($d['re_inspection_of'])) { $prev = DB::table('t_src_qc')->where('id', $d['re_inspection_of'])->where('fk_id_ent', $ent)->value('qc_code'); if ($prev) TaskEngine::close('SRC_QC_FAILED', [$prev], 'Re-inspection passed'); }
            return ['id' => (int) $id, 'code' => $code, 'result' => $result];
        });
    }

    public static function qcAction(int $ent, int $id, string $action): void
    {
        self::can(['OWNER', 'MANAGER', 'SOURCING']);
        $q = DB::table('t_src_qc')->where('id', $id)->where('fk_id_ent', $ent)->first() ?? throw new \InvalidArgumentException('QC not found.');
        if (trim($action) === '') throw new \InvalidArgumentException('Write what is done about the failed check.');
        DB::table('t_src_qc')->where('id', $id)->update(['corrective_action' => $action, 'updated_by' => Context::userId(), 'updated_at' => now()]);
        TaskEngine::close('SRC_QC_FAILED', [$q->qc_code], 'Corrective action written');
    }

    /** Latest QC per PO: shown as a chip on the lots that came from that PO. */
    public static function qcOfPos(array $poIds): array
    {
        if (! $poIds) return [];
        $out = [];
        foreach (DB::table('t_src_qc')->whereIn('fk_id_po', $poIds)->where('f_active', 1)->orderBy('id')->get(['fk_id_po', 'qc_code', 'qc_result', 'inspection_date', 'inspector']) as $q) $out[$q->fk_id_po] = ['code' => $q->qc_code, 'result' => $q->qc_result, 'date' => $q->inspection_date, 'inspector' => $q->inspector];
        return $out;
    }

    // ───────────── customs ─────────────

    public static function saveCustoms(int $ent, array $d): int
    {
        self::can(['OWNER', 'MANAGER', 'SOURCING', 'FINANCE']);
        $s = DB::table('t_src_shipments')->where('id', $d['shipment'] ?? 0)->where('fk_id_ent', $ent)->first() ?? throw new \InvalidArgumentException('Choose the shipment that is cleared.');
        $val = round((float) ($d['customs_value'] ?? 0), 4); $ratePct = (float) ($d['duty_rate'] ?? 0); if ($val < 0 || $ratePct < 0 || $ratePct > 100) throw new \InvalidArgumentException('The customs value cannot be negative and the duty rate is between 0 and 100 %.');
        $duty = isset($d['duty_amount']) && $d['duty_amount'] !== '' && $d['duty_amount'] !== null ? round((float) $d['duty_amount'], 4) : round($val * $ratePct / 100, 2); $vat = round((float) ($d['import_vat'] ?? 0), 4);
        if ($duty < 0 || $vat < 0) throw new \InvalidArgumentException('Duty and import VAT cannot be negative.');
        $cur = strtoupper((string) ($d['currency'] ?? Fx::base($ent))); $date = $d['clearance_date'] ?? date('Y-m-d'); $fx = isset($d['exchange_rate']) && (float) $d['exchange_rate'] > 0 ? (float) $d['exchange_rate'] : Fx::rate($cur, $date, $ent);
        $row = ['fk_id_ent' => $ent, 'fk_id_cycle' => $s->fk_id_cycle, 'fk_id_shipment' => $s->id, 'importer_of_record' => $d['importer_of_record'] ?? null, 'declaration_number' => $d['declaration_number'] ?? null, 'hs_code' => $d['hs_code'] ?? null, 'customs_qty' => (float) ($d['customs_qty'] ?? 0), 'customs_value' => $val,
            'fk_id_currency' => BuyingService::currencyId($cur), 'duty_rate' => $ratePct, 'duty_amount' => $duty, 'import_vat' => $vat, 'status' => $d['status'] ?? 'DECLARED', 'clearance_date' => $d['clearance_date'] ?? null, 'exchange_rate' => $fx, 'base_amount' => round($duty * $fx, 2),
            'fk_id_product' => $d['product'] ?? null, 'fk_id_broker' => $d['broker'] ?? null, 'updated_by' => Context::userId(), 'updated_at' => now()];
        if (! empty($d['id'])) {
            $old = DB::table('t_src_customs')->where('id', $d['id'])->where('fk_id_ent', $ent)->first() ?? throw new \InvalidArgumentException('Customs entry not found.');
            if ($old->pay_status === 'PAID') throw new \InvalidArgumentException('This customs entry is paid and cannot be changed.');
            DB::table('t_src_customs')->where('id', $old->id)->update($row); return (int) $old->id;
        }
        $id = DB::table('t_src_customs')->insertGetId($row + ['customs_code' => Numbering::next('CUS', $ent, $date), 'pay_status' => 'UNPAID', 'created_by' => Context::userId(), 'f_active' => 1, 'created_at' => now()]);
        Audit::log('t_src_customs', $id, 'INSERT', ['shipment' => $s->shipment_code, 'duty' => $duty, 'import_vat' => $vat, 'currency' => $cur], null, $ent);
        return (int) $id;
    }

    /**
     * Pay duty and import VAT from a bank account. Duty is part of the goods' cost on the way (Dr 1210 – the landed-cost run takes it from there into stock);
     * import VAT is kept apart to claim back (Dr 1400) and never enters landed cost. The amounts are what the bank really paid, in the bank's currency.
     */
    public static function payCustoms(int $ent, int $id, int $bankId, string $date, ?float $dutyPaid = null, ?float $vatPaid = null): array
    {
        self::can(['OWNER', 'FINANCE']);
        return DB::transaction(function () use ($ent, $id, $bankId, $date, $dutyPaid, $vatPaid) {
            $c = DB::table('t_src_customs')->where('id', $id)->where('fk_id_ent', $ent)->lockForUpdate()->first() ?? throw new \InvalidArgumentException('Customs entry not found.');
            if ($c->pay_status === 'PAID') throw new \InvalidArgumentException("Customs entry {$c->customs_code} is already paid.");
            $bank = DB::table('fin_bank_accounts')->where('id', $bankId)->where('company_id', $ent)->where('is_active', 1)->first() ?? throw new \InvalidArgumentException('Choose the bank account.');
            $fxEntry = (float) ($c->exchange_rate ?: 1); $bankRate = Fx::rate($bank->currency ?: Fx::base($ent), $date, $ent);
            $duty = round($dutyPaid ?? (float) $c->duty_amount * $fxEntry / $bankRate, 2); $vat = round($vatPaid ?? (float) $c->import_vat * $fxEntry / $bankRate, 2);
            if ($duty < 0 || $vat < 0 || $duty + $vat <= 0) throw new \InvalidArgumentException('Nothing to pay on this customs entry.');
            $dutyBase = round($duty * $bankRate, 2); $vatBase = round($vat * $bankRate, 2); $lines = [];
            if ($dutyBase > 0) $lines[] = ['account' => '1210', 'debit' => $dutyBase, 'memo' => 'Customs duty (goes into landed cost)'];
            if ($vatBase > 0) $lines[] = ['account' => '1400', 'debit' => $vatBase, 'memo' => 'Import VAT to claim back'];
            $lines[] = ['account' => '1000', 'credit' => round($dutyBase + $vatBase, 2), 'ref_id' => $bankId, 'amount_cur' => -round($duty + $vat, 2)];
            $jid = Ledger::post($ent, $date, 'CUSTOMS', $id, $lines, ['memo' => "Customs {$c->customs_code}" . ($c->declaration_number ? " · {$c->declaration_number}" : ''), 'currency' => $bank->currency]);
            DB::table('t_src_customs')->where('id', $id)->update(['pay_status' => 'PAID', 'paid_date' => $date, 'paid_base_amount' => $dutyBase, 'status' => 'CLEARED', 'clearance_date' => $c->clearance_date ?: $date, 'updated_by' => Context::userId(), 'updated_at' => now()]);
            Audit::log('t_src_customs', $id, 'UPDATE', ['paid' => ['duty' => $dutyBase, 'import_vat' => $vatBase], 'bank' => $bankId], null, $ent);
            return ['journal' => $jid, 'duty_base' => $dutyBase, 'vat_base' => $vatBase];
        });
    }

    /** Duty already paid for the shipments of a PO – what the landed-cost form should start from. */
    public static function dutyPaidForPo(int $ent, int $poId): float
    {
        return round((float) DB::table('t_src_customs as c')->join('t_src_shipments as s', 's.id', '=', 'c.fk_id_shipment')->where('c.fk_id_ent', $ent)->where('s.fk_id_po', $poId)->where('c.f_active', 1)->sum(DB::raw("CASE WHEN c.pay_status = 'PAID' THEN c.paid_base_amount ELSE c.base_amount END")), 2);
    }

    // ───────────── quick buy ─────────────

    /**
     * A local purchase in one save: cycle (type QUICK) + PO (confirmed) + shipment + GRN (posted → lots and stock) and, when a bank is given, the payment.
     * Everything runs through the same services as a full buying cycle, so stock, supplier balance and ledger are booked the usual way.
     */
    public static function quickBuy(int $ent, array $d): array
    {
        self::can(['OWNER', 'MANAGER', 'SOURCING']);
        if (! empty($d['bank']) && ! in_array(Context::role(), ['OWNER', 'FINANCE'], true)) throw new \InvalidArgumentException('Only the Owner or Finance pays from the bank. Save the quick buy without a bank account; Finance pays it from the purchase order.');
        return DB::transaction(function () use ($ent, $d) {
            $sup = DB::table('t_src_suppliers')->where('id', $d['supplier'] ?? 0)->first() ?? throw new \InvalidArgumentException('Choose the supplier.');
            $loc = DB::table('cat_inventory_locations')->where('id', $d['location'] ?? 0)->where('fk_id_ent', $ent)->where('f_active', 1)->first() ?? throw new \InvalidArgumentException('Choose where the goods are put.');
            $lines = array_values(array_filter((array) ($d['lines'] ?? []), fn ($l) => ! empty($l['product']) || trim((string) ($l['sku'] ?? '')) !== ''));
            if (! $lines) throw new \InvalidArgumentException('Add at least one product line.');
            foreach ($lines as &$l) { if (empty($l['product'])) $l['product'] = DB::table('t_product_vendors')->where('fk_id_ent', $ent)->where('SKU_VENDOR', trim((string) $l['sku']))->value('id') ?? throw new \InvalidArgumentException("No product with SKU {$l['sku']}."); if ((int) $l['qty'] != (float) $l['qty']) throw new \InvalidArgumentException('Quantities are whole units.'); } unset($l);
            $date = $d['date'] ?? date('Y-m-d'); if ($date > date('Y-m-d')) throw new \InvalidArgumentException('A quick buy is a purchase that already happened – the date cannot be in the future.');
            $first = DB::table('t_product_vendors')->where('id', $lines[0]['product'])->first();
            $cycle = DB::table('t_src_cycles')->insertGetId(RecordService::fillNotNull('t_src_cycles', ['fk_id_ent' => $ent, 'cycle_code' => Numbering::next('SC', $ent, $date), 'sourcing_date' => $date, 'requested_by' => Context::userId(), 'product_name' => mb_substr('Quick buy: ' . ($first->product_name_vendor ?? $first->SKU_VENDOR) . (count($lines) > 1 ? ' + ' . (count($lines) - 1) . ' more' : ''), 0, 255),
                'sku' => $first->SKU_VENDOR, 'target_qty' => array_sum(array_column($lines, 'qty')), 'status' => 'Open', 'notes' => $d['note'] ?? null, 'created_by' => Context::userId(), 'f_active' => 1, 'cycle_type' => 'QUICK', 'skipped_steps' => 'rfq,samples,production,qc,shipment,freight,customs,landed_cost', 'order_ref' => $d['reference'] ?? null]));
            foreach ($lines as $l) DB::table('t_src_cycle_products')->insert(RecordService::fillNotNull('t_src_cycle_products', ['fk_id_ent' => $ent, 'fk_id_cycle' => $cycle, 'fk_id_product' => $l['product'], 'product_name' => DB::table('t_product_vendors')->where('id', $l['product'])->value('product_name_vendor'), 'target_qty' => $l['qty'], 'row_status' => 'Confirmed', 'f_active' => 1, 'created_by' => Context::userId()]));
            $po = BuyingService::createPo(['cycle' => $cycle, 'supplier' => $sup->id, 'currency' => $d['currency'] ?? Fx::base($ent), 'date' => $date, 'deposit_pct' => 100, 'lines' => array_map(fn ($l) => ['product' => (int) $l['product'], 'qty' => (int) $l['qty'], 'unit_price' => (float) $l['unit_price']], $lines)]);
            BuyingService::confirmPo($po);
            $ship = BuyingService::createShipment(['po' => $po, 'eta' => $date]);
            $grn = BuyingService::createGrn(['shipment' => $ship, 'location' => (int) $loc->id, 'date' => $date]);
            DB::table('t_src_grn')->where('id', $grn)->update(['grn_source' => 'PURCHASE']);
            $res = BuyingService::postGrn($grn);
            $paid = null;
            if (! empty($d['bank'])) { $paid = [];   // the PO has one term of 100 % (deposit 100): pay it, or ask for approval above the limit
                foreach (DB::table('t_src_payment_terms')->where('fk_id_po', $po)->where('f_active', 1)->where('remaining_amount', '>', 0)->orderBy('term_no')->get(['id', 'remaining_amount']) as $t) $paid[] = BuyingService::payOrAsk(['term' => (int) $t->id, 'bank' => (int) $d['bank'], 'amount' => (float) $t->remaining_amount, 'date' => $date, 'notes' => 'Quick buy']); }
            DB::table('t_src_cycles')->where('id', $cycle)->update(['status' => 'Completed']);
            $c = DB::table('t_src_po')->where('id', $po)->first(['po_number', 'po_total']);
            Audit::log('t_src_cycles', $cycle, 'INSERT', ['quick_buy' => $c->po_number, 'total' => (float) $c->po_total, 'units' => array_sum(array_column($lines, 'qty'))], $d['note'] ?? null, $ent);
            return ['cycle' => (int) $cycle, 'po' => (int) $po, 'po_number' => $c->po_number, 'grn' => (int) $grn, 'value' => $res['value'] ?? null, 'units' => (int) array_sum(array_column($lines, 'qty')), 'paid' => $paid];
        });
    }

    // ───────────── the page of one cycle ─────────────
    public static function page(int $ent, int $cycleId): array
    {
        $c = self::cycle($ent, $cycleId);
        $pos = DB::table('t_src_po')->where('fk_id_cycle', $cycleId)->where('f_active', 1)->get(['id', 'po_number'])->all(); $poIds = array_column($pos, 'id');
        $qcs = DB::table('t_src_qc as q')->leftJoin('t_src_po as p', 'p.id', '=', 'q.fk_id_po')->where('q.fk_id_cycle', $cycleId)->where('q.f_active', 1)->orderByDesc('q.id')->get(['q.id', 'q.qc_code', 'p.po_number', 'q.fk_id_po', 'q.inspection_type', 'q.inspector', 'q.inspection_date', 'q.qty_inspected', 'q.qty_passed', 'q.qty_failed', 'q.defect_rate', 'q.qc_result', 'q.corrective_action', 'q.re_inspection_of']);
        $checks = DB::table('t_src_qc_checks')->whereIn('fk_id_qc', $qcs->pluck('id'))->orderBy('sort_no')->get()->groupBy('fk_id_qc');
        foreach ($qcs as $q) $q->checks = ($checks[$q->id] ?? collect())->map(fn ($k) => ['text' => $k->check_text, 'result' => $k->check_result, 'note' => $k->note, 'photo' => $k->fk_id_photo_doc])->all();
        $prod = DB::table('t_src_cycle_products as cp')->leftJoin('t_product_vendors as p', 'p.id', '=', 'cp.fk_id_product')->where('cp.fk_id_cycle', $cycleId)->get(['cp.id as cp', 'cp.fk_id_product as id', 'p.SKU_VENDOR as sku', 'cp.product_name as name'])->all();
        return ['cycle' => ['id' => (int) $c->id, 'code' => $c->cycle_code, 'name' => $c->product_name, 'type' => $c->cycle_type], 'base' => Fx::base($ent), 'pos' => $pos, 'products' => $prod,
            'samples' => DB::table('t_src_samples as s')->leftJoin('cat_currency as cu', 'cu.id', '=', 's.fk_id_currency')->where('s.fk_id_cycle', $cycleId)->where('s.f_active', 1)->orderByDesc('s.id')
                ->get(['s.id', 's.sample_code', 's.sample_date', 's.sample_qty', 's.sample_cost', 's.shipping_cost', 'cu.currency_code as currency', 's.exchange_rate', 's.base_amount', 's.tracking_number', 's.received_date', 's.approval_status', 's.rejection_reason', 's.notes'])
                ->map(function ($s) { $s->booked = DB::table('fin_expenses')->where('source', 'SAMPLE:' . $s->id)->where('f_active', 1)->exists(); return $s; }),
            'qcs' => $qcs, 'template' => self::qcTemplate($ent, $prod[0]->id ?? null),
            'shipments' => DB::table('t_src_shipments')->whereIn('fk_id_po', $poIds ?: [0])->where('f_active', 1)->get(['id', 'shipment_code']),
            'customs' => DB::table('t_src_customs as c')->leftJoin('t_src_shipments as s', 's.id', '=', 'c.fk_id_shipment')->leftJoin('cat_currency as cu', 'cu.id', '=', 'c.fk_id_currency')->where('c.fk_id_cycle', $cycleId)->where('c.f_active', 1)->orderByDesc('c.id')
                ->get(['c.id', 'c.customs_code', 's.shipment_code', 'c.fk_id_shipment', 'c.declaration_number', 'c.hs_code', 'c.customs_value', 'cu.currency_code as currency', 'c.duty_rate', 'c.duty_amount', 'c.import_vat', 'c.exchange_rate', 'c.base_amount', 'c.status', 'c.clearance_date', 'c.pay_status', 'c.paid_date', 'c.paid_base_amount']),
            'banks' => DB::table('fin_bank_accounts')->where('company_id', $ent)->where('is_active', 1)->get(['id', 'account_label as name', 'currency']),
            'currencies' => DB::table('cat_currency')->orderBy('currency_code')->pluck('currency_code'),
            'rights' => ['edit' => in_array(Context::role(), ['OWNER', 'MANAGER', 'SOURCING'], true), 'qc' => in_array(Context::role(), ['OWNER', 'MANAGER', 'SOURCING', 'WAREHOUSE'], true), 'decide' => in_array(Context::role(), ['OWNER', 'MANAGER'], true), 'pay' => in_array(Context::role(), ['OWNER', 'FINANCE'], true)]];
    }
}
