<?php
/**
 * SMSTunnel for OpenCart.
 *
 * @author    Narcis Bodea <contact@narboweb.ro>
 * @copyright 2026 NARBOWEB SRL
 * @license   GPL-3.0-or-later https://www.gnu.org/licenses/gpl-3.0.html
 */

namespace SmsTunnel\OpenCart\Store;

/**
 * Reads what the texts need from OpenCart's own tables, with plain SQL that works the same in
 * OpenCart 3.0, 4.0 and 4.1 (and does not fire OpenCart's model events).
 */
class OrderRepository
{
    /** @var object OpenCart's DB */
    private $db;

    /** @var string */
    private $prefix;

    /** @var array<string, mixed> */
    private $cache = array();

    /**
     * @param object $db     OpenCart's database object
     * @param string $prefix DB_PREFIX
     */
    public function __construct($db, string $prefix)
    {
        $this->db = $db;
        $this->prefix = $prefix;
    }

    /**
     * The order's fields the texts use, or null.
     *
     * @return array<string, mixed>|null
     */
    public function order(int $orderId)
    {
        if ($orderId <= 0) {
            return null;
        }
        $query = $this->db->query('SELECT `order_id`, `store_id`, `store_name`, `firstname`, `lastname`, `telephone`, `total`,
                `currency_code`, `currency_value`, `language_id`, `order_status_id`, `customer_id`, `shipping_country_id`, `payment_country_id`
            FROM `' . $this->prefix . 'order` WHERE `order_id` = ' . (int) $orderId);

        return !empty($query->row) ? $query->row : null;
    }

    /** The order's current status id (0: a checkout not finished yet), or -1 when there is no such order. */
    public function statusId(int $orderId): int
    {
        $query = $this->db->query('SELECT `order_status_id` FROM `' . $this->prefix . 'order` WHERE `order_id` = ' . (int) $orderId);

        return isset($query->row['order_status_id']) ? (int) $query->row['order_status_id'] : -1;
    }

    /** How many status changes the order has had. */
    public function historyCount(int $orderId): int
    {
        $query = $this->db->query('SELECT COUNT(*) AS `total` FROM `' . $this->prefix . 'order_history` WHERE `order_id` = ' . (int) $orderId);

        return isset($query->row['total']) ? (int) $query->row['total'] : 0;
    }

    /** The name of a status in a language (the store's default language when missing). */
    public function statusName(int $statusId, int $languageId): string
    {
        $names = $this->statusNames($statusId);
        if (isset($names[$languageId])) {
            return $names[$languageId];
        }

        return $names ? (string) reset($names) : '';
    }

    /**
     * A status's names in every language: [language_id => name].
     *
     * @return array<int, string>
     */
    public function statusNames(int $statusId): array
    {
        $key = 'status:' . $statusId;
        if (!isset($this->cache[$key])) {
            $names = array();
            $query = $this->db->query('SELECT `language_id`, `name` FROM `' . $this->prefix . 'order_status` WHERE `order_status_id` = ' . (int) $statusId);
            foreach ($query->rows as $row) {
                $names[(int) $row['language_id']] = html_entity_decode((string) $row['name'], ENT_QUOTES, 'UTF-8');
            }
            $this->cache[$key] = $names;
        }

        return $this->cache[$key];
    }

    /**
     * The order statuses of the store, in a language: [order_status_id => name], sorted by name.
     *
     * @return array<int, string>
     */
    public function orderStatuses(int $languageId): array
    {
        $query = $this->db->query('SELECT `order_status_id`, `name` FROM `' . $this->prefix . 'order_status` WHERE `language_id` = ' . (int) $languageId . ' ORDER BY `name`');
        $statuses = array();
        foreach ($query->rows as $row) {
            $statuses[(int) $row['order_status_id']] = html_entity_decode((string) $row['name'], ENT_QUOTES, 'UTF-8');
        }
        if (!$statuses) {
            // No names in this language: any language will do.
            $query = $this->db->query('SELECT `order_status_id`, MIN(`name`) AS `name` FROM `' . $this->prefix . 'order_status` GROUP BY `order_status_id` ORDER BY `name`');
            foreach ($query->rows as $row) {
                $statuses[(int) $row['order_status_id']] = html_entity_decode((string) $row['name'], ENT_QUOTES, 'UTF-8');
            }
        }

        return $statuses;
    }

    /** The ISO code of a country ("RO"), or ''. */
    public function countryIso(int $countryId): string
    {
        if ($countryId <= 0) {
            return '';
        }
        $key = 'country:' . $countryId;
        if (!isset($this->cache[$key])) {
            $query = $this->db->query('SELECT `iso_code_2` FROM `' . $this->prefix . 'country` WHERE `country_id` = ' . (int) $countryId);
            $this->cache[$key] = isset($query->row['iso_code_2']) ? strtoupper((string) $query->row['iso_code_2']) : '';
        }

        return $this->cache[$key];
    }

    /**
     * The store's countries for a list: [iso_code_2 => name], sorted by name. OpenCart 4.1 keeps the
     * names in country_description, earlier versions in country.
     *
     * @return array<string, string>
     */
    public function countries(int $languageId): array
    {
        if ($this->hasTable('country_description')) {
            $query = $this->db->query('SELECT c.`iso_code_2`, cd.`name` FROM `' . $this->prefix . 'country` c
                LEFT JOIN `' . $this->prefix . 'country_description` cd ON (cd.`country_id` = c.`country_id` AND cd.`language_id` = ' . (int) $languageId . ')
                WHERE c.`status` = 1 ORDER BY cd.`name`');
        } else {
            $query = $this->db->query('SELECT `iso_code_2`, `name` FROM `' . $this->prefix . 'country` WHERE `status` = 1 ORDER BY `name`');
        }
        $countries = array();
        foreach ($query->rows as $row) {
            $iso = strtoupper((string) $row['iso_code_2']);
            if ($iso !== '' && !isset($countries[$iso])) {
                $countries[$iso] = html_entity_decode((string) ($row['name'] !== null ? $row['name'] : $iso), ENT_QUOTES, 'UTF-8');
            }
        }

        return $countries;
    }

    /** The code of a language ("en-gb"), or ''. */
    public function languageCode(int $languageId): string
    {
        $key = 'language:' . $languageId;
        if (!isset($this->cache[$key])) {
            $query = $this->db->query('SELECT `code` FROM `' . $this->prefix . 'language` WHERE `language_id` = ' . (int) $languageId);
            $this->cache[$key] = isset($query->row['code']) ? (string) $query->row['code'] : '';
        }

        return $this->cache[$key];
    }

    /** The id of a language by its code ("en-gb"), or 0. */
    public function languageId(string $code): int
    {
        $query = $this->db->query('SELECT `language_id` FROM `' . $this->prefix . "language` WHERE `code` = '" . $this->db->escape($code) . "'");

        return isset($query->row['language_id']) ? (int) $query->row['language_id'] : 0;
    }

    /**
     * One setting of a store (store 0 is the default store), as stored: arrays decoded.
     *
     * @return mixed null when missing
     */
    public function setting(string $key, int $storeId = 0)
    {
        $query = $this->db->query('SELECT `value`, `serialized` FROM `' . $this->prefix . 'setting` WHERE `store_id` = ' . (int) $storeId . " AND `key` = '" . $this->db->escape($key) . "'");
        if (empty($query->row)) {
            return null;
        }

        return !empty($query->row['serialized']) ? json_decode((string) $query->row['value'], true) : $query->row['value'];
    }

    /**
     * All the settings of a code (such as module_smstunnel) in store 0: [key => value], arrays decoded.
     *
     * @return array<string, mixed>
     */
    public function settings(string $code): array
    {
        $query = $this->db->query('SELECT `key`, `value`, `serialized` FROM `' . $this->prefix . "setting` WHERE `store_id` = 0 AND `code` = '" . $this->db->escape($code) . "'");
        $settings = array();
        foreach ($query->rows as $row) {
            $settings[(string) $row['key']] = !empty($row['serialized']) ? json_decode((string) $row['value'], true) : $row['value'];
        }

        return $settings;
    }

    /** The language of the store owner (the admin's language code, then the store's). */
    public function adminLanguageCode(): string
    {
        foreach (array('config_language_admin', 'config_admin_language', 'config_language_catalog', 'config_language') as $key) {
            $code = $this->setting($key);
            if (is_string($code) && $code !== '') {
                return $code;
            }
        }

        return 'en-gb';
    }

    /** The calling code country of the store (ISO), from its address settings. */
    public function storeCountryIso(): string
    {
        return $this->countryIso((int) $this->setting('config_country_id'));
    }

    private function hasTable(string $table): bool
    {
        $key = 'table:' . $table;
        if (!isset($this->cache[$key])) {
            $query = $this->db->query("SHOW TABLES LIKE '" . $this->db->escape($this->prefix . $table) . "'");
            $this->cache[$key] = !empty($query->num_rows);
        }

        return $this->cache[$key];
    }
}
