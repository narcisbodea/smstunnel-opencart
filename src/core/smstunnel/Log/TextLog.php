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
 * The texts the extension sent or tried to send, in the table PREFIX_smstunnel_log (newest 1000 kept).
 *
 * One text per event: each order text has a key (order + event + recipient) that is unique in the
 * table, so an event OpenCart fires twice, or a status saved again, finds the key and sends nothing,
 * unless the earlier attempt did not go out.
 *
 * Privacy: the full number and the words are kept only while the text may still be sent again (it did
 * not go out). Once SMSTunnel has it, or once it is skipped, the row keeps the number's last 2 digits
 * and no words.
 *
 * The database object is OpenCart's own (query, escape, countAffected, getLastId), in 3.0 and 4.x alike.
 */
class TextLog
{
    const TABLE = 'smstunnel_log';

    /** The log keeps the newest rows only. */
    const KEEP = 1000;

    const SENDING = 'sending';
    const SENT = 'sent';
    const QUEUED = 'queued';
    const FAILED = 'failed';
    const SKIPPED = 'skipped';
    /**
     * Sent, but SMSTunnel's answer never came (a timeout while waiting): the text may have gone out. It is
     * never tried again on its own; the store owner can check and send it again.
     */
    const UNCONFIRMED = 'unconfirmed';

    const KIND_OWNER = 'owner';
    const KIND_CUSTOMER = 'customer';
    const KIND_TEST = 'test';

    /** A row left in "sending" this long (a request that died midway) can be tried again. */
    const STALE_SECONDS = 600;

    /** @var object OpenCart's DB */
    private $db;

    /** @var string */
    private $table;

    /**
     * @param object $db     OpenCart's database object
     * @param string $prefix DB_PREFIX
     */
    public function __construct($db, string $prefix)
    {
        $this->db = $db;
        $this->table = $prefix . self::TABLE;
    }

