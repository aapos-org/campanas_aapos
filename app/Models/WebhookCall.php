<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $provider
 * @property string $method
 * @property array<array-key, mixed> $payload
 * @property array<string, mixed>|null $headers
 * @property string|null $ip
 * @property string $status
 * @property string|null $result
 * @property Carbon|null $processed_at
 * @property Carbon|null $created_at
 */
#[Fillable(['provider', 'method', 'payload', 'headers', 'ip', 'status', 'result', 'processed_at'])]
class WebhookCall extends Model
{
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'headers' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}
