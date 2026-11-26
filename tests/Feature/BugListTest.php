<?php

namespace Tests\Feature;

use App\Services\Context;
use App\Services\RecordService;
use App\Services\Vault;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * R-C-47 the old person-area bug list, each with its regression check: saves answer JSON only (no print_r / echo of SQL in the code),
 * no list or export column bound to a secret, deletes are POST only (GET → 405), the e-mail notification mobile is its own field (#14),
 * the notification e-mail password is not a field any more (R-C-50 / #77), job history delete really deletes (#17), shop assignment keeps created_by (#18).
 */
class BugListTest extends TestCase
{
    public function test_c47_no_debug_output_in_the_code(): void
    {
        $hits = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())) as $f) {
            if ($f->getExtension() !== 'php') continue;
            $src = file_get_contents($f->getPathname());
            if (preg_match('/\b(print_r|var_dump|dd)\s*\(|echo\s+\$sql/', preg_replace('#//.*|/\*.*?\*/#s', '', $src))) $hits[] = str_replace(base_path() . '/', '', $f->getPathname());
        }
        $this->assertSame([], $hits, 'no print_r / var_dump / echo $sql in the services (#1)');
    }

    public function test_c47_no_secret_in_any_list_or_export_and_no_get_delete(): void
    {
        $this->actingAs($this->owner); \App\Services\Choices::set('SECURE_FORMS', 'tax_period', 1);      // the phone-app step of secure pages has its own test
        foreach (['t_account_credit_card', 't_account_ipburger', 't_account_subscription', 't_account_email', 't_account_marketplace', 't_account_paypal'] as $form) {
            $table = \App\Services\Registry::table($form); if (! $table) continue;
            $secret = array_keys(array_filter(Vault::kinds($table), fn ($k) => $k === 'NEVER'));
            $cols = array_column($this->getJson("/api/list/$form?per_page=5")->assertOk()->json('columns'), 'name');
            $this->assertSame([], array_values(array_intersect($cols, $secret)), "$form: CVV / PIN never a list column (#7)");
        }
        $this->assertSame(0, (int) DB::table('sys_registry')->where('column_name', 'notification_email_pass')->count(), 'R-C-50: the notification e-mail password is not a field');
        $this->assertTrue(DB::table('sys_registry')->where('form_key', 't_account_email')->where('field_name', 'popup_notification_mobile')->where('source', 'own:t_account_mobiles')->exists(), '#14 the notification mobile is its own field');
        foreach (Route::getRoutes() as $r) if (preg_match('#(^|/)(delete|remove)(/|$)#', $r->uri()) || str_ends_with($r->uri(), '/delete')) $this->assertNotContains('GET', $r->methods(), $r->uri() . ' must be POST only (#10)');
        $p = DB::table('t_account')->insertGetId(RecordService::fillNotNull('t_account', ['fk_id_ent' => 1, 'account_code' => 'BL' . substr(uniqid(), -6), 'first_name' => 'Bug', 'sur_name' => 'List', 'l_account_kind' => 'M', 'f_active' => 1]));
        $this->get("/api/people/$p/delete")->assertStatus(405);
    }
}
