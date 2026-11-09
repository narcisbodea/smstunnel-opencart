<?php
/**
 * SMSTunnel for OpenCart.
 *
 * @author    Narcis Bodea <contact@narboweb.ro>
 * @copyright 2026 NARBOWEB SRL
 * @license   GPL-3.0-or-later https://www.gnu.org/licenses/gpl-3.0.html
 */

namespace SmsTunnel\OpenCart\Admin;

use SmsTunnel\OpenCart\Api\ApiException;
use SmsTunnel\OpenCart\Api\Client;
use SmsTunnel\OpenCart\Api\TransportException;
use SmsTunnel\OpenCart\Log\Redact;
use SmsTunnel\OpenCart\Log\TextLog;
use SmsTunnel\OpenCart\Message\DefaultTexts;
use SmsTunnel\OpenCart\Message\Template;
use SmsTunnel\OpenCart\Notifier;
use SmsTunnel\OpenCart\Phone\CallingCodes;
use SmsTunnel\OpenCart\Phone\PhoneNumber;
use SmsTunnel\OpenCart\Settings;
use SmsTunnel\OpenCart\Version;

/**
 * Everything the settings page shows and does, the same in OpenCart 3.0 and 4.x: the OpenCart
 * controllers add their links, their language and their template.
 */
final class Page
{
    /** @var Notifier */
    private $notifier;

    /** @var callable fn(string $key): string */
    private $t;

    /** @var string */
    private $userAgent;

    /**
     * @param object   $db        OpenCart's database object
     * @param string   $prefix    DB_PREFIX
     * @param callable $translate the OpenCart language: fn(string $key): string
     * @param callable|null $logger fn(string $line): void, OpenCart's error log
     */
    public function __construct($db, string $prefix, string $userAgent, $translate, $logger = null)
    {
        $this->notifier = new Notifier($db, $prefix, $userAgent, null, $logger);
        $this->t = $translate;
        $this->userAgent = $userAgent;
    }

    public function notifier(): Notifier
    {
        return $this->notifier;
    }

    public function settings(): Settings
    {
        return $this->notifier->settings();
    }

    /**
     * The values the extension stores when it is installed: on, texts to the owner and the customer on,
     * the customer texted for the store's shipped, complete, canceled and refunded statuses.
     *
     * @return array<string, mixed>
     */
    public function installDefaults(int $adminLanguageId): array
    {
        $orders = $this->notifier->orders();
        $statuses = array();
        foreach (array_keys($orders->orderStatuses($adminLanguageId)) as $id) {
            if (in_array(DefaultTexts::kindOf($id, $orders->statusNames($id)), DefaultTexts::DEFAULT_KINDS, true)) {
                $statuses[] = $id;
            }
        }
        sort($statuses);

        return array(
            Settings::STATUS => '1',
            Settings::API_KEY => '',
            Settings::API_URL => Client::DEFAULT_URL,
            Settings::DEVICE_ID => '',
            Settings::COUNTRY => $orders->storeCountryIso(),
            Settings::OWNER_STATUS => '1',
            Settings::OWNER_NUMBERS => '',
            Settings::OWNER_TEXT => '',
            Settings::CUSTOMER_STATUS => '1',
            Settings::CUSTOMER_STATUSES => $statuses,
            Settings::CUSTOMER_TEXTS => array(),
        );
    }

