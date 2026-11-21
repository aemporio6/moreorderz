<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Security questions per provider (R-C-22).
 * - Each kit item has a block of 6 question / answer pairs; the question comes from the provider's list (Chains), the answer is a secret (vault).
 * - E-mails have 3 free-text question / answer pairs.
 * - The same question is not used twice on one record ("Already used in question N"); an empty question is stored as NULL (old 0).
 * - Health on the person: "Questions set n/6" per kit item (n/3 for e-mails).
 */
class SecurityQuestions
{
    /** kit table => [label column(s), number of pairs, question column prefix, answer column prefix, title] */
    public const KIT = [
        't_account_banks' => ['account_no', 6, 'fk_question_id', 'answer', 'Bank account'], 't_account_credit_cards' => ['card_last4', 6, 'fk_question_id', 'answer', 'Card'],
        't_account_marketplaces' => ['fk_id_marketplace', 6, 'fk_question_id', 'answer', 'Marketplace account'], 't_account_paypal' => ['paypal_name', 6, 'fk_question_id', 'answer', 'PayPal'],
        't_account_company_houses' => ['fk_company_house_id', 6, 'fk_question_id', 'answer', 'Company House link'], 't_account_social_media' => ['username', 6, 'fk_question_id', 'answer', 'Social media'],
        't_account_subscriptions' => ['username', 6, 'fk_question_id', 'answer', 'Subscription'], 't_account_emails' => ['email', 3, 'question', 'ans', 'E-mail'],
    ];

    public const FORM = ['t_account_banks' => 't_account_bank', 't_account_credit_cards' => 't_account_credit_card', 't_account_marketplaces' => 't_account_marketplace', 't_account_paypal' => 't_account_paypal', 't_account_company_houses' => 't_account_company_house', 't_account_social_media' => 't_account_social_media', 't_account_subscriptions' => 't_account_subscription', 't_account_emails' => 't_account_email'];

    /** Form check: no question twice on the record, empty question → NULL. $data is changed in place. */
    public static function check(string $table, array &$data, array $old): array
    {
        if (! isset(self::KIT[$table])) return [];
        [, $n, $qp] = self::KIT[$table]; $seen = []; $e = [];
        for ($i = 1; $i <= $n; $i++) {
            $c = $qp . $i; if (! array_key_exists($c, $data) && ! array_key_exists($c, $old)) continue;
            $v = array_key_exists($c, $data) ? $data[$c] : $old[$c];
            if ($v === '' || $v === '0' || $v === 0 || $v === null) { if (array_key_exists($c, $data)) $data[$c] = null; continue; }
            $k = mb_strtolower(trim((string) $v));
            if (isset($seen[$k])) { $e[$c] = 'Already used in question ' . $seen[$k]; continue; }
            $seen[$k] = $i;
        }
        return $e;
    }

    /** How many pairs are set (question and answer both present) on one record. */
    public static function setCount(string $table, object $r): int
    {
        [, $n, $qp, $ap] = self::KIT[$table]; $c = 0;
        for ($i = 1; $i <= $n; $i++) {
            $q = $r->{$qp . $i} ?? null; $a = $r->{$ap . $i} ?? null;
            $hasA = $a !== null && $a !== '';      // a secret answer keeps its masked mark in the column (the value is in the vault)
            if ($q !== null && $q !== '' && $q !== 0 && $q !== '0' && $hasA) $c++;
        }
        return $c;
    }

    /** The person's kit items with "Questions set n/6". */
    public static function health(int $person): array
    {
        $out = [];
        foreach (self::KIT as $t => [$lc, $n, , , $title]) {
            if (! Registry::hasColumn($t, 'fk_id_account')) continue;
            foreach (DB::table($t)->where('fk_id_account', $person)->where(fn ($w) => Registry::hasColumn($t, 'f_active') ? $w->whereNull('f_active')->orWhere('f_active', 1) : $w)->orderBy('id')->get() as $r) {
                $label = (string) ($r->$lc ?? $r->id); if ($t === 't_account_banks') $label = '••' . substr(preg_replace('/\D/', '', $label), -4);
                if ($t === 't_account_marketplaces') $label = (string) (DB::table('cat_marketplaces')->where('id', $r->fk_id_marketplace)->value('marketplace_short') ?: $label);
                if ($t === 't_account_company_houses') $label = (string) (DB::table('cat_company_house')->where('id', $r->fk_company_house_id)->value('company_name') ?: $label);
                $set = self::setCount($t, $r);
                $out[] = ['table' => $t, 'form' => self::FORM[$t], 'id' => (int) $r->id, 'kind' => $title, 'label' => $label, 'set' => $set, 'of' => $n, 'text' => "Questions set $set/$n", 'complete' => $set === $n];
            }
        }
        return $out;
    }
}
