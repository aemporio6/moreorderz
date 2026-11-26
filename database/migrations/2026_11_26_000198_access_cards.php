<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Owner 26-11 21:31 (from MY TOOL roles2): per person "access until" (after it: look only) and "approve up to £". */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('t_users', function (Blueprint $t) {
            if (! Schema::hasColumn('t_users', 'access_until')) $t->date('access_until')->nullable();
            if (! Schema::hasColumn('t_users', 'approve_limit')) $t->decimal('approve_limit', 14, 2)->nullable();
        });
    }

    public function down(): void {}
};
