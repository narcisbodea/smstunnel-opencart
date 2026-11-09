<?php
/**
 * SMSTunnel for OpenCart.
 *
 * @author    Narcis Bodea <contact@narboweb.ro>
 * @copyright 2026 NARBOWEB SRL
 * @license   GPL-3.0-or-later https://www.gnu.org/licenses/gpl-3.0.html
 */

namespace SmsTunnel\OpenCart;

use SmsTunnel\OpenCart\Api\ApiException;
use SmsTunnel\OpenCart\Api\Client;
use SmsTunnel\OpenCart\Api\TransportException;
use SmsTunnel\OpenCart\Log\Redact;
use SmsTunnel\OpenCart\Log\TextLog;
use SmsTunnel\OpenCart\Message\DefaultTexts;
use SmsTunnel\OpenCart\Message\Template;
use SmsTunnel\OpenCart\Phone\CallingCodes;
use SmsTunnel\OpenCart\Phone\PhoneNumber;
use SmsTunnel\OpenCart\Store\OrderRepository;

/**
 * Decides which order texts to send, to which number, and sends each one once.
 *
 * OpenCart calls it around its order-history model method (addOrderHistory in 3.0, addHistory in 4.x),
 * which runs at checkout and when the store owner changes an order's status:
 * - before(): notes the order's status before the change;
 * - after(): a first status (0 → something) is a new order: a text to the owner's numbers; a different
 *   status that the owner ticked: a text to the customer. A status saved again sends nothing.
 *
 * Every call to SMSTunnel has short timeouts, and the OpenCart controllers catch everything around these
 * methods, so neither a checkout nor a status change ever waits long or fails because of SMSTunnel.
 */
class Notifier
{
    /** Seconds a send may take while a customer checks out or an order status changes. */
    const TIMEOUT = 8.0;

    /** Seconds to wait for a connection to SMSTunnel. */
    const CONNECT_TIMEOUT = 3.0;

    /**
     * Each order's status before the change that is being saved, by order id (filled by before()).
     *
     * @var array<int, int>
     */
    private static $previous = array();

    /**
     * SMSTunnel addresses that could not be reached, or that refused the key or the rate, during this
     * request: later texts of the same request are not tried, so a checkout never waits for several
     * timeouts in a row and a wrong key is not tried five times. The value is the first error.
     *
     * @var array<string, string>
     */
    private static $blocked = array();

    /** @var OrderRepository */
    private $orders;

    /** @var TextLog */
    private $log;

    /** @var string */
    private $userAgent;

    /** @var callable|null fn(float $amount, string $currency, float $value): string */
    private $formatTotal;

    /** @var callable|null fn(string $line): void */
    private $logger;

    /** @var Settings|null */
    private $settings;

    /**
     * @param object        $db          OpenCart's database object
     * @param string        $prefix      DB_PREFIX
     * @param callable|null $formatTotal formats an order total like the store does (OpenCart's currency library)
     * @param callable|null $logger      writes a line to OpenCart's error log
     * @param Settings|null $settings    the settings; read from the database when null
     */
    public function __construct($db, string $prefix, string $userAgent, $formatTotal = null, $logger = null, $settings = null)
    {
        $this->orders = new OrderRepository($db, $prefix);
        $this->log = new TextLog($db, $prefix);
        $this->userAgent = $userAgent;
        $this->formatTotal = is_callable($formatTotal) ? $formatTotal : null;
        $this->logger = is_callable($logger) ? $logger : null;
        $this->settings = $settings instanceof Settings ? $settings : null;
    }

    public function settings(): Settings
    {
        if ($this->settings === null) {
            $this->settings = new Settings($this->orders->settings(Settings::CODE));
        }

        return $this->settings;
    }

    public function orders(): OrderRepository
    {
        return $this->orders;
    }

    public function log(): TextLog
    {
        return $this->log;
    }

    /**
     * Before OpenCart saves a status change: remember the order's status.
     */
    public function before(int $orderId)
    {
        if ($orderId > 0) {
            self::$previous[$orderId] = $this->orders->statusId($orderId);
        }
    }

