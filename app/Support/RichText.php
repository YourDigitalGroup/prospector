<?php

declare(strict_types=1);

namespace Prospector\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use DOMXPath;

/**
 * Clean HTML from the message composer down to something safe to send.
 *
 * The composer is a contenteditable box, so what arrives is whatever the
 * browser produced — and whatever anybody chose to post to the endpoint
 * directly, which is the case that actually matters. Nothing here trusts the
 * input.
 *
 * The approach is an allow-list rebuild rather than a search for bad things.
 * Stripping <script> and onclick= is a game you lose eventually, because the
 * list of dangerous constructs is open-ended and browsers keep adding to it.
 * Parsing the input and keeping only tags and attributes that appear below is
 * closed-ended: anything not named is gone, including whatever is invented next
 * year.
 *
 * The list is short on purpose. Email clients strip most of what a browser
 * would render anyway — Gmail drops <style> blocks, Outlook renders through
 * Word — so a formatting set of bold, italic, underline, size, lists and links
 * is both what survives the trip and what people actually reach for.
 */
final class RichText
{
    /**
     * Tags kept, with the attributes each may carry.
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED = [
        'b' => [],
        'strong' => [],
        'i' => [],
        'em' => [],
        'u' => [],
        's' => [],
        'strike' => [],
        'br' => [],
        'p' => ['style'],
        'div' => ['style'],
        'span' => ['style'],
        'ul' => [],
        'ol' => [],
        'li' => [],
        'blockquote' => ['style'],
        'a' => ['href', 'target', 'rel'],
    ];

    /**
     * Style properties kept, and the pattern each value must match.
     *
     * A free-text style attribute is an injection surface of its own —
     * `background:url(javascript:…)`, `position:fixed`, expression() — so the
     * property names are named individually and the values are matched against
     * a pattern rather than passed through.
     *
     * @var array<string, string>
     */
    private const ALLOWED_STYLES = [
        'font-size' => '/^\d{1,2}(\.\d+)?(px|pt|em|rem|%)$/',
        'font-weight' => '/^(normal|bold|[1-9]00)$/',
        'font-style' => '/^(normal|italic)$/',
        'text-decoration' => '/^(none|underline|line-through)$/',
        'text-align' => '/^(left|right|center|justify)$/',
        'color' => '/^(#[0-9a-f]{3,8}|rgb\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*\))$/i',
    ];

    /** Longer than any email anybody writes in a compose box. */
    private const MAX_BYTES = 200000;

    /**
     * Sanitise composer HTML.
     *
     * Returns HTML safe to store, display and send. An empty result means the
     * input carried nothing worth keeping.
     */
    public static function clean(string $html): string
    {
        $html = trim($html);

        if ($html === '') {
            return '';
        }

        if (strlen($html) > self::MAX_BYTES) {
            $html = substr($html, 0, self::MAX_BYTES);
        }

        $document = new DOMDocument();

        // The meta charset is how DOMDocument is told the input is UTF-8; left
        // off, it assumes Latin-1 and mangles anything above ASCII. The wrapper
        // div gives a single root to walk.
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8"><div id="rt-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            // Unparseable. Fall back to treating it as plain text, which is
            // always safe and never silently drops the message.
            return nl2br(htmlspecialchars(strip_tags($html), ENT_QUOTES, 'UTF-8'));
        }

        $xpath = new DOMXPath($document);
        $root = $xpath->query('//*[@id="rt-root"]')->item(0);

        if (!$root instanceof DOMElement) {
            return '';
        }

