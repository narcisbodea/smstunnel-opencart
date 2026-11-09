<?php
/**
 * SMSTunnel for OpenCart.
 *
 * @author    Narcis Bodea <contact@narboweb.ro>
 * @copyright 2026 NARBOWEB SRL
 * @license   GPL-3.0-or-later https://www.gnu.org/licenses/gpl-3.0.html
 */

namespace SmsTunnel\OpenCart;

/**
 * Keeps PHP warnings and notices away from OpenCart's own error handler while the extension runs.
 *
 * OpenCart answers any warning by printing it into the page (which breaks the JSON a checkout step
 * returns) or by redirecting to its error page and exiting (OpenCart 4.0 with errors hidden), and a
 * try/catch cannot stop an exit. Inside run(), a warning becomes an exception the extension's handlers
 * catch, so a checkout or a status change always goes on.
 */
final class Guard
{
    /**
     * Runs $fn; a PHP warning, notice or deprecation raised meanwhile is thrown as an \ErrorException.
     *
     * @return mixed what $fn returns
     */
    public static function run($fn)
    {
        set_error_handler(function ($severity, $message, $file = '', $line = 0) {
            if (!(error_reporting() & $severity)) {
                // Silenced with @: PHP's own handler, not OpenCart's.
                return false;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            return call_user_func($fn);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Runs $fn and ignores whatever goes wrong (for writing to a log that may not be writable).
     */
    public static function quietly($fn)
    {
        set_error_handler(function () {
            return true;
        });
        try {
            call_user_func($fn);
        } catch (\Throwable $e) {
            // Nothing to do: this was the last resort.
        } finally {
            restore_error_handler();
        }
    }
}
