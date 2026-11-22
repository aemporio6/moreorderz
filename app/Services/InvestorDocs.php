<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * RULES_D_PRODUCTS R-D-83 documents from a settled month and R-D-84 reopen, cancel, credit note, payments, statuses, e-mail (old messages kept).
 * Types: INVESTOR (investor → owner, UNPAID), SELF_SETTLEMENT (investor = shop owner, NOT_REQUIRED, with the owner lines), OWNER_STATEMENT (operator → owner,
 * NOT_REQUIRED, only with an owner share / fixed and no self-settlement), VA (operator → owner, UNPAID; NOT_REQUIRED when the VA is the owner), SBS_3PL (operator → owner,
 * 3PL total > 0), SBS_HANDLER (handler → owner, handler share / fixed > 0), CREDIT_NOTE (R-D-84).
 * Number = PREFIX-[ISSUER PARTY CODE-]SHOPNO-YYYYMM-NNN (party code only for INVESTOR / SELF_SETTLEMENT), NNN per series under a row lock (t_inv_number_seq).
 * Issuer / recipient snapshots are frozen on the document. Payments: only on UNPAID / PART_PAID, amount ≤ balance + 0.004, date valid and not in the future,
 * proof jpg / jpeg / png / pdf / webp ≤ 5 MB stored with a random name; status refresh after every payment change (PAID needs total > 0).
 */
class InvestorDocs
{
    public const TYPES = ['INVESTOR' => ['Investor', 'INVOICE', 'INV'], 'SELF_SETTLEMENT' => ['Investor + Owner (self-settlement)', 'SELF-SETTLEMENT STATEMENT', 'SS'], 'OWNER_STATEMENT' => ['Owner profit share statement', 'PROFIT SHARE STATEMENT', 'OS'],
        'VA' => ['VA salary / share', 'INVOICE', 'VA'], 'SBS_3PL' => ['SBS 3PL services', 'INVOICE', 'SBS-3PL'], 'SBS_HANDLER' => ['SBS handler share', 'INVOICE', 'SBS-HS'], 'CREDIT_NOTE' => ['Credit note', 'CREDIT NOTE', 'CN']];
    public const STATUS = ['UNPAID' => 'Unpaid', 'PART_PAID' => 'Part paid', 'PAID' => 'Paid', 'CANCELLED' => 'Cancelled', 'CREDITED' => 'Credited', 'NOT_REQUIRED' => 'Statement'];
    public const METHODS = ['Bank transfer', 'PayPal', 'Wise', 'Cash', 'Card', 'Other'];
    public const PROOF = ['jpg', 'jpeg', 'png', 'pdf', 'webp'];

