<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** R-G_CORE-53 WhatsApp template register: provider name (as approved), ordered params, language, approval status, sample text; the task keys of R-G_CORE-49 seeded. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('wa_templates', function (Blueprint $t) {
            if (! Schema::hasColumn('wa_templates', 'provider_name')) $t->string('provider_name', 120)->nullable();
            if (! Schema::hasColumn('wa_templates', 'params_json')) $t->text('params_json')->nullable();
            if (! Schema::hasColumn('wa_templates', 'language')) $t->string('language', 10)->default('en');
            if (! Schema::hasColumn('wa_templates', 'approval_status')) $t->string('approval_status', 20)->default('PENDING');
            if (! Schema::hasColumn('wa_templates', 'sample_text')) $t->text('sample_text')->nullable();
        });
        foreach (DB::table('wa_templates')->get() as $w) {
            preg_match_all('/\{(\w+)\}/', (string) $w->body, $m);
            DB::table('wa_templates')->where('id', $w->id)->update(['provider_name' => $w->provider_name ?: strtolower($w->code), 'params_json' => $w->params_json ?: json_encode(array_values(array_unique($m[1])))]);
        }
        $this->registry();
        foreach (\App\Services\AlertRules::TEXTS as $key => [$subject, $body]) {
            if (DB::table('wa_templates')->where('code', $key)->exists()) continue;
            preg_match_all('/\{(\w+)\}/', $body, $m);
            DB::table('wa_templates')->insert(['code' => $key, 'title' => $subject, 'audience' => 'VA', 'body' => $body, 'f_active' => 1, 'provider_name' => $key, 'params_json' => json_encode(array_values(array_unique($m[1]))), 'language' => 'en', 'approval_status' => 'PENDING', 'sample_text' => null]);
        }
    }

    /** The same five rows as database/meta/field_registry.csv (form wa_template, section Provider). */
    private function registry(): void
    {
        $h = null; $f = fopen(base_path('database/meta/field_registry.csv'), 'r');
        $cols = \Illuminate\Support\Facades\Schema::getColumnListing('sys_registry');
        while (($r = fgetcsv($f)) !== false) {
            if (! $h) { $h = $r; continue; }
            $x = array_combine($h, $r);
            if ($x['form_key'] !== 'wa_template' || $x['section'] !== 'Provider' || DB::table('sys_registry')->where('form_key', 'wa_template')->where('field_name', $x['field_name'])->exists()) continue;
            $row = array_intersect_key($x, array_flip($cols)); unset($row['id']);
            foreach ($row as $k => $v) if ($v === '') $row[$k] = null;
            $row['required'] = (int) ($x['required'] === 'Y'); $row['in_quick'] = (int) ($x['in_quick'] === 'Y'); $row['readonly'] = 0; $row['is_master'] = (int) ($x['is_master'] === 'Y'); $row['secret'] = 0;
            DB::table('sys_registry')->insert(\App\Services\RecordService::fillNotNull('sys_registry', $row));
        }
        fclose($f); \App\Services\Registry::forget();
    }

    public function down(): void {}
};
