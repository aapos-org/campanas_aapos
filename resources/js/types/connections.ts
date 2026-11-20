export type DriverValue = 'gmail_smtp' | 'gmail_api' | 'elastic_email';

export type ConnectionVerification = {
    domain?: string;
    account?: string | null;
    records?: {
        spf: boolean;
        dkim: boolean;
        dmarc: boolean;
        mx: boolean;
        tracking: string | null;
    } | null;
} | null;

export type MailConnection = {
    id: number;
    name: string;
    driver: DriverValue;
    driver_label: string;
    is_gmail: boolean;
    from_email: string;
    from_name: string | null;
    reply_to: string | null;
    daily_limit: number | null;
    used_today: number;
    is_default: boolean;
    status: 'unverified' | 'active' | 'error';
    status_label: string;
    last_verified_at: string | null;
    last_error: string | null;
    verification: ConnectionVerification;
    username: string | null;
    has_password: boolean;
    api_key_hint: string | null;
    oauth_connected: boolean;
    oauth_account: string | null;
};

export type Option<T extends string = string> = { value: T; label: string };
