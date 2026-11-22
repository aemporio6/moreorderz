<?php

namespace Tests\Feature;

use App\Services\Connect\Connections;
use App\Services\ListExports;
use App\Services\RecordService;
use App\Services\UserBulk;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** R-G_CORE-71 month_year, R-G_CORE-69 bulk role change, R-G_CORE-68 Drive schedule + old exports, R-G_CORE-105 / 106 UI (More… bar, audit date presets). */
class ListsUsersFormatsTest extends TestCase
{
    public function test_r71_month_year_card_expiry(): void
    {
        $f = ['type' => 'month_year', 'table_name' => 't_account_credit_cards', 'column_name' => 'valid_upto', 'field_name' => 'valid_upto'];
        $this->assertSame('2026-07-31', RecordService::monthYear($f, '0726'), 'type "0726" → 07/2026 (an expiry = the last day of the month)');
        $this->assertSame('2026-07-31', RecordService::monthYear($f, '07/2026'));
        $this->assertSame('2027-03-01', RecordService::monthYear(['column_name' => 'valid_from'] + $f, '3/27'));
        $this->assertSame('13/2026', RecordService::monthYear($f, '13/2026'), 'a bad month stays for the check');
        $this->assertSame('month_year', DB::table('sys_registry')->where('form_key', 't_account_credit_card')->where('field_name', 'valid_upto')->value('type'));
        $this->actingAs($this->owner);
        $acc = DB::table('t_account')->insertGetId(RecordService::fillNotNull('t_account', ['fk_id_ent' => 1, 'account_code' => 'MY' . random_int(10000, 99999), 'first_name' => 'Card Holder', 'f_active' => 1]));
        $err = function (string $v) use ($acc) { try { RecordService::save('t_account_credit_card', ['fk_id_account' => $acc, 'valid_upto' => $v, 'name_on_card' => 'C H']); return null; } catch (ValidationException $e) { return $e->errors()['valid_upto'][0] ?? null; } };
        $this->assertSame('Valid upto: month 01–12.', $err('13/2026'));
        $this->assertSame('Valid upto is in the past.', $err('01/2020'));
        $this->assertNull($err('0729'));
        $this->assertSame('2029-07-31', (string) DB::table('t_account_credit_cards')->where('fk_id_account', $acc)->value('valid_upto'));
        $this->assertStringContainsString("data-my type=\"text\"", file_get_contents(public_path('app/app.js')));
    }

    public function test_r69_bulk_role_change_preview_apply_undo(): void
    {
        $a = $this->makeUser('VA'); $b = $this->makeUser('VA'); $o2 = $this->makeUser('OWNER'); $m = $this->makeUser('MANAGER');
        $this->actingAs($m)->postJson('/api/users/bulk-role', ['ids' => [$a->id], 'role' => 'TL'])->assertStatus(403);
        $this->actingAs($this->owner);
        $this->postJson('/api/users/bulk-role', ['ids' => [], 'role' => 'TL'])->assertStatus(422);
        $this->assertSame('Please select at least one checkbox', $this->postJson('/api/users/bulk-role', ['ids' => [0], 'role' => 'TL'])->json('message'));
        $this->postJson('/api/users/bulk-role', ['ids' => [$a->id], 'role' => 'OWNER'])->assertStatus(422);
        $p = $this->postJson('/api/users/bulk-role', ['ids' => [$a->id, $b->id, $o2->id, $this->owner->id], 'role' => 'TL'])->assertOk()->json();
        $this->assertSame(['Change role of selected row(s)', 2, 'System will change 2 selected row(s), 2 skipped.'], [$p['title'], $p['will_change'], $p['message']]);
        $this->assertSame('An Owner login is never changed in bulk.', collect($p['rows'])->firstWhere('id', $o2->id)['skip']);
        $this->assertSame('VA', DB::table('t_users')->where('id', $a->id)->value('role_code'), 'the preview changes nothing');
        $r = $this->postJson('/api/users/bulk-role', ['ids' => [$a->id, $b->id], 'role' => 'TL', 'apply' => true])->assertOk()->json();
        $this->assertSame(2, $r['changed']); $this->assertSame(['TL', 'TL'], DB::table('t_users')->whereIn('id', [$a->id, $b->id])->pluck('role_code')->all());
        $u = $this->postJson('/api/bulk-jobs/' . $r['job'] . '/undo')->assertOk()->json();
        $this->assertSame(2, $u['back']); $this->assertSame(['VA', 'VA'], DB::table('t_users')->whereIn('id', [$a->id, $b->id])->pluck('role_code')->all());
    }