    private static function pct(float $p): string { return rtrim(rtrim(number_format($p, 4, '.', ''), '0'), '.'); }
    private static function month(object $p): string { return date('F Y', strtotime((string) $p->d_to)); }
    private static function code(?string $s, string $empty): string { $c = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $s)); return $c !== '' ? $c : $empty; }

    /** The party of an account (made from the person when missing): party code = typed code, else the account code, else "A" + id. */
    public static function partyOf(int $ent, int $accountId, string $type): object
    {
        $p = DB::table('t_inv_parties')->where('fk_id_account', $accountId)->where('f_active', 1)->orderBy('id')->first();
        if ($p) return $p;
        $a = DB::table('t_account')->where('id', $accountId)->first() ?? throw new \InvalidArgumentException('Owner or Safe Buy Stores party is missing.');
        $name = trim(($a->first_name ?? '') . ' ' . ($a->sur_name ?? '')) ?: ('Account ' . $accountId);
        $id = DB::table('t_inv_parties')->insertGetId(RecordService::fillNotNull('t_inv_parties', ['fk_id_ent' => $ent, 'party_type' => 'ACCOUNT', 'fk_id_account' => $accountId, 'party_code' => mb_substr(self::code($a->account_code ?? '', 'A' . $accountId), 0, 12),
            'display_name' => $name, 'company_name' => $name, 'payment_terms_days' => 0, 'f_is_sbs' => 0, 'f_portal_access' => 0, 'f_active' => 1, 'created_at' => now()]));
        return DB::table('t_inv_parties')->where('id', $id)->first();
    }

    private static function snapshot(object $p): string
    {
        return json_encode(['display_name' => $p->display_name, 'company' => $p->company_name, 'reg_no' => $p->company_reg_no, 'vat' => $p->vat_no, 'address' => $p->address, 'phone' => $p->phone, 'email' => $p->email,
            'bank' => ['name' => $p->bank_name, 'account_name' => $p->bank_account_name ?: ($p->company_name ?: $p->display_name), 'account_no' => $p->bank_account_no, 'sort_code' => $p->bank_sort_code, 'iban' => $p->bank_iban],
            'party_code' => $p->party_code, 'terms' => $p->payment_terms_days], JSON_UNESCAPED_UNICODE);
    }

    /** Next number of a series under a row lock. */
    public static function number(string $type, ?string $partyCode, string $shopNo, string $yyyymm): string
    {
        $pre = self::TYPES[$type][2]; $mid = in_array($type, ['INVESTOR', 'SELF_SETTLEMENT'], true) ? self::code($partyCode, 'X') . '-' : '';
        $series = "$pre-$mid$shopNo-$yyyymm";
        $row = DB::table('t_inv_number_seq')->where('series', $series)->lockForUpdate()->first();
        $n = $row ? (int) $row->last_no + 1 : 1;
        $row ? DB::table('t_inv_number_seq')->where('series', $series)->update(['last_no' => $n]) : DB::table('t_inv_number_seq')->insert(['series' => $series, 'last_no' => $n]);
        return $series . '-' . str_pad((string) $n, 3, '0', STR_PAD_LEFT);
    }

    /** R-D-83 generate the documents of a confirmed (settled) month. */
    public static function generate(int $ent, int $periodId, object $u): array
    {
        return DB::transaction(function () use ($ent, $periodId, $u) {
            $p = DB::table('t_inv_periods')->where('id', $periodId)->where('fk_id_ent', $ent)->lockForUpdate()->first() ?? throw new \InvalidArgumentException('Sales month not found.');
            if ($p->status !== 'CONFIRMED') throw new \InvalidArgumentException('Invoices can only be generated for a confirmed and settled sales month.');
            $sbs = DB::table('t_inv_parties')->where('f_is_sbs', 1)->where('f_active', 1)->where(fn ($w) => $w->where('fk_id_ent', $ent)->orWhere('fk_id_ent', 0))->orderByDesc('fk_id_ent')->first();
            if (! $sbs || ! $p->fk_owner_account) throw new \InvalidArgumentException('Owner or Safe Buy Stores party is missing.');
            $owner = self::partyOf($ent, (int) $p->fk_owner_account, 'OWNER');
            $shop = DB::table('t_branches')->where('id', $p->fk_id_branch)->first(); $shopNo = self::code($shop->shop_no ?? (string) $p->fk_id_branch, 'SHOP'); $shopName = (string) ($shop->shop_name ?? 'Shop ' . $p->fk_id_branch);
            $ym = date('Ym', strtotime((string) $p->d_to)); $mon = self::month($p); $made = 0;
            $exists = fn (string $type, ?int $inv) => DB::table('t_invoices')->where('fk_id_period', $p->id)->where('invoice_type', $type)->when($inv, fn ($q) => $q->where('fk_id_investor_account', $inv), fn ($q) => $q->where('fk_id_investor_account', 0))->whereNotIn('status', ['CANCELLED', 'CREDITED'])->exists();
            $make = function (string $type, object $issuer, object $recipient, ?int $invAccount, string $refWho, array $lines, bool $statement) use ($ent, $p, $shopNo, $ym, $mon, $shopName, $u, &$made) {
                $lines = array_values(array_filter($lines, fn ($l) => round((float) $l[3], 2) != 0.0)); if (! $lines) return;
                $total = round(array_sum(array_column($lines, 3)), 2);
                $days = (int) ($issuer->payment_terms_days ?: Settings::get('DEFAULT_PAYMENT_TERMS_DAYS', $ent, 14) ?: 14);
                $id = DB::table('t_invoices')->insertGetId(['fk_id_ent' => $ent, 'fk_id_period' => $p->id, 'invoice_no' => self::number($type, $issuer->party_code, $shopNo, $ym), 'invoice_type' => $type, 'fk_issuer_party' => $issuer->id, 'fk_recipient_party' => $recipient->id,
                    'fk_id_investor_account' => $invAccount ?? 0, 'issuer_json' => self::snapshot($issuer), 'recipient_json' => self::snapshot($recipient), 'd_invoice' => date('Y-m-d'), 'd_due' => $statement ? null : date('Y-m-d', strtotime("+$days days")),
                    'reference' => "$shopName / $refWho / $mon", 'currency' => 'GBP', 'subtotal' => $total, 'total' => $total, 'amount_paid' => 0, 'status' => $statement ? 'NOT_REQUIRED' : 'UNPAID', 'created_by' => $u->id, 'created_at' => now()]);
                foreach ($lines as $i => [$group, $desc, $qty, $amount]) DB::table('t_invoice_lines')->insert(['fk_id_invoice' => $id, 'line_no' => $i + 1, 'line_group' => $group, 'description' => $desc, 'sku' => '', 'qty' => $qty, 'unit_price' => $qty ? round($amount / $qty, 4) : $amount, 'amount' => round($amount, 2)]);
                Audit::log('t_invoices', (int) $id, 'INSERT', ['type' => $type, 'total' => $total], 'generated from the settled month', $ent); $made++;
            };
            $ownerLines = [['FEE', "Account rent (fixed) - $mon", 1, (float) $p->owner_fixed], ['SHARE', 'Account Owner share ' . self::pct((float) $p->owner_pct) . "% of Final Net Profit - $shopName, $mon", 1, (float) $p->owner_share]];
            $self = false;
            foreach (DB::table('t_inv_period_investors')->where('fk_id_period', $p->id)->orderBy('id')->get() as $iv) {
                $type = (int) $iv->f_self ? 'SELF_SETTLEMENT' : 'INVESTOR'; if ($type === 'SELF_SETTLEMENT') $self = true;
                if ($exists($type, (int) $iv->fk_id_investor_account)) continue;
                $party = self::partyOf($ent, (int) $iv->fk_id_investor_account, 'INVESTOR');
                $lines = [['ITEM', "Capital (raw cost) of the units sold - $shopName, $mon", (int) $iv->units, (float) $iv->capital],
                    ['SHARE', 'Investor profit share ' . self::pct((float) $iv->weight_pct ?: (float) $p->investor_pct) . "% of Final Net Profit - $shopName, $mon", 1, (float) $iv->share_amount]];
                if ($type === 'SELF_SETTLEMENT') $lines = array_merge($lines, $ownerLines);
                $make($type, $party, $owner, (int) $iv->fk_id_investor_account, $party->company_name ?: $party->display_name, $lines, $type === 'SELF_SETTLEMENT');
            }
            if (! $self && ((float) $p->owner_share > 0 || (float) $p->owner_fixed > 0) && ! $exists('OWNER_STATEMENT', null)) $make('OWNER_STATEMENT', $sbs, $owner, null, 'Owner statement', $ownerLines, true);
            if (((float) $p->va_salary > 0 || (float) $p->va_share > 0) && ! $exists('VA', null)) {
                $va = $p->fk_va_account ? trim((string) DB::table('t_account')->where('id', $p->fk_va_account)->selectRaw("CONCAT_WS(' ', first_name, sur_name) n")->value('n')) : 'VA';
                $make('VA', $sbs, $owner, null, 'VA', [['FEE', "VA salary - $va, $mon", 1, (float) $p->va_salary], ['SHARE', 'VA profit share ' . self::pct((float) $p->va_pct) . "% of Final Net Profit - $va", 1, (float) $p->va_share]], (int) $p->fk_va_account === (int) $p->fk_owner_account);
            }
            $tpl = [['COST', "Shipping / postage - $mon", 1, (float) $p->shipping], ['COST', "Packaging material - $mon", 1, (float) $p->packing_material], ['COST', "Packing fee (labour) - $mon", 1, (float) $p->packing_fee], ['COST', "Storage fee - $mon", 1, (float) $p->storage_fee]];
            if ((float) $p->extra_3pl > 0) $tpl[] = ['COST', 'Extra charges: ' . ($p->extra_3pl_note ?: '—'), 1, (float) $p->extra_3pl];
            if (array_sum(array_column($tpl, 3)) > 0 && ! $exists('SBS_3PL', null)) $make('SBS_3PL', $sbs, $owner, null, '3PL services', $tpl, false);
            if (((float) $p->handler_share > 0 || (float) $p->handler_fixed > 0) && ! $exists('SBS_HANDLER', null)) {
                $h = $p->fk_handler_party ? DB::table('t_inv_parties')->where('id', $p->fk_handler_party)->first() : null;
                $make('SBS_HANDLER', $h ?: $sbs, $owner, null, 'Handler share', [['FEE', "Management fee - $mon", 1, (float) $p->handler_fixed], ['SHARE', 'Handler / Operator share ' . self::pct((float) $p->handler_pct) . "% of Final Net Profit - $shopName, $mon", 1, (float) $p->handler_share]], false);
            }
            return ['made' => $made, 'message' => $made ? "$made document(s) generated." : 'All invoices for this month already exist.'];
        });
    }

    /** Status after a payment change; CANCELLED / CREDITED / NOT_REQUIRED never change. */
    public static function refresh(int $id): string
    {
        $d = DB::table('t_invoices')->where('id', $id)->first();
        if (in_array($d->status, ['CANCELLED', 'CREDITED', 'NOT_REQUIRED'], true)) return $d->status;
        $paid = round((float) DB::table('t_invoice_payments')->where('fk_id_invoice', $id)->where('f_deleted', 0)->sum('amount'), 2); $t = (float) $d->total;
        $st = $paid + 0.004 >= $t && $t > 0 ? 'PAID' : ($paid > 0 ? 'PART_PAID' : 'UNPAID');
        DB::table('t_invoices')->where('id', $id)->update(['amount_paid' => $paid, 'status' => $st]);
        return $st;
    }

    /** "Overdue" is shown instead of Unpaid / Part paid when the due date has passed – computed, never stored. */
    public static function badge(object $d): string
    {
        if (in_array($d->status, ['UNPAID', 'PART_PAID'], true) && $d->d_due && $d->d_due < date('Y-m-d')) return 'Overdue';
        return self::STATUS[$d->status] ?? $d->status;
    }

    public static function pay(int $ent, int $id, array $v, ?UploadedFile $proof, object $u): string
    {
        return DB::transaction(function () use ($ent, $id, $v, $proof, $u) {
            $d = DB::table('t_invoices')->where('id', $id)->where('fk_id_ent', $ent)->lockForUpdate()->first() ?? throw new \InvalidArgumentException('Invoice not found.');
            if (! in_array($d->status, ['UNPAID', 'PART_PAID'], true)) throw new \InvalidArgumentException('Payments can only be recorded on unpaid or part-paid invoices.');
            $amt = round((float) ($v['amount'] ?? 0), 2); if ($amt <= 0) throw new \InvalidArgumentException('Enter an amount above zero.');
            $bal = round((float) $d->total - (float) DB::table('t_invoice_payments')->where('fk_id_invoice', $id)->where('f_deleted', 0)->sum('amount'), 2);
            if ($amt > $bal + 0.004) throw new \InvalidArgumentException('Amount is more than the balance due (£' . number_format($bal, 2) . ').');
            $raw = trim((string) ($v['date'] ?? '')); $date = null;
            foreach (['Y-m-d', 'd-m-Y', 'd/m/Y'] as $fmt) { $x = \DateTime::createFromFormat('!' . $fmt, $raw); if ($x && $x->format($fmt) === $raw) { $date = $x->format('Y-m-d'); break; } }
            if (! $date) throw new \InvalidArgumentException('Enter a valid payment date.');
            if ($date > date('Y-m-d')) throw new \InvalidArgumentException('Payment date cannot be in the future.');
            $method = mb_substr(trim((string) ($v['method'] ?? 'Bank transfer')), 0, 50);
            $file = null;
            if ($proof) {
                $ext = strtolower($proof->getClientOriginalExtension());
                if (! in_array($ext, self::PROOF, true) || $proof->getSize() > 5 * 1024 * 1024) throw new \InvalidArgumentException('Proof upload failed: use a jpg, jpeg, png, pdf or webp file of at most 5 MB.');
                $file = 'invoice_proofs/' . bin2hex(random_bytes(16)) . '.' . $ext; Storage::disk('local')->put($file, (string) file_get_contents($proof->getRealPath()));
            }
            $pid = DB::table('t_invoice_payments')->insertGetId(['fk_id_invoice' => $id, 'd_payment' => $date, 'amount' => $amt, 'method' => $method, 'received_into' => mb_substr((string) ($v['received_into'] ?? ''), 0, 200),
                'reference' => mb_substr((string) ($v['reference'] ?? ''), 0, 200), 'notes' => mb_substr((string) ($v['notes'] ?? ''), 0, 500), 'proof_file' => $file ?? '', 'f_deleted' => 0, 'created_by' => $u->id, 'created_at' => now()]);
            Audit::log('t_invoice_payments', (int) $pid, 'INSERT', ['invoice' => $d->invoice_no, 'amount' => $amt], null, $ent);
            self::refresh($id);
            return 'Payment recorded.';
        });
    }

    public static function deletePayment(int $ent, int $paymentId, object $u): string
    {
        $p = DB::table('t_invoice_payments as p')->join('t_invoices as i', 'i.id', '=', 'p.fk_id_invoice')->where('p.id', $paymentId)->where('i.fk_id_ent', $ent)->first(['p.*']) ?? throw new \InvalidArgumentException('Payment not found.');
        DB::table('t_invoice_payments')->where('id', $paymentId)->update(['f_deleted' => 1, 'deleted_by' => $u->id, 'deleted_at' => now()]);
        Audit::log('t_invoice_payments', $paymentId, 'DELETE', ['amount' => (float) $p->amount], null, $ent);
        self::refresh((int) $p->fk_id_invoice);
        return 'Payment deleted.';
    }

    /** R-D-84 cancel: never sent / statement → CANCELLED; sent → CREDITED + a credit note for −total. */
    public static function cancel(int $ent, int $id, string $reason, object $u): array
    {
        return DB::transaction(function () use ($ent, $id, $reason, $u) {
            if (trim($reason) === '') throw new \InvalidArgumentException('Enter a reason for cancelling.');
            $d = DB::table('t_invoices')->where('id', $id)->where('fk_id_ent', $ent)->lockForUpdate()->first() ?? throw new \InvalidArgumentException('Invoice not found.');
            if (in_array($d->status, ['CANCELLED', 'CREDITED'], true) || $d->invoice_type === 'CREDIT_NOTE') throw new \InvalidArgumentException('This document is already cancelled or is a credit note.');
            if (DB::table('t_invoice_payments')->where('fk_id_invoice', $id)->where('f_deleted', 0)->exists()) throw new \InvalidArgumentException('Payments are recorded on this invoice. Delete them first, then cancel.');
            $cn = null;
            if ($d->status !== 'NOT_REQUIRED' && $d->sent_at) {
                $p = DB::table('t_inv_periods')->where('id', $d->fk_id_period)->first(); $shop = DB::table('t_branches')->where('id', $p->fk_id_branch ?? 0)->first();
                $no = self::number('CREDIT_NOTE', null, self::code($shop->shop_no ?? (string) ($p->fk_id_branch ?? ''), 'SHOP'), date('Ym', strtotime((string) ($p->d_to ?? date('Y-m-d')))));
                $cn = DB::table('t_invoices')->insertGetId(['fk_id_ent' => $ent, 'fk_id_period' => $d->fk_id_period, 'invoice_no' => $no, 'invoice_type' => 'CREDIT_NOTE', 'fk_issuer_party' => $d->fk_issuer_party, 'fk_recipient_party' => $d->fk_recipient_party,
                    'fk_id_investor_account' => $d->fk_id_investor_account, 'issuer_json' => $d->issuer_json, 'recipient_json' => $d->recipient_json, 'd_invoice' => date('Y-m-d'), 'd_due' => null, 'reference' => 'Credit for ' . $d->invoice_no . ' - ' . trim($reason),
                    'currency' => $d->currency, 'subtotal' => -(float) $d->subtotal, 'total' => -(float) $d->total, 'amount_paid' => 0, 'status' => 'NOT_REQUIRED', 'fk_id_credit_of' => $d->id, 'created_by' => $u->id, 'created_at' => now()]);
                foreach (DB::table('t_invoice_lines')->where('fk_id_invoice', $id)->orderBy('line_no')->get() as $l) DB::table('t_invoice_lines')->insert(['fk_id_invoice' => $cn, 'line_no' => $l->line_no, 'line_group' => $l->line_group, 'description' => $l->description, 'sku' => $l->sku, 'qty' => -(float) $l->qty, 'unit_price' => $l->unit_price, 'amount' => -(float) $l->amount]);
            }
            DB::table('t_invoices')->where('id', $id)->update(['status' => $cn ? 'CREDITED' : 'CANCELLED', 'cancel_reason' => mb_substr(trim($reason), 0, 255), 'cancelled_by' => $u->id, 'cancelled_at' => now(), 'fk_id_credit_note' => $cn]);
            Audit::log('t_invoices', $id, 'UPDATE', ['status' => [$d->status, $cn ? 'CREDITED' : 'CANCELLED']], $reason, $ent);
            return ['credit_note' => $cn ? (int) $cn : null, 'message' => 'Invoice cancelled. You can regenerate it from its settled month.'];
        });
    }

    /** R-D-84 reopen a settled month. */
    public static function reopen(int $ent, int $periodId, object $u): string
    {
        return DB::transaction(function () use ($ent, $periodId, $u) {
            $p = DB::table('t_inv_periods')->where('id', $periodId)->where('fk_id_ent', $ent)->lockForUpdate()->first();
            if (! $p || $p->status !== 'CONFIRMED') throw new \InvalidArgumentException('Settlement not found or already reopened.');
            $act = DB::table('t_invoices')->where('fk_id_period', $periodId)->whereIn('status', ['UNPAID', 'PART_PAID', 'PAID'])->orderBy('id')->first();
            if ($act) throw new \InvalidArgumentException("Invoice {$act->invoice_no} is still active. Cancel all invoices of this month first.");
            if (DB::table('t_inv_periods')->where('fk_id_branch', $p->fk_id_branch)->where('fk_id_marketplace', $p->fk_id_marketplace)->where('status', 'CONFIRMED')->where('d_from', '>', $p->d_to)->exists())
                throw new \InvalidArgumentException('A later month is already settled for this shop. Reopen the latest month first (loss carry-forward depends on it).');
            DB::table('t_invoices')->where('fk_id_period', $periodId)->where('status', 'NOT_REQUIRED')->where('invoice_type', '<>', 'CREDIT_NOTE')->update(['status' => 'CANCELLED', 'cancel_reason' => 'Settlement reopened', 'cancelled_by' => $u->id, 'cancelled_at' => now()]);
            DB::table('t_inv_periods')->where('id', $periodId)->update(['status' => 'UNCONFIRMED', 'unconfirmed_by' => $u->id, 'unconfirmed_at' => now()]);
            Audit::log('t_inv_periods', $periodId, 'UPDATE', ['status' => ['CONFIRMED', 'UNCONFIRMED']], 'settlement reopened', $ent);
            return 'Settlement reopened. The sales stay confirmed; settle the month again when ready.';
        });
    }

    /** The printable document (also the PDF and the e-mail attachment). */
    public static function html(int $id): string
    {
        $d = DB::table('t_invoices')->where('id', $id)->first(); $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES); $is = json_decode((string) $d->issuer_json, true) ?: []; $rc = json_decode((string) $d->recipient_json, true) ?: [];
        $rows = DB::table('t_invoice_lines')->where('fk_id_invoice', $id)->orderBy('line_no')->get()->map(fn ($l) => '<tr' . ($l->line_group === 'RETURN' ? ' style="color:#b91c1c"' : '') . '><td>' . $e($l->description) . '</td><td style="text-align:right">' . rtrim(rtrim(number_format((float) $l->qty, 2, '.', ''), '0'), '.') . '</td><td style="text-align:right">£' . number_format((float) $l->amount, 2) . '</td></tr>')->implode('');
        return '<div style="font-family:Arial,sans-serif;font-size:12px"><h2>' . $e(self::TYPES[$d->invoice_type][1] ?? 'INVOICE') . ' ' . $e($d->invoice_no) . '</h2><p><b>From</b> ' . $e($is['company'] ?? $is['display_name'] ?? '') . '<br><b>To</b> ' . $e($rc['company'] ?? $rc['display_name'] ?? '')
            . '<br><b>Date</b> ' . $e(date('d-m-Y', strtotime((string) $d->d_invoice))) . ($d->d_due ? ' · <b>Due</b> ' . $e(date('d-m-Y', strtotime((string) $d->d_due))) : '') . '<br><b>Reference</b> ' . $e($d->reference) . '</p>'
            . '<table border="1" cellpadding="4" cellspacing="0" width="100%"><tr><th align="left">Description</th><th>Qty</th><th>Amount</th></tr>' . $rows . '<tr><td colspan="2" align="right"><b>Total</b></td><td align="right"><b>£' . number_format((float) $d->total, 2) . '</b></td></tr></table>'
            . (($is['bank']['account_no'] ?? null) ? '<p>Bank: ' . $e($is['bank']['account_name'] ?? '') . ' · ' . $e($is['bank']['sort_code'] ?? '') . ' ' . $e($is['bank']['account_no']) . '</p>' : '') . '</div>';
    }

    /** E-mail a document (queued; PDF attached when the PDF maker works). */
    public static function email(int $ent, int $id, ?string $to, object $u): string
    {
        $d = DB::table('t_invoices')->where('id', $id)->where('fk_id_ent', $ent)->first();
        if (! $d || in_array($d->status, ['CANCELLED', 'CREDITED'], true)) throw new \InvalidArgumentException('Invoice not found or cancelled.');
        $rc = json_decode((string) $d->recipient_json, true) ?: []; $is = json_decode((string) $d->issuer_json, true) ?: [];
        $to = trim((string) ($to ?: ($rc['email'] ?? '')));
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('The recipient has no valid e-mail address. Add it in Invoicing > Parties.');
        $file = null;
        if (class_exists(PrintService::$maker)) { try { $file = 'invoices/' . $d->invoice_no . '.pdf'; Storage::disk('local')->put($file, PrintService::pdf(self::html($id), 210, 297)); } catch (\Throwable) { $file = null; } }
        $label = self::TYPES[$d->invoice_type][0] ?? 'Invoice';
        $body = 'Dear ' . ($rc['display_name'] ?? 'Sir or Madam') . ",\n\nPlease find attached {$d->invoice_no} for £" . number_format((float) $d->total, 2) . ($d->d_due ? ', due on ' . date('d-m-Y', strtotime((string) $d->d_due)) : '') . ".\nReference: {$d->reference}\n\nKind regards,\n" . ($is['company'] ?? $is['display_name'] ?? '');
        Connect\Messenger::queueEmail($ent, null, $to, "$label {$d->invoice_no} - £" . number_format((float) $d->total, 2), $body, $file, 'INVOICE_EMAIL', 't_invoices', $id);
        DB::table('t_invoices')->where('id', $id)->update(['sent_at' => now(), 'sent_to' => $to, 'sent_by' => $u->id]);
        return 'Invoice e-mailed.';
    }

    /** The list page. */
    public static function page(int $ent, array $f = []): array
    {
        $q = DB::table('t_invoices as i')->leftJoin('t_inv_periods as p', 'p.id', '=', 'i.fk_id_period')->leftJoin('t_branches as b', 'b.id', '=', 'p.fk_id_branch')->where('i.fk_id_ent', $ent)
            ->when(! empty($f['period']), fn ($x) => $x->where('i.fk_id_period', (int) $f['period']))->when(! empty($f['status']), fn ($x) => $x->where('i.status', $f['status']))->orderByDesc('i.id')->limit(300);
        $rows = $q->get(['i.*', 'b.shop_name', 'p.d_to'])->map(fn ($d) => ['id' => (int) $d->id, 'no' => $d->invoice_no, 'type' => self::TYPES[$d->invoice_type][0] ?? $d->invoice_type, 'type_code' => $d->invoice_type, 'shop' => $d->shop_name, 'month' => $d->d_to ? date('M Y', strtotime((string) $d->d_to)) : '',
            'date' => $d->d_invoice, 'due' => $d->d_due, 'total' => (float) $d->total, 'paid' => (float) $d->amount_paid, 'status' => $d->status, 'badge' => self::badge($d), 'sent' => $d->sent_at, 'reference' => $d->reference])->all();
        $periods = DB::table('t_inv_periods as p')->leftJoin('t_branches as b', 'b.id', '=', 'p.fk_id_branch')->where('p.fk_id_ent', $ent)->orderByDesc('p.d_to')->limit(60)->get(['p.id', 'p.status', 'p.d_to', 'b.shop_name'])
            ->map(fn ($p) => ['id' => (int) $p->id, 'name' => ($p->shop_name ?: 'Shop') . ' · ' . date('M Y', strtotime((string) $p->d_to)), 'status' => $p->status, 'documents' => DB::table('t_invoices')->where('fk_id_period', $p->id)->whereNotIn('status', ['CANCELLED', 'CREDITED'])->count()])->all();
        return ['rows' => $rows, 'periods' => $periods, 'methods' => self::METHODS];
    }
}
