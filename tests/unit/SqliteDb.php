<?php
/**
 * An in-memory stand-in for OpenCart's database object (query, escape, countAffected, getLastId), on
 * SQLite, with OpenCart's tables reduced to the columns the extension reads. MySQL-only syntax the
 * extension uses (INSERT IGNORE, SHOW TABLES LIKE) is translated.
 *
 * @license GPL-3.0-or-later
 */
final class SqliteDb
{
    /** @var PDO */
    private $pdo;

    /** @var int */
    private $affected = 0;

    /** @var string[] every statement, for the tests */
    public $log = array();

    public function __construct(string $prefix = 'oc_')
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $p = $prefix;
        $this->pdo->exec("CREATE TABLE `{$p}smstunnel_log` (
            `smstunnel_log_id` INTEGER PRIMARY KEY AUTOINCREMENT,
            `dedupe_key` VARCHAR(64) NULL UNIQUE,
            `kind` VARCHAR(16) NOT NULL,
            `order_id` INTEGER NOT NULL DEFAULT 0,
            `order_status_id` INTEGER NOT NULL DEFAULT 0,
            `store_id` INTEGER NOT NULL DEFAULT 0,
            `recipient` VARCHAR(40) NOT NULL DEFAULT '',
            `message` TEXT NULL,
            `status` VARCHAR(16) NOT NULL,
            `message_id` VARCHAR(64) NULL,
            `delivery` VARCHAR(16) NULL,
            `error` VARCHAR(255) NULL,
            `attempts` INTEGER NOT NULL DEFAULT 1,
            `cleared` INTEGER NOT NULL DEFAULT 0,
            `date_added` DATETIME NOT NULL,
            `date_modified` DATETIME NOT NULL)");
        $this->pdo->exec("CREATE TABLE `{$p}order` (`order_id` INTEGER PRIMARY KEY, `store_id` INTEGER, `store_name` TEXT, `firstname` TEXT,
            `lastname` TEXT, `telephone` TEXT, `total` REAL, `currency_code` TEXT, `currency_value` REAL, `language_id` INTEGER,
            `order_status_id` INTEGER, `customer_id` INTEGER, `shipping_country_id` INTEGER, `payment_country_id` INTEGER)");
        $this->pdo->exec("CREATE TABLE `{$p}order_history` (`order_history_id` INTEGER PRIMARY KEY AUTOINCREMENT, `order_id` INTEGER, `order_status_id` INTEGER, `notify` INTEGER, `comment` TEXT, `date_added` DATETIME)");
        $this->pdo->exec("CREATE TABLE `{$p}order_status` (`order_status_id` INTEGER, `language_id` INTEGER, `name` TEXT)");
        $this->pdo->exec("CREATE TABLE `{$p}country` (`country_id` INTEGER PRIMARY KEY, `name` TEXT, `iso_code_2` TEXT, `status` INTEGER DEFAULT 1)");
        $this->pdo->exec("CREATE TABLE `{$p}language` (`language_id` INTEGER PRIMARY KEY, `code` TEXT)");
        $this->pdo->exec("CREATE TABLE `{$p}setting` (`setting_id` INTEGER PRIMARY KEY AUTOINCREMENT, `store_id` INTEGER, `code` TEXT, `key` TEXT, `value` TEXT, `serialized` INTEGER DEFAULT 0)");
    }

    /**
     * @return object|bool like OpenCart: an object with row, rows and num_rows for a query that returns rows
     */
    public function query($sql)
    {
        $this->log[] = $sql;
        $sql = trim($sql);
        if (preg_match("/^SHOW TABLES LIKE '([^']+)'$/i", $sql, $match)) {
            $sql = "SELECT name FROM sqlite_master WHERE type = 'table' AND name = '" . $match[1] . "'";
        }
        $sql = preg_replace('/^INSERT IGNORE INTO/i', 'INSERT OR IGNORE INTO', $sql);
        $statement = $this->pdo->query($sql);
        if (preg_match('/^(SELECT|SHOW|PRAGMA)\b/i', $sql)) {
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            $result = new stdClass();
            $result->rows = $rows;
            $result->row = isset($rows[0]) ? $rows[0] : array();
            $result->num_rows = count($rows);

            return $result;
        }
        $this->affected = $statement->rowCount();

        return true;
    }

    public function escape($value)
    {
        return str_replace("'", "''", (string) $value);
    }

    public function countAffected()
    {
        return $this->affected;
    }

    public function getLastId()
    {
        return (int) $this->pdo->lastInsertId();
    }
}
