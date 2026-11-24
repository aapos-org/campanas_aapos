<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $automation_run_id
 * @property int|null $automation_step_id
 * @property string $message
 * @property Carbon|null $created_at
 */
#[Fillable(['automation_run_id', 'automation_step_id', 'message', 'created_at'])]
class AutomationRunLog extends Model
{
    public const UPDATED_AT = null;
}
