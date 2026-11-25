<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-C-8 look-alike check before a new person is saved and before a mobile / e-mail is added to an existing one.
 * Mobile: digits only, numbers under 6 digits skipped, the last 9 digits compared with every mobile of the company (active and inactive, any person kind).
 * E-mail: LOWER(TRIM()) equal. Name: similarity ≥ 0.85. One person is listed once with all reasons.
 * "Save anyway" needs a reason; it is written to the history and the pair joins the merge-scan hints.
 */
class LookAlikes
{
    public const NAME_MIN = 0.85;

    private static function tail(string $mobile): ?string { $d = preg_replace('/\D/', '', $mobile); return strlen($d) < 6 ? null : substr($d, -9); }

    /** [[id, code, name, kind M/E, reasons[], open], …] */
    public static function find(?string $name, array $emails = [], array $mobiles = [], ?int $notAccount = null, ?int $ent = null): array
    {
        $ent ??= (int) Context::ent(); $hits = []; $add = function (int $id, string $why) use (&$hits, $notAccount) { if ($id === (int) $notAccount) return; $hits[$id][$why] = true; };
        $acc = fn () => DB::table('t_account')->where('fk_id_ent', $ent);
        foreach (array_filter(array_map(fn ($m) => self::tail((string) $m), $mobiles)) as $i => $t) {
            foreach (DB::table('t_account_mobiles as m')->join('t_account as a', 'a.id', '=', 'm.fk_id_account')->where('a.fk_id_ent', $ent)->whereRaw("REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(m.mobile_no, ' ', ''), '-', ''), '(', ''), ')', ''), '+', '') LIKE ?", ['%' . $t])->limit(50)->pluck('m.mobile_no', 'm.fk_id_account') as $aid => $no)
                if (self::tail((string) $no) === $t) $add((int) $aid, 'mobile ' . $no);
        }
        foreach (array_filter(array_map(fn ($e) => mb_strtolower(trim((string) $e)), $emails)) as $em) {
            foreach (DB::table('t_account_emails as e')->join('t_account as a', 'a.id', '=', 'e.fk_id_account')->where('a.fk_id_ent', $ent)->whereRaw('LOWER(TRIM(e.email)) = ?', [$em])->limit(50)->pluck('e.fk_id_account') as $aid) $add((int) $aid, 'email ' . $em);
        }
        $n = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $name)));
        if (mb_strlen($n) >= 4) foreach ($acc()->limit(20000)->get(['id', 'first_name', 'sur_name']) as $a) {
            $o = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $a->first_name . ' ' . $a->sur_name))); if ($o === '') continue;
            similar_text($n, $o, $pct); if ($pct / 100 >= self::NAME_MIN) $add((int) $a->id, 'name');
        }
        if (! $hits) return [];
        return $acc()->whereIn('id', array_keys($hits))->orderBy('id')->get(['id', 'account_code', 'first_name', 'sur_name', 'l_account_kind'])->map(fn ($a) => ['id' => (int) $a->id, 'code' => $a->account_code, 'name' => trim($a->first_name . ' ' . $a->sur_name),
            'kind' => strtoupper(substr((string) $a->l_account_kind, 0, 1)) === 'E' ? 'E' : 'M', 'reasons' => array_keys($hits[(int) $a->id]), 'open' => '#/rec/' . (RecordService::formOfTable('t_account') ?: 't_account') . '/' . $a->id])->all();
    }

    /** Refuse (LookAlikeFound) unless a reason is given; with a reason: history + merge-scan hint. Returns the matches that were overridden. */
    public static function guard(array $matches, ?string $reason): array
    {
        if (! $matches) return [];
        if ($reason === null) throw new LookAlikeFound($matches);
        if (mb_strlen(trim($reason)) < 3) throw new \InvalidArgumentException('Say why it is saved anyway.');
        return $matches;
    }

    /** After the save: the reason in the history of the new person, and each pair as a hint for the merge scan. */
    public static function logged(int $accountId, array $matches, string $reason): void
    {
        Audit::log('t_account', $accountId, 'UPDATE', ['saved despite look-alikes' => implode('; ', array_map(fn ($m) => $m['name'] . ' (' . implode(', ', $m['reasons']) . ')', $matches))], $reason, (int) Context::ent());
        foreach ($matches as $m) if ((int) $m['id'] !== $accountId && ! DB::table('sys_merge_map')->where('entity', 'PARTY')->where('source_id', $accountId)->where('master_id', $m['id'])->exists())
            DB::table('sys_merge_map')->insert(RecordService::fillNotNull('sys_merge_map', ['entity' => 'PARTY', 'source_table' => 't_account', 'source_id' => $accountId, 'master_table' => 't_account', 'master_id' => $m['id'], 'match_score' => 0.85, 'status' => 'SUGGESTED']));
    }
}
