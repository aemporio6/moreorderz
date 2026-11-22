<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R-A_SALES-87: the marketplace money readers read their fee buckets from profile rows.
 * - eBay example: fee buckets get book_as + the eBay file columns (the code list is only the fall-back).
 * - Amazon example profile: keyword buckets (Amazon settlement V2 / transaction report).
 * - TikTok example: the TT_ buckets get the TikTok settlement column names.
 * - Every cat_marketplaces row gets one profile (a copy of the matching example, or a blank one); shops of that marketplace without a profile get it.
 * - The SALE / DS / MATCH column sets of a marketplace are copied into mp_profiles.sale_sets_json (the old tables stay as the fall-back).
 */
return new class extends Migration
{
    private const EBAY = ['FVF' => ['MP_COMMISSION', 'Final value fee - fixed,Final value fee - variable'], 'FVF_INAD' => ['MP_PENALTY', "Very high 'item not as described' fee"],
        'BELOW_STD' => ['MP_PENALTY', 'Below standard performance fee'], 'REGULATORY' => ['MP_REGULATORY', 'Regulatory operating fee'], 'INTERNATIONAL' => ['MP_INTERNATIONAL', 'International fee'],
        'INSERTION' => ['MP_LISTING', null], 'AD_FEE' => ['MP_ADS', null], 'POSTAGE_LABEL' => ['MP_SHIPPING_LABEL', null], 'FEE_CREDIT' => ['MP_ADJUSTMENT', null], 'CLAIM_FEE_CREDIT' => ['MP_ADJUSTMENT', null]];
    private const EBAY_WORDS = ['AD_FEE' => 'promoted listing,ad fee', 'POSTAGE_LABEL' => 'shipping label,postage label', 'INSERTION' => 'insertion fee'];
    private const TIKTOK = ['TT_COMMISSION' => 'TikTok Shop commission fee', 'TT_AFFILIATE' => 'Affiliate commission,Affiliate partner commission', 'TT_SHIPPING_SERVICE' => 'Shipping service fee',
        'TT_PROMOTION' => 'Smart promotion fee,Campaign resource fee,Campaign service fee,Co-funded promotion (seller-funded),GMV Max ad fee,Auto-post shoppable video commission fee', 'TT_MANAGED_SERVICE' => 'Managed service plan fee', 'TT_VAT' => 'VAT'];
    private const AMAZON = [['ADS', 'Advertising', 'MP_ADS', 'advertis,sponsored'], ['RESERVE', 'Reserve held / released', 'MP_HOLD', 'reserve'],
        ['REIMB', 'Reimbursements', 'MP_REIMBURSEMENT', 'reimburs,warehouse damage,warehouse lost,missing from inbound,free replacement,safe-t'], ['STORAGE', 'Storage / removal / disposal', 'MP_STORAGE', 'storage fee,removal,disposal,liquidation,inbound placement'],
        ['FBA', 'FBA fulfilment fees', 'MP_FULFILMENT', 'fbaperunitfulfillmentfee,fba fulfil,fba fulfill,pick & pack'], ['SUBSCRIPTION', 'Subscription', 'MP_SUBSCRIPTION', 'subscription'], ['COMMISSION', 'Referral fee / commission', 'MP_COMMISSION', 'commission,referral'], ['LABEL', 'Shipping labels', 'MP_SHIPPING_LABEL', 'shipping services']];

    public function up(): void
    {
        if (! Schema::hasColumn('mp_profiles', 'sale_sets_json')) DB::statement('ALTER TABLE mp_profiles ADD COLUMN sale_sets_json TEXT NULL');
        $ebay = DB::table('mp_profiles')->where('code', 'EBAY_EXAMPLE')->value('id');
        if ($ebay) foreach (self::EBAY as $code => [$as, $cols]) DB::table('mp_profile_fee_buckets')->where('fk_id_mp_profile', $ebay)->where('bucket_code', $code)->whereNull('book_as')->update(['book_as' => $as, 'file_columns' => $cols, 'match_keywords' => self::EBAY_WORDS[$code] ?? null]);
        $tt = DB::table('mp_profiles')->where('code', 'TIKTOK_EXAMPLE')->value('id');
        if ($tt) foreach (self::TIKTOK as $code => $cols) DB::table('mp_profile_fee_buckets')->where('fk_id_mp_profile', $tt)->where('bucket_code', $code)->whereNull('file_columns')->update(['file_columns' => $cols]);
        $amz = DB::table('mp_profiles')->where('code', 'AMAZON_EXAMPLE')->value('id');
        if (! $amz && $ebay) {
            $row = (array) DB::table('mp_profiles')->where('id', $ebay)->first(); unset($row['id']);
            $amz = DB::table('mp_profiles')->insertGetId(array_merge($row, ['code' => 'AMAZON_EXAMPLE', 'name' => 'Amazon (example profile)', 'money_report_type' => 'AMAZON_SETTLEMENT_V2', 'sale_sets_json' => null, 'created_at' => now(), 'updated_at' => null]));
            DB::table('mp_profile_report_types')->insert([['fk_id_mp_profile' => $amz, 'report_type' => 'AMAZON_SETTLEMENT_V2', 'report_role' => 'MONEY', 'f_required_for_close' => 1, 'detect_headers' => 'settlement-id,transaction-type,amount-type,amount-description', 'f_active' => 1, 'column_map_json' => null]]);
            foreach (self::AMAZON as $i => [$code, $label, $as, $words]) DB::table('mp_profile_fee_buckets')->insert(['fk_id_mp_profile' => $amz, 'bucket_code' => $code, 'label' => $label, 'source_rule' => 'Settlement rows: type / description words', 'charge_kind' => $code, 'sort_no' => ($i + 1) * 10, 'f_active' => 1, 'sign_rule' => 'COST', 'f_in_selling_costs' => 1, 'book_as' => $as, 'match_keywords' => $words]);
        }
        $ex = ['ebay' => $ebay, 'tiktok' => $tt, 'amazon' => $amz];
        foreach (DB::table('cat_marketplaces')->get() as $m) {
            $pid = DB::table('mp_profiles')->where('fk_id_marketplace', $m->id)->where('fk_id_ent', $m->fk_id_ent)->value('id');
            if (! $pid) {
                $name = trim(($m->marketplace_short ?? '') . ' ' . ($m->marketplace_descr ?? '')); $src = null;
                foreach ($ex as $k => $id) if ($id && str_contains(mb_strtolower($name), $k)) $src = $id;
                $base = (array) DB::table('mp_profiles')->where('id', $src ?? $ebay ?? $tt)->first(); if (! $base) continue; unset($base['id']);
                $pid = DB::table('mp_profiles')->insertGetId(array_merge($base, ['fk_id_ent' => $m->fk_id_ent, 'fk_id_marketplace' => $m->id, 'code' => mb_substr('MP_' . $m->id, 0, 30), 'name' => mb_substr(($name !== '' ? $name : 'Marketplace ' . $m->id) . ' profile', 0, 100), 'sale_sets_json' => null, 'created_at' => now(), 'updated_at' => null]));
                if ($src) {
                    foreach (DB::table('mp_profile_report_types')->where('fk_id_mp_profile', $src)->get() as $r) { $r = (array) $r; unset($r['id']); $r['fk_id_mp_profile'] = $pid; DB::table('mp_profile_report_types')->insert($r); }
                    foreach (DB::table('mp_profile_fee_buckets')->where('fk_id_mp_profile', $src)->get() as $b) { $b = (array) $b; unset($b['id']); $b['fk_id_mp_profile'] = $pid; DB::table('mp_profile_fee_buckets')->insert($b); }
                }
            }
            DB::table('t_branches')->where('fk_id_marketplace', $m->id)->where(fn ($w) => $w->whereNull('fk_id_mp_profile')->orWhere('fk_id_mp_profile', 0))->update(['fk_id_mp_profile' => $pid]);
            $sets = [];
            foreach (['SALE' => ['marketplace_sale_columns', 'sale_field'], 'DS' => ['marketplace_ds_columns', 'ds_field'], 'MATCH' => ['marketplace_5_matching_rule_columns', 'match_field']] as $set => [$t, $c])
                if (Schema::hasTable($t)) foreach (DB::table($t)->where('fk_marketplace_id', $m->id)->get() as $r) $sets[$set][$r->$c] = array_values(array_filter(array_map('trim', explode(',', (string) $r->csv_columns)), fn ($x) => $x !== ''));
            if ($sets) DB::table('mp_profiles')->where('id', $pid)->whereNull('sale_sets_json')->update(['sale_sets_json' => json_encode($sets)]);
        }
    }

    public function down(): void { if (Schema::hasColumn('mp_profiles', 'sale_sets_json')) DB::statement('ALTER TABLE mp_profiles DROP COLUMN sale_sets_json'); }
};
