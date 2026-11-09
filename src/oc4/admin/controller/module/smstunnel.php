<?php
/**
 * SMSTunnel for OpenCart (OpenCart 4.0.2 and newer): the settings page, the test text, "Send again",
 * install and uninstall.
 *
 * Texts store owners about new orders and customers about their own orders, from the owner's own
 * Android phone and own number, through SMSTunnel (https://smstunnel.io).
 *
 * @author    Narcis Bodea <contact@narboweb.ro>
 * @copyright 2026 NARBOWEB SRL
 * @license   GPL-3.0-or-later https://www.gnu.org/licenses/gpl-3.0.html
 */
namespace Opencart\Admin\Controller\Extension\Smstunnel\Module;

require_once DIR_EXTENSION . 'smstunnel/system/library/smstunnel/autoload.php';

use SmsTunnel\OpenCart\Admin\Page;
use SmsTunnel\OpenCart\Guard;
use SmsTunnel\OpenCart\Log\TextLog;
use SmsTunnel\OpenCart\Settings;
use SmsTunnel\OpenCart\Version;

/**
 * Class Smstunnel
 *
 * @package Opencart\Admin\Controller\Extension\Smstunnel\Module
 */
class Smstunnel extends \Opencart\System\Engine\Controller {
	public const ROUTE = 'extension/smstunnel/module/smstunnel';

	/**
	 * OpenCart events: code => [trigger, action, description]. OpenCart 4.0 names the order model's method
	 * "order/addHistory", 4.1 "order.addHistory"; both are registered and only one ever fires.
	 */
	public const EVENTS = [
		'smstunnel_history_before'    => ['catalog/model/checkout/order/addHistory/before', 'extension/smstunnel/module/smstunnel.beforeHistory', 'SMSTunnel: notes the order status before it changes (OpenCart 4.0).'],
		'smstunnel_history_after'     => ['catalog/model/checkout/order/addHistory/after', 'extension/smstunnel/module/smstunnel.afterHistory', 'SMSTunnel: texts the store owner about new orders and customers about their order status (OpenCart 4.0).'],
		'smstunnel_history_before_41' => ['catalog/model/checkout/order.addHistory/before', 'extension/smstunnel/module/smstunnel.beforeHistory', 'SMSTunnel: notes the order status before it changes (OpenCart 4.1).'],
		'smstunnel_history_after_41'  => ['catalog/model/checkout/order.addHistory/after', 'extension/smstunnel/module/smstunnel.afterHistory', 'SMSTunnel: texts the store owner about new orders and customers about their order status (OpenCart 4.1).'],
	];

