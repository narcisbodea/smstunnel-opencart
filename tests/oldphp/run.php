<?php
/**
 * The OpenCart 3.0 controllers on a PHP older than 7.1, which OpenCart 3.0 still runs on. tools/check.sh runs
 * this with PHP 5.6, with stand-ins for OpenCart:
 *
 *   php tests/oldphp/run.php admin      the settings page, install and uninstall
 *   php tests/oldphp/run.php catalog    the order events
 *
 * There the extension must do nothing, say why on its page, and never load its own classes (they need
 * PHP 7.1, and on PHP 5 a class that does not parse stops the page with a fatal error).
 *
 * @copyright 2026 NARBOWEB SRL
 * @license   GPL-3.0-or-later https://www.gnu.org/licenses/gpl-3.0.html
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

if (PHP_VERSION_ID >= 70100) {
	echo 'old PHP checks: skipped on PHP ' . PHP_VERSION . PHP_EOL;
	exit(0);
}

define('REPO', dirname(dirname(__DIR__)));
define('DB_PREFIX', 'oc_');
define('VERSION', '3.0.3.8');

// The packages copy src/core/smstunnel to system/library/smstunnel.
$system = sys_get_temp_dir() . '/smstunnel-oldphp-' . getmypid() . '/';
mkdir($system . 'library', 0777, true);
symlink(REPO . '/src/core/smstunnel', $system . 'library/smstunnel');
define('DIR_SYSTEM', $system);
register_shutdown_function(function () use ($system) {
	@unlink($system . 'library/smstunnel');
	@rmdir($system . 'library');
	@rmdir($system);
});

// Every PHP notice or warning is a failure: OpenCart's own error handler would stop the page on one.
set_error_handler(function ($severity, $message, $file, $line) {
	echo "FAIL  PHP error: $message ($file:$line)" . PHP_EOL;
	exit(1);
});

// ------------------------------------------------------------------------------------------------
// Stand-ins for the parts of OpenCart the controllers use.

class Registry {
	private $data = array();

	public function get($key) {
		return isset($this->data[$key]) ? $this->data[$key] : null;
	}

	public function set($key, $value) {
		$this->data[$key] = $value;
	}

	public function has($key) {
		return isset($this->data[$key]);
	}
}

abstract class Controller {
	protected $registry;

	public function __construct($registry) {
		$this->registry = $registry;
	}

	public function __get($key) {
		return $this->registry->get($key);
	}

	public function __set($key, $value) {
		$this->registry->set($key, $value);
	}
}

/** What the controllers asked of OpenCart, in order. */
class Journal {
	public $calls = array();
}

/** A model or service that only writes down its calls. */
class Recorder {
	private $name;
	private $journal;

	public function __construct($name, $journal) {
		$this->name = $name;
		$this->journal = $journal;
	}

	public function __call($method, $args) {
		$this->journal->calls[] = $this->name . '->' . $method . '(' . implode(', ', array_map('json_encode', $args)) . ')';

		return null;
	}
}

class Loader {
	private $registry;

	public function __construct($registry) {
		$this->registry = $registry;
	}

	public function language($route) {
		$_ = array();
		require REPO . '/src/language/en-gb.php';
		$this->registry->get('language')->data = array_merge($this->registry->get('language')->data, $_);
	}

	public function controller($route) {
		return '[' . $route . ']';
	}

	public function model($route) {
		$name = 'model_' . str_replace('/', '_', $route);
		$this->registry->set($name, new Recorder($name, $this->registry->get('journal')));
	}

	public function view($route, $data = array()) {
		$this->registry->get('journal')->calls[] = 'view ' . $route;

		return '';
	}
}

class Language {
	public $data = array();

	public function get($key) {
		return isset($this->data[$key]) ? $this->data[$key] : $key;
	}
}

class Document {
	public $title = '';

	public function setTitle($title) {
		$this->title = $title;
	}
}

class Response {
	public $output = '';

	public function setOutput($output) {
		$this->output = $output;
	}

	public function redirect($url) {
		throw new Exception('redirect to ' . $url);
	}

	public function addHeader($header) {
	}
}

$journal = new Journal();
$registry = new Registry();
$registry->set('journal', $journal);
$registry->set('load', new Loader($registry));
$registry->set('language', new Language());
$registry->set('document', new Document());
$registry->set('response', new Response());
foreach (array('db', 'config', 'session', 'request', 'url', 'user', 'log', 'currency') as $service) {
	$registry->set($service, new Recorder($service, $journal));
}

// ------------------------------------------------------------------------------------------------

$failed = 0;
$passed = 0;

function check($name, $ok, $detail = '') {
	global $failed, $passed;

	if ($ok) {
		$passed++;
		echo "PASS  $name" . PHP_EOL;
	} else {
		$failed++;
		echo "FAIL  $name" . ($detail !== '' ? "  ($detail)" : '') . PHP_EOL;
	}
}

function ownClassesLoaded() {
	return array_values(array_filter(get_declared_classes(), function ($class) {
		return strpos($class, 'SmsTunnel\\OpenCart\\') === 0;
	}));
}

$side = isset($argv[1]) ? $argv[1] : '';

if ($side === 'admin') {
	require REPO . '/src/oc3/upload/admin/controller/extension/module/smstunnel.php';
	$controller = new ControllerExtensionModuleSmstunnel($registry);

	$controller->install();
	check('install: does nothing on PHP ' . PHP_VERSION, $journal->calls === array(), implode('; ', $journal->calls));

	$controller->index();
	$output = $registry->get('response')->output;
	check('settings page: says it needs PHP 7.1 and names this PHP', strpos($output, 'needs PHP 7.1 or newer') !== false && strpos($output, 'runs PHP ' . PHP_VERSION) !== false, substr($output, 0, 160));
	check('settings page: inside the admin header, menu and footer', strpos($output, '[common/header]') === 0 && strpos($output, '[common/column_left]') !== false && substr($output, -strlen('[common/footer]')) === '[common/footer]');
	check('settings page: asks nothing of the database or the models', $journal->calls === array(), implode('; ', $journal->calls));

	$controller->uninstall();
	check('uninstall: deletes the two events and nothing else', $journal->calls === array(
		'model_setting_event->deleteEventByCode("smstunnel_history_before")',
		'model_setting_event->deleteEventByCode("smstunnel_history_after")',
	), implode('; ', $journal->calls));
} elseif ($side === 'catalog') {
	require REPO . '/src/oc3/upload/catalog/controller/extension/module/smstunnel.php';
	$controller = new ControllerExtensionModuleSmstunnel($registry);

	$route = 'checkout/order/addOrderHistory';
	$args = array(1042, 3, 'Tracking: 1Z999AA10123456784', 1);
	$output = null;
	$before = $controller->beforeHistory($route, $args);
	$after = $controller->afterHistory($route, $args, $output);
	check('order events: return nothing and change nothing', $before === null && $after === null && $output === null && $args === array(1042, 3, 'Tracking: 1Z999AA10123456784', 1));
	check('order events: ask nothing of OpenCart', $journal->calls === array(), implode('; ', $journal->calls));
} else {
	echo 'Usage: php tests/oldphp/run.php admin|catalog' . PHP_EOL;
	exit(2);
}

check("none of the extension's own classes loaded", ownClassesLoaded() === array(), implode(', ', ownClassesLoaded()));

echo "PHP " . PHP_VERSION . " ($side): $passed passed, $failed failed" . PHP_EOL;
exit($failed ? 1 : 0);
