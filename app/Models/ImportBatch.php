<?php

namespace App\Models;

use App\Enums\ImportStatus;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $audience_id
 * @property int|null $user_id
 * @property string $source
 * @property string|null $original_filename
 * @property string|null $file_path
 * @property ImportStatus $status
 * @property list<string> $headers
 * @property array<string, string> $mapping
 * @property array<string, mixed> $options
 * @property int $total_rows
 * @property int $processed_rows
 * @property int $created_count
 * @property int $updated_count
 * @property int $skipped_count
 * @property int $failed_count
 * @property string|null $job_batch_id
 * @property string|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 */
#[Fillable(['organization_id', 'audience_id', 'user_id', 'source', 'original_filename', 'file_path', 'status', 'headers', 'mapping', 'options', 'total_rows', 'processed_rows', 'created_count', 'updated_count', 'skipped_count', 'failed_count', 'job_batch_id', 'error', 'started_at', 'finished_at'])]
class ImportBatch extends Model
{
    use BelongsToOrganization;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'uploaded',
        'headers' => '[]',
        'mapping' => '{}',
        'options' => '{}',
    ];

    protected function casts(): array
    {
        return [
            'status' => ImportStatus::class,
            'headers' => 'array',
            'mapping' => 'array',
            'options' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Audience, $this>
     */
    public function audience(): BelongsTo
    {
        return $this->belongsTo(Audience::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<ImportBatchRow, $this>
     */
    public function rows(): HasMany
    {
        return $this->hasMany(ImportBatchRow::class);
    }

    public function progress(): int
    {
        return $this->total_rows > 0 ? (int) floor($this->processed_rows * 100 / $this->total_rows) : 0;
    }
}
