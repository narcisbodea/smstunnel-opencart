<?php
/**
 * SMSTunnel for OpenCart (OpenCart 3.0): the settings page, the test text, "Send again", install and
 * uninstall.
 *
 * Texts store owners about new orders and customers about their own orders, from the owner's own
 * Android phone and own number, through SMSTunnel (https://smstunnel.io).
 *
 * @author    Narcis Bodea <contact@narboweb.ro>
 * @copyright 2026 NARBOWEB SRL
 * @license   GPL-3.0-or-later https://www.gnu.org/licenses/gpl-3.0.html
 */

require_once DIR_SYSTEM . 'library/smstunnel/autoload.php';

use SmsTunnel\OpenCart\Admin\Page;
use SmsTunnel\OpenCart\Guard;
use SmsTunnel\OpenCart\Log\TextLog;
use SmsTunnel\OpenCart\Settings;
use SmsTunnel\OpenCart\Version;

class ControllerExtensionModuleSmstunnel extends Controller {
	const ROUTE = 'extension/module/smstunnel';

	/** OpenCart events (code => [trigger, action]). */
	const EVENTS = array(
		'smstunnel_history_before' => array('catalog/model/checkout/order/addOrderHistory/before', 'extension/module/smstunnel/beforeHistory'),
		'smstunnel_history_after'  => array('catalog/model/checkout/order/addOrderHistory/after', 'extension/module/smstunnel/afterHistory'),
	);

	/** The extension's own code needs PHP 7.1; OpenCart 3.0 also runs on PHP 5.x. */
	const MIN_PHP_ID = 70100;