        self::scrub($root);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $document->saveHTML($child);
        }

        return trim($out);
    }

    /**
     * Everything an email client will not render, as plain text.
     *
     * Sent as the text part alongside the HTML, so a recipient reading in plain
     * text gets the words rather than a wall of markup.
     */
    public static function toText(string $html): string
    {
        if ($html === '') {
            return '';
        }

        // Block-level tags become line breaks before the rest is stripped, or
        // three paragraphs collapse into one run-on sentence.
        $text = preg_replace('#<(br|/p|/div|/li|/blockquote)\s*/?>#i', "\n", $html) ?? $html;
        $text = preg_replace('#<li[^>]*>#i', '• ', $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    /** Is there anything here beyond whitespace and empty tags? */
    public static function isEmpty(string $html): bool
    {
        return self::toText($html) === '';
    }

    /**
     * Walk the tree, keeping only what ALLOWED names.
     *
     * A disallowed element is unwrapped rather than deleted — its children move
     * up in its place — so a stray <font> loses the tag and keeps the words.
     * The exception is a node whose content is never text worth keeping, like
     * <script>, which goes entirely.
     */
    private static function scrub(DOMNode $node): void
    {
        // Snapshot first: the loop reparents nodes, and iterating a live
        // childNodes list while doing that skips half of them.
        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if ($child instanceof DOMText) {
                continue;
            }

            if (!$child instanceof DOMElement) {
                // Comments and processing instructions have no place in a mail
                // body and can carry conditional markup.
                $child->parentNode?->removeChild($child);
                continue;
            }

            $tag = strtolower($child->tagName);

            if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'link', 'meta'], true)) {
                $child->parentNode?->removeChild($child);
                continue;
            }

            self::scrub($child);

            if (!array_key_exists($tag, self::ALLOWED)) {
                self::unwrap($child);
                continue;
            }

            self::scrubAttributes($child, self::ALLOWED[$tag]);
        }
    }

    /** @param list<string> $allowed */
    private static function scrubAttributes(DOMElement $element, array $allowed): void
    {
        $names = [];
        foreach ($element->attributes as $attribute) {
            $names[] = $attribute->nodeName;
        }

        foreach ($names as $name) {
            $lower = strtolower($name);

            if (!in_array($lower, $allowed, true)) {
                $element->removeAttribute($name);
                continue;
            }

            $value = $element->getAttribute($name);

            if ($lower === 'style') {
                $clean = self::scrubStyle($value);
                if ($clean === '') {
                    $element->removeAttribute($name);
                } else {
                    $element->setAttribute($name, $clean);
                }
                continue;
            }

            if ($lower === 'href' && !self::isSafeHref($value)) {
                $element->removeAttribute($name);
            }
        }

        // A link that survived opens away from the page and cannot reach back
        // through window.opener.
        if (strtolower($element->tagName) === 'a' && $element->hasAttribute('href')) {
            $element->setAttribute('target', '_blank');
            $element->setAttribute('rel', 'noopener noreferrer');
        }
    }

    private static function scrubStyle(string $style): string
    {
        $kept = [];

        foreach (explode(';', $style) as $declaration) {
            if (!str_contains($declaration, ':')) {
                continue;
            }

            [$property, $value] = explode(':', $declaration, 2);
            $property = strtolower(trim($property));
            $value = trim($value);

            if (!array_key_exists($property, self::ALLOWED_STYLES)) {
                continue;
            }

            if (preg_match(self::ALLOWED_STYLES[$property], $value) !== 1) {
                continue;
            }

            $kept[] = $property . ':' . $value;
        }

        return implode(';', $kept);
    }

    /**
     * Only links that go somewhere a mail client will follow.
     *
     * javascript:, data: and vbscript: are the obvious ones; the scheme check
     * is an allow-list so anything else exotic is refused too. A relative link
     * is refused because this ends up in an inbox, where relative means nothing.
     */
    private static function isSafeHref(string $href): bool
    {
        $href = trim($href);

        if ($href === '') {
            return false;
        }

        // Control characters are used to break up a scheme so it slips past a
        // naive check — "java\tscript:" — so they disqualify the value outright.
        if (preg_match('/[\x00-\x1F\x7F]/', $href) === 1) {
            return false;
        }

        return preg_match('#^(https?://|mailto:|tel:)#i', $href) === 1;
    }

    /** Replace an element with its own children. */
    private static function unwrap(DOMElement $element): void
    {
        $parent = $element->parentNode;

        if ($parent === null) {
            return;
        }

        while ($element->firstChild !== null) {
            $parent->insertBefore($element->firstChild, $element);
        }

        $parent->removeChild($element);
    }
}
