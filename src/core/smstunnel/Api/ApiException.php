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
 * SMSTunnel answered, but with an error (the same reading as the SMSTunnel PHP SDK).
 *
 * - 401: the API key is wrong, was deleted, or its account is suspended.
 * - 403: the key lacks a permission (code PERMISSION_REQUIRED), or the plan does not include the feature.
 * - 404: no such text (or phone) for this key.
 * - 429: too many requests, or the daily limit of the plan is reached.
 * - 5xx: a problem on SMSTunnel's side.
 */
class ApiException extends \RuntimeException
{
    /** @var int */
    private $status;

    /** @var string|null */
    private $errorCode;

    /** @var string|null */
    private $permission;

    /** @var int|null seconds, from Retry-After */
    private $retryAfter;

    /**
     * @param int         $status     the HTTP status
     * @param mixed       $body       the decoded JSON body (array), the raw text, or null
     * @param array       $headers    response headers, names in lower case
     * @param string|null $message    overrides the message read from the body
     */
    public function __construct(int $status, $body = null, array $headers = array(), $message = null)
    {
        $this->status = $status;
        if (is_array($body)) {
            if (isset($body['code']) && is_string($body['code'])) {
                $this->errorCode = $body['code'];
            }
            if (isset($body['permission']) && is_string($body['permission'])) {
                $this->permission = $body['permission'];
            }
        }
        if (isset($headers['retry-after']) && is_numeric($headers['retry-after'])) {
            $this->retryAfter = (int) $headers['retry-after'];
        }
        parent::__construct(is_string($message) && $message !== '' ? $message : self::messageOf($body, $status), $status);
    }

    /** The HTTP status SMSTunnel answered with. */
    public function getStatus(): int
    {
        return $this->status;
    }

    /** SMSTunnel's own code, such as PERMISSION_REQUIRED, when it sent one. */
    public function getErrorCode()
    {
        return $this->errorCode;
    }

    /** With PERMISSION_REQUIRED: the permission the key lacks, such as sms:send. */
    public function getPermission()
    {
        return $this->permission;
    }

    /** Seconds to wait before trying again (429), when SMSTunnel said. */
    public function getRetryAfter()
    {
        return $this->retryAfter;
    }

    /**
     * The words of an error answer, whatever its shape (same rules as the SMSTunnel SDKs).
     *
     * @param mixed $body
     */
    public static function messageOf($body, int $status): string
    {
        if (is_string($body) && trim($body) !== '') {
            // A proxy's error page, for instance: its text, on one line, at most 300 characters.
            $text = trim((string) preg_replace('/\s+/', ' ', strip_tags($body)));
            if ($text !== '') {
                return self::cut($text, 300);
            }
        }
        if (is_array($body)) {
            $message = isset($body['message']) ? $body['message'] : null;
            if (is_array($message)) {
                $message = implode('; ', array_filter($message, 'is_string'));
            }
            if (is_string($message) && $message !== '') {
                return self::cut($message, 300);
            }
            if (isset($body['error']) && is_string($body['error']) && $body['error'] !== '') {
                return self::cut($body['error'], 300);
            }
        }

        return 'SMSTunnel returned error: ' . $status;
    }

    private static function cut(string $text, int $length): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $length, 'UTF-8');
        }

        return substr($text, 0, $length);
    }
}
