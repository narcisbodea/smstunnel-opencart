<?php
/**
 * SMSTunnel for OpenCart.
 *
 * @author    Narcis Bodea <contact@narboweb.ro>
 * @copyright 2026 NARBOWEB SRL
 * @license   GPL-3.0-or-later https://www.gnu.org/licenses/gpl-3.0.html
 */

namespace SmsTunnel\OpenCart\Phone;

/**
 * Turns the number a customer typed into the international format (E.164) and refuses the numbers a
 * store should never text: malformed ones, premium-rate numbers, short codes and satellite networks.
 *
 * The checks are those of the SMSTunnel WordPress plugin and PrestaShop module. Numbers written without
 * "+" or "00" are completed with a country calling code, the way the SMSTunnel server does.
 */
final class PhoneNumber
{
    /** The number is fine. */
    const OK = '';

    /** No number was given. */
    const MISSING = 'missing';

    /** Not a phone number SMSTunnel can text. */
    const INVALID = 'invalid';

    /** A premium-rate number, a short code or a satellite network. */
    const PREMIUM = 'premium';

    /**
     * Calling codes of the countries where the leading 0 belongs to the number itself (Italy, San Marino,
     * Vatican, Côte d'Ivoire, Benin, Gabon, Republic of the Congo), so it stays after the calling code.
     */
    const KEEP_LEADING_ZERO = array('39', '378', '379', '225', '229', '241', '242');

    /** Kept in sync with the SMSTunnel WordPress plugin (SMSTunnel::is_premium_number()). */
    const PREMIUM_PATTERNS = array(
        '/^\+40(90|80)[0-9]+$/',
        '/^\+44(90|91|98)[0-9]+$/',
        '/^\+1(900)[0-9]+$/',
        '/^\+49(900|137|138)[0-9]+$/',
        '/^\+33(89)[0-9]+$/',
        '/^\+39(89|166|899)[0-9]+$/',
        '/^\+34(80[3-7]|90[3-7])[0-9]+$/',
    );

    /** Satellite networks the SMSTunnel server refuses (Iridium, Thuraya, Inmarsat, Globalstar...). */
    const BLOCKED_PREFIXES = array('+8816', '+8817', '+88216', '+88217', '+870', '+871', '+872', '+873', '+874', '+881', '+882');

    /**
     * The number in E.164 format, or an empty string when it is missing, invalid or not allowed.
     *
     * @param mixed      $raw        the number as typed
     * @param string|int $callPrefix the calling code to complete a national number with, e.g. "40" (empty: none)
     */
    public static function normalize($raw, $callPrefix = ''): string
    {
        $result = self::analyse($raw, $callPrefix);

        return $result[0];
    }

    /**
     * @param mixed      $raw        the number as typed
     * @param string|int $callPrefix the calling code to complete a national number with, e.g. "40" (empty: none)
     *
     * @return array{0: string, 1: string} the E.164 number (or '') and the problem (one of the constants)
     */
    public static function analyse($raw, $callPrefix = ''): array
    {
        if (!is_string($raw) && !is_int($raw) && !is_float($raw)) {
            return array('', self::MISSING);
        }
        $raw = trim(html_entity_decode((string) $raw, ENT_QUOTES, 'UTF-8'));
        // "+49 (0) 170 1234567": the (0) is the trunk prefix written for local callers, not a digit to dial.
        $raw = trim((string) preg_replace('/\(\s*0\s*\)/', ' ', $raw));
        $digits = (string) preg_replace('/\D+/', '', $raw);
        if ($digits === '') {
            return array('', $raw === '' ? self::MISSING : self::INVALID);
        }
        $prefix = (string) preg_replace('/\D+/', '', (string) $callPrefix);

        if (preg_match('/^[^0-9]*\+/', $raw)) {
            // Already international: "+40 721 123 456", "(+40) 721 123 456".
            $number = '+' . $digits;
        } elseif (strpos($digits, '00') === 0) {
            // International with 00: "0040 721 123 456".
            $number = '+' . substr($digits, 2);
        } elseif ($prefix === '') {
            // No country to complete it with: read it as international, like the WordPress plugin.
            $number = '+' . $digits;
        } elseif ($prefix === '7' && strlen($digits) === 11 && $digits[0] === '8') {
            // Russia and Kazakhstan write the trunk prefix as 8: "8 916 123-45-67".
            $number = '+7' . substr($digits, 1);
        } elseif ($digits[0] === '0') {
            // National number with its trunk 0: "0721 123 456" in Romania is +40 721 123 456.
            $number = '+' . $prefix . (in_array($prefix, self::KEEP_LEADING_ZERO, true) ? $digits : substr($digits, 1));
        } elseif (strlen($digits) >= 11 && strpos($digits, $prefix) === 0) {
            // The calling code typed without "+": "40721123456". No national number is that long.
            $number = '+' . $digits;
        } else {
            // National number without the trunk 0: "721123456", "(415) 555-0123".
            $number = '+' . $prefix . $digits;
        }

        if (!self::isE164($number)) {
            return array('', self::INVALID);
        }
        if (self::isPremium($number)) {
            return array('', self::PREMIUM);
        }

        return array($number, self::OK);
    }

    /**
     * @param mixed $number
     */
    public static function isE164($number): bool
    {
        return is_string($number) && preg_match('/^\+[1-9]\d{6,14}$/', $number) === 1;
    }

    /**
     * True for premium-rate numbers, short codes and satellite networks (E.164 input).
     */
    public static function isPremium(string $number): bool
    {
        if (!preg_match('/^\+[1-9]\d+$/', $number)) {
            return false;
        }
        if (strlen($number) < 10) {
            return true;
        }
        foreach (self::PREMIUM_PATTERNS as $pattern) {
            if (preg_match($pattern, $number)) {
                return true;
            }
        }
        foreach (self::BLOCKED_PREFIXES as $blocked) {
            if (strpos($number, $blocked) === 0) {
                return true;
            }
        }

        return false;
    }
}
