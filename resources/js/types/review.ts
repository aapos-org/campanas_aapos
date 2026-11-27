export type ReviewSeverity = 'info' | 'warning' | 'error';

export type ReviewFinding = {
    severity: ReviewSeverity;
    category: string;
    excerpt: string;
    message: string;
    suggestion: string;
    source: 'local' | 'ai';
};

export type ReviewMetrics = {
    words: number;
    max_words: number;
    subject_chars: number;
    subject_max_chars: number;
    subject_words: number;
    sentences: number;
    avg_sentence_words: number;
    sentence_max_words: number;
    long_paragraphs: number;
    has_bullets: boolean;
    has_bold: boolean;
};

/** Public assistant settings for the editor (no secrets). */
export type AssistantConfig = {
    ai_available: boolean;
    live_review_enabled: boolean;
    manual_review_enabled: boolean;
    smart_brevity_enabled: boolean;
    smart_brevity_max_words: number;
    debounce_ms: number;
    min_chars_change: number;
    can_configure: boolean;
};

export type ReviewResponse = {
    available: boolean;
    reason: string | null;
    limited: boolean;
    deterministic: ReviewFinding[];
    ai: ReviewFinding[];
    metrics: ReviewMetrics;
    cached: boolean;
    truncated: boolean;
    usage: {
        day: number;
        month: number;
        daily_limit: number | null;
        monthly_limit: number | null;
    };
    config: AssistantConfig;
};
