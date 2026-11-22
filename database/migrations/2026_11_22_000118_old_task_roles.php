<?php

use Illuminate\Database\Migrations\Migration;

/** R-G_CORE-20: the old DASH_PARTNER / DASH_MANAGER groups (cat_src_lov) become the roles PARTNER / MANAGER of their logins. */
return new class extends Migration
{
    public function up(): void { \App\Services\TaskRoles::fromOld(); }
    public function down(): void {}
};
