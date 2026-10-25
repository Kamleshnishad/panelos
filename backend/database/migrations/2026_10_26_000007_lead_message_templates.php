<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LEADS_PLAN 5.3 (audit LP-02). The company's ready-made messages for contacting a lead: WhatsApp texts and e-mails with placeholders such as
 * {name} and {my_name}. Starter templates are made for a company the first time it needs them (LeadMessageService::ensureDefaults); the company edits them.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('lead_message_templates')) {
            Schema::create('lead_message_templates', function (Blueprint $t) {
                $t->id();
                $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $t->string('channel', 10);                      // whatsapp | email
                $t->string('name', 80);
                $t->string('subject', 150)->nullable();         // e-mail only
                $t->text('body');
                $t->boolean('is_active')->default(true);
                $t->unsignedSmallInteger('sort_order')->default(0);
                $t->timestamps();
                $t->softDeletes();
                $t->index(['company_id', 'channel', 'sort_order']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_message_templates');
    }
};
