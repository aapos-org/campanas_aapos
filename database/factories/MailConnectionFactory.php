<?php

namespace Database\Factories;

use App\Enums\ConnectionStatus;
use App\Enums\MailDriver;
use App\Models\MailConnection;
use App\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MailConnection>
 */
class MailConnectionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => fn () => app(CurrentOrganization::class)->id() ?? Organization::factory(),
            'name' => 'ElasticEmail principal',
            'driver' => MailDriver::ElasticEmail,
            'from_email' => 'boletin@example.org',
            'from_name' => 'AAPOS',
            'credentials' => ['api_key' => 'test-api-key'],
            'daily_limit' => null,
            'status' => ConnectionStatus::Active,
        ];
    }

    public function gmailSmtp(int $dailyLimit = 1800): static
    {
        return $this->state(fn () => [
            'name' => 'Gmail pruebas',
            'driver' => MailDriver::GmailSmtp,
            'from_email' => 'pruebas@example.org',
            'credentials' => ['username' => 'pruebas@example.org', 'password' => 'abcd efgh ijkl mnop'],
            'daily_limit' => $dailyLimit,
        ]);
    }

    public function gmailApi(int $dailyLimit = 1800): static
    {
        return $this->state(fn () => [
            'name' => 'Gmail OAuth',
            'driver' => MailDriver::GmailApi,
            'from_email' => 'pruebas@example.org',
            'credentials' => [
                'access_token' => 'ya29.test',
                'refresh_token' => '1//refresh',
                'expires_at' => now()->addHour()->getTimestamp(),
            ],
            'daily_limit' => $dailyLimit,
        ]);
    }
}