    /**
     * Validates the posted form.
     *
     * @param array<string, mixed> $post
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    public function validate(array $post, int $adminLanguageId): array
    {
        $form = new SettingsForm($this->t);

        return $form->validate($post, $this->settings(), array_keys($this->notifier->orders()->orderStatuses($adminLanguageId)));
    }

    /**
     * What the template shows.
     *
     * @param array<string, mixed>|null $posted the form as posted, when it is shown again with errors
     * @param callable                  $orderUrl fn(int $orderId): string, the order's page in the admin
     *
     * @return array<string, mixed>
     */
    public function view(int $adminLanguageId, string $adminLanguageCode, $posted, $orderUrl): array
    {
        $settings = $this->settings();
        $orders = $this->notifier->orders();
        $language = DefaultTexts::language($adminLanguageCode);
        $field = function ($name, $saved) use ($posted) {
            if (is_array($posted)) {
                return isset($posted[$name]) && is_scalar($posted[$name]) ? html_entity_decode((string) $posted[$name], ENT_QUOTES, 'UTF-8') : '';
            }

            return $saved;
        };

        // The phones of the account.
        $phones = array();
        $phonesError = '';
        if ($settings->apiKey() !== '') {
            try {
                $client = new Client($settings->apiKey(), $settings->apiUrl(), 6.0, 3.0, $this->userAgent);
                $phones = $client->getDevices();
            } catch (\Throwable $e) {
                $phonesError = $this->describeError($e, $e->getMessage());
            }
        }
        $deviceId = $field('device_id', $settings->deviceId());
        $deviceKnown = $deviceId === '';
        foreach ($phones as $index => $phone) {
            $phones[$index]['selected'] = $phone['id'] === $deviceId;
            $phones[$index]['last_seen'] = $this->when($phone['lastSeenAt']);
            $deviceKnown = $deviceKnown || $phone['id'] === $deviceId;
        }

        // Countries, with their calling code.
        $countries = array();
        $country = strtoupper($field('country', $settings->country()));
        if ($country === '') {
            $country = $orders->storeCountryIso();
        }
        foreach ($orders->countries($adminLanguageId) as $iso => $name) {
            $code = CallingCodes::forCountry($iso);
            if ($code !== '') {
                $countries[] = array('iso' => $iso, 'name' => $name . ' (+' . $code . ')', 'selected' => $iso === $country);
            }
        }

        // Order statuses, each with its text.
        $chosen = is_array($posted)
            ? array_map('intval', isset($posted['customer_statuses']) && is_array($posted['customer_statuses']) ? $posted['customer_statuses'] : array())
            : $settings->customerStatuses();
        $postedTexts = is_array($posted) && isset($posted['customer_texts']) && is_array($posted['customer_texts']) ? $posted['customer_texts'] : array();
        $statuses = array();
        foreach ($orders->orderStatuses($adminLanguageId) as $id => $name) {
            $suggestion = DefaultTexts::customer(DefaultTexts::kindOf($id, $orders->statusNames($id)), $language);
            if (is_array($posted)) {
                $text = isset($postedTexts[$id]) && is_scalar($postedTexts[$id]) ? html_entity_decode((string) $postedTexts[$id], ENT_QUOTES, 'UTF-8') : '';
            } else {
                $text = $settings->customerText($id);
                if ($text === '') {
                    $text = $suggestion;
                }
            }
            $statuses[] = array(
                'id' => $id,
                'name' => $name,
                'checked' => in_array($id, $chosen, true),
                'text' => $text,
                'suggestion' => $suggestion,
            );
        }

        $ownerText = $field('owner_text', $settings->ownerText());
        if (!is_array($posted) && $ownerText === '') {
            $ownerText = DefaultTexts::owner($language);
        }
        $failure = $this->notifier->log()->lastFailure();
        $ownerNumbers = $settings->ownerNumbers();
        $apiKey = $settings->apiKey();

        return array(
            'has_key' => $apiKey !== '',
            // Only the start and the end of the key ever reach the page.
            'key_hint' => $apiKey !== '' ? substr($apiKey, 0, 8) . '…' . substr($apiKey, -4) : '',
            'status' => is_array($posted) ? !empty($posted['status']) : $settings->enabled(),
            'phones' => $phones,
            'phones_error' => $phonesError,
            'device_id' => $deviceId,
            'device_unknown' => !$deviceKnown && $phonesError === '' && $settings->apiKey() !== '',
            'api_url' => $field('api_url', $settings->apiUrl()),
            'test_server' => $settings->apiUrl() !== Client::DEFAULT_URL ? $settings->apiUrl() : '',
            'countries' => $countries,
            'owner_status' => is_array($posted) ? !empty($posted['owner_status']) : $settings->ownerEnabled(),
            'owner_numbers' => $field('owner_numbers', implode(', ', $ownerNumbers)),
            'owner_text' => $ownerText,
            'customer_status' => is_array($posted) ? !empty($posted['customer_status']) : $settings->customerEnabled(),
            'statuses' => $statuses,
            'placeholders' => Template::placeholderList(),
            'max_length' => Template::MAX_LENGTH,
            'max_owner_numbers' => Settings::MAX_OWNER_NUMBERS,
            'test_number' => $ownerNumbers ? $ownerNumbers[0] : '',
            'last_failure' => $failure ? array(
                'date' => (string) $failure['date_modified'],
                'error' => $this->detail((string) $failure['error']),
            ) : null,
            'recent' => $this->recent($orderUrl, $adminLanguageId),
            'google_play_url' => Version::GOOGLE_PLAY_URL,
            'dashboard_url' => Version::DASHBOARD_URL,
            'version' => Version::NUMBER,
        );
    }

