<?php

declare(strict_types=1);

namespace Prospector\Support;

/**
 * Minimal Markdown renderer for the daily brief. Deliberately small: it only
 * needs the subset the model produces (headings, tables, lists, emphasis,
 * links, code spans) and it escapes everything before adding any markup, so
 * model output can never inject HTML.
 */
final class Markdown
{
    public static function toHtml(string $markdown): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $markdown) ?: [];
        $html = [];
        $listType = null;
        $inTable = false;
        $paragraph = [];

        $flushParagraph = static function () use (&$paragraph, &$html): void {
            if ($paragraph !== []) {
                $html[] = '<p>' . self::inline(implode(' ', $paragraph)) . '</p>';
                $paragraph = [];
            }
        };

        $closeList = static function () use (&$listType, &$html): void {
            if ($listType !== null) {
                $html[] = $listType === 'ul' ? '</ul>' : '</ol>';
                $listType = null;
            }
        };

        $closeTable = static function () use (&$inTable, &$html): void {
            if ($inTable) {
                $html[] = '</tbody></table></div>';
                $inTable = false;
            }
        };

        $count = count($lines);
        for ($i = 0; $i < $count; $i++) {
            $line = rtrim($lines[$i]);
            $trimmed = trim($line);

            if ($trimmed === '') {
                $flushParagraph();
                $closeList();
                $closeTable();
                continue;
            }

            // Heading
            if (preg_match('/^(#{1,6})\s+(.*)$/', $trimmed, $m) === 1) {
                $flushParagraph();
                $closeList();
                $closeTable();
                $level = min(6, max(2, strlen($m[1]) + 1));
                $html[] = "<h{$level}>" . self::inline($m[2]) . "</h{$level}>";
                continue;
            }

            // Horizontal rule
            if (preg_match('/^(\*\s*){3,}$|^(-\s*){3,}$|^(_\s*){3,}$/', $trimmed) === 1) {
                $flushParagraph();
                $closeList();
                $closeTable();
                $html[] = '<hr>';
                continue;
            }

            // Table: a pipe row followed by a separator row
            if (!$inTable
                && str_contains($trimmed, '|')
                && isset($lines[$i + 1])
                && preg_match('/^\s*\|?[\s:\-|]+\|?\s*$/', $lines[$i + 1]) === 1
                && str_contains($lines[$i + 1], '-')
            ) {
                $flushParagraph();
                $closeList();
                $html[] = '<div class="table-scroll"><table class="md-table"><thead><tr>';
                foreach (self::cells($trimmed) as $cell) {
                    $html[] = '<th>' . self::inline($cell) . '</th>';
                }
                $html[] = '</tr></thead><tbody>';
                $inTable = true;
                $i++; // consume the separator row

                continue;
            }

            if ($inTable) {
                if (!str_contains($trimmed, '|')) {
                    $closeTable();
                } else {
                    $html[] = '<tr>';
                    foreach (self::cells($trimmed) as $cell) {
                        $html[] = '<td>' . self::inline($cell) . '</td>';
                    }
                    $html[] = '</tr>';
                    continue;
                }
            }

            // Unordered list
            if (preg_match('/^[-*+]\s+(.*)$/', $trimmed, $m) === 1) {
                $flushParagraph();
                if ($listType !== 'ul') {
                    $closeList();
                    $html[] = '<ul>';
                    $listType = 'ul';
                }
                $html[] = '<li>' . self::inline($m[1]) . '</li>';
                continue;
            }

            // Ordered list
            if (preg_match('/^\d+[.)]\s+(.*)$/', $trimmed, $m) === 1) {
                $flushParagraph();
                if ($listType !== 'ol') {
                    $closeList();
                    $html[] = '<ol>';
                    $listType = 'ol';
                }
                $html[] = '<li>' . self::inline($m[1]) . '</li>';
                continue;
            }

            $closeList();
            $paragraph[] = $trimmed;
        }

        $flushParagraph();
        $closeList();
        $closeTable();

        return implode("\n", $html);
    }

    /** @return list<string> */
    private static function cells(string $row): array
    {
        $row = trim($row);
        $row = preg_replace('/^\|/', '', $row) ?? $row;
        $row = preg_replace('/\|$/', '', $row) ?? $row;

        return array_map('trim', explode('|', $row));
    }

    private static function inline(string $text): string
    {
        $out = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $out = preg_replace('/`([^`]+)`/', '<code>$1</code>', $out) ?? $out;
        $out = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $out) ?? $out;
        $out = preg_replace('/(?<![\*\w])\*([^*\n]+)\*(?![\*\w])/', '<em>$1</em>', $out) ?? $out;

        // Markdown links, http(s) and mailto only.
        $out = preg_replace_callback(
            '/\[([^\]]+)\]\(((?:https?:\/\/|mailto:)[^\s)]+)\)/',
            static fn (array $m): string => '<a href="' . $m[2] . '" target="_blank" rel="noopener noreferrer">' . $m[1] . '</a>',
            $out
        ) ?? $out;

        // Angle-bracket autolinks, <https://example.com>. The brackets are
        // already escaped by this point, so match the entities.
        $out = preg_replace(
            '/&lt;((?:https?:\/\/|mailto:)[^\s&]+)&gt;/',
            '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>',
            $out
        ) ?? $out;

        // Bare URLs not already inside an anchor.
        $out = preg_replace(
            '/(?<!href=")(?<!">)\b(https?:\/\/[^\s<"]+)/',
            '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>',
            $out
        ) ?? $out;

        return $out;
    }
}
