<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Raw webhook requests from providers, stored before processing (audit + reprocessing).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_calls', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 30);
            $table->string('method', 10);
            $table->jsonb('payload');
            // Selected headers only (signature, content type, user agent)
            $table->jsonb('headers')->nullable();
            $table->string('ip', 45)->nullable();
            // received | processed | ignored | failed
            $table->string('status', 20)->default('received');
            $table->text('result')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['provider', 'created_at']);
        });

        Schema::table('audience_contact', function (Blueprint $table) {
            // Soft bounces in a row; reaching the threshold cleans the address.
            $table->unsignedSmallInteger('soft_bounces')->default(0)->after('unsubscribe_reason');
        });
    }

    public function down(): void
    {
        Schema::table('audience_contact', fn (Blueprint $table) => $table->dropColumn('soft_bounces'));
        Schema::dropIfExists('webhook_calls');
    }
};