    /**
     * The last texts, newest first, ready for the template: numbers show their last 2 digits only.
     *
     * @param callable $orderUrl   fn(int $orderId): string
     * @param int      $languageId the language of the status names
     *
     * @return array<int, array<string, mixed>>
     */
    public function recent($orderUrl, int $languageId = 0, int $limit = 30): array
    {
        $orders = $this->notifier->orders();
        $rows = array();
        foreach ($this->notifier->log()->recent($limit) as $row) {
            $kind = (string) $row['kind'];
            if ($kind === TextLog::KIND_OWNER) {
                $what = $this->t('text_kind_owner');
            } elseif ($kind === TextLog::KIND_TEST) {
                $what = $this->t('text_kind_test');
            } else {
                $name = $orders->statusName((int) $row['order_status_id'], $languageId);
                $what = sprintf($this->t('text_kind_customer'), $name !== '' ? $name : '#' . (int) $row['order_status_id']);
            }
            $status = (string) $row['status'];
            $rows[] = array(
                'id' => (int) $row['smstunnel_log_id'],
                'date' => (string) $row['date_added'],
                'what' => $what,
                'order_id' => (int) $row['order_id'],
                'order_url' => (int) $row['order_id'] > 0 ? (string) call_user_func($orderUrl, (int) $row['order_id']) : '',
                'number' => Redact::number((string) $row['recipient']),
                'status' => $status,
                'status_label' => $this->statusLabel($status),
                'status_class' => $this->statusClass($status, (string) $row['delivery']),
                'delivery' => $this->deliveryLabel((string) $row['delivery']),
                'detail' => $this->detail((string) $row['error']),
                'attempts' => (int) $row['attempts'],
                'can_resend' => in_array($status, array(TextLog::FAILED, TextLog::UNCONFIRMED), true) && trim((string) $row['message']) !== '',
                // What the page asks SMSTunnel about once it is shown.
                'awaiting' => TextLog::isAwaiting($row, time()),
            );
        }

        return $rows;
    }

    /**
     * Sends a test text to the number typed on the page.
     *
     * @return array{type: string, text: string}
     */
    public function sendTest(string $raw, string $storeName, string $languageCode): array
    {
        $settings = $this->settings();
        if ($settings->apiKey() === '') {
            return array('type' => 'danger', 'text' => $this->t('error_no_key'));
        }
        $raw = trim(html_entity_decode($raw, ENT_QUOTES, 'UTF-8'));
        list($number, $problem) = PhoneNumber::analyse($raw, $settings->callingCode());
        if ($problem !== PhoneNumber::OK) {
            return array('type' => 'danger', 'text' => sprintf($this->t('error_test_number'), $raw));
        }
        $message = Template::render(DefaultTexts::test(DefaultTexts::language($languageCode)), array('store_name' => $storeName));
        $result = $this->notifier->test($settings, $number, $message);

        if ($result['status'] === TextLog::QUEUED) {
            return array('type' => 'warning', 'text' => sprintf($this->t('text_test_queued'), $number));
        }
        if ($result['status'] === TextLog::SENT) {
            return array('type' => 'success', 'text' => sprintf($this->t('text_test_sent'), $number));
        }

        return array('type' => $result['status'] === TextLog::UNCONFIRMED ? 'warning' : 'danger', 'text' => $this->describeError($result['exception'], $result['error']));
    }

    /**
     * Sends a text that did not go out once more.
     *
     * @return array{type: string, text: string}
     */
    public function resend(int $logId): array
    {
        $settings = $this->settings();
        if ($settings->apiKey() === '') {
            return array('type' => 'danger', 'text' => $this->t('error_no_key'));
        }
        $row = $this->notifier->log()->find($logId);
        $result = $this->notifier->resend($settings, $logId);
        if ($result === null || $row === null) {
            return array('type' => 'danger', 'text' => $this->t('error_resend'));
        }
        $number = Redact::number((string) $row['recipient']);
        if ($result['status'] === TextLog::QUEUED) {
            return array('type' => 'warning', 'text' => sprintf($this->t('text_resend_queued'), $number));
        }
        if ($result['status'] === TextLog::SENT) {
            return array('type' => 'success', 'text' => sprintf($this->t('text_resend_sent'), $number));
        }

        return array('type' => $result['status'] === TextLog::UNCONFIRMED ? 'warning' : 'danger', 'text' => $this->describeError($result['exception'], $result['error']));
    }

