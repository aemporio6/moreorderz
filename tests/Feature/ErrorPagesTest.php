<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** R-G_CORE-121 error pages: 403 / 404 / 500 in the app's look; details only in sys_error_log. */
class ErrorPagesTest extends TestCase
{
    public function test_g121_403_names_the_right_and_is_audited(): void
    {
        $va = $this->makeUser('VA'); $this->actingAs($va); $n = DB::table('sys_audit_log')->where('action', 'DENIED')->count();
        // the rule: open a page without the right → 403 with the right's name and an audit row
        $r = $this->postJson('/api/data-quality/run')->assertStatus(403); $this->assertStringContainsString('Ask the Owner', $r->json('message')); $this->assertNotEmpty($r->json('perm'));
        $a = DB::table('sys_audit_log')->where('action', 'DENIED')->orderByDesc('id')->first(); $this->assertSame($n + 1, DB::table('sys_audit_log')->where('action', 'DENIED')->count());
        $this->assertSame($va->id, (int) $a->user_id); $this->assertStringContainsString('data-quality', (string) $a->changes_json);
        Route::middleware('web')->get('/_t/forbidden', fn () => abort(403, 'Stock changes'));
        $h = $this->get('/_t/forbidden')->assertStatus(403); $h->assertSee('You do not have the right to open this page – ask the Owner for "Stock changes".'); $h->assertSee('/app/design.css', false);
    }

    public function test_g121_404_has_the_search_and_500_shows_only_the_error_id(): void
    {
        $this->actingAs($this->owner);
        $p = $this->get('/no/such/page')->assertStatus(404); $p->assertSee('Page not found – search or go Home.'); $p->assertSee('placeholder="Search"', false); $p->assertSee('href="/"', false);
        $this->getJson('/api/no-such-thing')->assertStatus(404);
        // the rule: force a DB error → 500 with E-id, no SQL on screen, the SQL in sys_error_log
        Route::middleware('web')->get('/_t/boom', fn () => DB::select('SELECT secret_col FROM no_such_table_zz'));
        Route::middleware('web')->get('/api/_t/boom', fn () => DB::select('SELECT secret_col FROM no_such_table_zz'));
        $before = (int) DB::table('sys_error_log')->max('id');
        $h = $this->get('/_t/boom')->assertStatus(500); $row = DB::table('sys_error_log')->where('id', '>', $before)->orderBy('id')->first(); $this->assertNotNull($row);
        $h->assertSee('Something went wrong – the error was logged as E-' . $row->id . '.'); $h->assertDontSee('no_such_table_zz'); $h->assertDontSee('SELECT');
        $this->assertStringContainsString('no_such_table_zz', (string) $row->context_json); $this->assertStringContainsString('GET _t/boom', (string) $row->url);
        $j = $this->getJson('/api/_t/boom')->assertStatus(500)->json(); $this->assertSame(['message', 'error_id'], array_keys($j));
        $this->assertMatchesRegularExpression('/^Something went wrong – the error was logged as E-\d+\.$/', $j['message']); $this->assertStringNotContainsString('no_such_table', json_encode($j));
        // the command-line version: the same short text and the id; details only in the log
        $n = (int) DB::table('sys_error_log')->max('id'); $line = \App\Services\ErrorPages::cli(new \RuntimeException('secret detail zz')); $this->assertSame('Something went wrong – the error was logged as E-' . ($n + 1) . '.', $line);
        $this->assertStringContainsString('secret detail zz', (string) DB::table('sys_error_log')->where('id', $n + 1)->value('message')); $this->assertSame('cli', DB::table('sys_error_log')->where('id', $n + 1)->value('url'));
        $this->assertInstanceOf(\App\Exceptions\Handler::class, app(\Illuminate\Contracts\Debug\ExceptionHandler::class));
        $this->assertSame('orders.fulfilExport', \App\Services\Perm::code('sales.export_shipping'), 'R-D-55 right name');
        // our own refusals keep their plain sentence (not an error page)
        $this->postJson('/api/inventory-sharing', ['action' => 'PUSH', 'ids' => []])->assertStatus(422)->assertJsonPath('message', 'Tick at least one row.');
    }
    /** R-C-45 users list: person, shops / marketplaces scope, last login, 2-step, Reset password; never a password column. */
    public function test_c45_users_list_scope_and_reset_password(): void
    {
        $this->actingAs($this->owner); $f = fn (string $t, array $d) => (int) DB::table($t)->insertGetId(\App\Services\RecordService::fillNotNull($t, $d));
        $acc = $f('t_account', ['fk_id_ent' => 1, 'account_code' => 'P-045', 'first_name' => 'Sana', 'sur_name' => 'Malik', 'f_active' => 1]); $u = $this->makeUser('VA'); DB::table('t_users')->where('id', $u->id)->update(['fk_id_account' => $acc]);
        $eb = $f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'eBay', 'f_active' => 1]); $am = $f('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'Amazon', 'f_active' => 1]);
        $shop = fn (int $mp, string $no, array $x = []) => $f('t_branches', $x + ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => $no, 'iso_branch' => $no, 'shop_name' => "Shop $no", 'f_active' => 1]);
        // the rule: user seller of 2 eBay shops and assigned 1 Amazon shop → "eBay, Amazon"
        $shop($eb, 'C45A', ['fk_id_seller' => $acc]); $shop($eb, 'C45B', ['fk_id_seller' => $acc, 'f_active' => 0]); $a3 = $shop($am, 'C45C'); $a4 = $shop($am, 'C45D');
        $f('t_account_branches', ['fk_id_branch' => $a3, 'fk_id_account' => $acc, 'f_active' => 1, 'role_code' => 'VA', 'd_from' => date('Y-m-d')]);
        $row = collect($this->getJson('/api/users')->assertOk()->json('users'))->firstWhere('id', $u->id);
        $this->assertSame(['eBay, Amazon', 'P-045 – Sana Malik'], [$row['scope'], $row['person']]); $this->assertArrayHasKey('last_login_at', $row); $this->assertArrayHasKey('two_step', $row);
        foreach (array_keys($row) as $k) $this->assertDoesNotMatchRegularExpression('/^pass|password_hash|totp_secret$/', $k, 'never a password column');
        // Reset password: a one-time link to the person's own e-mail; nothing secret comes back
        $this->postJson("/api/users/{$u->id}/reset-password")->assertStatus(422)->assertJsonPath('message', 'This person has no e-mail on record – add one to the person first.');
        $f('t_account_emails', ['fk_id_account' => $acc, 'email' => 'sana@example.test', 'f_active' => 1]); $n = DB::table('sys_password_resets')->where('user_id', $u->id)->count();
        $r = $this->postJson("/api/users/{$u->id}/reset-password")->assertOk()->json(); $this->assertSame(['message'], array_keys($r)); $this->assertStringContainsString('s•••@example.test', $r['message']);
        $this->assertSame($n + 1, DB::table('sys_password_resets')->where('user_id', $u->id)->count()); $this->assertSame(1, DB::table('sys_audit_log')->where('table_name', 't_users')->where('record_id', $u->id)->where('changes_json', 'like', '%by an administrator%')->count());
        $this->actingAs($u)->postJson("/api/users/{$u->id}/reset-password")->assertStatus(403);
    }
}
