<?php

namespace App\Services\Campaigns;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Removes anything that can run code from email HTML while keeping what emails need
 * (tables, inline styles, <style> blocks with media queries, images).
 *
 * HTMLPurifier is not used because it strips <style> and most email layout markup.
 * Defense in depth: previews are also rendered in sandboxed iframes without scripts.
 * Merge tags ({{ … }}) are protected so they survive the DOM round trip unchanged.
 */
class EmailHtmlSanitizer
{
    private const array FORBIDDEN_ELEMENTS = [
        'script', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'form', 'input',
        'button', 'select', 'textarea', 'base', 'link', 'svg', 'math', 'portal', 'noscript',
    ];

    private const array URL_ATTRIBUTES = ['href', 'src', 'background', 'action', 'poster', 'cite', 'xlink:href', 'formaction'];

    public function sanitize(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        [$html, $tags] = self::protectMergeTags($html);

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_HTML_NODEFDTD | LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($document);

        foreach (self::FORBIDDEN_ELEMENTS as $tag) {
            foreach (iterator_to_array($document->getElementsByTagName($tag)) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        foreach (iterator_to_array($xpath->query('//meta[@http-equiv]') ?: []) as $meta) {
            if ($meta instanceof DOMElement && strtolower($meta->getAttribute('http-equiv')) === 'refresh') {
                $meta->parentNode?->removeChild($meta);
            }
        }

        foreach (iterator_to_array($xpath->query('//*') ?: []) as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }

            /** @var list<DOMAttr> $attributes */
            $attributes = iterator_to_array($element->attributes ?? []);

            foreach ($attributes as $attribute) {
                $name = strtolower($attribute->nodeName);
                $value = $attribute->nodeValue ?? '';

                if (str_starts_with($name, 'on') || $name === 'srcdoc' || $name === 'formaction') {
                    $element->removeAttributeNode($attribute);
                } elseif (in_array($name, self::URL_ATTRIBUTES, true) && ! $this->isSafeUrl($value, $element->tagName === 'img' && $name === 'src')) {
                    $element->removeAttributeNode($attribute);
                } elseif ($name === 'style' && $this->hasDangerousCss($value)) {
                    $element->removeAttributeNode($attribute);
                }
            }
        }

        foreach (iterator_to_array($document->getElementsByTagName('style')) as $style) {
            $css = (string) $style->textContent;

            if ($this->hasDangerousCss($css)) {
                $style->textContent = (string) preg_replace(['/@import[^;]*;?/i', '/expression\s*\(/i', '/url\s*\(\s*["\']?\s*javascript:[^)]*\)/i'], ['', '', 'none'], $css);
            }
        }

        $output = (string) $document->saveHTML();

        return self::restoreMergeTags(trim(str_replace('<?xml encoding="UTF-8">', '', $output)), $tags);
    }

    /**
     * libxml URL-encodes attribute values ({{ enlace_baja }} → %7B%7B…) and may touch quotes:
     * merge tags are swapped for inert alphanumeric markers while the DOM is processed.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    public static function protectMergeTags(string $html): array
    {
        $tags = [];
        $html = (string) preg_replace_callback('/\{\{.*?\}\}/s', function (array $m) use (&$tags) {
            $marker = 'cmpmt'.count($tags).'x'.bin2hex(random_bytes(3)).'z';
            $tags[$marker] = $m[0];

            return $marker;
        }, $html);

        return [$html, $tags];
    }

    /**
     * @param  array<string, string>  $tags
     */
    public static function restoreMergeTags(string $html, array $tags): string
    {
        return strtr($html, $tags);
    }

    private function isSafeUrl(string $url, bool $isImage): bool
    {
        $url = trim(html_entity_decode($url));
        // Remove whitespace/control characters browsers ignore ("java\tscript:").
        $normalized = strtolower((string) preg_replace('/[\x00-\x20]+/', '', $url));

        if ($normalized === '' || str_starts_with($normalized, '#') || str_starts_with($normalized, '{{')) {
            return true;
        }

        if (preg_match('/^(https?:|mailto:|tel:)/', $normalized)) {
            return true;
        }

        if ($isImage && preg_match('#^data:image/(png|gif|jpe?g|webp);base64,#', $normalized)) {
            return true;
        }

        // Relative URLs (no scheme) are harmless in email.
        return ! preg_match('/^[a-z][a-z0-9+.-]*:/', $normalized);
    }

    private function hasDangerousCss(string $css): bool
    {
        return (bool) preg_match('/expression\s*\(|javascript:|@import|behavior\s*:|-moz-binding/i', $css);
    }
}
