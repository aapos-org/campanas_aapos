<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audiences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('default_from_name')->nullable();
            $table->string('default_from_email')->nullable();
            // Reminder shown in every email: why the recipient is receiving it.
            $table->text('permission_reminder')->nullable();
            $table->boolean('double_opt_in')->default(true);
            $table->string('mailchimp_id')->nullable();
            $table->string('mailchimp_web_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'mailchimp_id']);
        });

        // Organization-wide custom fields; values live in contacts.fields (jsonb) keyed by `key`.
        Schema::create('merge_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('key', 50);
            $table->string('label');
            // text | number | date | birthday | select | phone (App\Enums\FieldType)
            $table->string('type', 20);
            $table->jsonb('options')->default('[]');
            $table->boolean('required')->default(false);
            $table->unsignedInteger('position')->default(0);
            // Original Mailchimp merge tag (e.g. MMERGE5) for idempotent migration.
            $table->string('mailchimp_tag')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'key']);
        });

        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            // Normalized: trimmed + lowercase. Unique per organization.
            $table->string('email');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            // E.164
            $table->string('phone', 32)->nullable();
            $table->string('language', 10)->nullable();
            $table->jsonb('fields')->default('{}');
            $table->string('mailchimp_unique_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'email']);
        });
        DB::statement('CREATE INDEX contacts_fields_gin ON contacts USING gin (fields jsonb_path_ops)');

        // Subscription of a contact to an audience, with its status and proof of consent.
        Schema::create('audience_contact', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('audience_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            // subscribed | unsubscribed | cleaned | pending | non_subscribed (App\Enums\ContactStatus)
            $table->string('status', 20);
            $table->timestamp('subscribed_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->string('unsubscribe_reason')->nullable();
            // Consent evidence
            $table->timestamp('consent_at')->nullable();
            $table->string('consent_ip', 45)->nullable();
            // manual | import | form | api | mailchimp ... plus free detail
            $table->string('consent_source')->nullable();
            $table->text('consent_detail')->nullable();
            $table->string('mailchimp_id')->nullable();
            $table->timestamps();

            $table->unique(['audience_id', 'contact_id']);
            $table->index(['audience_id', 'status']);
            $table->index('contact_id');
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->string('mailchimp_id')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
        });

        Schema::create('contact_tag', function (Blueprint $table) {
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->primary(['contact_id', 'tag_id']);
            $table->index('tag_id');
        });

        // Global or per-audience suppression list. A suppressed address is never
        // (re)subscribed by imports or automatic processes.
        Schema::create('suppressions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('email');
            // null = global (all audiences)
            $table->foreignId('audience_id')->nullable()->constrained()->cascadeOnDelete();
            // unsubscribed | hard_bounce | complaint | cleaned | manual (App\Enums\SuppressionReason)
            $table->string('reason', 20);
            $table->string('source')->nullable();
            $table->text('detail')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'email']);
        });
        DB::statement('CREATE UNIQUE INDEX suppressions_global_unique ON suppressions (organization_id, email) WHERE audience_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX suppressions_audience_unique ON suppressions (organization_id, email, audience_id) WHERE audience_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('suppressions');
        Schema::dropIfExists('contact_tag');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('audience_contact');
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('merge_fields');
        Schema::dropIfExists('audiences');
    }
};
