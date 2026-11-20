<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('timezone')->default('America/Guatemala');
            $table->string('locale', 10)->default('es');
            // Mandatory physical address shown in every campaign footer (anti-spam laws).
            $table->text('physical_address')->nullable();
            $table->string('default_from_name')->nullable();
            $table->string('default_from_email')->nullable();
            // Usage limits (contacts, monthly sends...). Structure documented in App\Models\Organization.
            $table->jsonb('limits')->default('{}');
            $table->jsonb('settings')->default('{}');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
