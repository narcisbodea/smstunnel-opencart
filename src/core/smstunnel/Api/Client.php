<?php
/**
 * SMSTunnel for OpenCart.
 *
 * @author    Narcis Bodea <contact@narboweb.ro>
 * @copyright 2026 NARBOWEB SRL
 * @license   GPL-3.0-or-later https://www.gnu.org/licenses/gpl-3.0.html
 */

namespace SmsTunnel\OpenCart\Api;

/**
 * Calls the SMSTunnel public API the way the SMSTunnel PHP SDK does (same endpoints, headers, JSON and
 * error reading), with PHP's cURL extension only. One text to one number per call.
 *
 * Timeouts are short on purpose: texts are sent while a customer checks out or while the store owner
 * changes an order's status, and neither may wait long for SMSTunnel.
 */
class Client
{
    const DEFAULT_URL = 'https://smstunnel.io';

    /** @var string */
    private $apiKey;

    /** @var string */
    private $baseUrl;

    /** @var float */
    private $timeout;

    /** @var float */
    private $connectTimeout;

    /** @var string */
    private $userAgent;

    /**
     * @param string $apiKey         a key from Apps → Add an app in the SMSTunnel dashboard
     * @param string $baseUrl        https://smstunnel.io; another address only for a test server
     * @param float  $timeout        seconds for the whole request
     * @param float  $connectTimeout seconds to open the connection
     */
    public function __construct(string $apiKey, string $baseUrl = self::DEFAULT_URL, $timeout = 8.0, $connectTimeout = 3.0, string $userAgent = 'SMSTunnel-OpenCart')
    {
        // A key never holds spaces or line breaks; dropping them keeps the header intact whatever was saved.
        $this->apiKey = (string) preg_replace('/\s+/', '', $apiKey);
        $baseUrl = rtrim(trim($baseUrl), '/');
        $this->baseUrl = $baseUrl !== '' ? $baseUrl : self::DEFAULT_URL;
        $this->timeout = max(0.5, (float) $timeout);
        $this->connectTimeout = max(0.5, min((float) $connectTimeout, $this->timeout));
        $this->userAgent = $userAgent;
    }

    /** True for an address the client can call: http(s)://host[:port][/path]. */
    public static function isValidBaseUrl(string $url): bool
    {
        return preg_match('~^https?://[^/\s?#]+(/[^\s?#]*)?$~i', rtrim(trim($url), '/')) === 1;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Sends one text to one number.
     *
     * @param string      $to                 the number in international format, e.g. +14155550123
     * @param string      $message            up to 1600 characters
     * @param string|null $deviceId           one of the account's phones, or null to let SMSTunnel pick one
     * @param string|null $defaultCountryCode e.g. "+40": completes a number written without + or 00
     *
     * @return array{messageId: string, queued: bool, branded: bool}
     *               queued is true when the phone was offline: the text waits on the account and the
     *               phone sends it as soon as it is back online
     *
     * @throws ApiException       when SMSTunnel refuses the text
     * @throws TransportException when SMSTunnel cannot be reached
     */
    public function sendSms(string $to, string $message, $deviceId = null, $defaultCountryCode = null): array
    {
        $body = array('to' => trim($to), 'message' => $message);
        if (is_string($deviceId) && trim($deviceId) !== '') {
            $body['deviceId'] = trim($deviceId);
        }
        if (is_string($defaultCountryCode) && trim($defaultCountryCode) !== '') {
            $body['defaultCountryCode'] = trim($defaultCountryCode);
        }

        $answer = $this->request('POST', '/api/v1/sms/send', $body);
        $data = isset($answer['data']) && is_array($answer['data']) ? $answer['data'] : array();

        $messageId = '';
        if (isset($answer['messageId']) && (is_string($answer['messageId']) || is_int($answer['messageId']))) {
            $messageId = (string) $answer['messageId'];
        } elseif (isset($data['messageId']) && (is_string($data['messageId']) || is_int($data['messageId']))) {
            $messageId = (string) $data['messageId'];
        }
        $queued = isset($answer['queued']) && is_bool($answer['queued'])
            ? $answer['queued']
            : (isset($data['queued']) && $data['queued'] === true);

        return array(
            'messageId' => $messageId,
            'queued' => $queued,
            'branded' => isset($data['branded']) && $data['branded'] === true,
        );
    }

    /**
     * The phones linked to the key's account, and which are online.
     *
     * @return array<int, array{id: string, name: string, isOnline: bool, lastSeenAt: string}>
     *
     * @throws ApiException
     * @throws TransportException
     */
    public function getDevices(): array
    {
        $answer = $this->request('GET', '/api/v1/devices');
        $data = isset($answer['data']) && is_array($answer['data']) ? $answer['data'] : array();
        $devices = isset($data['devices']) && is_array($data['devices']) ? $data['devices'] : array();

        $phones = array();
        foreach ($devices as $device) {
            if (!is_array($device) || !isset($device['id']) || !(is_string($device['id']) || is_int($device['id']))) {
                continue;
            }
            $phones[] = array(
                'id' => (string) $device['id'],
                'name' => isset($device['name']) && is_string($device['name']) ? $device['name'] : '',
                'isOnline' => isset($device['isOnline']) && $device['isOnline'] === true,
                'lastSeenAt' => isset($device['lastSeenAt']) && is_string($device['lastSeenAt']) ? $device['lastSeenAt'] : '',
            );
        }

        return $phones;
    }

    /**
     * Where a text is now. Needs the key's sms:read permission (every new key has it).
     *
     * @return array{id: string, phoneNumber: string, status: string, deliveryStatus: string, unconfirmed: bool, sentAt: string, deliveredAt: string, errorMessage: string}
     *               status is pending, sent, delivered or failed; deliveryStatus can also be unconfirmed
     *
     * @throws ApiException 404 when the key's account has no such text
     * @throws TransportException
     */
    public function getMessageStatus(string $messageId): array
    {
        if (trim($messageId) === '') {
            throw new \InvalidArgumentException('The message id is empty.');
        }
        $answer = $this->request('GET', '/api/v1/sms/status/' . rawurlencode(trim($messageId)));
        $data = isset($answer['data']) && is_array($answer['data']) ? $answer['data'] : array();

        $string = function ($key, $default = '') use ($data) {
            return isset($data[$key]) && (is_string($data[$key]) || is_int($data[$key])) ? (string) $data[$key] : $default;
        };
        $status = $string('status', 'pending');

        return array(
            'id' => $string('id'),
            'phoneNumber' => $string('phoneNumber'),
            'status' => $status,
            'deliveryStatus' => $string('deliveryStatus', $status),
            'unconfirmed' => isset($data['unconfirmed']) && $data['unconfirmed'] === true,
            'sentAt' => $string('sentAt'),
            'deliveredAt' => $string('deliveredAt'),
            'errorMessage' => $string('errorMessage'),
        );
    }

    /**
     * @param array<string, string>|null $body
     *
     * @return array<string, mixed> the decoded answer
     *
     * @throws ApiException
     * @throws TransportException
     */
    private function request(string $method, string $path, $body = null): array
    {
        if ($this->apiKey === '') {
            throw new ApiException(401, null, array(), 'No API key is saved.');
        }
        if (!self::isValidBaseUrl($this->baseUrl)) {
            throw new TransportException(sprintf('The SMSTunnel address must look like https://smstunnel.io (got "%s").', $this->baseUrl));
        }
        if (!function_exists('curl_init')) {
            throw new TransportException('The PHP cURL extension is missing.');
        }

        $headers = array(
            'Expect:',
            'X-API-Key: ' . $this->apiKey,
            'Accept: application/json',
            'User-Agent: ' . $this->userAgent,
        );
        $payload = null;
        if ($body !== null) {
            $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
            if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
                $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
            }
            $payload = json_encode($body, $flags);
            if (!is_string($payload)) {
                throw new ApiException(400, null, array(), 'The text could not be encoded.');
            }
            $headers[] = 'Content-Type: application/json';
        }

        $handle = curl_init();
        if ($handle === false) {
            throw new TransportException('cURL could not start.');
        }
        $responseHeaders = array();
        $options = array(
            CURLOPT_URL => $this->baseUrl . $path,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            // Redirects are not followed, so the API key never goes to another address.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT_MS => (int) round($this->timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) round($this->connectTimeout * 1000),
            CURLOPT_NOSIGNAL => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => function ($curl, $line) use (&$responseHeaders) {
                $trimmed = trim($line);
                if (strpos($trimmed, 'HTTP/') === 0) {
                    // A new status line (after "100 Continue", for instance): start over.
                    $responseHeaders = array();
                } elseif (strpos($trimmed, ':') !== false) {
                    list($name, $value) = explode(':', $trimmed, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);
                }

                return strlen($line);
            },
        );
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }
        if ($payload !== null) {
            $options[CURLOPT_POSTFIELDS] = $payload;
        }
        curl_setopt_array($handle, $options);

