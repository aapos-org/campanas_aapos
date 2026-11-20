<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $mail_connection_id
 * @property Carbon $day
 * @property int $sent_count
 */
#[Fillable(['mail_connection_id', 'day', 'sent_count'])]
class MailConnectionDailyUsage extends Model
{
    protected $table = 'mail_connection_daily_usage';

    protected function casts(): array
    {
        return [
            'day' => 'date',
            'sent_count' => 'integer',
        ];
    }
}