	/**
	 * The settings page, and its forms (save, test text, send again, clear the list).
	 *
	 * @return void
	 */
	public function index(): void {
		$this->loadLanguage();

		$this->document->setTitle($this->language->get('heading_title'));

		$page = $this->page();
		$posted = null;
		$errors = [];

		if ($this->request->server['REQUEST_METHOD'] == 'POST' && isset($this->request->post['smstunnel_action'])) {
			$action = (string)$this->request->post['smstunnel_action'];

			if (!$this->user->hasPermission('modify', self::ROUTE)) {
				$this->flash('danger', $this->language->get('error_permission'));
				$this->response->redirect($this->link(self::ROUTE));
			}

			if ($action == 'save') {
				[$values, $errors] = $page->validate($this->request->post, $this->languageId());

				if (!$errors) {
					$this->load->model('setting/setting');
					$this->model_setting_setting->editSetting(Settings::CODE, $values);

					$this->flash('success', $this->language->get('text_success'));
					$this->response->redirect($this->link(self::ROUTE));
				}

				$posted = $this->request->post;
			} elseif ($action == 'test') {
				$result = $page->sendTest((string)($this->request->post['test_number'] ?? ''), (string)$this->config->get('config_name'), $this->languageCode());
				$this->flash($result['type'], $result['text']);
				$this->response->redirect($this->link(self::ROUTE));
			} elseif ($action == 'resend') {
				$result = $page->resend((int)($this->request->post['log_id'] ?? 0));
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
		// OpenCart 4 asks for a telephone at checkout only when the store owner turned it on.
		$data['telephone_off'] = $data['customer_status'] && !$this->config->get('config_telephone_display');
		$data['flash'] = $this->session->data['smstunnel_flash'] ?? null;
		unset($this->session->data['smstunnel_flash']);

		$data['text_key_hint'] = $data['has_key'] ? sprintf($this->language->get('text_key_saved'), $data['key_hint']) : $this->language->get('text_key_help');
		$data['text_owner_numbers_help'] = sprintf($this->language->get('text_owner_numbers_help'), $data['max_owner_numbers']);
		$data['text_placeholders'] = sprintf($this->language->get('text_placeholders'), $data['placeholders']);
		$data['text_version'] = sprintf($this->language->get('text_version'), $data['version']);

		$data['breadcrumbs'] = [];
		$data['breadcrumbs'][] = [
			'text' => $this->language->get('text_home'),
			'href' => $this->link('common/dashboard')
		];
		$data['breadcrumbs'][] = [
			'text' => $this->language->get('text_extension'),
			'href' => $this->link('marketplace/extension', '&type=module')
		];
		$data['breadcrumbs'][] = [
			'text' => $this->language->get('heading_title'),
			'href' => $this->link(self::ROUTE)
		];

		$data['action'] = $this->link(self::ROUTE);
		$data['refresh'] = $this->link(self::ROUTE . '.refresh');
		$data['back'] = $this->link('marketplace/extension', '&type=module');

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view(self::ROUTE, $data));
	}

	/**
	 * Asks SMSTunnel what the phone did with the recent texts; the page calls it once it is shown.
	 *
	 * @return void
	 */
	public function refresh(): void {
		$this->loadLanguage();

		$json = ['rows' => []];

		if ($this->request->server['REQUEST_METHOD'] == 'POST') {
			try {
				$json['rows'] = Guard::run(fn () => $this->page()->refresh($this->orderUrl(), $this->languageId()));
			} catch (\Throwable $e) {
				$json['rows'] = [];
			}
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	/**
	 * Extensions > Extensions > Modules > SMSTunnel > Install.
	 *
	 * @return void
	 */
	public function install(): void {
		if (!$this->user->hasPermission('modify', 'extension/module')) {
			return;
		}

		$this->load->model('setting/event');

		foreach (self::EVENTS as $code => [$trigger, $action, $description]) {
			$this->model_setting_event->deleteEventByCode($code);
			$this->model_setting_event->addEvent([
				'code'        => $code,
				'description' => $description,
				'trigger'     => $trigger,
				'action'      => $action,
				'status'      => 1,
				'sort_order'  => 0
			]);
		}

		$page = $this->page();
		$page->notifier()->log()->install();

		$this->load->model('setting/setting');

		if (!$this->model_setting_setting->getSetting(Settings::CODE)) {
			$this->model_setting_setting->editSetting(Settings::CODE, $page->installDefaults($this->languageId()));
		}
	}

	/**
	 * Extensions > Extensions > Modules > SMSTunnel > Uninstall: removes the events, the settings and
	 * the list of texts.
	 *
	 * @return void
	 */
	public function uninstall(): void {
		if (!$this->user->hasPermission('modify', 'extension/module')) {
			return;
		}

		$this->load->model('setting/event');

		foreach (array_keys(self::EVENTS) as $code) {
			$this->model_setting_event->deleteEventByCode($code);
		}

		$this->load->model('setting/setting');
		$this->model_setting_setting->deleteSetting(Settings::CODE);

		(new TextLog($this->db, DB_PREFIX))->uninstall();
	}

	/**
	 * English first, then the admin's language on top: a language the extension has no file for shows
	 * English words, not keys.
	 */
	private function loadLanguage(): void {
		$this->load->language(self::ROUTE, '', 'en-gb');
		$this->load->language(self::ROUTE);
	}

	private function page(): Page {
		$language = $this->language;
		$log = $this->log;

		return new Page(
			$this->db,
			DB_PREFIX,
			Version::userAgent('OpenCart ' . VERSION),
			static fn ($key) => $language->get($key),
			static function ($line) use ($log): void {
				$log->write($line);
			}
		);
	}

	/**
	 * fn(int $order_id): the order's page in the admin.
	 */
	private function orderUrl(): \Closure {
		return fn ($order_id) => $this->link('sale/order.info', '&order_id=' . (int)$order_id);
	}

	private function link(string $route, string $args = ''): string {
		return $this->url->link($route, 'user_token=' . $this->session->data['user_token'] . $args);
	}

	private function flash(string $type, string $text): void {
		$this->session->data['smstunnel_flash'] = ['type' => $type, 'text' => $text];
	}

	private function languageId(): int {
		return (int)$this->config->get('config_language_id');
	}

	private function languageCode(): string {
		$code = (string)$this->config->get('config_language_admin');

		return $code !== '' ? $code : (string)$this->config->get('config_language');
	}
}
