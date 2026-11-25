<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** R-C-15 the bank fields of the person and supplier bank forms: IBAN / SWIFT without spaces in upper case, account no without spaces, sort code trimmed – shown as "Auto-corrected · Undo". */
return new class extends Migration {
    public function up(): void
    {
        $q = fn () => DB::table('sys_registry')->where('table_name', 't_account_banks')->whereIn('form_key', ['t_account_bank', 'supplier_bank']);
        $q()->whereIn('column_name', ['iban_no', 'swift_code'])->update(['auto_correct' => 'upper_nospace']); $q()->where('column_name', 'account_no')->update(['auto_correct' => 'nospace']); $q()->where('column_name', 'sort_branch_code')->update(['auto_correct' => 'trim']);
        \App\Services\Registry::forget();
    }
    public function down(): void {}
};
