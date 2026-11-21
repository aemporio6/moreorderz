<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** R-G_CORE-15: a wrong evidence file is hidden with a reason, never deleted. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('task_evidence', function (Blueprint $t) {
            if (! Schema::hasColumn('task_evidence', 'hidden_at')) $t->dateTime('hidden_at')->nullable();
            if (! Schema::hasColumn('task_evidence', 'hidden_by')) $t->integer('hidden_by')->nullable();
            if (! Schema::hasColumn('task_evidence', 'hidden_reason')) $t->string('hidden_reason', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('task_evidence', fn (Blueprint $t) => $t->dropColumn(['hidden_at', 'hidden_by', 'hidden_reason']));
    }
};
