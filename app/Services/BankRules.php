<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-C-14 / R-C-15 the shared bank rules of every bank form (person, supplier, External Contact):
 * clean-up (IBAN and SWIFT: spaces removed, upper case; account no: spaces removed; sort code: trimmed), the old checks with the old messages,
 * and "+ New bank": the same name in the same country is reused, never added twice; a similar name in another country is named as a warning.
 */
class BankRules
{
    public const MSG = ['iban' => 'This IBAN does not look right. Please check it.', 'swift' => 'SWIFT/BIC is 8 or 11 letters/numbers, e.g. HSBCGB2L.', 'sort' => 'Sort Code: numbers, e.g. 04-06-05.',
        'account' => 'Account Number: letters and numbers only, 3 to 34 characters.', 'either' => 'Enter the Account Number or the IBAN.', 'currency' => 'Please choose a currency from the list.'];

    public static function ibanOk(string $iban): bool { return ExternalContact::ibanOk($iban); }

    /** Cleaned values of the posted bank fields (a masked value is left alone). */
    public static function clean(array $data): array
    {
        foreach (['iban_no', 'swift_code'] as $k) if (isset($data[$k]) && is_string($data[$k]) && ! Vault::isMasked($data[$k])) $data[$k] = strtoupper(preg_replace('/\s+/u', '', $data[$k]));
        if (isset($data['account_no']) && is_string($data['account_no']) && ! Vault::isMasked($data['account_no'])) $data['account_no'] = preg_replace('/\s+/u', '', $data['account_no']);
        if (isset($data['sort_branch_code']) && is_string($data['sort_branch_code'])) $data['sort_branch_code'] = trim($data['sort_branch_code']);
        return $data;
    }

    /** Errors of a bank row [field => old message]. $data = the posted (cleaned) values, $old = the stored row, $id = null on a new row. */
    public static function check(array $data, array $old, ?int $id): array
    {
        $e = []; $has = fn ($k) => isset($data[$k]) && $data[$k] !== '' && ! Vault::isMasked($data[$k]);
        if ($has('iban_no') && ! self::ibanOk((string) $data['iban_no'])) $e['iban_no'] = self::MSG['iban'];
        if ($has('swift_code') && ! preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', (string) $data['swift_code'])) $e['swift_code'] = self::MSG['swift'];
        if ($has('sort_branch_code') && ! preg_match('/^[0-9 \-]{4,12}$/', (string) $data['sort_branch_code'])) $e['sort_branch_code'] = self::MSG['sort'];
        if ($has('account_no') && ! preg_match('/^[A-Za-z0-9\-]{3,34}$/', (string) $data['account_no'])) $e['account_no'] = self::MSG['account'];
        $card = ! empty($data['card_number']) || ! empty($old['card_last4']) || ! empty($data['card_last4']);      /* a card row has no account number of its own */
        if (! $id && ! $card && empty($data['account_no']) && empty($data['iban_no'])) $e['account_no'] = self::MSG['either'];
        if (! empty($data['fk_currency_id']) && Registry::hasColumn('cat_currency', 'f_active') && DB::table('cat_currency')->where('id', (int) $data['fk_currency_id'])->where('f_active', 0)->exists()) $e['fk_currency_id'] = self::MSG['currency'];
        return $e;
    }

    public static function tidyName(string $name): string { return trim(preg_replace('/\s+/u', ' ', $name)); }

    /** "+ New bank": the bank of this name in this country (active first, lowest id), or null. */
    public static function existing(string $name, ?int $country): ?int
    {
        $name = self::tidyName($name); if ($name === '') return null;
        $id = DB::table('cat_banks')->whereRaw('LOWER(TRIM(bank_name)) = ?', [mb_strtolower($name)])->when($country, fn ($q) => $q->where('country_id', $country), fn ($q) => $q->where(fn ($w) => $w->whereNull('country_id')->orWhere('country_id', 0)))->orderByDesc('f_active')->orderBy('id')->value('id');
        return $id ? (int) $id : null;
    }

    /** A bank of the same name in another country (the warning of "+ New bank"). */
    public static function elsewhere(string $name, ?int $country): ?string
    {
        $name = self::tidyName($name); if ($name === '') return null;
        $r = DB::table('cat_banks as b')->leftJoin('cat_country as c', 'c.id', '=', 'b.country_id')->whereRaw('LOWER(TRIM(b.bank_name)) = ?', [mb_strtolower($name)])->where(fn ($w) => $w->where('b.country_id', '<>', (int) $country)->orWhereNull('b.country_id'))->orderBy('b.id')->first(['b.bank_name', 'c.*']);
        if (! $r) return null; $cn = $r->country_name ?? null;
        return 'A bank named "' . $r->bank_name . '" already exists' . ($cn ? " in $cn" : ' in another country') . ' – check the country before using a new one.';
    }
}