    /**
     * Asks SMSTunnel where the recent texts are, and returns the rows that changed.
     *
     * @param callable $orderUrl fn(int $orderId): string
     *
     * @return array<int, array<string, mixed>>
     */
    public function refresh($orderUrl, int $languageId = 0): array
    {
        $updated = $this->notifier->refreshDeliveries($this->settings());
        if (!$updated) {
            return array();
        }
        $rows = array();
        foreach ($this->recent($orderUrl, $languageId) as $row) {
            if (isset($updated[$row['id']])) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * A sentence for the store owner about why a call to SMSTunnel failed.
     *
     * @param \Throwable|null $error
     */
    public function describeError($error, string $fallback): string
    {
        if ($error instanceof ApiException) {
            $status = $error->getStatus();
            if ($status === 401) {
                return $this->t('error_api_401');
            }
            if ($status === 403 && $error->getErrorCode() === 'PERMISSION_REQUIRED') {
                return $this->t('error_api_403');
            }
            if ($status === 429) {
                return sprintf($this->t('error_api_429'), Redact::text($error->getMessage()));
            }
            if ($status >= 500) {
                return sprintf($this->t('error_api_5xx'), $status);
            }

            return sprintf($this->t('error_api_refused'), Redact::text($error->getMessage()));
        }
        if ($error instanceof TransportException) {
            return sprintf($this->t($error->mayHaveArrived() ? 'error_api_no_answer' : 'error_api_unreachable'), Redact::text($error->getMessage()));
        }

        return $this->detail($fallback);
    }

    /** The words for an error stored in the log. */
    public function detail(string $error): string
    {
        $known = array(
            'number:' . PhoneNumber::MISSING => 'text_reason_missing',
            'number:' . PhoneNumber::INVALID => 'text_reason_invalid',
            'number:' . PhoneNumber::PREMIUM => 'text_reason_premium',
            'empty' => 'text_reason_empty',
        );
        if (isset($known[$error])) {
            return $this->t($known[$error]);
        }
        if (strpos($error, 'Not tried: ') === 0) {
            return sprintf($this->t('text_not_tried'), $this->detail(substr($error, strlen('Not tried: '))));
        }
        if (preg_match('/^HTTP 401\b/', $error)) {
            return $this->t('error_api_401');
        }
        if (preg_match('/^HTTP 403 PERMISSION_REQUIRED\b/', $error)) {
            return $this->t('error_api_403');
        }
        if (preg_match('/^HTTP 429\b(?:[^:]*):\s*(.*)$/s', $error, $match)) {
            return sprintf($this->t('error_api_429'), $match[1]);
        }
        if (preg_match('/^HTTP (5\d\d)\b/', $error, $match)) {
            return sprintf($this->t('error_api_5xx'), (int) $match[1]);
        }
        if (strpos($error, 'Could not reach ') === 0) {
            return sprintf($this->t('error_api_unreachable'), $error);
        }
        if (strpos($error, 'No answer from ') === 0) {
            return sprintf($this->t('error_api_no_answer'), $error);
        }

        return $error;
    }

    private function statusLabel(string $status): string
    {
        $keys = array(
            TextLog::SENT => 'text_status_sent',
            TextLog::QUEUED => 'text_status_queued',
            TextLog::FAILED => 'text_status_failed',
            TextLog::SKIPPED => 'text_status_skipped',
            TextLog::SENDING => 'text_status_sending',
            TextLog::UNCONFIRMED => 'text_status_unconfirmed',
        );

        return isset($keys[$status]) ? $this->t($keys[$status]) : $status;
    }

    /** success, warning, danger or default: the colour of the status label. */
    private function statusClass(string $status, string $delivery): string
    {
        if ($status === TextLog::FAILED || in_array($delivery, array('failed', 'unconfirmed'), true)) {
            return 'danger';
        }
        if ($status === TextLog::QUEUED || $status === TextLog::SENDING || $status === TextLog::UNCONFIRMED) {
            return 'warning';
        }

        return $status === TextLog::SENT ? 'success' : 'default';
    }

    private function deliveryLabel(string $delivery): string
    {
        $keys = array(
            'pending' => 'text_delivery_pending',
            'sent' => 'text_delivery_sent',
            'delivered' => 'text_delivery_delivered',
            'failed' => 'text_delivery_failed',
            'unconfirmed' => 'text_delivery_unconfirmed',
        );

        return isset($keys[$delivery]) ? $this->t($keys[$delivery]) : '';
    }

    /** "2026-11-09 10:42" from an ISO 8601 time, in the server's time zone; '' when unknown. */
    private function when(string $iso): string
    {
        $time = $iso !== '' ? strtotime($iso) : false;

        return $time ? date('Y-m-d H:i', $time) : '';
    }

    private function t(string $key): string
    {
        return (string) call_user_func($this->t, $key);
    }
}
