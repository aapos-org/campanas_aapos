<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $campaign_id
 * @property string $url
 * @property string $hash
 * @property int $position
 * @property int|null $historic_clicks
 * @property int|null $historic_unique_clicks
 */
#[Fillable(['campaign_id', 'url', 'hash', 'position', 'historic_clicks', 'historic_unique_clicks'])]
class CampaignLink extends Model
{
    public static function hashFor(string $url): string
    {
        return substr(hash('sha256', $url), 0, 16);
    }
}
