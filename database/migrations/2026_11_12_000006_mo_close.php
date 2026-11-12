<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Step 6 Month close: the owner's one-line note and the figures frozen at lock, on the shop × month run. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aud_runs', function (Blueprint $t) {
            if (! Schema::hasColumn('aud_runs', 'owner_note')) $t->string('owner_note', 255)->nullable();
            if (! Schema::hasColumn('aud_runs', 'snapshot_json')) $t->text('snapshot_json')->nullable();
            if (! Schema::hasColumn('aud_runs', 'checked_at')) $t->dateTime('checked_at')->nullable();
        });
    }

    public function down(): void {}
};