        $raw = curl_exec($handle);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        // Whether the request left: a body uploaded, or a connection made before the transfer began.
        $requestSent = (float) curl_getinfo($handle, CURLINFO_SIZE_UPLOAD) > 0 || (float) curl_getinfo($handle, CURLINFO_PRETRANSFER_TIME) > 0;
        if (PHP_VERSION_ID < 80000) {
            // A no-op since PHP 8.0, and deprecated in PHP 8.5.
            curl_close($handle);
        }

        if (!is_string($raw) || $errno !== 0) {
            // 28 timeout, 52 empty answer, 55 send error, 56 receive error: after the request left, SMSTunnel
            // may have taken the text even though no answer came back.
            $mayHaveArrived = $method === 'POST' && $requestSent && in_array($errno, array(28, 52, 55, 56), true);
            throw new TransportException(sprintf(
                $mayHaveArrived ? 'No answer from SMSTunnel at %s: %s' : 'Could not reach SMSTunnel at %s: %s',
                self::origin($this->baseUrl),
                $error !== '' ? $error : 'cURL error ' . $errno
            ), $errno, $mayHaveArrived);
        }

        $decoded = self::decode($raw);
        if ($status >= 300 && $status < 400) {
            $location = isset($responseHeaders['location']) ? $responseHeaders['location'] : '';
            throw new ApiException($status, $decoded, $responseHeaders, $location !== ''
                ? sprintf('SMSTunnel answered with a redirect to %s. Set the server address to that address.', $location)
                : sprintf('SMSTunnel answered with a redirect (%d).', $status));
        }
        if ($status < 200 || $status >= 300) {
            throw new ApiException($status, $decoded, $responseHeaders);
        }
        if (!is_array($decoded)) {
            throw new ApiException($status, $decoded, $responseHeaders, 'SMSTunnel answered with something that is not JSON.');
        }
        if (array_key_exists('success', $decoded) && $decoded['success'] === false) {
            throw new ApiException($status, $decoded, $responseHeaders);
        }

        return $decoded;
    }

    /**
     * The JSON body as an array, the raw text when it is not JSON, or null when it is empty.
     *
     * @return mixed
     */
    private static function decode(string $raw)
    {
        if (trim($raw) === '') {
            return null;
        }
        $decoded = json_decode($raw, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $raw;
    }

    private static function origin(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host'])) {
            return $url;
        }

        return (isset($parts['scheme']) ? $parts['scheme'] : 'https') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }
}
