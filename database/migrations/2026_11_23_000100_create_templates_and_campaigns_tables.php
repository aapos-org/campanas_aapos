<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            // visual (GrapesJS + MJML) | html (code)
            $table->string('editor', 10)->default('visual');
            $table->longText('mjml')->nullable();
            // GrapesJS project data, to reopen the visual editor
            $table->jsonb('design')->nullable();
            // Sanitized, CSS-inlined HTML ready to send
            $table->longText('html')->nullable();
            // own | mailchimp
            $table->string('source', 20)->default('own');
            $table->string('mailchimp_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'mailchimp_id']);
        });

        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('subject')->nullable();
            $table->string('preheader')->nullable();
            $table->string('from_name')->nullable();
            $table->string('from_email')->nullable();
            $table->string('reply_to')->nullable();
            $table->foreignId('audience_id')->nullable()->constrained()->nullOnDelete();
            // Optional: only contacts with ANY of these tags (segments arrive in Module 5)
            $table->jsonb('tag_ids')->default('[]');
            $table->foreignId('mail_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('template_id')->nullable()->constrained()->nullOnDelete();
            $table->string('editor', 10)->default('visual');
            $table->longText('mjml')->nullable();
            $table->jsonb('design')->nullable();
            $table->longText('html')->nullable();
            // draft | scheduled | sending | paused | sent | cancelled | failed (App\Enums\CampaignStatus)
            $table->string('status', 20)->default('draft');
            $table->timestamp('scheduled_at')->nullable();
            $table->string('timezone', 64)->default('America/Guatemala');
            // Emails per minute (null = no app-side limit)
            $table->unsignedInteger('rate_per_minute')->nullable();
            $table->boolean('track_opens')->default(true);
            $table->boolean('track_clicks')->default(true);
            $table->unsignedInteger('recipients_count')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->string('status_reason')->nullable();
            $table->string('job_batch_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('last_test_sent_at')->nullable();
            // Campaigns migrated from Mailchimp are read-only history
            $table->boolean('is_historic')->default(false);
            $table->string('mailchimp_id')->nullable();
            $table->string('mailchimp_web_id')->nullable();
            $table->jsonb('report_summary')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'status']);
            $table->unique(['organization_id', 'mailchimp_id']);
        });

        Schema::create('campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('audience_contact')->nullOnDelete();
            // Frozen at send time
            $table->string('email');
            // pending | sending | sent | failed | skipped (App\Enums\RecipientStatus)
            $table->string('status', 10)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->string('message_id')->nullable();
            $table->string('provider_id')->nullable();
            // Random public identifier used in tracking/unsubscribe links
            $table->char('token', 32)->unique();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'contact_id']);
            $table->index(['campaign_id', 'status']);
            $table->index('message_id');
        });

        Schema::create('campaign_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->text('url');
            $table->char('hash', 16);
            $table->unsignedInteger('position')->default(0);
            // Historic totals (Mailchimp)
            $table->unsignedInteger('historic_clicks')->nullable();
            $table->unsignedInteger('historic_unique_clicks')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_links');
        Schema::dropIfExists('campaign_recipients');
        Schema::dropIfExists('campaigns');
        Schema::dropIfExists('templates');
    }
};
