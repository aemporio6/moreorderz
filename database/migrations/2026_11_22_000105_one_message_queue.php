<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R-G_CORE-46 ONE message queue: every engine writes tm_messages (statements, approvals, capital calls, finance alerts, monthly summaries, VA statements,
 * WhatsApp replies, export e-mails, PO e-mails). The old outboxes (wa_outbound, email_outbound) and the finance log (fin_messages) are copied in as history and
 * are no longer written or sent from. A message for a person who has no login keeps the person (fk_id_account) and no user.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement('ALTER TABLE tm_messages MODIFY user_id INT NULL');
        Schema::table('tm_messages', function (Blueprint $t) {
            if (! Schema::hasColumn('tm_messages', 'fk_id_ent')) $t->integer('fk_id_ent')->default(0);
            if (! Schema::hasColumn('tm_messages', 'fk_id_account')) $t->integer('fk_id_account')->nullable();
            if (! Schema::hasColumn('tm_messages', 'link_table')) $t->string('link_table', 64)->nullable();
            if (! Schema::hasColumn('tm_messages', 'link_id')) $t->bigInteger('link_id')->nullable();
            if (! Schema::hasColumn('tm_messages', 'attachment')) $t->string('attachment', 255)->nullable();
        });
        try { DB::statement('CREATE INDEX idx_link ON tm_messages (link_table, link_id)'); } catch (\Throwable) {}
        try { DB::statement('CREATE INDEX idx_account ON tm_messages (fk_id_account, created_at)'); } catch (\Throwable) {}
        $user = fn ($acc) => $acc ? DB::table('t_users')->where('fk_id_account', $acc)->orderBy('id')->value('id') : null;
        $st = fn ($s) => $s === 'HELD' ? 'QUEUED' : $s;
        foreach (DB::table('wa_outbound')->orderBy('id')->get() as $m)
            DB::table('tm_messages')->insertOrIgnore(['fk_id_ent' => $m->fk_id_ent, 'fk_id_account' => $m->fk_id_account, 'user_id' => $user($m->fk_id_account), 'channel' => 'WHATSAPP', 'direction' => 'OUT', 'to_address' => $m->phone,
                'template_key' => $m->template_code, 'rule_code' => $m->template_code, 'text' => explode("\n\x1e", (string) $m->body, 2)[0], 'template_params' => null, 'event_key' => 'WAO:' . $m->id, 'status' => $st($m->status),
                'provider_id' => $m->provider_msg_id, 'error' => $m->error, 'retry_count' => (int) $m->attempts, 'link_table' => $m->link_table, 'link_id' => $m->link_id, 'created_at' => $m->queued_at, 'sent_at' => $m->sent_at]);
        foreach (DB::table('email_outbound')->orderBy('id')->get() as $m) {
            [$body, $file] = array_pad(explode("\n\x1e", (string) $m->body, 2), 2, null);
            DB::table('tm_messages')->insertOrIgnore(['fk_id_ent' => $m->fk_id_ent, 'fk_id_account' => $m->fk_id_account, 'user_id' => $user($m->fk_id_account), 'channel' => 'EMAIL', 'direction' => 'OUT', 'to_address' => $m->to_address,
                'template_key' => $m->template_code, 'rule_code' => $m->template_code, 'subject' => $m->subject, 'text' => $body, 'attachment' => $file, 'event_key' => 'EMO:' . $m->id, 'status' => $st($m->status), 'error' => $m->error,
                'retry_count' => (int) $m->attempts, 'link_table' => $m->link_table, 'link_id' => $m->link_id, 'created_at' => $m->queued_at, 'sent_at' => $m->sent_at]);
        }
        foreach (DB::table('fin_messages')->orderBy('id')->get() as $m)
            DB::table('tm_messages')->insertOrIgnore(['fk_id_ent' => 0, 'fk_id_account' => $m->investor_id, 'user_id' => $m->user_id, 'channel' => in_array($m->channel, ['WHATSAPP', 'EMAIL'], true) ? $m->channel : 'INAPP', 'direction' => 'OUT',
                'rule_code' => $m->kind, 'text' => $m->text, 'event_key' => mb_substr((string) $m->event_key, 0, 120), 'status' => $m->status, 'created_at' => $m->created_at, 'sent_at' => $m->sent_at, 'note' => 'from the finance log']);
        // the copies of messages still waiting go out from tm_messages – the old rows are closed so nothing is sent twice
        DB::table('wa_outbound')->whereIn('status', ['QUEUED', 'HELD'])->update(['status' => 'FAILED', 'error' => 'Moved to the one message queue (tm_messages)']);
        DB::table('email_outbound')->whereIn('status', ['QUEUED', 'HELD'])->update(['status' => 'FAILED', 'error' => 'Moved to the one message queue (tm_messages)']);
    }

    public function down(): void {}
};
