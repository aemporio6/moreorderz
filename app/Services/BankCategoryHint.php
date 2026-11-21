<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-FIN-30 Category suggestion for a bank line – a chip, never applied by itself. First what was chosen before for the same payee
 * (sys_learned_choices, context BANK_CATEGORY), else the keyword seed (central list BANK_CATEGORY_HINT, editable; the old rules:
 * IN + amazon / amzn → payout; payoneer / ebay → payout when IN, platform fee when OUT; alibaba / supplier → supplier payment;
 * "ltd" only below −500 → supplier payment; hmrc / vat / duty → duty & VAT; freight / shipping / dhl / maersk → freight;
 * salary / upwork → VA salary). Only a category of the line's direction is offered. Spec names: MARKETPLACE_PAYOUT = SALES_PAYOUT.
 */
class BankCategoryHint
{
    public const LIST = 'BANK_CATEGORY_HINT';
    public const CONTEXT = 'BANK_CATEGORY';

    /** The payee key of a line: counterparty, else the first words of the description (no digits, no punctuation). */
    public static function key(object $l): string
    {
        $t = mb_strtolower(trim((string) ($l->counterparty ?: $l->description)));
        $t = preg_replace('/[^a-z ]+/u', ' ', $t); $w = array_slice(array_values(array_filter(explode(' ', $t), fn ($x) => strlen($x) >= 2)), 0, 3);
        return mb_substr(implode(' ', $w), 0, 120);
    }

    /** "IN:SALES_PAYOUT|OUT:PLATFORM_FEE", "OUT<-500:SUPPLIER_PAYMENT" or "FREIGHT" → the code for this direction / amount, or null. */
    private static function pick(string $rule, string $dir, float $amount): ?string
    {
        foreach (explode('|', $rule) as $part) {
            if (! str_contains($part, ':')) return $part;
            [$cond, $code] = explode(':', $part, 2);
            if (preg_match('/^(IN|OUT)(?:<(-?[\d.]+))?$/', $cond, $m) && $m[1] === $dir && (! isset($m[2]) || $m[2] === '' || $amount < (float) $m[2])) return $code;
        }
        return null;
    }

    /** ['code' => …, 'name' => …, 'why' => 'learned' | 'keyword "dhl"'] or null. */
    public static function suggest(int $ent, object $l): ?array
    {
        $dir = (float) $l->amount >= 0 ? 'IN' : 'OUT'; $cats = DB::table('fin_categories')->where('f_active', 1)->get(['code', 'name', 'direction'])->keyBy('code');
        $ok = fn (?string $c) => $c && isset($cats[$c]) && in_array($cats[$c]->direction, [$dir, 'BOTH'], true);
        $key = self::key($l);
        if ($key !== '' && ($c = DB::table('sys_learned_choices')->where('fk_id_ent', $ent)->where('context', self::CONTEXT . ':' . $dir)->where('input_key', $key)->orderByDesc('times')->orderByDesc('last_at')->value('chosen_value')) && $ok($c))
            return ['code' => $c, 'name' => $cats[$c]->name, 'why' => 'chosen before for "' . $key . '"'];
        $text = ' ' . mb_strtolower($l->description . ' ' . $l->counterparty) . ' ';
        foreach (DB::table('sys_field_options')->where('list_code', self::LIST)->where('f_active', 1)->orderBy('sort')->orderBy('id')->get(['value', 'label']) as $k) {
            if (! preg_match('/(?<![a-z])' . preg_quote(mb_strtolower($k->value), '/') . '(?![a-z])/u', $text)) continue;
            $c = self::pick((string) $k->label, $dir, (float) $l->amount); if ($ok($c)) return ['code' => $c, 'name' => $cats[$c]->name, 'why' => 'keyword "' . $k->value . '"'];
        }
        return null;
    }

    /** After a line is booked by hand: remember payee → category. */
    public static function learn(int $ent, object $l, string $code): void
    {
        $key = self::key($l); if ($key === '') return; $ctx = self::CONTEXT . ':' . ((float) $l->amount >= 0 ? 'IN' : 'OUT');
        $w = ['fk_id_ent' => $ent, 'context' => $ctx, 'input_key' => $key, 'chosen_value' => $code];
        if (DB::table('sys_learned_choices')->where($w)->exists()) DB::table('sys_learned_choices')->where($w)->update(['times' => DB::raw('times + 1'), 'last_at' => now()]);
        else DB::table('sys_learned_choices')->insert($w + ['times' => 1, 'last_at' => now()]);
    }
}
