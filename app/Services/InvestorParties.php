<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Investor ids are company parties (R-D-80, K4).
 * Old `t_product_investors.fk_id_account` held Companies House ids (cat_company_house), while the old invoicing engine read the same numbers as person ids.
 * Migration maps each to the company party (t_account party type C + t_party_profile linked to the Companies House row) and keeps the directors
 * and their share % as the party's director links. An id that matches no company, or that a settled period (t_inv_period_investors) names while the
 * person account of the same number is someone else, goes to the review list "Unmatched investor ids" – never guessed as a person.
 * The product Investors picker lists company parties as "Company – directors (share %)"; saving is an upsert of unique ids > 0.
 */
class InvestorParties
{
    /** The company party of a Companies House row (made once, with its directors). */
    public static function partyOf(int $chId): ?int
    {
        $ch = DB::table('cat_company_house')->where('id', $chId)->first(); if (! $ch) return null;
        $p = DB::table('t_party_profile')->where('fk_id_company_house', $chId)->value('fk_id_account');
        if (! $p) {
            $p = (int) DB::table('t_account')->insertGetId(RecordService::fillNotNull('t_account', ['fk_id_ent' => (int) ($ch->fk_id_ent ?: Context::ent()), 'l_party_type' => 'C', 'first_name' => mb_substr((string) $ch->company_name, 0, 100), 'f_active' => 1]));
            DB::table('t_party_profile')->insert(['fk_id_account' => $p, 'company_name' => $ch->company_name, 'company_reg_no' => $ch->registration_no, 'vat_no' => $ch->vat_no, 'fk_id_company_house' => $chId, 'updated_at' => now()]);
            Audit::log('t_account', $p, 'INSERT', ['company party of' => $chId], 'Investor id mapping (R-D-80)');
        }
        foreach ([1, 2, 3] as $i) if ($ch->{"fk_director_$i"})
            DB::table('t_party_directors')->updateOrInsert(['fk_id_party' => $p, 'position' => $i], ['fk_id_director' => (int) $ch->{"fk_director_$i"}, 'share_pct' => $ch->{"director_{$i}_share"} !== null && $ch->{"director_{$i}_share"} !== '' ? (float) $ch->{"director_{$i}_share"} : null, 'created_at' => now()]);
        return (int) $p;
    }

    /** Map the old rows. $dry = report only. Idempotent: mapped rows are skipped. */
    public static function migrate(bool $dry = true): array
    {
        $rows = DB::table('t_product_investors as i')->leftJoin('t_product_vendors as p', 'p.id', '=', 'i.fk_id_product')->whereNull('i.fk_id_party')->where(fn ($w) => $w->whereNull('i.map_status')->orWhere('i.map_status', '<>', 'REVIEW'))->get(['i.id', 'i.fk_id_product', 'i.fk_id_account', 'p.SKU_VENDOR as sku']);
        $mapped = []; $review = [];
        foreach ($rows as $r) {
            $id = (int) $r->fk_id_account; $ch = DB::table('cat_company_house')->where('id', $id)->first(['id', 'company_name']);
            $settled = DB::table('t_inv_period_investors')->where('fk_id_investor_account', $id)->exists();
            $person = trim((string) DB::table('t_account')->where('id', $id)->selectRaw("CONCAT_WS(' ', first_name, sur_name) n")->value('n'));
            if (! $ch) { $review[] = ['row' => (int) $r->id, 'sku' => $r->sku, 'old_id' => $id, 'why' => 'No company with this id']; if (! $dry) DB::table('t_product_investors')->where('id', $r->id)->update(['map_status' => 'REVIEW', 'map_note' => 'No company with this id']); continue; }
            $clash = $settled && $person !== '' && mb_strtolower($person) !== mb_strtolower((string) $ch->company_name) ? "A settled period names investor $id; person $id is “{$person}”, company $id is “{$ch->company_name}” – mapped to the company, check the old settlement" : null;
            if ($clash) $review[] = ['row' => (int) $r->id, 'sku' => $r->sku, 'old_id' => $id, 'why' => $clash];
            $mapped[] = ['row' => (int) $r->id, 'sku' => $r->sku, 'old_id' => $id, 'company' => $ch->company_name];
            if (! $dry) { $party = self::partyOf($id); DB::table('t_product_investors')->where('id', $r->id)->update(['fk_id_party' => $party, 'map_status' => $clash ? 'REVIEW' : 'MAPPED', 'map_note' => $clash ? mb_substr($clash, 0, 255) : null]); }
        }
        if (! $dry && ($mapped || $review)) Audit::log('t_product_investors', 0, 'UPDATE', ['mapped' => count($mapped), 'review' => count($review)], 'Investor id mapping run');
        return ['dry' => $dry, 'mapped' => $mapped, 'review' => $review, 'message' => $dry ? count($mapped) . ' to map, ' . count($review) . ' for review.' : count($mapped) . ' mapped, ' . count($review) . ' for review.' . (! $mapped && ! $review ? ' 0 changes.' : '')];
    }

