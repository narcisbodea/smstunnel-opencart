<?php
/**
 * SMSTunnel for OpenCart.
 *
 * @author    Narcis Bodea <contact@narboweb.ro>
 * @copyright 2026 NARBOWEB SRL
 * @license   GPL-3.0-or-later https://www.gnu.org/licenses/gpl-3.0.html
 */

namespace SmsTunnel\OpenCart\Message;

/**
 * Fills a text's placeholders and tidies the result: an empty placeholder leaves no double spaces
 * behind, and the text is cut at the 1600 characters SMSTunnel accepts.
 */
final class Template
{
    /** The longest text SMSTunnel accepts (10 joined SMS). */
    const MAX_LENGTH = 1600;

    /** The placeholders a text can use. */
    const PLACEHOLDERS = array(
        'firstname',
        'lastname',
        'order_id',
        'total',
        'store_name',
        'status',
        'comment',
    );

    /**
     * @param string               $template the text with placeholders such as {firstname}
     * @param array<string, mixed> $values   the value of each placeholder, by name without braces
     */
    public static function render(string $template, array $values): string
    {
        $map = array();
        foreach (self::PLACEHOLDERS as $name) {
            $value = isset($values[$name]) && is_scalar($values[$name]) ? (string) $values[$name] : '';
            // OpenCart stores what people type HTML-escaped; a text is plain, on one line per value.
            $value = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
            $map['{' . $name . '}'] = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', self::plainSpaces($value)));
        }
        $text = html_entity_decode(str_replace(array("\r\n", "\r"), "\n", $template), ENT_QUOTES, 'UTF-8');
        $text = strtr($text, $map);

        $lines = array();
        foreach (explode("\n", $text) as $line) {
            $lines[] = trim((string) preg_replace('/[ \t]+/', ' ', $line));
        }
        $text = trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));

        return self::cut($text, self::MAX_LENGTH);
    }

    /**
     * The text, cut to at most $length characters.
     */
    public static function cut(string $text, int $length): string
    {
        if (function_exists('mb_substr')) {
            return mb_strlen($text, 'UTF-8') > $length ? rtrim(mb_substr($text, 0, $length, 'UTF-8')) : $text;
        }

        return strlen($text) > $length ? rtrim(substr($text, 0, $length)) : $text;
    }

    /** Characters, not bytes. */
    public static function length(string $text): int
    {
        return function_exists('mb_strlen') ? (int) mb_strlen($text, 'UTF-8') : strlen($text);
    }

    /**
     * The placeholders as they are typed in a text: "{firstname} {lastname} ...".
     */
    public static function placeholderList(): string
    {
        return '{' . implode('} {', self::PLACEHOLDERS) . '}';
    }

    /**
     * No-break and thin spaces (prices, some names) are not in the basic SMS alphabet: a plain space
     * keeps each SMS part 160 characters long instead of 70.
     */
    public static function plainSpaces(string $text): string
    {
        return str_replace(array("\xC2\xA0", "\xE2\x80\xAF", "\xE2\x80\x89", "\xE2\x80\x88", "\xE2\x80\x87"), ' ', $text);
    }
}