    /**
     * After OpenCart saved a status change: send the texts it calls for.
     *
     * @param string $comment the comment typed with the change
     * @param bool   $notify  whether "Notify customer" was ticked (the comment is shared only then)
     */
    public function after(int $orderId, string $comment = '', bool $notify = false)
    {
        if ($orderId <= 0) {
            return;
        }
        $old = array_key_exists($orderId, self::$previous) ? self::$previous[$orderId] : null;
        unset(self::$previous[$orderId]);

        $order = $this->orders->order($orderId);
        if ($order === null) {
            return;
        }
        // The status OpenCart really saved (a fraud check may have changed the one asked for).
        $new = (int) $order['order_status_id'];
        if ($new <= 0) {
            return;
        }
        if ($old === null) {
            // before() did not run (the extension was installed during this request): the first entry of
            // the order's history is the new order.
            $old = $this->orders->historyCount($orderId) <= 1 ? 0 : -1;
        }

        $settings = $this->settings();
        if (!$settings->enabled() || $settings->apiKey() === '') {
            return;
        }
        if ($old === 0) {
            $this->newOrder($settings, $order);
        }
        if ($new !== $old) {
            $this->statusChanged($settings, $order, $new, $comment, $notify);
        }
    }

    /**
     * A new order: a text to each of the owner's numbers.
     *
     * @param array<string, mixed> $order
     */
    public function newOrder(Settings $settings, array $order)
    {
        $numbers = $settings->ownerNumbers();
        if (!$settings->ownerEnabled() || !$numbers) {
            return;
        }
        $languageCode = $this->orders->adminLanguageCode();
        $template = $settings->ownerText();
        if ($template === '') {
            $template = DefaultTexts::owner(DefaultTexts::language($languageCode));
        }
        $statusId = (int) $order['order_status_id'];
        $message = Template::render($template, $this->values($order, $statusId, $this->orders->languageId($languageCode), ''));
        $prefix = $settings->callingCode();

        foreach ($numbers as $raw) {
            list($number, $problem) = PhoneNumber::analyse($raw, $prefix);
            $this->deliver($settings, array(
                'dedupe_key' => 'o:' . (int) $order['order_id'] . ':new:' . substr(sha1($number !== '' ? $number : $raw), 0, 16),
                'kind' => TextLog::KIND_OWNER,
                'order_id' => (int) $order['order_id'],
                'order_status_id' => $statusId,
                'store_id' => (int) $order['store_id'],
                'recipient' => $number !== '' ? $number : $raw,
                'message' => $message,
            ), $problem, $prefix);
        }
    }

    /**
     * An order moved to another status: a text to the customer, if the owner ticked that status.
     *
     * @param array<string, mixed> $order
     */
    public function statusChanged(Settings $settings, array $order, int $statusId, string $comment = '', bool $notify = false)
    {
        if (!$settings->customerEnabled() || !in_array($statusId, $settings->customerStatuses(), true)) {
            return;
        }
        $languageId = (int) $order['language_id'];
        $template = $settings->customerText($statusId);
        if ($template === '') {
            $kind = DefaultTexts::kindOf($statusId, $this->orders->statusNames($statusId));
            $template = DefaultTexts::customer($kind, DefaultTexts::language($this->orders->languageCode($languageId)));
        }
        $message = Template::render($template, $this->values($order, $statusId, $languageId, $notify ? $comment : ''));
        list($number, $problem, $raw, $prefix) = $this->customerNumber($settings, $order);

        $this->deliver($settings, array(
            'dedupe_key' => 'o:' . (int) $order['order_id'] . ':s:' . $statusId,
            'kind' => TextLog::KIND_CUSTOMER,
            'order_id' => (int) $order['order_id'],
            'order_status_id' => $statusId,
            'store_id' => (int) $order['store_id'],
            'recipient' => $number !== '' ? $number : $raw,
            'message' => $message,
        ), $problem, $prefix);
    }

    /**
     * The customer's number: the order's telephone, completed with the calling code of the billing
     * address country (the telephone is the customer's own, while a delivery can go to someone abroad),
     * then the delivery address country, then the default country of the settings.
     *
     * @param array<string, mixed> $order
     *
     * @return array{0: string, 1: string, 2: string, 3: string} the E.164 number (or ''), the problem,
     *                                                           the number as typed, the calling code used
     */
    public function customerNumber(Settings $settings, array $order): array
    {
        $raw = trim(html_entity_decode((string) $order['telephone'], ENT_QUOTES, 'UTF-8'));
        $prefix = '';
        foreach (array('payment_country_id', 'shipping_country_id') as $field) {
            if ($prefix === '' && !empty($order[$field])) {
                $prefix = CallingCodes::forCountry($this->orders->countryIso((int) $order[$field]));
            }
        }
        if ($prefix === '') {
            $prefix = $settings->callingCode();
        }
        list($number, $problem) = PhoneNumber::analyse($raw, $prefix);

        return array($number, $problem, $raw, $prefix);
    }

