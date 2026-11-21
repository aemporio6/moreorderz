<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Resend of a lost parcel (R-H-36): a sale line may hold one reservation per send – the unique key becomes sale + product + resend number. */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('wh_reservations', 'resend_no')) DB::statement('ALTER TABLE wh_reservations ADD resend_no TINYINT NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE wh_reservations DROP INDEX uq_res_line, ADD UNIQUE KEY uq_res_line (fk_id_sale, fk_id_product, resend_no)');
    }
    public function down(): void {}
};
