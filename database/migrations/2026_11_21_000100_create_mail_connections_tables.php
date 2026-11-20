<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('name');
            // gmail_smtp | gmail_api | elastic_email (App\Enums\MailDriver)
            $table->string('driver', 30);
            $table->string('from_email');
            $table->string('from_name')->nullable();
            $table->string('reply_to')->nullable();
            // Encrypted JSON (password, API key, OAuth tokens). Never logged or sent to the browser.
            $table->text('credentials')->nullable();
            // Max emails per day for this connection (null = no app-side limit).
            $table->unsignedInteger('daily_limit')->nullable();
            $table->boolean('is_default')->default(false);
            // unverified | active | error (App\Enums\ConnectionStatus)
            $table->string('status', 20)->default('unverified');
            $table->timestamp('last_verified_at')->nullable();
            $table->text('last_error')->nullable();
            $table->jsonb('meta')->default('{}');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'driver']);
        });

        Schema::create('mail_connection_daily_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_connection_id')->constrained()->cascadeOnDelete();
            // Calendar day in the organization's timezone.
            $table->date('day');
            $table->unsignedInteger('sent_count')->default(0);
            $table->timestamps();

            $table->unique(['mail_connection_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_connection_daily_usage');
        Schema::dropIfExists('mail_connections');
    }
};
