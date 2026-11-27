import type { ReviewFinding, ReviewMetrics } from '@/types';

/**
 * Browser mirror of App\Services\Ai\ContentLint: instant deterministic checks and Smart
 * Brevity metrics while typing. The server runs the same checks with the same thresholds
 * on every review, and its answer replaces these.
 */
export const SUBJECT_MAX_CHARS = 50;
export const SENTENCE_MAX_WORDS = 20;
const PARAGRAPH_MAX_WORDS = 60;

const wordCount = (text: string) =>
    (text.match(/[\p{L}\p{N}][\p{L}\p{N}'’-]*/gu) ?? []).length;
const clean = (text: string) => text.replace(/\s+/g, ' ').trim();

/** Visible text of an HTML fragment (no scripts or styles). */
export function textOf(html: string): string {
    const doc = new DOMParser().parseFromString(html, 'text/html');
    doc.querySelectorAll('script, style, head, title').forEach((n) =>
        n.remove(),
    );

    return clean(doc.body?.textContent ?? '');
}

export function lintContent(
    subject: string,
    preheader: string | null,
    html: string,
    knownTags: string[],
    maxWords: number,
    smartBrevity: boolean,
): { findings: ReviewFinding[]; metrics: ReviewMetrics } {
    const doc = new DOMParser().parseFromString(html, 'text/html');
    doc.querySelectorAll('script, style, head, title').forEach((n) =>
        n.remove(),
    );
    const text = clean(doc.body?.textContent ?? '');
    const findings: ReviewFinding[] = [];
    const add = (
        severity: ReviewFinding['severity'],
        category: string,
        message: string,
        excerpt = '',
        suggestion = '',
    ) =>
        findings.push({
            severity,
            category,
            excerpt,
            message,
            suggestion,
            source: 'local',
        });

    const s = subject.trim();

    if (!s) {
        add('error', 'asunto', 'Falta el asunto.');
    } else {
        const letters = s.replace(/[^\p{L}]/gu, '');

        if (letters.length >= 6 && letters.toUpperCase() === letters) {
            add(
                'warning',
                'spam',
                'El asunto está todo en mayúsculas: los filtros lo marcan como spam.',
                s,
            );
        }

        if (smartBrevity && s.length > SUBJECT_MAX_CHARS) {
            add(
                'info',
                'smart_brevity',
                `El asunto tiene ${s.length} caracteres; Smart Brevity recomienda unos ${SUBJECT_MAX_CHARS}, con la palabra más fuerte primero.`,
                s,
            );
        }
    }

    if (!preheader?.trim()) {
        add(
            'warning',
            'asunto',
            'Falta el texto de vista previa: es lo que se lee junto al asunto en la bandeja de entrada.',
        );
    }

    const known = new Set(knownTags.map((k) => k.toLowerCase()));
    const unknown = new Set<string>();

    for (const m of `${html} ${subject} ${preheader ?? ''}`.matchAll(
        /\{\{\s*([^}|\s]+)\s*(?:\|[^}]*)?\}\}/gu,
    )) {
        if (!known.has(m[1].toLowerCase())) {
            unknown.add(`{{ ${m[1]} }}`);
        }
    }

    unknown.forEach((tag) =>
        add(
            'error',
            'variables',
            `La variable «${tag}» no existe: se enviaría tal cual.`,
            tag,
            'Elige una variable de la lista «Variables de personalización».',
        ),
    );

    doc.querySelectorAll('a').forEach((a) => {
        const href = (a.getAttribute('href') ?? '').trim();

        if (href === '' || href === '#') {
            add(
                'warning',
                'enlaces',
                'Hay un enlace sin dirección.',
                clean(a.textContent ?? ''),
            );
        } else if (href.toLowerCase().startsWith('http://')) {
            add(
                'warning',
                'enlaces',
                'Un enlace usa http:// en lugar de https://.',
                href,
                `https://${href.slice(7)}`,
            );
        }
    });

    const noAlt = [...doc.querySelectorAll('img')].filter(
        (img) => !(img.getAttribute('alt') ?? '').trim(),
    ).length;

    if (noAlt > 0) {
        add(
            'warning',
            'imagenes',
            `${noAlt} ${noAlt === 1 ? 'imagen' : 'imágenes'} sin texto alternativo (alt): quien no ve las imágenes no sabrá qué muestran.`,
        );
    }

    const sentences = text
        .split(/(?<=[.!?¡¿…])\s+/u)
        .filter((x) => wordCount(x) > 0);
    const words = wordCount(text);
    const blocks = [
        ...doc.querySelectorAll('p, li, h1, h2, h3'),
        ...[...doc.querySelectorAll('div, td')].filter(
            (el) => !el.querySelector('div, p, table, td'),
        ),
    ]
        .map((el) => clean(el.textContent ?? ''))
        .filter(Boolean);
    const longParagraphs = [...new Set(blocks)].filter(
        (p) =>
            p.split(/(?<=[.!?])\s+/u).length > 2 ||
            wordCount(p) > PARAGRAPH_MAX_WORDS,
    ).length;

    if (smartBrevity && words > maxWords) {
        add(
            'info',
            'smart_brevity',
            `El correo tiene ${words} palabras; el máximo sugerido es ${maxWords}. Mueve el detalle a un enlace «Ver más».`,
        );
    }

    return {
        findings,
        metrics: {
            words,
            max_words: maxWords,
            subject_chars: s.length,
            subject_max_chars: SUBJECT_MAX_CHARS,
            subject_words: wordCount(s),
            sentences: sentences.length,
            avg_sentence_words: sentences.length
                ? Math.round((words / sentences.length) * 10) / 10
                : 0,
            sentence_max_words: SENTENCE_MAX_WORDS,
            long_paragraphs: longParagraphs,
            has_bullets: !!doc.querySelector('ul, ol'),
            has_bold:
                !!doc.querySelector('strong, b') ||
                [...doc.querySelectorAll('[style]')].some((el) =>
                    /font-weight:\s*(bold|[6-9]00)/i.test(
                        el.getAttribute('style') ?? '',
                    ),
                ),
        },
    };
}
