import { computed, onBeforeUnmount, ref, shallowRef, watch } from 'vue';
import { lintContent, textOf } from '@/lib/contentLint';
import { HttpError, requestJson } from '@/lib/http';
import type {
    AssistantConfig,
    ReviewFinding,
    ReviewMetrics,
    ReviewResponse,
} from '@/types';

export type ReviewStatus =
    | 'idle'
    | 'reviewing'
    | 'ready'
    | 'unavailable'
    | 'error';

type Options = {
    url: string;
    variant: string | null;
    subject: string;
    preheader: string | null;
    knownTags: string[];
    config: AssistantConfig;
    /** Current HTML of the editor (unsaved); null while the editor is not ready. */
    getHtml: () => string | null;
};

const LIVE_KEY = 'campanas.ai-live-review';
const SB_KEY = 'campanas.ai-smart-brevity';
const LOCAL_DELAY = 350;
const MIN_TEXT = 40;

const readFlag = (key: string, fallback: boolean): boolean => {
    try {
        const value = localStorage.getItem(key);

        return value === null ? fallback : value === '1';
    } catch {
        return fallback;
    }
};
const writeFlag = (key: string, value: boolean) => {
    try {
        localStorage.setItem(key, value ? '1' : '0');
    } catch {
        // Private mode or blocked storage: the switch still works for this page.
    }
};

/** Characters that differ between two texts (common prefix/suffix trimmed). */
const changedChars = (a: string, b: string): number => {
    let start = 0;

    while (start < a.length && start < b.length && a[start] === b[start]) {
        start++;
    }

    let endA = a.length;
    let endB = b.length;

    while (endA > start && endB > start && a[endA - 1] === b[endB - 1]) {
        endA--;
        endB--;
    }

    return Math.max(endA - start, endB - start);
};

/**
 * Live, advisory review of the draft: deterministic checks and Smart Brevity metrics run
 * instantly in the browser; the AI review runs on the server after a pause in typing,
 * cancelling the previous request and ignoring stale answers. Never blocks saving.
 */
export function useContentReview(options: Options) {
    const config = ref<AssistantConfig>(options.config);
    const status = ref<ReviewStatus>('idle');
    const reason = ref<string | null>(null);
    const localFindings = shallowRef<ReviewFinding[]>([]);
    const aiFindings = shallowRef<ReviewFinding[]>([]);
    const metrics = shallowRef<ReviewMetrics | null>(null);
    const cached = ref(false);
    const usage = ref<ReviewResponse['usage'] | null>(null);
    const pausedReason = ref<string | null>(null);

    const liveAllowed = computed(
        () => config.value.ai_available && config.value.live_review_enabled,
    );
    const liveOn = ref(readFlag(LIVE_KEY, true));
    const live = computed(
        () => liveAllowed.value && liveOn.value && !pausedReason.value,
    );
    const smartBrevity = ref(
        config.value.smart_brevity_enabled && readFlag(SB_KEY, true),
    );

    watch(liveOn, (v) => writeFlag(LIVE_KEY, v));
    watch(smartBrevity, (v) => {
        writeFlag(SB_KEY, v);
        runLocal();
        lastSent = null; // a different review: allow it again
        scheduleAi();
    });

    let localTimer: ReturnType<typeof setTimeout> | undefined;
    let aiTimer: ReturnType<typeof setTimeout> | undefined;
    let controller: AbortController | null = null;
    let version = 0;
    let lastSent: string | null = null;

    function runLocal(): string | null {
        const html = options.getHtml();

        if (html === null) {
            return null;
        }

        const result = lintContent(
            options.subject,
            options.preheader,
            html,
            options.knownTags,
            config.value.smart_brevity_max_words,
            smartBrevity.value,
        );
        localFindings.value = result.findings;
        metrics.value = result.metrics;

        return html;
    }

    async function review(force = false): Promise<void> {
        if (!config.value.ai_available) {
            return;
        }

        const html = options.getHtml();

        if (html === null) {
            return;
        }

        const text = textOf(html);

        if (!force) {
            if (text.length < MIN_TEXT) {
                return;
            }

            if (
                lastSent !== null &&
                changedChars(lastSent, text) < config.value.min_chars_change
            ) {
                return;
            }
        }

        controller?.abort();
        controller = new AbortController();
        const mine = ++version;
        lastSent = text;
        status.value = 'reviewing';

        try {
            const res = await requestJson<ReviewResponse>(
                'POST',
                options.url,
                {
                    html,
                    variant: options.variant,
                    smart_brevity: smartBrevity.value,
                },
                controller.signal,
            );

            if (mine !== version) {
                return; // a newer review is on its way
            }

            config.value = res.config;
            usage.value = res.usage;
            localFindings.value = res.deterministic;
            metrics.value = res.metrics;
            cached.value = res.cached;

            if (res.available) {
                aiFindings.value = res.ai;
                reason.value = null;
                status.value = 'ready';
            } else {
                reason.value = res.reason;
                status.value = 'unavailable';

                if (res.limited) {
                    pausedReason.value = res.reason;
                }
            }
        } catch (e) {
            if (mine !== version || (e as Error).name === 'AbortError') {
                return;
            }

            if (e instanceof HttpError && e.status === 429) {
                pausedReason.value =
                    'Se hicieron muchas revisiones seguidas; la revisión en vivo se pausó un minuto.';
                setTimeout(() => (pausedReason.value = null), 60_000);
            }

            reason.value = (e as Error).message;
            status.value = 'error';
        }
    }

    function scheduleAi(): void {
        clearTimeout(aiTimer);

        if (live.value) {
            aiTimer = setTimeout(() => void review(), config.value.debounce_ms);
        }
    }

    /** Call on every editor change. */
    function notifyChange(): void {
        clearTimeout(localTimer);
        localTimer = setTimeout(runLocal, LOCAL_DELAY);
        scheduleAi();
    }

    function setLive(on: boolean): void {
        liveOn.value = on;
        pausedReason.value = null;

        if (on) {
            scheduleAi();
        }
    }

    onBeforeUnmount(() => {
        clearTimeout(localTimer);
        clearTimeout(aiTimer);
        controller?.abort();
    });

    return {
        config,
        status,
        reason,
        localFindings,
        aiFindings,
        metrics,
        cached,
        usage,
        pausedReason,
        liveAllowed,
        liveOn,
        live,
        smartBrevity,
        notifyChange,
        runLocal,
        review,
        setLive,
    };
}