	public function index() {
		$this->load->language(self::ROUTE);
		$this->document->setTitle($this->language->get('heading_title'));

		if (PHP_VERSION_ID < self::MIN_PHP_ID) {
			// The page says why, without loading code this PHP cannot read.
			$data['heading_title'] = $this->language->get('heading_title');
			$data['header'] = $this->load->controller('common/header');
			$data['column_left'] = $this->load->controller('common/column_left');
			$data['footer'] = $this->load->controller('common/footer');
			$this->response->setOutput($data['header'] . $data['column_left'] . '<div id="content"><div class="container-fluid"><div class="alert alert-danger">' . sprintf($this->language->get('error_php'), PHP_VERSION) . '</div></div></div>' . $data['footer']);

			return;
		}

		$this->load->model('setting/extension');

		if (!in_array('smstunnel', $this->model_setting_extension->getInstalled('module'), true)) {
			// Opened after uninstalling (OpenCart 3.0.3 keeps the permission): nothing to set, back to the list.
			$this->response->redirect($this->link('marketplace/extension', '&type=module'));
		}

		if (!$this->eventsExist()) {
			// Installed while the store ran a PHP older than 7.1, where install() set nothing up: do it now.
			$this->install();
		}

		$page = $this->page();
		$posted = null;
		$errors = array();

		if ($this->request->server['REQUEST_METHOD'] == 'POST' && isset($this->request->post['smstunnel_action'])) {
			$action = (string)$this->request->post['smstunnel_action'];

			if (!$this->user->hasPermission('modify', self::ROUTE)) {
				$this->flash('danger', $this->language->get('error_permission'));
				$this->response->redirect($this->link(self::ROUTE));
			}

			if ($action == 'save') {
				list($values, $errors) = $page->validate($this->request->post, $this->languageId());

				if (!$errors) {
					$this->load->model('setting/setting');
					$this->model_setting_setting->editSetting(Settings::CODE, $values);

					$this->flash('success', $this->language->get('text_success'));
					$this->response->redirect($this->link(self::ROUTE));
				}

				$posted = $this->request->post;
			} elseif ($action == 'test') {
				$result = $page->sendTest(isset($this->request->post['test_number']) ? (string)$this->request->post['test_number'] : '', (string)$this->config->get('config_name'), $this->languageCode());
				$this->flash($result['type'], $result['text']);
				$this->response->redirect($this->link(self::ROUTE));
			} elseif ($action == 'resend') {
				$result = $page->resend(isset($this->request->post['log_id']) ? (int)$this->request->post['log_id'] : 0);
				$this->flash($result['type'], $result['text']);
				$this->response->redirect($this->link(self::ROUTE) . '#smstunnel-recent');
			} elseif ($action == 'clear') {
				$page->notifier()->log()->clear();
				$this->flash('success', $this->language->get('text_cleared'));
				$this->response->redirect($this->link(self::ROUTE) . '#smstunnel-recent');
			}
		}

		$data = $page->view($this->languageId(), $this->languageCode(), $posted, $this->orderUrl());

		$data['errors'] = $errors;
		$data['error_warning'] = $errors ? $this->language->get('error_form') : '';
		$data['flash'] = isset($this->session->data['smstunnel_flash']) ? $this->session->data['smstunnel_flash'] : null;
		unset($this->session->data['smstunnel_flash']);

		$data['text_key_hint'] = $data['has_key'] ? sprintf($this->language->get('text_key_saved'), $data['key_hint']) : $this->language->get('text_key_help');
		$data['text_owner_numbers_help'] = sprintf($this->language->get('text_owner_numbers_help'), $data['max_owner_numbers']);
		$data['text_placeholders'] = sprintf($this->language->get('text_placeholders'), $data['placeholders']);
		$data['text_version'] = sprintf($this->language->get('text_version'), $data['version']);

		$data['breadcrumbs'] = array();
		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('text_home'),
			'href' => $this->link('common/dashboard')
		);
		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('text_extension'),
			'href' => $this->link('marketplace/extension', '&type=module')
		);
		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('heading_title'),
			'href' => $this->link(self::ROUTE)
		);

		$data['action'] = $this->link(self::ROUTE);
		$data['refresh'] = $this->link(self::ROUTE . '/refresh');
		$data['cancel'] = $this->link('marketplace/extension', '&type=module');

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view(self::ROUTE, $data));
	}

	/**
	 * Asks SMSTunnel what the phone did with the recent texts; the page calls it once it is shown.
	 */
	public function refresh() {
		$this->load->language(self::ROUTE);

		$json = array('rows' => array());

		if ($this->request->server['REQUEST_METHOD'] == 'POST') {
			try {
				$json['rows'] = Guard::run(function () {
					return $this->page()->refresh($this->orderUrl(), $this->languageId());
				});
			} catch (\Throwable $e) {
				$json['rows'] = array();
			}
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	public function install() {
		if (PHP_VERSION_ID < self::MIN_PHP_ID) {
			// No events on a PHP that cannot run the extension: checkout stays untouched.
			return;
		}

		$this->load->model('setting/event');

		foreach (self::EVENTS as $code => $event) {
			$this->model_setting_event->deleteEventByCode($code);
			$this->model_setting_event->addEvent($code, $event[0], $event[1]);
		}

		$page = $this->page();
		$page->notifier()->log()->install();

		$this->load->model('setting/setting');

		if (!$this->model_setting_setting->getSetting(Settings::CODE)) {
			$this->model_setting_setting->editSetting(Settings::CODE, $page->installDefaults($this->languageId()));
		}
	}

	public function uninstall() {
		$this->load->model('setting/event');

		foreach (array_keys(self::EVENTS) as $code) {
			$this->model_setting_event->deleteEventByCode($code);
		}

		if (PHP_VERSION_ID < self::MIN_PHP_ID) {
			// OpenCart deletes the settings itself. The rest needs the extension's own code, which this PHP
			// cannot read, and an install on this PHP created nothing else.
			return;
		}

		$this->load->model('setting/setting');
		$this->model_setting_setting->deleteSetting(Settings::CODE);

		$log = new TextLog($this->db, DB_PREFIX);
		$log->uninstall();
	}

	/**
	 * fn(int $order_id): the order's page in the admin.
	 */
	private function orderUrl() {
		return function ($order_id) {
			return $this->link('sale/order/info', '&order_id=' . (int)$order_id);
		};
	}

	private function page() {
		$language = $this->language;
		$log = $this->log;

		return new Page(
			$this->db,
			DB_PREFIX,
			Version::userAgent('OpenCart ' . VERSION),
			function ($key) use ($language) {
				return $language->get($key);
			},
			function ($line) use ($log) {
				$log->write($line);
			}
		);
	}

	/**
	 * Whether all the extension's events are in OpenCart's list (an event the owner turned off counts).
	 */
	private function eventsExist() {
		$codes = array();

		foreach (array_keys(self::EVENTS) as $code) {
			$codes[] = "'" . $this->db->escape($code) . "'";
		}

		$query = $this->db->query("SELECT COUNT(*) AS `total` FROM `" . DB_PREFIX . "event` WHERE `code` IN (" . implode(', ', $codes) . ")");

		return (int)$query->row['total'] >= count(self::EVENTS);
	}

	private function link($route, $args = '') {
		return $this->url->link($route, 'user_token=' . $this->session->data['user_token'] . $args, true);
	}

	private function flash($type, $text) {
		$this->session->data['smstunnel_flash'] = array('type' => $type, 'text' => $text);
	}

	private function languageId() {
		return (int)$this->config->get('config_language_id');
	}

	private function languageCode() {
		$code = (string)$this->config->get('config_admin_language');

		return $code !== '' ? $code : (string)$this->config->get('config_language');
	}
}
