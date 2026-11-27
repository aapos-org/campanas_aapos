<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Writing assistant configuration: one row per organization. The API key lives
        // here (encrypted), never in .env.
        Schema::create('ai_assistant_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('provider', 20)->default('anthropic');
            $table->text('api_key')->nullable(); // encrypted cast
            $table->string('model', 100)->default('claude-opus-5-5');
            $table->boolean('enabled')->default(false);
            $table->boolean('live_review_enabled')->default(true);
            $table->boolean('manual_review_enabled')->default(true);
            $table->unsignedSmallInteger('debounce_ms')->default(1800);
            $table->unsignedSmallInteger('min_chars_change')->default(20);
            $table->jsonb('categories')->default('[]');
            $table->unsignedTinyInteger('max_findings')->default(8);
            $table->boolean('smart_brevity_enabled')->default(true);
            $table->unsignedSmallInteger('smart_brevity_max_words')->default(300);
            $table->text('tone_guidelines')->nullable();
            $table->unsignedInteger('daily_limit')->nullable();
            $table->unsignedInteger('monthly_limit')->nullable();
            $table->unsignedSmallInteger('timeout_seconds')->default(30);
            // unverified | active | error
            $table->string('status', 12)->default('unverified');
            $table->timestamp('last_verified_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();
        });

        // AI reviews per organization and day (cached answers are not counted).
        Schema::create('ai_review_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->unsignedInteger('reviews')->default(0);
            $table->timestamps();

            $table->unique(['organization_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_review_usage');
        Schema::dropIfExists('ai_assistant_settings');
    }
};
