<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->foreignId('audience_id')->constrained()->cascadeOnDelete();
            // subscribed | tag_added | date_field (App\Enums\AutomationTrigger)
            $table->string('trigger_type', 20);
            $table->jsonb('trigger_config')->default('{}');
            // draft | active | paused
            $table->string('status', 10)->default('draft');
            // false: a contact enters the flow at most once
            $table->boolean('allow_reentry')->default(false);
            $table->foreignId('mail_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('from_name')->nullable();
            $table->string('from_email')->nullable();
            $table->string('reply_to')->nullable();
            // Only events after activation start the flow (no retroactive enrolment).
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });

        Schema::create('automation_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            // send_email | wait | add_tag | remove_tag | condition (App\Enums\AutomationStepType)
            $table->string('type', 20);
            $table->jsonb('config')->default('{}');
            // Backing campaign of a send_email step (content, tracking and report)
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['automation_id', 'position']);
        });

        Schema::create('automation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('automation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('audience_contact')->nullOnDelete();
            // Identifies one entry: "once" when re-entry is disabled; otherwise the event (date, year…)
            $table->string('trigger_key', 64);
            // active | completed | exited | failed
            $table->string('status', 10)->default('active');
            $table->foreignId('next_step_id')->nullable()->constrained('automation_steps')->nullOnDelete();
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('entered_at');
            $table->timestamp('finished_at')->nullable();
            $table->string('exit_reason')->nullable();
            $table->timestamps();

            $table->unique(['automation_id', 'contact_id', 'trigger_key']);
            $table->index(['status', 'next_run_at']);
        });

        Schema::create('automation_run_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_step_id')->nullable()->constrained()->nullOnDelete();
            $table->string('message', 500);
            $table->timestamp('created_at')->nullable();
        });

        Schema::table('campaign_recipients', function (Blueprint $table) {
            $table->foreign('automation_run_id')->references('id')->on('automation_runs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('campaign_recipients', function (Blueprint $table) {
            $table->dropForeign(['automation_run_id']);
        });
        Schema::dropIfExists('automation_run_logs');
        Schema::dropIfExists('automation_runs');
        Schema::dropIfExists('automation_steps');
        Schema::dropIfExists('automations');
    }
};
