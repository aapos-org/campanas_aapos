<?php

namespace App\Services\Ai;

use App\Http\Controllers\Campaigns\MergeTags;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Deterministic content checks and Smart Brevity metrics: instant, free and available
 * even when the AI assistant is off. The same thresholds are sent to the browser, which
 * runs a mirror of these checks while the user types (resources/js/lib/contentLint.ts).
 */
class ContentLint
{
    /** Smart Brevity: subject "≈ 50 characters". The only subject threshold, shared with the AI prompt. */
    public const int SUBJECT_MAX_CHARS = 50;

    /** Smart Brevity: sentences of about 20 words or fewer. */
    public const int SENTENCE_MAX_WORDS = 20;

    /** A paragraph is "long" beyond 2 sentences or this many words. */
    public const int PARAGRAPH_MAX_WORDS = 60;

    /**
     * @return array{text: string, links: list<array{text: string, href: string}>, paragraphs: list<string>, has_bullets: bool, has_bold: bool, images_without_alt: int}
     */
    public function extract(string $html): array
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8"><div>'.$html.'</div>', LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        $xpath = new DOMXPath($dom);

        foreach (iterator_to_array($xpath->query('//script|//style|//head|//title') ?: []) as $node) {
            if ($node instanceof DOMElement) {
                $node->parentNode?->removeChild($node);
            }
        }

        $links = [];
        foreach ($xpath->query('//a') ?: [] as $a) {
            if ($a instanceof DOMElement) {
                $links[] = ['text' => $this->clean($a->textContent), 'href' => trim($a->getAttribute('href'))];
            }
        }

        $imagesWithoutAlt = 0;
        foreach ($xpath->query('//img') ?: [] as $img) {
            if ($img instanceof DOMElement && trim($img->getAttribute('alt')) === '') {
                $imagesWithoutAlt++;
            }
        }

        // Paragraph-like blocks (MJML compiles text into <div>s inside tables).
        $paragraphs = [];
        foreach ($xpath->query('//p | //li | //h1 | //h2 | //h3 | //td[not(.//td) and not(.//p) and not(.//div)] | //div[not(.//div) and not(.//p) and not(.//table)]') ?: [] as $block) {
            if (! $block instanceof DOMElement) {
                continue;
            }

            $text = $this->clean($block->textContent);

            if ($text !== '') {
                $paragraphs[] = $text;
            }
        }

        $hasBold = $this->matches($xpath, '//strong | //b | //*[contains(translate(@style, "BOLD", "bold"), "font-weight:bold") or contains(@style, "font-weight: bold") or contains(@style, "font-weight:700") or contains(@style, "font-weight: 700")]');

