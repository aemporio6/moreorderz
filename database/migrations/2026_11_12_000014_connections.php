<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Outside connections (08_APIs_WhatsApp_Mobile, 06B IC-0): marketplace / carrier / messaging adapters chosen by data, every call logged
 * without secrets, an outbox for e-mail like the WhatsApp one, an inbox for WhatsApp replies. Secrets of a connection live in sys_vault only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_connections', function (Blueprint $t) {
            $t->string('adapter', 40)->nullable()->after('provider');          // which built adapter talks to the service (data, not code)
            $t->string('label', 120)->nullable()->after('adapter');
            $t->integer('fk_id_ent')->default(0)->after('id');
            $t->integer('fk_id_carrier')->nullable();
            $t->text('settings_json')->nullable();                             // region, marketplace ids, shop cipher, base url … (never a secret)
            $t->string('last_test', 255)->nullable();
        });
        DB::statement('ALTER TABLE api_connections MODIFY fk_id_branch INT NULL');
        Schema::table('wa_outbound', function (Blueprint $t) {
            $t->integer('fk_id_ent')->default(0)->after('id');
            $t->tinyInteger('attempts')->default(0);
            $t->dateTime('next_try_at')->nullable();
        });
        DB::statement("ALTER TABLE wa_outbound MODIFY status ENUM('QUEUED','HELD','SENT','DELIVERED','READ','FAILED') NOT NULL DEFAULT 'QUEUED'");
        DB::statement('ALTER TABLE wa_outbound MODIFY fk_id_account INT NULL');
        Schema::create('email_outbound', function (Blueprint $t) {
            $t->id();
            $t->integer('fk_id_ent')->default(0);
            $t->integer('fk_id_account')->nullable();
            $t->string('to_address', 190);
            $t->string('subject', 200);
            $t->text('body');
            $t->unsignedBigInteger('fk_id_document')->nullable();               // attachment (sys_documents), e.g. a statement PDF
            $t->string('template_code', 40)->nullable();
            $t->string('link_table', 64)->nullable();
            $t->unsignedBigInteger('link_id')->nullable();
            $t->enum('status', ['QUEUED', 'HELD', 'SENT', 'FAILED'])->default('QUEUED');
            $t->string('error', 255)->nullable();
            $t->tinyInteger('attempts')->default(0);
            $t->dateTime('next_try_at')->nullable();
            $t->dateTime('queued_at')->useCurrent();
            $t->dateTime('sent_at')->nullable();
            $t->index(['status', 'next_try_at']);
        });
        Schema::create('wa_inbound', function (Blueprint $t) {
            $t->id();
            $t->integer('fk_id_ent')->default(0);
            $t->string('provider_msg_id', 120)->unique();
            $t->string('phone', 30);
            $t->integer('fk_id_account')->nullable();
            $t->text('body')->nullable();
            $t->string('media_id', 120)->nullable();
            $t->string('reply_to', 120)->nullable();
            $t->enum('status', ['NEW', 'HANDLED', 'IGNORED'])->default('NEW');
            $t->string('note', 255)->nullable();
            $t->dateTime('received_at')->useCurrent();
        });
        Schema::create('sys_api_log', function (Blueprint $t) {
            $t->id();
            $t->integer('fk_id_ent')->default(0);
            $t->integer('fk_id_connection')->nullable();
            $t->string('adapter', 40);
            $t->string('method', 8);
            $t->string('url', 255);                                             // path only: query strings can carry keys
            $t->smallInteger('status_code')->nullable();
            $t->integer('ms')->nullable();
            $t->string('error', 255)->nullable();
            $t->integer('user_id')->nullable();
            $t->dateTime('created_at')->useCurrent();
            $t->index(['fk_id_connection', 'id']);
        });
        if (! DB::table('wa_templates')->where('code', 'DIGEST')->exists())
            DB::table('wa_templates')->insert(['code' => 'DIGEST', 'title' => 'Daily tasks digest', 'audience' => 'TL', 'body' => 'Good morning {name}: {late} late and {urgent} urgent task(s) are waiting. {link}', 'f_active' => 1]);
    }

    public function down(): void
    {
        Schema::dropIfExists('sys_api_log');
        Schema::dropIfExists('wa_inbound');
        Schema::dropIfExists('email_outbound');
        DB::table('wa_templates')->where('code', 'DIGEST')->delete();
        Schema::table('wa_outbound', fn (Blueprint $t) => $t->dropColumn(['fk_id_ent', 'attempts', 'next_try_at']));
        Schema::table('api_connections', fn (Blueprint $t) => $t->dropColumn(['adapter', 'label', 'fk_id_ent', 'fk_id_carrier', 'settings_json', 'last_test']));
    }
};
