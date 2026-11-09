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
 * The texts the extension suggests until the store owner writes their own, in English and Romanian.
 *
 * They stay in the basic SMS alphabet (no ă, ș, ț...): one character outside it shortens every SMS part
 * from 160 to 70 characters.
 */
final class DefaultTexts
{
    const SHIPPED = 'shipped';
    const COMPLETE = 'complete';
    const CANCELED = 'canceled';
    const REFUNDED = 'refunded';
    const PROCESSING = 'processing';
    const PENDING = 'pending';
    const OTHER = 'other';

    /** The kinds whose statuses text the customer when the extension is installed. */
    const DEFAULT_KINDS = array(self::SHIPPED, self::COMPLETE, self::CANCELED, self::REFUNDED);

    /** OpenCart's own order statuses, for stores whose status names say nothing we recognise. */
    const KIND_BY_ID = array(
        1 => self::PENDING,
        2 => self::PROCESSING,
        3 => self::SHIPPED,
        5 => self::COMPLETE,
        7 => self::CANCELED,
        11 => self::REFUNDED,
        15 => self::PROCESSING,
    );

    const TEXTS = array(
        'en' => array(
            'owner' => '{store_name}: new order #{order_id} from {firstname} {lastname}, {total}.',
            'test' => 'This is a test text from {store_name}. Your order texts will come from this phone.',
            self::PENDING => 'Hello {firstname}, we have received your order #{order_id} at {store_name}. We will text you when it ships.',
            self::PROCESSING => 'Hello {firstname}, we are preparing your order #{order_id} from {store_name}.',
            self::SHIPPED => 'Hello {firstname}, your order #{order_id} from {store_name} is on its way. {comment}',
            self::COMPLETE => 'Hello {firstname}, your order #{order_id} from {store_name} is complete. Thank you!',
            self::CANCELED => 'Hello {firstname}, your order #{order_id} from {store_name} has been canceled. Reply to this text if you have any questions.',
            self::REFUNDED => 'Hello {firstname}, your order #{order_id} from {store_name} has been refunded.',
            self::OTHER => 'Hello {firstname}, your order #{order_id} from {store_name} is now: {status}.',
        ),
        'ro' => array(
            'owner' => '{store_name}: comanda noua #{order_id} de la {firstname} {lastname}, {total}.',
            'test' => 'Acesta este un mesaj de test de la {store_name}. Mesajele despre comenzi vor pleca de pe acest telefon.',
            self::PENDING => 'Buna, {firstname}! Am primit comanda #{order_id} la {store_name}. Iti scriem cand o expediem.',
            self::PROCESSING => 'Buna, {firstname}! Pregatim comanda #{order_id} de la {store_name}.',
            self::SHIPPED => 'Buna, {firstname}! Comanda #{order_id} de la {store_name} a fost expediata. {comment}',
            self::COMPLETE => 'Buna, {firstname}! Comanda #{order_id} de la {store_name} a fost finalizata. Multumim!',
            self::CANCELED => 'Buna, {firstname}! Comanda #{order_id} de la {store_name} a fost anulata. Raspunde la acest mesaj daca ai intrebari.',
            self::REFUNDED => 'Buna, {firstname}! Banii pentru comanda #{order_id} de la {store_name} au fost returnati.',
            self::OTHER => 'Buna, {firstname}! Comanda #{order_id} de la {store_name} are acum starea: {status}.',
        ),
    );

    /**
     * The language of the suggested texts for an OpenCart language code ("ro-ro" → ro, anything else → en).
     */
    public static function language($code): string
    {
        return stripos(trim((string) $code), 'ro') === 0 ? 'ro' : 'en';
    }

    /** The text to the store owner for a new order. */
    public static function owner(string $language): string
    {
        return self::text($language, 'owner');
    }

    /** The test text. */
    public static function test(string $language): string
    {
        return self::text($language, 'test');
    }

    /** The text to the customer for a kind of status. */
    public static function customer(string $kind, string $language): string
    {
        return self::text($language, isset(self::TEXTS['en'][$kind]) && !in_array($kind, array('owner', 'test'), true) ? $kind : self::OTHER);
    }

    /**
     * True when a text is one of the suggestions (in any language): it is then not stored, so each
     * order gets the suggestion in its own language and later versions can improve the wording.
     */
    public static function isSuggestion(string $text, string $key): bool
    {
        $text = trim(str_replace(array("\r\n", "\r"), "\n", $text));
        foreach (self::TEXTS as $texts) {
            if (isset($texts[$key]) && $texts[$key] === $text) {
                return true;
            }
        }

        return false;
    }

    /**
     * Which suggested text fits an order status, from its names (in any of the store's languages) or,
     * failing that, from OpenCart's own status ids.
     *
     * @param string[] $names
     */
    public static function kindOf(int $statusId, array $names): string
    {
        $joined = html_entity_decode(implode(' | ', $names), ENT_QUOTES, 'UTF-8');
        $joined = function_exists('mb_strtolower') ? mb_strtolower($joined, 'UTF-8') : strtolower($joined);
        $patterns = array(
            // "Canceled Reversal", "Chargeback"...: no suggestion of their own.
            self::OTHER => '/revers|chargeback|denied|expired|failed|voided|respins|esuat|eșuat|expirat/u',
            self::REFUNDED => '/refund|ramburs|returnat/u',
            self::CANCELED => '/cancel|anulat|anulare/u',
            self::SHIPPED => '/ship|expedi|dispatch|in transit/u',
            self::COMPLETE => '/complet|finaliz|deliver|livrat/u',
            self::PROCESSING => '/process|procesa|prepar|pregat|pregăt/u',
            self::PENDING => '/pending|astept|aștept/u',
        );
        foreach ($patterns as $kind => $pattern) {
            if (preg_match($pattern, $joined)) {
                return $kind;
            }
        }

        return isset(self::KIND_BY_ID[$statusId]) ? self::KIND_BY_ID[$statusId] : self::OTHER;
    }

    private static function text(string $language, string $key): string
    {
        $texts = isset(self::TEXTS[$language]) ? self::TEXTS[$language] : self::TEXTS['en'];

        return $texts[$key];
    }
}