        return [
            'text' => $this->clean($dom->textContent),
            'links' => $links,
            'paragraphs' => array_values(array_unique($paragraphs)),
            'has_bullets' => $this->matches($xpath, '//ul | //ol'),
            'has_bold' => $hasBold,
            'images_without_alt' => $imagesWithoutAlt,
        ];
    }

    /**
     * @return array{findings: list<array{severity: string, category: string, excerpt: string, message: string, suggestion: string, source: string}>, metrics: array<string, int|float|bool>}
     */
    public function check(string $subject, ?string $preheader, string $html, int $maxWords, bool $smartBrevity): array
    {
        $content = $this->extract($html);
        $findings = [];
        $add = function (string $severity, string $category, string $message, string $excerpt = '', string $suggestion = '') use (&$findings) {
            $findings[] = compact('severity', 'category', 'excerpt', 'message', 'suggestion') + ['source' => 'local'];
        };

        $subject = trim($subject);

        if ($subject === '') {
            $add('error', 'asunto', 'Falta el asunto.');
        } else {
            $letters = (string) preg_replace('/[^\p{L}]/u', '', $subject);

            if (mb_strlen($letters) >= 6 && mb_strtoupper($letters) === $letters) {
                $add('warning', 'spam', 'El asunto está todo en mayúsculas: los filtros lo marcan como spam.', $subject, mb_convert_case(mb_strtolower($subject), MB_CASE_TITLE));
            }

            if ($smartBrevity && mb_strlen($subject) > self::SUBJECT_MAX_CHARS) {
                $add('info', 'smart_brevity', sprintf('El asunto tiene %d caracteres; Smart Brevity recomienda unos %d, con la palabra más fuerte primero.', mb_strlen($subject), self::SUBJECT_MAX_CHARS), $subject);
            }
        }

        if (blank($preheader)) {
            $add('warning', 'asunto', 'Falta el texto de vista previa: es lo que se lee junto al asunto en la bandeja de entrada.');
        }

        foreach ($this->unknownMergeTags($html.' '.$subject.' '.$preheader) as $tag) {
            $add('error', 'variables', "La variable «{$tag}» no existe: se enviaría tal cual.", $tag, 'Elige una variable de la lista «Variables de personalización».');
        }

        foreach ($content['links'] as $link) {
            $href = $link['href'];

            if ($href === '' || $href === '#') {
                $add('warning', 'enlaces', 'Hay un enlace sin dirección.', $link['text']);
            } elseif (str_starts_with(strtolower($href), 'http://')) {
                $add('warning', 'enlaces', 'Un enlace usa http:// en lugar de https://.', $href, 'https://'.substr($href, 7));
            }
        }

        if ($content['images_without_alt'] > 0) {
            $add('warning', 'imagenes', sprintf('%d %s sin texto alternativo (alt): quien no ve las imágenes no sabrá qué muestran.', $content['images_without_alt'], $content['images_without_alt'] === 1 ? 'imagen' : 'imágenes'));
        }

        $metrics = $this->metrics($subject, $content, $maxWords);

        if ($smartBrevity && $metrics['words'] > $maxWords) {
            $add('info', 'smart_brevity', sprintf('El correo tiene %d palabras; el máximo sugerido es %d. Mueve el detalle a un enlace «Ver más».', $metrics['words'], $maxWords));
        }

        return ['findings' => $findings, 'metrics' => $metrics];
    }

    /**
     * @param  array{text: string, paragraphs: list<string>, has_bullets: bool, has_bold: bool}  $content
     * @return array<string, int|float|bool>
     */
    public function metrics(string $subject, array $content, int $maxWords): array
    {
        $sentences = array_values(array_filter(
            preg_split('/(?<=[.!?¡¿…])\s+/u', $content['text']) ?: [],
            fn (string $s) => $this->wordCount($s) > 0,
        ));
        $words = $this->wordCount($content['text']);

        $longParagraphs = 0;
        foreach ($content['paragraphs'] as $paragraph) {
            $count = count(preg_split('/(?<=[.!?])\s+/u', $paragraph) ?: []);

            if ($count > 2 || $this->wordCount($paragraph) > self::PARAGRAPH_MAX_WORDS) {
                $longParagraphs++;
            }
        }

        return [
            'words' => $words,
            'max_words' => $maxWords,
            'subject_chars' => mb_strlen(trim($subject)),
            'subject_max_chars' => self::SUBJECT_MAX_CHARS,
            'subject_words' => $this->wordCount($subject),
            'sentences' => count($sentences),
            'avg_sentence_words' => $sentences === [] ? 0 : round($words / count($sentences), 1),
            'sentence_max_words' => self::SENTENCE_MAX_WORDS,
            'long_paragraphs' => $longParagraphs,
            'has_bullets' => $content['has_bullets'],
            'has_bold' => $content['has_bold'],
        ];
    }

    /**
     * Variables used in the content that do not exist (same syntax as CampaignRenderer).
     *
     * @return list<string>
     */
    public function unknownMergeTags(string $text): array
    {
        $known = self::knownTagKeys();
        preg_match_all('/\{\{\s*([^}|\s]+)\s*(?:\|[^}]*)?\}\}/u', $text, $matches);

        return array_values(array_unique(array_map(
            fn (string $key) => '{{ '.$key.' }}',
            array_filter($matches[1], fn (string $key) => ! in_array(mb_strtolower($key), $known, true)),
        )));
    }

    /**
     * @return list<string>
     */
    public static function knownTagKeys(): array
    {
        $keys = [];
        foreach (MergeTags::all() as $tag) {
            if (preg_match('/\{\{\s*([^}|\s]+)/u', $tag['tag'], $m)) {
                $keys[] = mb_strtolower($m[1]);
            }
        }

        return array_values(array_unique($keys));
    }

    private function matches(DOMXPath $xpath, string $expression): bool
    {
        $nodes = $xpath->query($expression);

        return $nodes !== false && $nodes->length > 0;
    }

    private function wordCount(string $text): int
    {
        // false only on a regex error (e.g. invalid UTF-8): count nothing.
        return preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\'’-]*/u', $text) ?: 0;
    }

    private function clean(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