    /** "Unmatched investor ids" review list: rows not mapped or mapped with a settled-period clash. */
    public static function reviewList(): array
    {
        return DB::table('t_product_investors as i')->leftJoin('t_product_vendors as p', 'p.id', '=', 'i.fk_id_product')->where('i.map_status', 'REVIEW')->orderBy('i.id')->get(['i.id as row', 'p.SKU_VENDOR as sku', 'i.fk_id_account as old_id', 'i.map_note as why'])->map(fn ($r) => (array) $r)->all();
    }

    /** The picker: company parties "Company – directors (share %)". */
    public static function options(int $ent, ?string $q = null): array
    {
        $x = DB::table('t_account as a')->join('t_party_profile as pp', 'pp.fk_id_account', '=', 'a.id')->where('a.l_party_type', 'C')->where('a.f_active', 1)->where(fn ($w) => $w->where('a.fk_id_ent', $ent)->orWhereNull('a.fk_id_ent')->orWhere('a.fk_id_ent', 0));
        if ($q) $x->where('pp.company_name', 'like', "%$q%");
        return $x->orderBy('pp.company_name')->limit(200)->get(['a.id', 'pp.company_name'])->map(function ($c) {
            $d = DB::table('t_party_directors as d')->leftJoin('t_account as p', 'p.id', '=', 'd.fk_id_director')->where('d.fk_id_party', $c->id)->orderBy('d.position')->get([DB::raw("TRIM(CONCAT_WS(' ', p.first_name, p.sur_name)) n"), 'd.share_pct'])
                ->map(fn ($r) => trim($r->n . ($r->share_pct !== null ? ' (' . rtrim(rtrim(number_format((float) $r->share_pct, 2, '.', ''), '0'), '.') . '%)' : '')))->filter()->implode(', ');
            return [(string) $c->id, $c->company_name . ($d ? ' – ' . $d : '')];
        })->all();
    }

    public static function ofProduct(int $product): array
    {
        return DB::table('t_product_investors')->where('fk_id_product', $product)->whereNotNull('fk_id_party')->pluck('fk_id_party')->map(fn ($x) => (int) $x)->unique()->values()->all();
    }

    /** Save the Investors of a product: upsert of unique company-party ids > 0 (old: delete all + re-insert). */
    public static function save(int $product, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($x) => $x > 0)));
        $ok = DB::table('t_account')->whereIn('id', $ids ?: [0])->where('l_party_type', 'C')->pluck('id')->map(fn ($x) => (int) $x)->all();
        if ($bad = array_diff($ids, $ok)) throw new \InvalidArgumentException('An investor must be a company party (not a person): ' . implode(', ', $bad) . '.');
        $have = self::ofProduct($product);
        DB::table('t_product_investors')->where('fk_id_product', $product)->whereNotNull('fk_id_party')->whereNotIn('fk_id_party', $ids ?: [0])->delete();
        foreach (array_diff($ids, $have) as $p) DB::table('t_product_investors')->insert(['fk_id_product' => $product, 'fk_id_account' => (int) (DB::table('t_party_profile')->where('fk_id_account', $p)->value('fk_id_company_house') ?? 0), 'fk_id_party' => $p, 'map_status' => 'MAPPED', 'created_by' => (int) Context::userId(), 'created_at' => now()]);
        if ($have !== $ids) Audit::log('t_product_vendors', $product, 'UPDATE', ['investors' => [$have, $ids]]);
        return ['investors' => $ids, 'message' => count($ids) . ' investor(s) saved.'];
    }
}
