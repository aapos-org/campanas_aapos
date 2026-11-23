<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('audience_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // Public, unguessable identifier used in /f/{slug}
            $table->string('slug', 32)->unique();
            $table->boolean('is_active')->default(true);
            // [{key, label, required}] in display order. "email" is always present.
            $table->jsonb('fields');
            // title, description, button, success, pending, confirmed, already, confirmation_subject, confirmation_intro
            $table->jsonb('texts');
            // primary_color, background_color, text_color (hex)
            $table->jsonb('style');
            // null = inherit the audience setting
            $table->boolean('double_opt_in')->nullable();
            $table->boolean('captcha')->default(false);
            $table->jsonb('tags')->default('[]');
            $table->string('redirect_url', 500)->nullable();
            $table->unsignedInteger('submissions_count')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forms');
    }
};
