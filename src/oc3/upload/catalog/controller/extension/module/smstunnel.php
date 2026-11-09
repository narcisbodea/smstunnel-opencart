<?php
/**
 * SMSTunnel for OpenCart (OpenCart 3.0): the order events.
 *
 * OpenCart runs model/checkout/order/addOrderHistory when a checkout ends and when the store owner
 * changes an order's status (the admin calls the store's API). Around it, these handlers send the texts:
 * never a return value (it would replace the model's result), never an exception or a PHP warning (it
 * would stop the checkout or the status change, or break the JSON it answers with).
 *
 * @author    Narcis Bodea <contact@narboweb.ro>
 * @copyright 2026 NARBOWEB SRL
 * @license   GPL-3.0-or-later https://www.gnu.org/licenses/gpl-3.0.html
 */

require_once DIR_SYSTEM . 'library/smstunnel/autoload.php';

use SmsTunnel\OpenCart\Guard;
use SmsTunnel\OpenCart\Log\Redact;
use SmsTunnel\OpenCart\Notifier;
use SmsTunnel\OpenCart\Version;

class ControllerExtensionModuleSmstunnel extends Controller {
	/**
	 * A layout position shows nothing: the extension only sends texts.
	 */
	public function index() {
		return '';
	}

	/**
	 * Event catalog/model/checkout/order/addOrderHistory/before: notes the order's status.
	 */
	public function beforeHistory(&$route, &$args) {
		if (PHP_VERSION_ID < 70100) {
			// The extension's own code needs PHP 7.1: on an older PHP it does nothing.
			return;
		}

		try {
			if (isset($args[0]) && is_numeric($args[0])) {
				$order_id = (int)$args[0];

				Guard::run(function () use ($order_id) {
					$this->notifier()->before($order_id);
				});
			}
		} catch (\Throwable $e) {
			$this->failed($e);
		}
	}

	/**
	 * Event catalog/model/checkout/order/addOrderHistory/after: sends the texts the change calls for.
	 */
	public function afterHistory(&$route, &$args, &$output) {
		if (PHP_VERSION_ID < 70100) {
			return;
		}

		try {
			if (isset($args[0]) && is_numeric($args[0])) {
				$order_id = (int)$args[0];
				$comment = isset($args[2]) && is_scalar($args[2]) ? (string)$args[2] : '';
				$notify = !empty($args[3]);

				Guard::run(function () use ($order_id, $comment, $notify) {
					$this->notifier()->after($order_id, $comment, $notify);
				});
			}
		} catch (\Throwable $e) {
			$this->failed($e);
		}
	}

	private function notifier() {
		$registry = $this->registry;

		return new Notifier(
			$this->db,
			DB_PREFIX,
			Version::userAgent('OpenCart ' . VERSION),
			function ($amount, $currency, $value) use ($registry) {
				return $registry->has('currency') ? $registry->get('currency')->format($amount, $currency, $value) : '';
			},
			function ($line) use ($registry) {
				if ($registry->has('log')) {
					$registry->get('log')->write($line);
				}
			}
		);
	}

	/**
	 * Something unexpected: one line in OpenCart's error log, nothing else, whatever happens.
	 */
	private function failed(\Throwable $e) {
		$registry = $this->registry;

		Guard::quietly(function () use ($registry, $e) {
			if ($registry->has('log')) {
				$registry->get('log')->write('SMSTunnel: ' . Redact::text(get_class($e) . ': ' . $e->getMessage()));
			}
		});
	}
}
