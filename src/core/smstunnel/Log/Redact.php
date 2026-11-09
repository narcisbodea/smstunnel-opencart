<?php
/**
 * SMSTunnel for OpenCart.
 *
 * @author    Narcis Bodea <contact@narboweb.ro>
 * @copyright 2026 NARBOWEB SRL
 * @license   GPL-3.0-or-later https://www.gnu.org/licenses/gpl-3.0.html
 */

namespace SmsTunnel\OpenCart\Log;

/**
 * What may be written down: phone numbers show only their last 2 digits, API keys never show.
 */
final class Redact
{
    /**
     * A phone number with all but its last 2 digits hidden: "+14155550123" → "+•••••••••23".
     */
    public static function number($number): string
    {
        $number = trim((string) $number);
        if ($number === '' || strpos($number, '•') !== false) {
            // Empty, or masked already (the log keeps sent texts' numbers masked).
            return $number;
        }
        $digits = (string) preg_replace('/\D+/', '', $number);
        if ($digits === '') {
            // Not a number at all ("n/a"...): nothing worth hiding, but keep it short.
            return self::cut($number, 12);
        }
        $shown = substr($digits, -2);
        $hidden = max(0, strlen($digits) - strlen($shown));

        return (strpos(ltrim($number), '+') === 0 ? '+' : '') . str_repeat('•', min($hidden, 15)) . $shown;
    }

    /**
     * A text safe for a log: API keys removed, long digit runs (phone numbers) masked to their last 2 digits.
     */
    public static function text($text): string
    {
        $text = (string) $text;
        $text = (string) preg_replace('/\b(sk|rk)_(live|test)_[A-Za-z0-9_*]+/', '[API key]', $text);
        $text = (string) preg_replace('/(?<![A-Za-z0-9])(?:key|token|api[_-]?key)\s*[=:]\s*\S+/i', '[API key]', $text);

        return (string) preg_replace_callback('/\+?\d[\d\s().\-]{4,}\d/', function ($match) {
            $found = trim($match[0]);
            // IP addresses and dates are not phone numbers.
            if (preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', $found) || preg_match('/^\d{4}-\d{2}-\d{2}/', $found)) {
                return $match[0];
            }
            $digits = preg_replace('/\D+/', '', $found);

            // Order numbers, HTTP statuses and ports stay; anything as long as a phone number is masked.
            return strlen((string) $digits) >= 6 ? self::number($found) : $match[0];
        }, $text);
    }

    private static function cut(string $text, int $length): string
    {
        return function_exists('mb_substr') ? (string) mb_substr($text, 0, $length, 'UTF-8') : substr($text, 0, $length);
    }
}