    /**
     * Sends a test text now and logs it.
     *
     * @return array{status: string, messageId: string, error: string, exception: \Throwable|null}
     */
    public function test(Settings $settings, string $number, string $message)
    {
        $prefix = $settings->callingCode();
        $id = $this->log->claim(array(
            'dedupe_key' => null,
            'kind' => TextLog::KIND_TEST,
            'order_id' => 0,
            'order_status_id' => 0,
            'store_id' => 0,
            'recipient' => $number,
            'message' => $message,
        ));
        $result = $this->send($settings, $number, $message, $prefix, 10.0, 4.0);
        if ($id > 0) {
            $this->log->finish($id, $result['status'], $result['messageId'], $result['error']);
        }

        return $result;
    }

    /**
     * Sends a text that did not go out once more, as it was written ("Send again").
     *
     * @return array{status: string, messageId: string, error: string, exception: \Throwable|null}|null null
     *                                                                     when the text is not failed (any more)
     */
    public function resend(Settings $settings, int $logId)
    {
        $row = $this->log->find($logId);
        if ($row === null || !in_array($row['status'], array(TextLog::FAILED, TextLog::UNCONFIRMED), true) || trim((string) $row['message']) === '' || !$this->log->retry($logId)) {
            return null;
        }
        $result = $this->send($settings, (string) $row['recipient'], (string) $row['message'], '', 10.0, 4.0);
        $this->log->finish($logId, $result['status'], $result['messageId'], $result['error']);

        return $result;
    }

    /**
     * Asks SMSTunnel what the phone did with the recent texts it accepted (GET /api/v1/sms/status/:id).
     *
     * @return array<int, string> the new delivery state by log id
     */
    public function refreshDeliveries(Settings $settings, int $limit = 10): array
    {
        $updated = array();
        if ($settings->apiKey() === '') {
            return $updated;
        }
        $client = new Client($settings->apiKey(), $settings->apiUrl(), 5.0, 3.0, $this->userAgent);
        foreach ($this->log->awaitingDelivery($limit) as $row) {
            try {
                $status = $client->getMessageStatus((string) $row['message_id']);
            } catch (ApiException $e) {
                if ($e->getStatus() === 404) {
                    // Not a text of this key's account (the key changed, say): stop asking about it.
                    $this->log->setDelivery((int) $row['smstunnel_log_id'], 'unknown');
                    continue;
                }
                // 401/403 (a key without sms:read), 429, 5xx: try another time.
                break;
            } catch (TransportException $e) {
                break;
            }
            $delivery = $status['deliveryStatus'] !== '' ? $status['deliveryStatus'] : $status['status'];
            $error = in_array($delivery, array('failed', 'unconfirmed'), true) ? $status['errorMessage'] : '';
            $this->log->setDelivery((int) $row['smstunnel_log_id'], $delivery, $error);
            $updated[(int) $row['smstunnel_log_id']] = $delivery;
        }

        return $updated;
    }

    /**
     * Sends one text now.
     *
     * @return array{status: string, messageId: string, error: string, exception: \Throwable|null}
     */
    public function send(Settings $settings, string $number, string $message, string $prefix, $timeout = self::TIMEOUT, $connectTimeout = self::CONNECT_TIMEOUT): array
    {
        $url = $settings->apiUrl();
        if (isset(self::$blocked[$url])) {
            return self::result(TextLog::FAILED, '', 'Not tried: ' . self::$blocked[$url]);
        }
        try {
            $client = new Client($settings->apiKey(), $url, $timeout, $connectTimeout, $this->userAgent);
            $deviceId = $settings->deviceId();
            $sent = $client->sendSms($number, $message, $deviceId !== '' ? $deviceId : null, $prefix !== '' ? '+' . $prefix : null);

            return self::result($sent['queued'] ? TextLog::QUEUED : TextLog::SENT, $sent['messageId'], '');
        } catch (TransportException $e) {
            self::$blocked[$url] = $e->getMessage();

            // No answer after the request left: SMSTunnel may have the text, so it is not marked failed
            // (a failed text is tried again on the next event) but unconfirmed, for the store owner to check.
            return self::result($e->mayHaveArrived() ? TextLog::UNCONFIRMED : TextLog::FAILED, '', $e->getMessage(), $e);
        } catch (ApiException $e) {
            $code = $e->getErrorCode();
            $error = 'HTTP ' . $e->getStatus() . ($code ? ' ' . $code : '') . ': ' . $e->getMessage();
            if (in_array($e->getStatus(), array(401, 403, 429), true)) {
                // The key or the rate was refused: the next texts of this request would be too.
                self::$blocked[$url] = $error;
            }

            return self::result(TextLog::FAILED, '', $error, $e);
        }
    }

