<?php
/**
 * SMSTunnel for OpenCart (OpenCart 4.0.2 and newer): the order events.
 *
 * OpenCart runs the order model's addHistory when a checkout ends and when the store owner changes an
 * order's status (the admin runs the store's API in the same request). Around it, these handlers send
 * the texts: never a return value (it would replace the model's result), never an exception or a PHP
 * warning (OpenCart stops the request on a warning, which would stop the checkout or the status change).
 *
 * @author    Narcis Bodea <contact@narboweb.ro>
 * @copyright 2026 NARBOWEB SRL
 * @license   GPL-3.0-or-later https://www.gnu.org/licenses/gpl-3.0.html
 */
namespace Opencart\Catalog\Controller\Extension\Smstunnel\Module;

require_once DIR_EXTENSION . 'smstunnel/system/library/smstunnel/autoload.php';

use SmsTunnel\OpenCart\Guard;
use SmsTunnel\OpenCart\Log\Redact;
use SmsTunnel\OpenCart\Notifier;
use SmsTunnel\OpenCart\Version;

/**
 * Class Smstunnel
 *
 * @package Opencart\Catalog\Controller\Extension\Smstunnel\Module
 */
class Smstunnel extends \Opencart\System\Engine\Controller {
	/**
	 * A layout position shows nothing: the extension only sends texts.
	 *
	 * @return string
	 */
	public function index(): string {
		return '';
	}

	/**
	 * Event catalog/model/checkout/order/addHistory/before (4.0) and order.addHistory/before (4.1):
	 * notes the order's status. Called without arguments (a URL), it does nothing.
	 *
	 * @param string $route
	 * @param array  $args
	 *
	 * @return void
	 */
	public function beforeHistory(string &$route = '', array &$args = []): void {
		try {
			if (isset($args[0]) && is_numeric($args[0])) {
				$order_id = (int)$args[0];

				Guard::run(fn () => $this->notifier()->before($order_id));
			}
		} catch (\Throwable $e) {
			$this->failed($e);
		}
	}

	/**
	 * Event catalog/model/checkout/order/addHistory/after (4.0) and order.addHistory/after (4.1): sends
	 * the texts the change calls for. Called without arguments (a URL), it does nothing.
	 *
	 * @param string $route
	 * @param array  $args
	 * @param mixed  $output
	 *
	 * @return void
	 */
	public function afterHistory(string &$route = '', array &$args = [], mixed &$output = null): void {
		try {
			if (isset($args[0]) && is_numeric($args[0])) {
				$order_id = (int)$args[0];
				$comment = isset($args[2]) && is_scalar($args[2]) ? (string)$args[2] : '';
				$notify = !empty($args[3]);

				Guard::run(fn () => $this->notifier()->after($order_id, $comment, $notify));
			}
		} catch (\Throwable $e) {
			$this->failed($e);
		}
	}

	private function notifier(): Notifier {
		$registry = $this->registry;

		return new Notifier(
			$this->db,
			DB_PREFIX,
			Version::userAgent('OpenCart ' . VERSION),
			static function ($amount, $currency, $value) use ($registry): string {
				return $registry->has('currency') ? (string)$registry->get('currency')->format((float)$amount, (string)$currency, (float)$value) : '';
			},
			static function ($line) use ($registry): void {
				if ($registry->has('log')) {
					$registry->get('log')->write($line);
				}
			}
		);
	}

	/**
	 * Something unexpected: one line in OpenCart's error log, nothing else, whatever happens.
	 */
	private function failed(\Throwable $e): void {
		$registry = $this->registry;

		Guard::quietly(static function () use ($registry, $e): void {
			if ($registry->has('log')) {
				$registry->get('log')->write('SMSTunnel: ' . Redact::text(get_class($e) . ': ' . $e->getMessage()));
			}
		});
	}
}
