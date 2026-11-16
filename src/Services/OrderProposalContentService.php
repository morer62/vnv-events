<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;

class OrderProposalContentService
{
    private const ALLOWED_TAGS = ['div', 'p', 'h2', 'h3', 'ul', 'ol', 'li', 'strong', 'em', 'u', 'br', 'img', 'figure', 'figcaption'];

    /**
     * Sanitizes the editor HTML while preserving document semantics needed by
     * the browser and PDF renderer (headings, paragraphs, lists and page breaks).
     */
    public function sanitize(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->loadHTML(
            '<?xml encoding="utf-8" ?><div id="proposal-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('proposal-root');
        if (!$root) {
            return '';
        }

        $this->sanitizeChildren($root);
        $result = '';
        foreach ($root->childNodes as $child) {
            $result .= $document->saveHTML($child);
        }
        return trim($result);
    }

    public function toPlainText(string $html): string
    {
        $text = html_entity_decode(strip_tags(str_replace(['</p>', '</li>', '</h2>', '</h3>', '<br>', '<br/>', '<br />'], "\n", $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\t ]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
        return trim($text);
    }

    private function sanitizeChildren(DOMNode $parent): void
    {
        for ($node = $parent->firstChild; $node !== null;) {
            $next = $node->nextSibling;
            if ($node instanceof DOMElement) {
                $tag = strtolower($node->tagName);
                if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                    if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed'], true)) {
                        $parent->removeChild($node);
                    } else {
                        while ($node->firstChild) {
                            $parent->insertBefore($node->firstChild, $node);
                        }
                        $parent->removeChild($node);
                    }
                    $node = $next;
                    continue;
                }

                $this->sanitizeAttributes($node, $tag);
                $this->sanitizeChildren($node);
            }
            $node = $next;
        }
    }

    private function sanitizeAttributes(DOMElement $element, string $tag): void
    {
        $attributes = [];
        foreach ($element->attributes as $attribute) {
            $attributes[] = $attribute->name;
        }
        foreach ($attributes as $name) {
            $keep = false;
            if ($tag === 'img' && in_array($name, ['src', 'alt'], true)) {
                $keep = true;
            }
            if ($tag === 'div' && in_array($name, ['class', 'data-page-break'], true)) {
                $keep = true;
            }
            if (!$keep) {
                $element->removeAttribute($name);
            }
        }

        if ($tag === 'img') {
            $src = trim($element->getAttribute('src'));
            if (!filter_var($src, FILTER_VALIDATE_URL) || strtolower((string)parse_url($src, PHP_URL_SCHEME)) !== 'https') {
                $element->parentNode?->removeChild($element);
                return;
            }
            $element->setAttribute('alt', trim($element->getAttribute('alt')) ?: 'Proposal image');
        }

        if ($tag === 'div') {
            $isPageBreak = $element->getAttribute('data-page-break') === 'true'
                || str_contains($element->getAttribute('class'), 'proposal-page-break');
            if ($isPageBreak) {
                $element->setAttribute('class', 'proposal-page-break');
                $element->setAttribute('data-page-break', 'true');
                while ($element->firstChild) {
                    $element->removeChild($element->firstChild);
                }
            } else {
                $element->removeAttribute('class');
                $element->removeAttribute('data-page-break');
            }
        }
    }
}
