<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Marketplace master and its questions (RULES_A_SALES R-A_SALES-68). The master keeps the identity of a marketplace (short name, full name, country, ISO code, contact, typical fee %);
 * payout timing lives in the marketplace profile. The questions of a marketplace are a checklist on each of its shops.
 */
class Marketplaces
{
    public const PAYOUT = ['Daily', 'Weekly', 'Monthly'];

    private static function text(mixed $v): string { $s = trim((string) $v); return $s === '' ? '' : mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1); }      // trimmed, first letter upper-case

    /** Is this ISO code free? (the screen asks while the person types) */
    public static function isoFree(string $iso, ?int $exceptId = null): bool
    {
        return ! DB::table('cat_marketplaces')->whereIn('fk_id_ent', [0, (int) Context::ent()])->whereRaw('UPPER(TRIM(iso_code)) = ?', [mb_strtoupper(trim($iso))])->when($exceptId, fn ($q) => $q->where('id', '<>', $exceptId))->exists();
    }

    public static function save(array $d, ?int $id, ?object $u): array
    {
        Perm::need($u, 'setup.marketplaces'); $ent = (int) Context::ent();
        $short = self::text($d['short'] ?? ''); $full = self::text($d['full'] ?? ''); $iso = mb_strtoupper(trim((string) ($d['iso'] ?? ''))); $country = (int) ($d['country'] ?? 0);
        if ($short === '') throw new \InvalidArgumentException('Short Name is required'); if ($full === '') throw new \InvalidArgumentException('Full Name is required');
        if (! $country || ! DB::table('cat_country')->where('id', $country)->exists()) throw new \InvalidArgumentException('Country is required');
        if ($iso === '') throw new \InvalidArgumentException('ISO Code is required');
        if (! preg_match('/^[A-Z0-9]{2,10}$/', $iso)) throw new \InvalidArgumentException('ISO Code must be 2 to 10 letters or digits, for example EBAY.');
        if (! self::isoFree($iso, $id)) throw new \InvalidArgumentException('This ISO code already exists. Please add another');
        $payout = in_array($d['payout'] ?? 'Daily', self::PAYOUT, true) ? ($d['payout'] ?? 'Daily') : throw new \InvalidArgumentException('Payout must be Daily, Weekly or Monthly.');
        $fee = $d['fee_pct'] ?? null; if ($fee !== null && $fee !== '' && (! is_numeric($fee) || (float) $fee < 0 || (float) $fee > 100)) throw new \InvalidArgumentException('Typical fee % must be a number from 0 to 100.');
        foreach (['email1', 'email2'] as $e) if (trim((string) ($d[$e] ?? '')) !== '' && ! filter_var(trim((string) $d[$e]), FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('Enter a correct e-mail address.');
        $row = ['marketplace_short' => mb_substr($short, 0, 50), 'marketplace_descr' => mb_substr($full, 0, 200), 'iso_code' => $iso, 'country_id' => $country, 'marketplace_payout' => $payout, 'f_active' => (int) (bool) ($d['f_active'] ?? 1), 'fee_pct' => $fee === null || $fee === '' ? null : round((float) $fee, 2),
            'telephone1' => mb_substr(trim((string) ($d['telephone1'] ?? '')), 0, 50), 'telephone2' => mb_substr(trim((string) ($d['telephone2'] ?? '')), 0, 50), 'telephone3' => mb_substr(trim((string) ($d['telephone3'] ?? '')), 0, 50),
            'email1' => mb_strtolower(trim((string) ($d['email1'] ?? ''))), 'email2' => mb_strtolower(trim((string) ($d['email2'] ?? ''))), 'address' => mb_substr(self::text($d['address'] ?? ''), 0, 255)];
        if ($id) { $old = DB::table('cat_marketplaces')->where('id', $id)->whereIn('fk_id_ent', [0, $ent])->first() ?? throw new \InvalidArgumentException('Marketplace not found.'); DB::table('cat_marketplaces')->where('id', $id)->update($row); Audit::log('cat_marketplaces', $id, 'UPDATE', array_diff_assoc(array_map('strval', $row), array_map('strval', array_intersect_key((array) $old, $row)))); }
        else { $id = (int) DB::table('cat_marketplaces')->insertGetId(RecordService::fillNotNull('cat_marketplaces', $row + ['fk_id_ent' => $ent])); Audit::log('cat_marketplaces', $id, 'INSERT', $row); Profiles::ensureForMarketplace($id); }
        return ['message' => 'Marketplace saved.', 'id' => $id];
    }

    /** "+ New" next to Country: the name is added to the country list once (the same name returns the one that exists). */
    public static function addCountry(string $name, ?object $u): array
    {
        Perm::need($u, 'setup.marketplaces'); $name = self::text($name); if ($name === '') throw new \InvalidArgumentException('Country name is required'); if (mb_strlen($name) > 100) throw new \InvalidArgumentException('The country name can have up to 100 characters.');
        $id = DB::table('cat_country')->whereRaw('LOWER(country_name) = ?', [mb_strtolower($name)])->value('id') ?: DB::table('cat_country')->insertGetId(RecordService::fillNotNull('cat_country', ['country_name' => $name, 'f_active' => 1, 'fk_id_ent' => (int) Context::ent()]));
        return ['message' => 'Country added.', 'id' => (int) $id, 'name' => $name];
    }

    public static function list(?object $u): array
    {
        $ent = (int) Context::ent();
        $rows = DB::table('cat_marketplaces as m')->leftJoin('cat_country as c', 'c.id', '=', 'm.country_id')->whereIn('m.fk_id_ent', [0, $ent])->orderBy('m.marketplace_short')->orderBy('m.id')
            ->get(['m.*', 'c.country_name'])->map(fn ($m) => ['id' => (int) $m->id, 'short' => (string) $m->marketplace_short, 'full' => (string) $m->marketplace_descr, 'iso' => (string) $m->iso_code, 'country' => (int) $m->country_id, 'country_name' => $m->country_name, 'payout' => $m->marketplace_payout, 'f_active' => (int) $m->f_active,
                'fee_pct' => $m->fee_pct === null ? null : (float) $m->fee_pct, 'telephone1' => $m->telephone1, 'telephone2' => $m->telephone2, 'telephone3' => $m->telephone3, 'email1' => $m->email1, 'email2' => $m->email2, 'address' => $m->address,
                'questions' => DB::table('marketplace_questions')->where('fk_marketplace_id', $m->id)->count(), 'shops' => DB::table('t_branches')->where('fk_id_ent', Context::ent())->where('fk_id_marketplace', $m->id)->count()])->all();
        return ['rows' => $rows, 'countries' => DB::table('cat_country')->orderBy('country_name')->get(['id', 'country_name as name'])->all(), 'payouts' => self::PAYOUT, 'can_edit' => $u && Perm::has($u, 'setup.marketplaces'),
            'shops' => array_map(fn ($s) => ['id' => $s['id'], 'name' => $s['label_mp'], 'marketplace' => $s['marketplace']], ShopLabel::list($u))];
    }

    // ---------- questions of a marketplace ----------
    private static function mp(int $id): object { return DB::table('cat_marketplaces')->where('id', $id)->whereIn('fk_id_ent', [0, (int) Context::ent()])->first() ?? throw new \InvalidArgumentException('Marketplace not found.'); }

    public static function questions(int $mpId): array
    {
        $m = self::mp($mpId); $name = trim((string) ($m->marketplace_short ?: $m->marketplace_descr)) ?: 'Marketplace ' . $m->id;
        return ['title' => "Questions List for ($name)", 'empty' => 'No questions found.', 'rows' => DB::table('marketplace_questions')->where('fk_marketplace_id', $mpId)->orderBy('question')->get(['id', 'question'])->map(fn ($q) => (array) $q)->all()];      // sorted A–Z
    }

    public static function saveQuestion(int $mpId, string $text, ?int $id, ?object $u): array
    {
        Perm::need($u, 'setup.marketplaces'); self::mp($mpId); $text = trim($text); if ($text === '') throw new \InvalidArgumentException('Question is required'); if (mb_strlen($text) > 200) throw new \InvalidArgumentException('A question can have up to 200 characters.');
        if (DB::table('marketplace_questions')->where('fk_marketplace_id', $mpId)->whereRaw('LOWER(question) = ?', [mb_strtolower($text)])->when($id, fn ($q) => $q->where('id', '<>', $id))->exists()) throw new \InvalidArgumentException('This question is already in the list.');
        if ($id) { if (! DB::table('marketplace_questions')->where('id', $id)->where('fk_marketplace_id', $mpId)->update(['question' => $text]) && ! DB::table('marketplace_questions')->where('id', $id)->where('fk_marketplace_id', $mpId)->exists()) throw new \InvalidArgumentException('Question not found.'); }
        else $id = (int) DB::table('marketplace_questions')->insertGetId(['fk_marketplace_id' => $mpId, 'question' => $text]);
        Audit::log('marketplace_questions', $id, 'UPDATE', ['question' => $text]); return ['message' => 'Question saved.', 'id' => $id] + self::questions($mpId);
    }

    public static function deleteQuestion(int $mpId, int $id, ?object $u): array
    {
        Perm::need($u, 'setup.marketplaces'); self::mp($mpId); DB::table('marketplace_questions')->where('id', $id)->where('fk_marketplace_id', $mpId)->delete(); DB::table('mo_question_answers')->where('fk_id_question', $id)->delete(); Audit::log('marketplace_questions', $id, 'DELETE', []);
        return ['message' => 'Question removed.'] + self::questions($mpId);
    }

    // ---------- the questions as a checklist on a shop ----------
    public static function checklist(int $shopId, ?object $u): array
    {
        $shop = DB::table('t_branches')->where('id', $shopId)->where('fk_id_ent', Context::ent())->first() ?? throw new \InvalidArgumentException('Shop not found.'); if (! Perm::shopAllowed($u, $shopId)) abort(403, "You don't have rights for this shop.");
        $ans = DB::table('mo_question_answers as a')->leftJoin('t_users as x', 'x.id', '=', 'a.done_by')->where('a.fk_id_branch', $shopId)->get(['a.*', 'x.display_name'])->keyBy('fk_id_question');
        $rows = DB::table('marketplace_questions')->where('fk_marketplace_id', $shop->fk_id_marketplace)->orderBy('question')->get()->map(fn ($q) => ['id' => (int) $q->id, 'question' => $q->question, 'done' => (bool) ($ans[$q->id]->f_done ?? 0), 'note' => $ans[$q->id]->note ?? null, 'by' => $ans[$q->id]->display_name ?? null, 'at' => isset($ans[$q->id]->done_at) ? date('d-m-Y', strtotime((string) $ans[$q->id]->done_at)) : null])->all();
        $done = count(array_filter($rows, fn ($r) => $r['done']));
        return ['shop' => $shopId, 'shop_label' => SalesImport::label($shop), 'rows' => $rows, 'done' => $done, 'total' => count($rows), 'text' => count($rows) ? "$done of " . count($rows) . ' done' : 'No questions found.', 'can_tick' => $u && Perm::has($u, 'orders.SEE')];
    }

    public static function tick(int $shopId, int $questionId, bool $done, ?string $note, ?object $u): array
    {
        $shop = DB::table('t_branches')->where('id', $shopId)->where('fk_id_ent', Context::ent())->first() ?? throw new \InvalidArgumentException('Shop not found.'); if (! Perm::shopAllowed($u, $shopId)) abort(403, "You don't have rights for this shop.");
        if (! DB::table('marketplace_questions')->where('id', $questionId)->where('fk_marketplace_id', $shop->fk_id_marketplace)->exists()) throw new \InvalidArgumentException('This question does not belong to the marketplace of the shop.');
        DB::table('mo_question_answers')->updateOrInsert(['fk_id_branch' => $shopId, 'fk_id_question' => $questionId], ['f_done' => (int) $done, 'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 255) : null, 'done_by' => $u->id ?? null, 'done_at' => now()]);
        Audit::log('t_branches', $shopId, 'UPDATE', ['checklist' => $questionId, 'done' => $done]);
        return self::checklist($shopId, $u);
    }

    /** The typical fee % of a marketplace – only a hint next to the import fee, never a second fee source. */
    public static function feeHint(int $mpId): ?float { $v = DB::table('cat_marketplaces')->where('id', $mpId)->value('fee_pct'); return $v === null ? null : (float) $v; }
}
