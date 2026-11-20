<?php

namespace App\Enums;

enum MailDriver: string
{
    case GmailSmtp = 'gmail_smtp';
    case GmailApi = 'gmail_api';
    case ElasticEmail = 'elastic_email';

    public function label(): string
    {
        return match ($this) {
            self::GmailSmtp => 'Gmail (SMTP con contraseña de aplicación)',
            self::GmailApi => 'Gmail (OAuth / API de Gmail)',
            self::ElasticEmail => 'ElasticEmail (API v4)',
        };
    }

    public function isGmail(): bool
    {
        return $this !== self::ElasticEmail;
    }

    /**
     * Default daily cap suggested when creating the connection. Gmail limits (Google, 2026):
     * ~500/day for consumer accounts and 2,000/day for Workspace; we stay below with margin.
     */
    public function suggestedDailyLimit(): ?int
    {
        return match ($this) {
            self::GmailSmtp, self::GmailApi => 1800,
            self::ElasticEmail => null,
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $d) => ['value' => $d->value, 'label' => $d->label()], self::cases());
    }
}