    public function test_r68_schedule_to_a_drive_folder_and_old_exports_moved(): void
    {
        $this->actingAs($this->owner);
        $id = $this->postJson('/api/export-templates/src_research', ['name' => 'To Drive', 'columns' => ['title'], 'schedule' => 'DAILY', 'send_to' => 'https://drive.google.com/drive/folders/1DriveFolderAbc'])->assertOk()->json('id');
        $this->assertSame('drive:1DriveFolderAbc', DB::table('mo_export_templates')->where('id', $id)->value('send_to'));
        $this->postJson('/api/export-templates/src_research', ['name' => 'Bad', 'columns' => ['title'], 'schedule' => 'DAILY', 'send_to' => 'not a target'])->assertStatus(422)
            ->assertJsonPath('message', 'A scheduled export needs the e-mail address or the Google Drive folder link it goes to.');
        $k = openssl_pkey_new(['private_key_bits' => 2048]); openssl_pkey_export($k, $pem);
        Connections::save(['adapter' => 'GOOGLE_DRIVE', 'settings' => ['root_folder_id' => 'ROOT1'], 'secrets' => ['service_account_json' => json_encode(['client_email' => 'mo@test.iam.gserviceaccount.com', 'private_key' => $pem])]]);
        $up = [];
        Http::fake(function (HttpRequest $q) use (&$up) {
            if (str_contains($q->url(), 'oauth2.googleapis.com/token')) return Http::response(['access_token' => 'ya29.x', 'expires_in' => 3600]);
            if (str_contains($q->url(), '/upload/drive/v3/files')) { $up[] = $q->body(); return Http::response(['id' => 'UP1', 'webViewLink' => 'https://drive.google.com/file/d/UP1']); }
            return Http::response([], 404);
        });
        DB::table('mo_export_templates')->where('id', '<>', $id)->update(['schedule' => 'NONE']);
        $this->assertSame(1, ListExports::runScheduled(date('Y-m-d')));
        $this->assertCount(1, $up); $this->assertStringContainsString('"parents":["1DriveFolderAbc"]', $up[0]);
        $this->assertSame(0, ListExports::runScheduled(date('Y-m-d')), 'once a day');
        // the old saved exports → templates of their list
        $col = DB::table('sys_registry')->where('form_key', 'src_research')->where('field_name', 'title')->value('column_name');
        DB::table('t_export_options')->insert(['export_name' => 'Old research', 'export_fields' => "$col,no_such_column", 'export_controller_name' => 'src_research', 'created_by' => $this->owner->id, 'created_at' => now()]);
        DB::table('t_export_options')->insert(['export_name' => 'Gone page', 'export_fields' => 'x', 'export_controller_name' => 'old_only_page', 'created_by' => $this->owner->id, 'created_at' => now()]);
        $r = ListExports::importOld();
        $this->assertSame(1, $r['moved']);
        $this->assertSame(['Moved without: no_such_column (not on the list).', 'No list for the old page "old_only_page".'], array_column($r['skipped'], 'why'));
        $this->assertSame(['title'], json_decode(DB::table('mo_export_templates')->where('name', 'Old research')->value('columns_json'), true));
    }

    public function test_r105_r106_more_bar_and_audit_presets(): void
    {
        $js = file_get_contents(public_path('app/app.js'));
        foreach (['NAVMAX = 6', 'id="fNavMore"', 'function auditRange(p)', "['today', 'Today – what changed today']", "'preset'"] as $s) $this->assertStringContainsString($s, $js);
        $this->actingAs($this->owner);
        $today = date('Y-m-d');
        $n = $this->getJson("/api/audit?from=$today&to=$today")->assertOk()->json('total');
        $this->assertSame(DB::table('sys_audit_log')->whereBetween('created_at', ["$today 00:00:00", "$today 23:59:59"])->count(), $n, 'what changed today');
    }
}
