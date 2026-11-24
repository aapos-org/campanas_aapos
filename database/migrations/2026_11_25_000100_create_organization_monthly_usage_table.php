<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Emails sent per organization and calendar month (organization timezone).
        Schema::create('organization_monthly_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->char('month', 7); // YYYY-MM
            $table->unsignedInteger('emails_sent')->default(0);
            $table->timestamps();

            $table->unique(['organization_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_monthly_usage');
    }
};
