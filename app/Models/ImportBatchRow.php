<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Row of an import that was skipped or failed, with the reason (downloadable report).
 *
 * @property int $id
 * @property int $import_batch_id
 * @property int $row_number
 * @property string|null $email
 * @property string $outcome
 * @property string $reason
 * @property array<string, mixed>|null $data
 */
#[Fillable(['import_batch_id', 'row_number', 'email', 'outcome', 'reason', 'data', 'created_at'])]
class ImportBatchRow extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'data' => 'array',
        ];
    }
}
