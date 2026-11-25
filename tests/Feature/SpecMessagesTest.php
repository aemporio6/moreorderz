<?php

namespace Tests\Feature;

use App\Services\RecordService;
use Tests\TestCase;

/** R-B_SOURCING-54 required fields of the sourcing forms with the old messages, checked on the server. */
class SpecMessagesTest extends TestCase
{
    private function err(string $form, array $v): array
    {
        try { RecordService::save($form, $v); return []; } catch (\Illuminate\Validation\ValidationException $e) { return $e->errors(); }
    }

    public function test_old_messages(): void
    {
        $this->actingAs($this->makeUser('OWNER')); \App\Services\Context::set(1, null);
        $this->assertSame(['LOV Group is required'], $this->err('cat_src_lov', ['lov_value' => 'x'])['lov_group'] ?? null);
        $this->assertSame(['LOV Value is required'], $this->err('cat_src_lov', ['lov_group' => 'G'])['lov_value'] ?? null);
        $this->assertSame(['Forwarder Name is required'], $this->err('t_src_forwarders', [])['forwarder_name'] ?? null);
        $this->assertSame(['Investor Name is required'], $this->err('t_src_investors', [])['investor_name'] ?? null);
        $this->assertSame(['Supplier Name is required'], $this->err('t_src_suppliers', [])['supplier_name'] ?? null);
        $this->assertSame(['Trigger / Milestone is required'], $this->err('t_src_payment_terms', ['pct' => 30])['milestone'] ?? null);
        $this->assertSame(['Google Drive URL is required'], $this->err('t_src_documents', ['document_name' => 'PI'])['drive_url'] ?? null);
    }
}
