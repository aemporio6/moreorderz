<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-FIN-3 Partner onboarding gate. A partner login works only when all six items are ticked (fin_onboarding, one row per partner):
 * master_entry · agreement_signed · id_proof · capital_received · scope_entered · login_created.
 * Ticks are set by the Owner (audited) or by events: scope_entered when an agreement is saved, capital_received when capital is booked
 * (capital in / a capital call received), login_created when a partner login is linked. Improved: master_entry, agreement_signed and id_proof
 * tick themselves when the party record, a signed agreement document and an ID document exist. A tick set by an event is never taken back.
 * A staff login can never be linked as a Partner Account.
 */
class PartnerOnboarding
{
    public const ITEMS = [
        'master_entry' => 'Investor Master entry',
        'agreement_signed' => 'agreement PDF signed + uploaded',
        'id_proof' => 'ID + proof of address',
        'capital_received' => 'capital received + bank line matched',
        'scope_entered' => 'scope + % entered',
        'login_created' => 'login created',
    ];
    public const STAFF_REFUSED = 'Refused: this user already has a staff role; a Partner Account must be a separate login.';

    /** What the records show today (the automatic ticks). */
    private static function derived(int $partner): array
    {
        $acc = DB::table('t_account')->where('id', $partner)->first(['id', 'f_active']);
        return [
            'master_entry' => (bool) ($acc && (int) $acc->f_active === 1),
            'agreement_signed' => DB::table('fin_investor_agreements')->where('investor_id', $partner)->whereNotNull('agreement_doc_path')->where('agreement_doc_path', '<>', '')->exists(),
            'id_proof' => DB::table('t_account_photo_ids')->where('fk_id_account', $partner)->where('f_active', 1)->exists() || DB::table('t_account_doc')->where('fk_id_account', $partner)->where('f_active', 1)->exists(),
            'capital_received' => DB::table('fin_investor_ledger')->where('investor_id', $partner)->where('type', 'CAPITAL_IN')->where('amount', '>', 0)->exists(),
            'scope_entered' => DB::table('fin_investor_agreements')->where('investor_id', $partner)->exists(),
            'login_created' => DB::table('fin_partner_users')->where('investor_id', $partner)->exists(),
        ];
    }

    /** Tick what the records now show (never untick); every new tick is audited. Returns the state. */
    public static function refresh(int $partner, ?string $why = null): array
    {
        $row = (array) (DB::table('fin_onboarding')->where('investor_id', $partner)->first() ?? []);
        $new = [];
        foreach (self::derived($partner) as $k => $on) if ($on && empty($row[$k])) $new[$k] = 1;
        if ($new) {
            if ($row) DB::table('fin_onboarding')->where('investor_id', $partner)->update($new + ['updated_at' => now()]);
            else DB::table('fin_onboarding')->insert(array_merge(['investor_id' => $partner], array_fill_keys(array_keys(self::ITEMS), 0), $new, ['updated_at' => now()]));      // array_merge: the new ticks win over the zeros
            Audit::log('fin_onboarding', $partner, 'UPDATE', array_map(fn () => [0, 1], $new), $why ?? 'Onboarding ticked by the records', (int) (Context::ent() ?? 0));
        }
        return self::state($partner);
    }

    public static function state(int $partner): array
    {
        $row = (array) (DB::table('fin_onboarding')->where('investor_id', $partner)->first() ?? []);
        $items = []; foreach (self::ITEMS as $k => $label) $items[] = ['key' => $k, 'label' => $label, 'done' => ! empty($row[$k])];
        $missing = array_values(array_map(fn ($i) => $i['label'], array_filter($items, fn ($i) => ! $i['done'])));
        return ['items' => $items, 'missing' => $missing, 'complete' => ! $missing, 'done' => 6 - count($missing)];
    }

    /** The Owner ticks / unticks one item (audited). */
    public static function tick(int $partner, string $item, bool $on): array
    {
        if (! isset(self::ITEMS[$item])) throw new \InvalidArgumentException('Unknown onboarding item.');
        if (! DB::table('t_account')->where('id', $partner)->exists()) throw new \InvalidArgumentException('Choose the partner.');
        if (! DB::table('fin_onboarding')->where('investor_id', $partner)->exists()) DB::table('fin_onboarding')->insert(['investor_id' => $partner] + array_fill_keys(array_keys(self::ITEMS), 0) + ['updated_at' => now()]);
        $old = (int) DB::table('fin_onboarding')->where('investor_id', $partner)->value($item);
        DB::table('fin_onboarding')->where('investor_id', $partner)->update([$item => (int) $on, 'updated_at' => now()]);
        if ($old !== (int) $on) Audit::log('fin_onboarding', $partner, 'UPDATE', [$item => [$old, (int) $on]], 'Ticked by the Owner', (int) (Context::ent() ?? 0));
        return self::state($partner);
    }

    /** The login gate: null = may sign in, else the refusal (old text). Only partner logins are gated. */
    public static function blocked(object $user): ?string
    {
        if (strtoupper((string) $user->role_code) !== 'PARTNER') return null;
        $p = PartnerService::partnerOfUser((int) $user->id);
        if (! $p) return 'Partner Account is blocked until onboarding is complete. Missing: ' . self::ITEMS['login_created'];
        $s = self::refresh($p);
        return $s['complete'] ? null : 'Partner Account is blocked until onboarding is complete. Missing: ' . implode(' · ', $s['missing']);
    }

    /** Linking a login as a Partner Account: never a staff login. */
    public static function checkLink(?int $userId): ?string
    {
        if (! $userId) return null;
        $role = strtoupper((string) DB::table('t_users')->where('id', $userId)->value('role_code'));
        return $role !== '' && $role !== 'PARTNER' ? self::STAFF_REFUSED : null;
    }
}
