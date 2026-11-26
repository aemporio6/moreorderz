<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Owner 26-11 19:18: every dropdown shows suggestions – the empty central lists get sensible starting values (each can be renamed,
 * switched off or added to with + Add; a list that already has values is left alone). Document category reads its own list.
 */
return new class extends Migration
{
    private const DEFAULTS = [
        'DOC_CATEGORY' => ['Proforma invoice (PI)', 'Commercial invoice', 'Packing list', 'Bill of lading / AWB', 'Certificate', 'Test report', 'Product photos', 'Artwork', 'Contract', 'Customs entry', 'Other'],
        'DOC_STATUS' => ['Draft', 'Received', 'Checked', 'Approved', 'Rejected', 'Expired'],
        'CATEGORY' => ['Home & Kitchen', 'Beauty & Personal care', 'Pet supplies', 'Health', 'Toys & Games', 'Sports & Outdoors', 'Automotive', 'Tools & DIY', 'Electronics accessories', 'Clothing & Accessories', 'Office & Stationery', 'Garden'],
        'INVEST_TYPE' => ['Capital', 'Loan', 'Profit share'],
        'INVESTOR_STATUS' => ['Active', 'Paused', 'Exited'],
        'OWNERSHIP_STATUS' => ['Owned', 'Shared', 'Consignment'],
        'PAYMENT_METHOD' => ['Bank transfer', 'Wise', 'Payoneer', 'PayPal', 'Alibaba Trade Assurance', 'Card', 'Cash'],
        'INSPECTION_TYPE' => ['Pre-production', 'During production (DUPRO)', 'Pre-shipment (PSI)', 'Container loading'],
        'QC_APPROVAL' => ['Pending', 'Approved', 'Approved with notes', 'Rejected'],
        'FCL_LCL' => ['FCL (full container)', 'LCL (shared container)'],
        'CONTAINER_TYPE' => ['20 ft', '40 ft', '40 ft HC', '45 ft HC'],
        'VERIFICATION_STATUS' => ['Not checked', 'Verified', 'Failed'],
        'RISK_LEVEL' => ['Low', 'Medium', 'High'],
        'PORT_TYPE' => ['Sea port', 'Airport', 'Rail terminal', 'Inland depot'],
        'SOURCING_PLATFORM' => ['Alibaba', '1688', 'Made-in-China', 'Global Sources', 'Direct factory', 'Trade show'],
        'DEPARTMENT' => ['Management', 'Sourcing', 'Sales', 'Warehouse', 'Finance', 'HR', 'Customer service'],
        'SKILL' => ['Listing', 'Customer service', 'Product research', 'Sourcing', 'Accounts', 'Graphic design', 'Ads'],
        'LANGUAGE' => ['English', 'Chinese (Mandarin)', 'Urdu', 'Arabic', 'Hindi'],
        'DOC_TAG' => ['Important', 'To check', 'Signed', 'Copy'],
        'COLOUR' => ['Black', 'White', 'Grey', 'Red', 'Blue', 'Green', 'Yellow', 'Orange', 'Pink', 'Purple', 'Brown', 'Beige', 'Gold', 'Silver', 'Clear', 'Multicolour'],
        'SIZE' => ['XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL', 'One size'],
    ];

    public function up(): void
    {
        foreach (self::DEFAULTS as $code => $values) {
            $ref = DB::table('cat_references')->where('ref_code', $code)->value('id'); if (! $ref) continue;
            if (DB::table('reference_items')->where('fk_reference_id', $ref)->exists()) continue;
            foreach ($values as $i => $v) DB::table('reference_items')->insert(['fk_reference_id' => $ref, 'reference_name' => $v, 'sort_no' => $i + 1, 'f_active' => 1, 'f_pending' => 0, 'fk_id_ent' => 0, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('sys_registry')->where('form_key', 't_src_documents')->where('column_name', 'doc_category')->update(['source' => 'ref:DOC_CATEGORY']);
    }

    public function down(): void {}
};
