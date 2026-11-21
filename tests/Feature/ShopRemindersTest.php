<?php

namespace Tests\Feature;

use App\Services\RecordService;
use App\Services\ShopReminders;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RULES_A_SALES R-A_SALES-72: shop monthly reminders and the monthly templates. */
class ShopRemindersTest extends TestCase
{
    private int $shop; private int $type;

    private function fill(string $t, array $d): array { return RecordService::fillNotNull($t, $d); }

    private function world(): void
    {
        $this->actingAs($this->owner);
        $mp = DB::table('cat_marketplaces')->insertGetId($this->fill('cat_marketplaces', ['fk_id_ent' => 1, 'marketplace_short' => 'Ebay', 'marketplace_descr' => 'eBay', 'f_active' => 1]));
        $this->shop = DB::table('t_branches')->insertGetId($this->fill('t_branches', ['fk_id_ent' => 1, 'fk_id_marketplace' => $mp, 'shop_no' => '101A', 'shop_name' => 'Pets', 'iso_branch' => 'P1', 'f_active' => 1]));
        $ref = DB::table('cat_references')->where('ref_code', 'REMINDER_TYPE')->value('id') ?: DB::table('cat_references')->insertGetId($this->fill('cat_references', ['ref_code' => 'REMINDER_TYPE', 'reference_descr' => 'Reminder type']));
        $this->type = DB::table('reference_items')->insertGetId($this->fill('reference_items', ['fk_reference_id' => $ref, 'reference_name' => 'Invoice', 'f_active' => 1]));
    }

    public function test_all_fields_are_needed_and_the_status_goes_pending_resolved_pending(): void
    {
        $this->world(); $ok = ['shop' => $this->shop, 'month' => 7, 'year' => 2026, 'type' => $this->type, 'text' => 'Send the VAT invoice'];
        foreach ([['shop', 'Select a shop.'], ['month', 'Select the month.'], ['year', 'Enter the year.'], ['type', 'Select the reminder type.'], ['text', 'Write the description.']] as [$k, $msg]) { try { ShopReminders::save([$k => ''] + $ok, null, $this->owner); $this->fail('no error'); } catch (\InvalidArgumentException $e) { $this->assertSame($msg, $e->getMessage()); } }
        $a = ShopReminders::save($ok, null, $this->owner)['id']; $b = ShopReminders::save(['month' => 9, 'text' => str_repeat('x', 300)] + $ok, null, $this->owner)['id'];
        $l = ShopReminders::list($this->owner); $this->assertSame([$b, $a], array_column($l['pending'], 'id')); $this->assertSame(251, mb_strlen($l['pending'][0]['text'])); $this->assertSame('July 2026', $l['pending'][1]['period']);      // newest first, text cut to 250
        ShopReminders::resolve($a, $this->owner); ShopReminders::resolve($b, $this->owner); $l = ShopReminders::list($this->owner); $this->assertSame([[], [$a, $b]], [$l['pending'], array_column($l['resolved'], 'id')]);      // resolved: oldest first
        try { ShopReminders::save(['text' => 'new'] + $ok, $a, $this->owner); $this->fail('no error'); } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('Revert it first', $e->getMessage()); }
        ShopReminders::revert($a, $this->owner); $this->assertSame('PENDING', DB::table('t_branch_reminders')->where('id', $a)->value('e_status'));
        $this->postJson('/api/shop-reminders', ['id' => $a, 'text' => 'Changed'] + $ok)->assertOk(); $this->postJson("/api/shop-reminders/$a/remove")->assertOk(); $this->assertNull(DB::table('t_branch_reminders')->where('id', $a)->first());
        $this->getJson('/api/shop-reminders')->assertOk()->assertJsonCount(1, 'resolved')->assertJsonCount(12, 'months');
    }

    public function test_a_template_makes_the_reminder_and_its_task_on_the_first_of_the_month(): void
    {
        $this->world(); ShopReminders::saveTemplate(['shop' => $this->shop, 'type' => $this->type, 'text' => 'Upload monthly invoice', 'due_day' => 5], null, $this->owner);
        $this->assertSame(1, ShopReminders::monthly('2026-12-01')); $this->assertSame(0, ShopReminders::monthly('2026-12-02'));      // once per month
        $r = DB::table('t_branch_reminders')->first(); $this->assertSame([12, 2026, 'PENDING', 'Upload monthly invoice'], [(int) $r->month, (int) $r->year, $r->e_status, $r->reminder_text]);
        $t = DB::table('tasks')->where('dedupe_key', 'SHOP_REMINDER:' . $r->id)->first(); $this->assertSame(['2026-12-05', 'TODO', '101A: Upload monthly invoice'], [substr((string) $t->due_at, 0, 10), $t->status, $t->title]);      // a task due 05-12
        ShopReminders::resolve((int) $r->id, $this->owner); $this->assertSame('DONE', DB::table('tasks')->where('id', $t->id)->value('status'));
        ShopReminders::revert((int) $r->id, $this->owner); $this->assertSame('TODO', DB::table('tasks')->where('id', $t->id)->value('status'));      // the same task opens again
        $this->assertSame(1, ShopReminders::monthly('2027-01-01')); $this->assertSame(2, DB::table('t_branch_reminders')->count());
        try { ShopReminders::saveTemplate(['type' => $this->type, 'text' => 'x', 'due_day' => 31], null, $this->owner); $this->fail('no error'); } catch (\InvalidArgumentException $e) { $this->assertSame('The due day must be between 1 and 28.', $e->getMessage()); }
    }
}
