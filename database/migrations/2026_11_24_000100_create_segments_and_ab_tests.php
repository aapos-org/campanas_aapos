<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('audience_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // all = every condition (Y), any = at least one (O)
            $table->string('match', 3)->default('all');
            // list of {field, op, value}; validated by App\Services\Segments\SegmentRules
            $table->jsonb('conditions')->default('[]');
            $table->string('mailchimp_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'mailchimp_id']);
        });

        Schema::create('campaign_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->char('label', 1);
            $table->string('subject')->nullable();
            $table->string('preheader')->nullable();
            $table->string('from_name')->nullable();
            $table->longText('mjml')->nullable();
            $table->jsonb('design')->nullable();
            $table->longText('html')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'label']);
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->foreignId('segment_id')->nullable()->after('tag_ids')->constrained()->nullOnDelete();
            // regular | automation (backing campaign of an automation email step)
            $table->string('kind', 12)->default('regular')->after('name');
            // A/B test: subject | from_name | content (null = no test)
            $table->string('ab_type', 12)->nullable();
            $table->unsignedTinyInteger('ab_sample_percent')->nullable();
            // opens | clicks
            $table->string('ab_metric', 8)->nullable();
            $table->unsignedSmallInteger('ab_wait_hours')->nullable();
            $table->foreignId('ab_winner_variant_id')->nullable()->constrained('campaign_variants')->nullOnDelete();
            $table->timestamp('ab_sample_finished_at')->nullable();
            $table->timestamp('ab_decided_at')->nullable();

            $table->index(['organization_id', 'kind']);
        });

        Schema::table('campaign_recipients', function (Blueprint $table) {
            $table->foreignId('variant_id')->nullable()->constrained('campaign_variants')->nullOnDelete();
            $table->unsignedBigInteger('automation_run_id')->nullable();
            $table->dropUnique(['campaign_id', 'contact_id']);
        });

        // A contact receives a regular campaign once; automation emails once per run.
        DB::statement('CREATE UNIQUE INDEX campaign_recipients_campaign_contact_unique ON campaign_recipients (campaign_id, contact_id) WHERE automation_run_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX campaign_recipients_campaign_run_unique ON campaign_recipients (campaign_id, automation_run_id) WHERE automation_run_id IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS campaign_recipients_campaign_run_unique');
        DB::statement('DROP INDEX IF EXISTS campaign_recipients_campaign_contact_unique');

        Schema::table('campaign_recipients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('variant_id');
            $table->dropColumn('automation_run_id');
            $table->unique(['campaign_id', 'contact_id']);
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'kind']);
            $table->dropConstrainedForeignId('ab_winner_variant_id');
            $table->dropConstrainedForeignId('segment_id');
            $table->dropColumn(['kind', 'ab_type', 'ab_sample_percent', 'ab_metric', 'ab_wait_hours', 'ab_sample_finished_at', 'ab_decided_at']);
        });

        Schema::dropIfExists('campaign_variants');
        Schema::dropIfExists('segments');
    }
};
