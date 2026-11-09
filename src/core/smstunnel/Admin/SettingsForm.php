<?php
/**
 * SMSTunnel for OpenCart.
 *
 * @author    Narcis Bodea <contact@narboweb.ro>
 * @copyright 2026 NARBOWEB SRL
 * @license   GPL-3.0-or-later https://www.gnu.org/licenses/gpl-3.0.html
 */

namespace SmsTunnel\OpenCart\Admin;

use SmsTunnel\OpenCart\Api\Client;
use SmsTunnel\OpenCart\Message\DefaultTexts;
use SmsTunnel\OpenCart\Message\Template;
use SmsTunnel\OpenCart\Phone\CallingCodes;
use SmsTunnel\OpenCart\Phone\PhoneNumber;
use SmsTunnel\OpenCart\Settings;

/**
 * Checks the settings form and turns it into the values to store. The same rules in OpenCart 3.0 and 4.x.
 */
final class SettingsForm
{
    const KEY_PATTERN = '/^sk_(live|test)_[A-Za-z0-9]{16,128}$/';
    const DEVICE_PATTERN = '/^[A-Za-z0-9_\-]{1,64}$/';

    /** @var callable fn(string $key): string */
    private $t;

    /**
     * @param callable $translate the OpenCart language: fn(string $key): string
     */
    public function __construct($translate)
    {
        $this->t = $translate;
    }

    /**
     * @param array<string, mixed> $post      the form as posted (OpenCart HTML-escapes what it receives)
     * @param Settings             $current   the saved settings (an empty API key field keeps the saved key)
     * @param int[]                $statusIds the store's order statuses
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>} the values to store, and the
     *                                                                   errors by field (empty when valid)
     */
    public function validate(array $post, Settings $current, array $statusIds): array
    {
        $errors = array();
        $text = function ($name) use ($post) {
            if (!isset($post[$name]) || !is_scalar($post[$name])) {
                return '';
            }
            $value = html_entity_decode((string) $post[$name], ENT_QUOTES, 'UTF-8');

            return trim(str_replace(array("\r\n", "\r"), "\n", $value));
        };

        $apiKey = (string) preg_replace('/\s+/', '', $text('api_key'));
        if ($apiKey !== '' && !preg_match(self::KEY_PATTERN, $apiKey)) {
            $errors['api_key'] = $this->t('error_api_key');
        }
        if ($apiKey === '') {
            $apiKey = $current->apiKey();
        }

        $deviceId = $text('device_id');
        if ($deviceId !== '' && !preg_match(self::DEVICE_PATTERN, $deviceId)) {
            $errors['device_id'] = $this->t('error_device_id');
        }

        $apiUrl = rtrim($text('api_url'), '/');
        if ($apiUrl === '') {
            $apiUrl = Client::DEFAULT_URL;
        }
        if (!Client::isValidBaseUrl($apiUrl)) {
            $errors['api_url'] = $this->t('error_api_url');
        }

        $country = strtoupper($text('country'));
        if ($country !== '' && CallingCodes::forCountry($country) === '') {
            $errors['country'] = $this->t('error_country');
        }
        $prefix = CallingCodes::forCountry($country);

        $ownerEnabled = !empty($post['owner_status']);
        $ownerNumbers = array();
        $bad = array();
        foreach (Settings::splitNumbers($text('owner_numbers')) as $raw) {
            list($number, $problem) = PhoneNumber::analyse($raw, $prefix);
            if ($problem !== PhoneNumber::OK) {
                $bad[] = $raw;
            } elseif (!in_array($number, $ownerNumbers, true)) {
                $ownerNumbers[] = $number;
            }
        }
        if ($bad) {
            $errors['owner_numbers'] = sprintf($this->t('error_owner_number'), implode(', ', $bad));
        } elseif (count($ownerNumbers) > Settings::MAX_OWNER_NUMBERS) {
            $errors['owner_numbers'] = sprintf($this->t('error_owner_count'), Settings::MAX_OWNER_NUMBERS);
        } elseif ($ownerEnabled && !$ownerNumbers) {
            $errors['owner_numbers'] = $this->t('error_owner_none');
        }

        $ownerText = $text('owner_text');
        if (Template::length($ownerText) > Template::MAX_LENGTH) {
            $errors['owner_text'] = sprintf($this->t('error_text_length'), Template::MAX_LENGTH);
        }
        // A text left empty, or as suggested, is not stored: the suggestion (in the language of each
        // text) is used, and it follows the extension's updates.
        if (DefaultTexts::isSuggestion($ownerText, 'owner')) {
            $ownerText = '';
        }

        $statuses = array();
        $posted = isset($post['customer_statuses']) && is_array($post['customer_statuses']) ? $post['customer_statuses'] : array();
        foreach ($posted as $id) {
            $id = (int) $id;
            if (in_array($id, $statusIds, true) && !in_array($id, $statuses, true)) {
                $statuses[] = $id;
            }
        }
        sort($statuses);

        $texts = array();
        $postedTexts = isset($post['customer_texts']) && is_array($post['customer_texts']) ? $post['customer_texts'] : array();
        foreach ($statusIds as $id) {
            $value = isset($postedTexts[$id]) && is_scalar($postedTexts[$id])
                ? trim(str_replace(array("\r\n", "\r"), "\n", html_entity_decode((string) $postedTexts[$id], ENT_QUOTES, 'UTF-8')))
                : '';
            if (Template::length($value) > Template::MAX_LENGTH) {
                $errors['customer_text_' . $id] = sprintf($this->t('error_text_length'), Template::MAX_LENGTH);
            }
            $suggestion = false;
            foreach (array(DefaultTexts::SHIPPED, DefaultTexts::COMPLETE, DefaultTexts::CANCELED, DefaultTexts::REFUNDED, DefaultTexts::PROCESSING, DefaultTexts::PENDING, DefaultTexts::OTHER) as $kind) {
                if (DefaultTexts::isSuggestion($value, $kind)) {
                    $suggestion = true;
                    break;
                }
            }
            if ($value !== '' && !$suggestion) {
                $texts[$id] = $value;
            }
        }

        $values = array(
            Settings::STATUS => empty($post['status']) ? '0' : '1',
            Settings::API_KEY => $apiKey,
            Settings::API_URL => $apiUrl,
            Settings::DEVICE_ID => $deviceId,
            Settings::COUNTRY => $country,
            Settings::OWNER_STATUS => $ownerEnabled ? '1' : '0',
            Settings::OWNER_NUMBERS => implode(',', $ownerNumbers),
            Settings::OWNER_TEXT => $ownerText,
            Settings::CUSTOMER_STATUS => empty($post['customer_status']) ? '0' : '1',
            Settings::CUSTOMER_STATUSES => $statuses,
            Settings::CUSTOMER_TEXTS => $texts,
        );

        return array($values, $errors);
    }

    private function t(string $key): string
    {
        return (string) call_user_func($this->t, $key);
    }
}