    /** Forgets what this request learned (for long-running scripts and tests). */
    public static function reset()
    {
        self::$blocked = array();
        self::$previous = array();
    }

    /**
     * Writes one line to OpenCart's error log: never a whole number, a text's words or the API key.
     */
    public function logError(string $line)
    {
        if ($this->logger === null) {
            return;
        }
        $logger = $this->logger;
        // Logging must never break the page that triggered it (a log file that is not writable, say).
        Guard::quietly(function () use ($logger, $line) {
            call_user_func($logger, 'SMSTunnel: ' . Redact::text($line));
        });
    }

    /**
     * Reserves the text, then sends it unless it went out already.
     *
     * @param array{dedupe_key: string|null, kind: string, order_id: int, order_status_id: int, store_id: int, recipient: string, message: string} $text
     */
    private function deliver(Settings $settings, array $text, string $problem, string $prefix)
    {
        $id = $this->log->claim($text);
        if ($id === 0) {
            // The same text went out already, or is going out right now.
            return;
        }
        if ($problem !== PhoneNumber::OK) {
            $this->log->finish($id, TextLog::SKIPPED, '', 'number:' . $problem);

            return;
        }
        if (trim($text['message']) === '') {
            $this->log->finish($id, TextLog::SKIPPED, '', 'empty');

            return;
        }
        $result = $this->send($settings, $text['recipient'], $text['message'], $prefix);
        $this->log->finish($id, $result['status'], $result['messageId'], $result['error']);
        if ($result['status'] === TextLog::FAILED || $result['status'] === TextLog::UNCONFIRMED) {
            $this->logError(sprintf(
                $result['status'] === TextLog::UNCONFIRMED ? 'the %s text about order %d to %s may not have gone out (no answer, not sent again on its own): %s' : 'the %s text about order %d to %s did not go out: %s',
                $text['kind'],
                $text['order_id'],
                Redact::number($text['recipient']),
                $result['error']
            ));
        }
    }

    /**
     * The placeholder values of an order.
     *
     * @param array<string, mixed> $order
     *
     * @return array<string, string>
     */
    private function values(array $order, int $statusId, int $languageId, string $comment): array
    {
        $storeName = trim((string) $order['store_name']);
        if ($storeName === '') {
            $storeName = (string) $this->orders->setting('config_name');
        }

        return array(
            'firstname' => (string) $order['firstname'],
            'lastname' => (string) $order['lastname'],
            'order_id' => (string) (int) $order['order_id'],
            'total' => $this->total((float) $order['total'], (string) $order['currency_code'], (float) $order['currency_value']),
            'store_name' => $storeName,
            'status' => $this->orders->statusName($statusId, $languageId),
            'comment' => $comment,
        );
    }

    private function total(float $amount, string $currency, float $value): string
    {
        if ($this->formatTotal !== null && $currency !== '') {
            try {
                $formatted = (string) call_user_func($this->formatTotal, $amount, $currency, $value);
                if (trim(strip_tags($formatted)) !== '') {
                    return Template::plainSpaces(html_entity_decode(strip_tags($formatted), ENT_QUOTES, 'UTF-8'));
                }
            } catch (\Throwable $e) {
                // The plain format below.
            }
        }

        return trim(number_format($amount * ($value > 0 ? $value : 1.0), 2, '.', '') . ' ' . $currency);
    }

    /**
     * @param \Throwable|null $exception
     *
     * @return array{status: string, messageId: string, error: string, exception: \Throwable|null}
     */
    private static function result(string $status, string $messageId, string $error, $exception = null): array
    {
        return array('status' => $status, 'messageId' => $messageId, 'error' => $error, 'exception' => $exception);
    }
}
