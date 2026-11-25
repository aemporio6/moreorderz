<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** R-A_SALES-59 an audit run may name its buying supplier (optional): the Amazon / GD tiles follow that supplier's audit profile. */
return new class extends Migration {
    public function up(): void { if (! Schema::hasColumn('t_audit_main', 'fk_id_vendor')) Schema::table('t_audit_main', fn (Blueprint $t) => $t->integer('fk_id_vendor')->nullable()->after('fk_id_branch')); }
    public function down(): void { if (Schema::hasColumn('t_audit_main', 'fk_id_vendor')) Schema::table('t_audit_main', fn (Blueprint $t) => $t->dropColumn('fk_id_vendor')); }
};
