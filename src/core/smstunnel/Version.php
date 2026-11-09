<?php
/**
 * SMSTunnel for OpenCart.
 *
 * @author    Narcis Bodea <contact@narboweb.ro>
 * @copyright 2026 NARBOWEB SRL
 * @license   GPL-3.0-or-later https://www.gnu.org/licenses/gpl-3.0.html
 */

namespace SmsTunnel\OpenCart;

final class Version
{
    /** The extension's version (also in install.json and CHANGELOG.md). */
    const NUMBER = '1.0.0';

    const GOOGLE_PLAY_URL = 'https://play.google.com/store/apps/details?id=io.smstunnel.app';

    const DASHBOARD_URL = 'https://dashboard.smstunnel.io/apps';

    /**
     * The User-Agent of every call to SMSTunnel.
     *
     * @param string $platform e.g. "OpenCart 4.1.0.4"
     */
    public static function userAgent(string $platform): string
    {
        return 'SMSTunnel-OpenCart/' . self::NUMBER . ' (' . $platform . '; PHP ' . PHP_VERSION . ')';
    }
}