    public function install()
    {
        $this->db->query('CREATE TABLE IF NOT EXISTS `' . $this->table . '` (
            `smstunnel_log_id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `dedupe_key` VARCHAR(64) NULL DEFAULT NULL,
            `kind` VARCHAR(16) NOT NULL,
            `order_id` INT(11) NOT NULL DEFAULT 0,
            `order_status_id` INT(11) NOT NULL DEFAULT 0,
            `store_id` INT(11) NOT NULL DEFAULT 0,
            `recipient` VARCHAR(40) NOT NULL DEFAULT \'\',
            `message` TEXT NULL,
            `status` VARCHAR(16) NOT NULL,
            `message_id` VARCHAR(64) NULL DEFAULT NULL,
            `delivery` VARCHAR(16) NULL DEFAULT NULL,
            `error` VARCHAR(255) NULL DEFAULT NULL,
            `attempts` INT(11) NOT NULL DEFAULT 1,
            `cleared` TINYINT(1) NOT NULL DEFAULT 0,
            `date_added` DATETIME NOT NULL,
            `date_modified` DATETIME NOT NULL,
            PRIMARY KEY (`smstunnel_log_id`),
            UNIQUE KEY `dedupe_key` (`dedupe_key`),
            KEY `order_id` (`order_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');
    }

    public function uninstall()
    {
        $this->db->query('DROP TABLE IF EXISTS `' . $this->table . '`');
    }

    /**
     * Reserves a text before it is sent.
     *
     * @param array{dedupe_key: string|null, kind: string, order_id: int, order_status_id: int, store_id: int, recipient: string, message: string} $text
     *
     * @return int the row id, or 0 when this text went out already or is going out right now
     */
    public function claim(array $text): int
    {
        $now = $this->now();
        $key = isset($text['dedupe_key']) && $text['dedupe_key'] !== '' ? substr((string) $text['dedupe_key'], 0, 64) : null;
        $recipient = $this->cut((string) $text['recipient'], 40);

        $this->db->query('INSERT IGNORE INTO `' . $this->table . '`
            (`dedupe_key`, `kind`, `order_id`, `order_status_id`, `store_id`, `recipient`, `message`, `status`, `attempts`, `date_added`, `date_modified`)
            VALUES (' . ($key === null ? 'NULL' : "'" . $this->db->escape($key) . "'") . ",
                '" . $this->db->escape((string) $text['kind']) . "',
                " . (int) $text['order_id'] . ',
                ' . (int) $text['order_status_id'] . ',
                ' . (int) $text['store_id'] . ",
                '" . $this->db->escape($recipient) . "',
                '" . $this->db->escape((string) $text['message']) . "',
                '" . self::SENDING . "',
                1,
                '" . $now . "',
                '" . $now . "')");
        if ($this->db->countAffected() > 0) {
            $id = (int) $this->db->getLastId();
            if ($id > 0 && $id % 50 === 0) {
                $this->prune();
            }

            return $id;
        }
        if ($key === null) {
            return 0;
        }

        // Already there: try again only when the earlier attempt surely did not go out (failed or skipped,
        // or a request that died midway long ago). A sent, waiting or unconfirmed text is never sent twice.
        $query = $this->db->query('SELECT `smstunnel_log_id` FROM `' . $this->table . "` WHERE `dedupe_key` = '" . $this->db->escape($key) . "'");
        $id = isset($query->row['smstunnel_log_id']) ? (int) $query->row['smstunnel_log_id'] : 0;
        if ($id <= 0) {
            return 0;
        }
        $this->db->query('UPDATE `' . $this->table . "`
            SET `status` = '" . self::SENDING . "', `attempts` = `attempts` + 1, `cleared` = 0,
                `recipient` = '" . $this->db->escape($recipient) . "',
                `message` = '" . $this->db->escape((string) $text['message']) . "',
                `message_id` = NULL, `delivery` = NULL, `error` = NULL, `date_modified` = '" . $now . "'
            WHERE `smstunnel_log_id` = " . $id . "
                AND (`status` IN ('" . self::FAILED . "', '" . self::SKIPPED . "')
                    OR (`status` = '" . self::SENDING . "' AND `date_modified` < '" . date('Y-m-d H:i:s', time() - self::STALE_SECONDS) . "'))");

        return $this->db->countAffected() > 0 ? $id : 0;
    }

    /**
     * Takes a text that did not go out, or whose sending was not confirmed, back for another attempt
     * ("Send again", the store owner's decision). False when someone sent it again in the meantime.
     */
    public function retry(int $id): bool
    {
        $this->db->query('UPDATE `' . $this->table . "`
            SET `status` = '" . self::SENDING . "', `attempts` = `attempts` + 1, `error` = NULL, `date_modified` = '" . $this->now() . "'
            WHERE `smstunnel_log_id` = " . (int) $id . " AND `status` IN ('" . self::FAILED . "', '" . self::UNCONFIRMED . "')
                AND `message` IS NOT NULL AND `message` <> ''");

        return $this->db->countAffected() > 0;
    }

    /**
     * Records how a reserved text ended. Only a text that may be sent again (failed, unconfirmed) keeps its
     * number and words.
     */
    public function finish(int $id, string $status, string $messageId = '', string $error = '')
    {
        $set = "`status` = '" . $this->db->escape($status) . "',
            `message_id` = " . ($messageId === '' ? 'NULL' : "'" . $this->db->escape(substr($messageId, 0, 64)) . "'") . ',
            `error` = ' . ($error === '' ? 'NULL' : "'" . $this->db->escape($this->cut(Redact::text($error), 255)) . "'") . ",
            `date_modified` = '" . $this->now() . "'";
        if (!in_array($status, array(self::FAILED, self::UNCONFIRMED, self::SENDING), true)) {
            $row = $this->find($id);
            if ($row !== null) {
                $set .= ", `recipient` = '" . $this->db->escape($this->cut(Redact::number($row['recipient']), 40)) . "', `message` = NULL";
            }
        }
        $this->db->query('UPDATE `' . $this->table . '` SET ' . $set . ' WHERE `smstunnel_log_id` = ' . (int) $id);
    }

    /**
     * What the phone did with a text SMSTunnel accepted (GET /api/v1/sms/status).
     */
    public function setDelivery(int $id, string $delivery, string $error = '')
    {
        $this->db->query('UPDATE `' . $this->table . "`
            SET `delivery` = '" . $this->db->escape(substr($delivery, 0, 16)) . "', `date_modified` = '" . $this->now() . "'"
            . ($error !== '' ? ", `error` = '" . $this->db->escape($this->cut(Redact::text($error), 255)) . "'" : '') . '
            WHERE `smstunnel_log_id` = ' . (int) $id);
    }

    /**
     * One row, or null.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id)
    {
        $query = $this->db->query('SELECT * FROM `' . $this->table . '` WHERE `smstunnel_log_id` = ' . (int) $id);

        return !empty($query->row) ? $query->row : null;
    }

    /**
     * The newest texts.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recent(int $limit = 30): array
    {
        $query = $this->db->query('SELECT * FROM `' . $this->table . '` WHERE `cleared` = 0 ORDER BY `smstunnel_log_id` DESC LIMIT ' . max(1, min(200, $limit)));

        return is_array($query->rows) ? $query->rows : array();
    }

    /**
     * The texts of one order, oldest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forOrder(int $orderId): array
    {
        $query = $this->db->query('SELECT * FROM `' . $this->table . '` WHERE `order_id` = ' . (int) $orderId . ' ORDER BY `smstunnel_log_id` ASC');

        return is_array($query->rows) ? $query->rows : array();
    }

    /**
     * The last text that did not go out because of an error, if any.
     *
     * @return array<string, mixed>|null
     */
    public function lastFailure()
    {
        $query = $this->db->query('SELECT * FROM `' . $this->table . "` WHERE `status` = '" . self::FAILED . "' AND `cleared` = 0 ORDER BY `smstunnel_log_id` DESC LIMIT 1");

        return !empty($query->row) ? $query->row : null;
    }

    /**
     * Recent texts SMSTunnel accepted whose fate on the phone is not known yet: never asked about, not
     * sent by the phone yet (asked about for 3 days), or sent and maybe delivered soon (asked about for
     * an hour: many carriers never confirm delivery, and a text the phone sent is done). A text is asked
     * about at most every 2 minutes.
     *
     * @return array<int, array<string, mixed>>
     */
    public function awaitingDelivery(int $limit = 10): array
    {
        $query = $this->db->query('SELECT * FROM `' . $this->table . '`
            WHERE ' . self::awaitingCondition(time()) . '
            ORDER BY `smstunnel_log_id` DESC LIMIT ' . max(1, min(50, $limit)));

        return is_array($query->rows) ? $query->rows : array();
    }

    /**
     * True when awaitingDelivery() would ask about this row (the list marks those rows).
     *
     * @param array<string, mixed> $row
     */
    public static function isAwaiting(array $row, int $now): bool
    {
        if ($row['message_id'] === null || $row['message_id'] === '' || !empty($row['cleared']) || !in_array($row['status'], array(self::SENT, self::QUEUED), true)) {
            return false;
        }
        $delivery = (string) $row['delivery'];
        $added = strtotime((string) $row['date_added']);
        $modified = strtotime((string) $row['date_modified']);
        if ($delivery === '') {
            return $added > $now - 3 * 86400;
        }
        if ($delivery === 'pending') {
            return $added > $now - 3 * 86400 && $modified < $now - 120;
        }

        return $delivery === 'sent' && $added > $now - 3600 && $modified < $now - 120;
    }

    private static function awaitingCondition(int $now): string
    {
        $threeDays = "'" . date('Y-m-d H:i:s', $now - 3 * 86400) . "'";
        $hour = "'" . date('Y-m-d H:i:s', $now - 3600) . "'";
        $cooldown = "'" . date('Y-m-d H:i:s', $now - 120) . "'";

        return "`message_id` IS NOT NULL AND `cleared` = 0 AND `status` IN ('" . self::SENT . "', '" . self::QUEUED . "')
            AND ((`delivery` IS NULL AND `date_added` > $threeDays)
                OR (`delivery` = 'pending' AND `date_added` > $threeDays AND `date_modified` < $cooldown)
                OR (`delivery` = 'sent' AND `date_added` > $hour AND `date_modified` < $cooldown))";
    }

    /**
     * "Clear the list": the texts leave the list and keep no number and no words, but an order text keeps
     * its key, so the same event still sends nothing twice. Test texts have no key and go for good.
     */
    public function clear()
    {
        $this->db->query('DELETE FROM `' . $this->table . '` WHERE `dedupe_key` IS NULL');
        $this->db->query('UPDATE `' . $this->table . "` SET `cleared` = 1, `recipient` = '', `message` = NULL WHERE `cleared` = 0");
    }

    /**
     * Keeps the newest KEEP rows.
     */
    public function prune()
    {
        $query = $this->db->query('SELECT `smstunnel_log_id` FROM `' . $this->table . '` ORDER BY `smstunnel_log_id` DESC LIMIT ' . (self::KEEP - 1) . ', 1');
        $oldestKept = isset($query->row['smstunnel_log_id']) ? (int) $query->row['smstunnel_log_id'] : 0;
        if ($oldestKept > 0) {
            $this->db->query('DELETE FROM `' . $this->table . '` WHERE `smstunnel_log_id` < ' . $oldestKept);
        }
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    private function cut(string $text, int $length): string
    {
        return function_exists('mb_substr') ? (string) mb_substr($text, 0, $length, 'UTF-8') : substr($text, 0, $length);
    }
}
