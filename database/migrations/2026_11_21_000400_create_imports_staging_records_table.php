<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Raw Mailchimp records loaded from the encrypted backup, before normalization.
 * One table for every resource type (column `resource`), with the outcome of each record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imports_staging_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('snapshot', 64);
            // audience | merge_field | member | segment | signup_form | template | campaign | automation
            $table->string('resource', 30);
            $table->string('list_id', 64)->nullable();
            $table->string('mailchimp_id', 128);
            $table->jsonb('payload');
            // staged | imported | skipped | rejected
            $table->string('status', 20)->default('staged');
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'snapshot', 'resource', 'list_id', 'mailchimp_id'], 'staging_unique_record')->nullsNotDistinct();
            $table->index(['snapshot', 'resource', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imports_staging_records');
    }
};
