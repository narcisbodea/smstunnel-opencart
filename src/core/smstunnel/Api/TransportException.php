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
 * SMSTunnel could not be reached, or did not answer: DNS, TLS, refused connection or timeout.
 */
class TransportException extends \RuntimeException
{
    /** @var bool */
    private $mayHaveArrived;

    /**
     * @param bool $mayHaveArrived true when the request was sent and the answer never came: SMSTunnel may
     *                             have taken the text
     */
    public function __construct(string $message, int $code = 0, bool $mayHaveArrived = false)
    {
        parent::__construct($message, $code);
        $this->mayHaveArrived = $mayHaveArrived;
    }

    /**
     * True when the request reached SMSTunnel but its answer did not come back (a timeout while waiting,
     * a connection closed midway): the text may have gone out, so it must not be sent again on its own.
     */
    public function mayHaveArrived(): bool
    {
        return $this->mayHaveArrived;
    }
}
