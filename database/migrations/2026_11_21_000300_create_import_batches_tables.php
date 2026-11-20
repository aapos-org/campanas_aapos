<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('audience_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // csv | xlsx | mailchimp
            $table->string('source', 20);
            $table->string('original_filename')->nullable();
            $table->string('file_path')->nullable();
            // uploaded | mapped | processing | completed | failed | cancelled (App\Enums\ImportStatus)
            $table->string('status', 20)->default('uploaded');
            $table->jsonb('headers')->default('[]');
            // column index => target ("email", "first_name", "field:<key>", "tags", "ignore")
            $table->jsonb('mapping')->default('{}');
            // status for new contacts, update existing, tags to add, consent text...
            $table->jsonb('options')->default('{}');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->string('job_batch_id')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
        });

        Schema::create('import_batch_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('email')->nullable();
            // failed | skipped
            $table->string('outcome', 10);
            $table->string('reason');
            $table->jsonb('data')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['import_batch_id', 'row_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batch_rows');
        Schema::dropIfExists('import_batches');
    }
};
