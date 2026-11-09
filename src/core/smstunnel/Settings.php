<?php
/**
 * SMSTunnel for OpenCart.
 *
 * @author    Narcis Bodea <contact@narboweb.ro>
 * @copyright 2026 NARBOWEB SRL
 * @license   GPL-3.0-or-later https://www.gnu.org/licenses/gpl-3.0.html
 */

namespace SmsTunnel\OpenCart;

use SmsTunnel\OpenCart\Api\Client;
use SmsTunnel\OpenCart\Phone\CallingCodes;

/**
 * The extension's settings: OpenCart's setting table, code "module_smstunnel", default store (they apply
 * to every store of the installation).
 */
final class Settings
{
    const CODE = 'module_smstunnel';

    const STATUS = 'module_smstunnel_status';
    const API_KEY = 'module_smstunnel_api_key';
    const API_URL = 'module_smstunnel_api_url';
    const DEVICE_ID = 'module_smstunnel_device_id';
    const COUNTRY = 'module_smstunnel_country';
    const OWNER_STATUS = 'module_smstunnel_owner_status';
    const OWNER_NUMBERS = 'module_smstunnel_owner_numbers';
    const OWNER_TEXT = 'module_smstunnel_owner_text';
    const CUSTOMER_STATUS = 'module_smstunnel_customer_status';
    const CUSTOMER_STATUSES = 'module_smstunnel_customer_statuses';
    const CUSTOMER_TEXTS = 'module_smstunnel_customer_texts';

    /** Every key the extension stores; uninstalling deletes them all (they share the code). */
    const KEYS = array(
        self::STATUS, self::API_KEY, self::API_URL, self::DEVICE_ID, self::COUNTRY,
        self::OWNER_STATUS, self::OWNER_NUMBERS, self::OWNER_TEXT,
        self::CUSTOMER_STATUS, self::CUSTOMER_STATUSES, self::CUSTOMER_TEXTS,
    );

    /** New-order texts go to at most this many numbers. */
    const MAX_OWNER_NUMBERS = 5;

    /** @var array<string, mixed> */
    private $values;

    /**
     * @param array<string, mixed> $values the stored settings, by key
     */
    public function __construct(array $values)
    {
        $this->values = $values;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->values;
    }

    public function enabled(): bool
    {
        return !empty($this->values[self::STATUS]);
    }

    public function apiKey(): string
    {
        return $this->string(self::API_KEY);
    }

    public function apiUrl(): string
    {
        $url = rtrim($this->string(self::API_URL), '/');

        return $url !== '' ? $url : Client::DEFAULT_URL;
    }

    public function deviceId(): string
    {
        return $this->string(self::DEVICE_ID);
    }

    /** The country whose calling code completes local numbers (ISO, e.g. "RO"). */
    public function country(): string
    {
        return strtoupper($this->string(self::COUNTRY));
    }

    /** That country's calling code, e.g. "40", or ''. */
    public function callingCode(): string
    {
        return CallingCodes::forCountry($this->country());
    }

    public function ownerEnabled(): bool
    {
        return !empty($this->values[self::OWNER_STATUS]);
    }

    /**
     * @return string[] the owner's numbers as saved (E.164), at most 5
     */
    public function ownerNumbers(): array
    {
        return array_slice(self::splitNumbers($this->string(self::OWNER_NUMBERS)), 0, self::MAX_OWNER_NUMBERS);
    }

    /** The owner text as written, or '' for the suggested one. */
    public function ownerText(): string
    {
        return $this->string(self::OWNER_TEXT);
    }

    public function customerEnabled(): bool
    {
        return !empty($this->values[self::CUSTOMER_STATUS]);
    }

    /**
     * @return int[] the order statuses that text the customer
     */
    public function customerStatuses(): array
    {
        $statuses = isset($this->values[self::CUSTOMER_STATUSES]) ? $this->values[self::CUSTOMER_STATUSES] : array();
        if (is_string($statuses)) {
            $decoded = json_decode($statuses, true);
            $statuses = is_array($decoded) ? $decoded : array();
        }
        if (!is_array($statuses)) {
            return array();
        }

        return array_values(array_unique(array_filter(array_map('intval', $statuses), function ($id) {
            return $id > 0;
        })));
    }

    /**
     * The customer texts the store owner wrote: [order_status_id => text].
     *
     * @return array<int, string>
     */
    public function customerTexts(): array
    {
        $texts = isset($this->values[self::CUSTOMER_TEXTS]) ? $this->values[self::CUSTOMER_TEXTS] : array();
        if (is_string($texts)) {
            $decoded = json_decode($texts, true);
            $texts = is_array($decoded) ? $decoded : array();
        }
        $clean = array();
        if (is_array($texts)) {
            foreach ($texts as $statusId => $text) {
                if (is_string($text) && trim($text) !== '' && (int) $statusId > 0) {
                    $clean[(int) $statusId] = trim($text);
                }
            }
        }

        return $clean;
    }

    /** The text written for one status, or '' for the suggested one. */
    public function customerText(int $statusId): string
    {
        $texts = $this->customerTexts();

        return isset($texts[$statusId]) ? $texts[$statusId] : '';
    }

    /**
     * @return string[] the non-empty parts of a list of numbers separated by commas, semicolons or lines
     */
    public static function splitNumbers(string $numbers): array
    {
        $parts = preg_split('/[,;\r\n]+/', $numbers);

        return array_values(array_filter(array_map('trim', is_array($parts) ? $parts : array()), function ($part) {
            return $part !== '';
        }));
    }

    private function string(string $key): string
    {
        return isset($this->values[$key]) && is_scalar($this->values[$key]) ? trim((string) $this->values[$key]) : '';
    }
}
