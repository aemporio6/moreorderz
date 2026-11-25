<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * SUP_BANK (06_ENGINES §A; R-FIN-70; R-B_SOURCING-57 / 66): the bank details of a supplier, a forwarder or the payee of a supplier payment
 * are approved before they can be paid. A new row or a change of the paying details makes it PENDING:
 *   1. a second person (not the one who typed it) records "confirmed by phone with the supplier" – task ACC_BANK_PHONE_CHECK;
 *   2. then the Owner approves (approval SUP_BANK, high risk). Refused → REJECTED.
 * Payments to a supplier whose bank details are not approved are blocked. Staff / partner banks are outside (a normal history entry).
 */
class SupplierBanks
{
    /** a change of one of these is a change of where the money goes */
    public const PAY_FIELDS = ['account_no', 'iban_no', 'swift_code', 'sort_branch_code', 'fk_bank_id', 'beneficiary_name', 'fk_currency_id', 'fk_id_src_supplier'];
    public const ROLES = ['SUPPLIER', 'FORWARDER'];
    public const BLOCKED = 'The bank details of this supplier are not approved yet (phone check by a second person, then the Owner approves). The payment is blocked until then.';

    /** Is this bank row one of a supplier / forwarder / payee? */
    public static function inScope(array $row): bool
    {
        if (! empty($row['fk_id_src_supplier'])) return true;
        if (empty($row['fk_id_account'])) return false;
        return DB::table('t_account_in_types as i')->join('cat_account_types as t', 't.id', '=', 'i.fk_id_account_type')->where('i.fk_id_account', (int) $row['fk_id_account'])->where('i.f_active', 1)->whereIn(DB::raw('UPPER(t.role_code)'), self::ROLES)->exists();
    }

    /** RecordService, before the write: a new in-scope row, or a change of its paying details, becomes PENDING. Returns the extra columns. */
    public static function beforeSave(array $data, array $old, ?int $id): array
    {
        $row = $data + $old; if (! self::inScope($row)) return [];
        $changed = ! $id || (bool) array_filter(self::PAY_FIELDS, fn ($f) => array_key_exists($f, $data) && ! Vault::isMasked($data[$f]) && (string) ($data[$f] ?? '') !== (string) ($old[$f] ?? ''));
        if (! $changed) return [];
        return ['approval_status' => 'PENDING', 'bank_changed_by' => Context::userId(), 'bank_changed_at' => now(), 'phone_checked_by' => null, 'phone_checked_at' => null, 'phone_check_note' => null, 'bank_approved_by' => null, 'bank_approved_at' => null];
    }

    /** RecordService, after the write: the approval request and the phone-check task of a PENDING row. */
    public static function afterSave(int $id): void
    {
        $b = DB::table('t_account_banks')->where('id', $id)->first(); if (! $b || $b->approval_status !== 'PENDING') return;
        $ent = (int) Context::ent(); $who = self::label($b);
        if ($b->fk_id_bank_approval && DB::table('sys_approvals')->where('id', $b->fk_id_bank_approval)->where('status', 'PENDING')->exists()) { /* a change on top of a waiting change: the same request, the phone check starts again */ }
        else { $aid = Approvals::request($ent, 'SUP_BANK', 't_account_banks', $id, 0.0, ['bank' => $id, 'party' => $who], "Approve the bank details of $who (after the phone check)");
            DB::table('sys_approvals')->where('id', $aid)->update(['risk' => 'HIGH']); DB::table('t_account_banks')->where('id', $id)->update(['fk_id_bank_approval' => $aid]); }
        TaskEngine::open('ACC_BANK_PHONE_CHECK', [$id], "Phone the supplier to confirm the bank details of $who", ['ent' => $ent, 'module' => 't_account_banks', 'record_id' => $id, 'fix_url' => '#/approvals']);
    }

    private static function label(object $b): string
    {
        if ($b->fk_id_src_supplier && ($n = DB::table('t_src_suppliers')->where('id', $b->fk_id_src_supplier)->value('supplier_name'))) return (string) $n;
        $a = $b->fk_id_account ? DB::table('t_account')->where('id', $b->fk_id_account)->first(['first_name', 'sur_name']) : null;
        return $a ? trim($a->first_name . ' ' . $a->sur_name) : 'bank row ' . $b->id;
    }

