export type ContactStatusValue =
    | 'subscribed'
    | 'unsubscribed'
    | 'cleaned'
    | 'pending'
    | 'non_subscribed';

export type TagRef = { id: number; name: string };

export type FieldDefinition = {
    key: string;
    label: string;
    type: 'text' | 'number' | 'date' | 'birthday' | 'select' | 'phone';
    options: string[];
    required: boolean;
};

export type ImportSummary = {
    id: number;
    audience: { id: number; name: string } | null;
    user: string | null;
    file: string | null;
    status: 'uploaded' | 'processing' | 'completed' | 'failed' | 'cancelled';
    status_label: string;
    finished: boolean;
    total_rows: number;
    processed_rows: number;
    progress: number;
    created_count: number;
    updated_count: number;
    skipped_count: number;
    failed_count: number;
    options: Record<string, unknown>;
    error: string | null;
    created_at: string | null;
    finished_at: string | null;
};
