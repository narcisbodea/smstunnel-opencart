<?php
/**
 * SMSTunnel for OpenCart: the shared core, used by the OpenCart 3.0 and OpenCart 4 builds.
 *
 * Plain PHP (7.1 or newer), no OpenCart class: the OpenCart controllers hand it the database, the
 * settings and a few callbacks.
 *
 * @author    Narcis Bodea <contact@narboweb.ro>
 * @copyright 2026 NARBOWEB SRL
 * @license   GPL-3.0-or-later https://www.gnu.org/licenses/gpl-3.0.html
 */

if (!defined('SMSTUNNEL_OPENCART_CORE')) {
    define('SMSTUNNEL_OPENCART_CORE', __DIR__);

    spl_autoload_register(function ($class) {
        static $map = array(
            'SmsTunnel\\OpenCart\\Version' => 'Version.php',
            'SmsTunnel\\OpenCart\\Guard' => 'Guard.php',
            'SmsTunnel\\OpenCart\\Settings' => 'Settings.php',
            'SmsTunnel\\OpenCart\\Notifier' => 'Notifier.php',
            'SmsTunnel\\OpenCart\\Api\\Client' => 'Api/Client.php',
            'SmsTunnel\\OpenCart\\Api\\ApiException' => 'Api/ApiException.php',
            'SmsTunnel\\OpenCart\\Api\\TransportException' => 'Api/TransportException.php',
            'SmsTunnel\\OpenCart\\Phone\\PhoneNumber' => 'Phone/PhoneNumber.php',
            'SmsTunnel\\OpenCart\\Phone\\CallingCodes' => 'Phone/CallingCodes.php',
            'SmsTunnel\\OpenCart\\Message\\Template' => 'Message/Template.php',
            'SmsTunnel\\OpenCart\\Message\\DefaultTexts' => 'Message/DefaultTexts.php',
            'SmsTunnel\\OpenCart\\Log\\TextLog' => 'Log/TextLog.php',
            'SmsTunnel\\OpenCart\\Log\\Redact' => 'Log/Redact.php',
            'SmsTunnel\\OpenCart\\Store\\OrderRepository' => 'Store/OrderRepository.php',
            'SmsTunnel\\OpenCart\\Admin\\Page' => 'Admin/Page.php',
            'SmsTunnel\\OpenCart\\Admin\\SettingsForm' => 'Admin/SettingsForm.php',
        );
        if (isset($map[$class])) {
            require_once __DIR__ . '/' . $map[$class];
        }
    });
}
