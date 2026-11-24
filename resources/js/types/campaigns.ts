export type CampaignStatusValue =
    | 'draft'
    | 'scheduled'
    | 'sending'
    | 'testing'
    | 'automation'
    | 'paused'
    | 'sent'
    | 'cancelled'
    | 'failed';

export type CampaignSummary = {
    id: number;
    name: string;
    subject: string | null;
    audience: string | null;
    status: CampaignStatusValue;
    status_label: string;
    is_historic: boolean;
    recipients_count: number;
    sent_count: number;
    scheduled_at: string | null;
    finished_at: string | null;
    updated_at: string | null;
};

export type CampaignDetail = CampaignSummary & {
    preheader: string | null;
    from_name: string | null;
    from_email: string | null;
    reply_to: string | null;
    audience_id: number | null;
    tag_ids: number[];
    mail_connection_id: number | null;
    connection: string | null;
    editor: 'visual' | 'html';
    has_content: boolean;
    rate_per_minute: number | null;
    track_opens: boolean;
    track_clicks: boolean;
    failed_count: number;
    skipped_count: number;
    status_reason: string | null;
    started_at: string | null;
    last_test_sent_at: string | null;
    timezone: string;
    report_summary: Record<string, unknown> | null;
    kind: 'regular' | 'automation';
    segment_id: number | null;
    segment: string | null;
    ab_type: 'subject' | 'from_name' | 'content' | null;
    ab_sample_percent: number | null;
    ab_metric: 'opens' | 'clicks' | null;
    ab_wait_hours: number | null;
    ab_winner_variant_id: number | null;
    ab_decided_at: string | null;
    variants: {
        id: number;
        label: string;
        subject: string | null;
        from_name: string | null;
        has_content: boolean;
    }[];
};