    /** "Confirmed by phone with the supplier" – by a second person, never the one who typed the details. */
    public static function phoneCheck(int $bankId, string $note, object $u): array
    {
        $b = DB::table('t_account_banks')->where('id', $bankId)->first() ?? throw new \InvalidArgumentException('Bank details not found.');
        if ($b->approval_status !== 'PENDING') throw new \InvalidArgumentException('These bank details are not waiting for a check.');
        if ((int) $b->bank_changed_by === (int) $u->id) throw new \InvalidArgumentException('You typed these details yourself – a second person must phone the supplier.');
        if (trim($note) === '') throw new \InvalidArgumentException('Write who you spoke to and on which number.');
        DB::table('t_account_banks')->where('id', $bankId)->update(['phone_checked_by' => $u->id, 'phone_checked_at' => now(), 'phone_check_note' => mb_substr(trim($note), 0, 250)]);
        TaskEngine::close('ACC_BANK_PHONE_CHECK', [$bankId], 'Phone check recorded'); Audit::log('t_account_banks', $bankId, 'UPDATE', ['phone check' => mb_substr(trim($note), 0, 250)], null, (int) Context::ent());
        return ['message' => 'Phone check recorded. The Owner can now approve the bank details.'];
    }

    /** Before the Owner decides: an approval needs the phone check (a refusal does not). */
    public static function canDecide(object $a, bool $approve, ?object $u): void
    {
        if ($u && ($u->role_code ?? '') !== 'OWNER') throw new \InvalidArgumentException('Only the Owner approves the bank details of a supplier.');
        $b = DB::table('t_account_banks')->where('id', $a->entity_id)->first();
        if ($approve && (! $b || ! $b->phone_checked_at)) throw new \InvalidArgumentException('A second person must record the phone check with the supplier first.');
    }

    public static function decided(object $a, bool $ok, ?string $reason): void
    {
        DB::table('t_account_banks')->where('id', $a->entity_id)->update(['approval_status' => $ok ? 'APPROVED' : 'REJECTED', 'bank_approved_by' => $ok ? Context::userId() : null, 'bank_approved_at' => $ok ? now() : null]);
        TaskEngine::close('ACC_BANK_PHONE_CHECK', [(int) $a->entity_id], $ok ? 'Bank details approved' : 'Bank change rejected');
        Audit::log('t_account_banks', (int) $a->entity_id, $ok ? 'APPROVE' : 'REJECT', ['bank details' => $ok ? 'approved' : 'rejected'], $reason, (int) $a->fk_id_ent);
    }

    /** The supplier's bank details as the payment screen shows them: beneficiary, bank, last 4 digits, status. Never the full number. */
    public static function ofSupplier(int $supplierId): array
    {
        return DB::table('t_account_banks as b')->leftJoin('cat_banks as k', 'k.id', '=', 'b.fk_bank_id')->where('b.fk_id_src_supplier', $supplierId)->where(fn ($w) => $w->where('b.f_active', 1)->orWhereNull('b.f_active'))->orderBy('b.id')
            ->get(['b.id', 'b.beneficiary_name', 'k.bank_name', 'b.approval_status', 'b.phone_checked_at'])->map(function ($b) {
                $last4 = DB::table('sys_vault')->where('table_name', 't_account_banks')->where('record_id', $b->id)->where('column_name', 'account_no')->value('last4');
                return ['id' => (int) $b->id, 'beneficiary' => $b->beneficiary_name, 'bank' => $b->bank_name, 'last4' => $last4, 'status' => $b->approval_status, 'phone_checked' => (bool) $b->phone_checked_at]; })->all();
    }

    /** Payment gate: blocked while the supplier has bank details and none is approved, or one is waiting / rejected. No details recorded → Choice SUP_BANK_NO_DETAILS. */
    public static function assertPayable(int $ent, int $supplierId): void
    {
        $rows = self::ofSupplier($supplierId);
        if (! $rows) { if (Choices::get('SUP_BANK_NO_DETAILS', $ent) === 'BLOCK') throw new \RuntimeException('This supplier has no bank details recorded. Add them (they are approved after a phone check) before paying.'); return; }
        if (! array_filter($rows, fn ($r) => $r['status'] === 'APPROVED') || array_filter($rows, fn ($r) => $r['status'] === 'PENDING')) throw new \RuntimeException(self::BLOCKED);
    }
}
