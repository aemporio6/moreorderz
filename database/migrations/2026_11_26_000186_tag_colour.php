<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** R-D-13 / R-D-69: the tag colour is a hex colour with a colour picker; Tag 1–3 are read-only copies of the first three tags. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('sys_registry')->where('table_name', 'cat_tags')->where('column_name', 'tag_color')->update(['help' => 'Hex colour #rrggbb – pick it with the colour picker; every chip of this tag is painted in it.']);
        foreach (DB::table('t_product_vendors')->where(fn ($q) => $q->whereNull('tag_ids')->orWhere('tag_ids', ''))->where(fn ($q) => $q->where('f_tag1', '>', 0)->orWhere('f_tag2', '>', 0)->orWhere('f_tag3', '>', 0))->get(['id', 'f_tag1', 'f_tag2', 'f_tag3']) as $p)
            DB::table('t_product_vendors')->where('id', $p->id)->update(['tag_ids' => implode(',', array_values(array_unique(array_filter([(int) $p->f_tag1, (int) $p->f_tag2, (int) $p->f_tag3]))))]);      // the old three tags become the Tags field
    }

    public function down(): void {}
};
