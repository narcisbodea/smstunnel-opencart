<?php
/**
 * Unit tests of the shared core of SMSTunnel for OpenCart: numbers, texts, settings, the log of texts
 * (on SQLite), and, with SMSTUNNEL_FAKE_URL set, the API client and the order logic against the fake
 * SMSTunnel API (tests/fake-api/server.mjs). Never against smstunnel.io.
 *
 *   php tests/unit/run.php        (no PHPUnit, so it runs on every PHP version the extension supports)
 *   SMSTUNNEL_FAKE_URL=http://host.docker.internal:PORT php tests/unit/run.php
 *
 * @license GPL-3.0-or-later
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$root = dirname(__DIR__, 2);
require $root . '/src/core/smstunnel/autoload.php';
require __DIR__ . '/SqliteDb.php';

use SmsTunnel\OpenCart\Admin\SettingsForm;
use SmsTunnel\OpenCart\Api\ApiException;
use SmsTunnel\OpenCart\Api\Client;
use SmsTunnel\OpenCart\Api\TransportException;
use SmsTunnel\OpenCart\Log\Redact;
use SmsTunnel\OpenCart\Log\TextLog;
use SmsTunnel\OpenCart\Message\DefaultTexts;
use SmsTunnel\OpenCart\Message\Template;
use SmsTunnel\OpenCart\Notifier;
use SmsTunnel\OpenCart\Phone\CallingCodes;
use SmsTunnel\OpenCart\Phone\PhoneNumber;
use SmsTunnel\OpenCart\Settings;

$passed = 0;
$failed = 0;

function check($name, $actual, $expected)
{
    global $passed, $failed;
    if ($actual === $expected) {
        ++$passed;

        return true;
    }
    ++$failed;
    echo 'FAIL ', $name, "\n  expected: ", var_export($expected, true), "\n  actual:   ", var_export($actual, true), "\n";

    return false;
}

function section($name)
{
    echo '-- ', $name, "\n";
}

// ---------------------------------------------------------------------------------------------------
section('phone numbers');
// [typed, calling code, expected E.164, expected problem]
$numbers = array(
    array('+40 721 123 456', '40', '+40721123456', ''),
    array('0721 123 456', '40', '+40721123456', ''),
    array('721123456', '40', '+40721123456', ''),
    array('40721123456', '40', '+40721123456', ''),
    array('0040 721 123 456', '1', '+40721123456', ''),
    array('(+40) 721-123-456', '1', '+40721123456', ''),
    array('(415) 555-0123', '1', '+14155550123', ''),
    array('415.555.0123', '1', '+14155550123', ''),
    array('14155550123', '1', '+14155550123', ''),
    array('+1 415 555 0123', '', '+14155550123', ''),
    array('06 12 34 56 78', '33', '+33612345678', ''),
    array('612 345 678', '34', '+34612345678', ''),
    array('3123456789', '39', '+393123456789', ''),
    array('06 1234 5678', '39', '+390612345678', ''),
    array('8 916 123-45-67', '7', '+79161234567', ''),
    array('0151 23456789', '49', '+4915123456789', ''),
    array('07700 900123', '44', '+447700900123', ''),
    array('&#43;40 721 123 456', '1', '+40721123456', ''),
    array('+49 (0) 170 1234567', '1', '+491701234567', ''),
    array('+40 (0)721 123 456', '1', '+40721123456', ''),
    array('+44 (0)7700 900123', '', '+447700900123', ''),
    array('(0) 721 123 456', '40', '+40721123456', ''),
    array('0612345678', '', '', 'invalid'),
    array('', '40', '', 'missing'),
    array('   ', '40', '', 'missing'),
    array(null, '40', '', 'missing'),
    array('abc', '40', '', 'invalid'),
    array('12', '1', '', 'invalid'),
    array('+40 7', '', '', 'invalid'),
    array('+1234567890123456', '', '', 'invalid'),
    array('+1 900 555 0123', '', '', 'premium'),
    array('0900 123 456', '40', '', 'premium'),
    array('08 91 23 45 67', '33', '', 'premium'),
    array('0900 1234567', '49', '', 'premium'),
    array('+44 909 879 0000', '', '', 'premium'),
    array('+881 6 12345678', '', '', 'premium'),
    array('+870 772 123 456', '', '', 'premium'),
    array('+3361234', '', '', 'premium'),
);
foreach ($numbers as $case) {
    check('analyse ' . var_export($case[0], true) . ' / ' . $case[1], PhoneNumber::analyse($case[0], $case[1]), array($case[2], $case[3]));
}
check('normalize', PhoneNumber::normalize('0721 123 456', 40), '+40721123456');
check('isE164', PhoneNumber::isE164('+14155550123'), true);
check('isE164 leading zero', PhoneNumber::isE164('+0412345678'), false);
check('calling code RO', CallingCodes::forCountry('ro'), '40');
check('calling code US', CallingCodes::forCountry('US'), '1');
check('calling code GB', CallingCodes::forCountry('GB'), '44');
check('calling code unknown', CallingCodes::forCountry('ZZ'), '');
check('calling code empty', CallingCodes::forCountry(''), '');
check('every calling code is digits', count(array_filter(CallingCodes::CODES, function ($code) {
    return !preg_match('/^[1-9]\d{0,3}$/', $code);
})), 0);

// ---------------------------------------------------------------------------------------------------
section('texts');
$values = array(
    'firstname' => 'Ana', 'lastname' => 'Pop', 'order_id' => '1042', 'total' => '$105.00',
    'store_name' => 'Ana &amp; Co', 'status' => 'Shipped', 'comment' => '',
);
check('empty placeholder leaves no trailing space', Template::render(DefaultTexts::customer(DefaultTexts::SHIPPED, 'en'), $values), 'Hello Ana, your order #1042 from Ana & Co is on its way.');
check('all placeholders', Template::render('{firstname} {lastname} {order_id} {total} {store_name} {status} [{comment}]', $values), 'Ana Pop 1042 $105.00 Ana & Co Shipped []');
check('owner text', Template::render(DefaultTexts::owner('en'), $values), 'Ana & Co: new order #1042 from Ana Pop, $105.00.');
check('romanian owner text', Template::render(DefaultTexts::owner('ro'), $values), 'Ana & Co: comanda noua #1042 de la Ana Pop, $105.00.');
check('comment on one line', Template::render('Track: {comment}', array('comment' => "1Z999\r\nAA10")), 'Track: 1Z999 AA10');
check('line breaks kept', Template::render("Hello {firstname},\r\nshipped.\n\n\n\nBye", $values), "Hello Ana,\nshipped.\n\nBye");
check('escaped template decoded', Template::render('Hi {firstname} &amp; welcome', $values), 'Hi Ana & welcome');
check('escaped names decoded', Template::render('{firstname}', array('firstname' => 'O&#039;Brien')), "O'Brien");
check('no-break spaces become spaces', Template::render('{total}', array('total' => "105,00\xC2\xA0€")), '105,00 €');
check('unknown placeholder kept', Template::render('{firstname} {nope}', $values), 'Ana {nope}');
check('cut to 1600 characters', Template::length(Template::render(str_repeat('ă', 2000), array())), 1600);
check('placeholder list', Template::placeholderList(), '{firstname} {lastname} {order_id} {total} {store_name} {status} {comment}');
foreach (DefaultTexts::TEXTS as $language => $texts) {
    foreach ($texts as $key => $text) {
        check("suggestion $language/$key stays in the basic SMS alphabet", preg_match('/^[A-Za-z0-9 .,!?#:{}_\'-]+$/', $text) === 1, true);
    }
}
check('language ro', DefaultTexts::language('ro-ro'), 'ro');
check('language en', DefaultTexts::language('en-gb'), 'en');
check('language other', DefaultTexts::language('de-de'), 'en');
$kinds = array(
    array(1, array('Pending'), 'pending'), array(2, array('Processing'), 'processing'), array(3, array('Shipped'), 'shipped'),
    array(5, array('Complete'), 'complete'), array(7, array('Canceled'), 'canceled'), array(8, array('Denied'), 'other'),
    array(9, array('Canceled Reversal'), 'other'), array(10, array('Failed'), 'other'), array(11, array('Refunded'), 'refunded'),
    array(12, array('Reversed'), 'other'), array(13, array('Chargeback'), 'other'), array(14, array('Expired'), 'other'),
    array(15, array('Processed'), 'processing'), array(16, array('Voided'), 'other'),
    array(3, array('Expediată'), 'shipped'), array(7, array('Anulată'), 'canceled'), array(5, array('Finalizată'), 'complete'),
    array(3, array('Wysłane'), 'shipped'), array(42, array('Custom'), 'other'), array(11, array(), 'refunded'),
);
foreach ($kinds as $case) {
    check('kind of ' . $case[0] . ' ' . implode('/', $case[1]), DefaultTexts::kindOf($case[0], $case[1]), $case[2]);
}
check('suggestion detected', DefaultTexts::isSuggestion(DefaultTexts::customer(DefaultTexts::SHIPPED, 'ro'), DefaultTexts::SHIPPED), true);
check('edited text is not a suggestion', DefaultTexts::isSuggestion('Your parcel left.', DefaultTexts::SHIPPED), false);

// ---------------------------------------------------------------------------------------------------
section('redaction');
check('number masked', Redact::number('+14155550123'), '+•••••••••23');
check('short number', Redact::number('12'), '12');
check('empty number', Redact::number(''), '');
// Test keys are built from parts, so that no file holds something a secret scanner takes for a real key.
$liveKey = 'sk_live_' . str_repeat('0123456789abcdef', 2);
$wrongKey = 'sk_test_' . 'wrongwrongwrongwrong01';
check('key removed', Redact::text('bad key ' . $liveKey . ' here'), 'bad key [API key] here');
check('numbers in a text masked', Redact::text('to +1 415 555 0123 failed'), 'to +•••••••••23 failed');
check('order ids, ports and IPs stay', Redact::text('order 1042 at 10.255.255.1 port 3101 after 3001 ms'), 'order 1042 at 10.255.255.1 port 3101 after 3001 ms');

// ---------------------------------------------------------------------------------------------------
section('API errors');
check('message', ApiException::messageOf(array('message' => 'Invalid or expired API key', 'statusCode' => 401), 401), 'Invalid or expired API key');
check('message list', ApiException::messageOf(array('message' => array('to must be a string', 'message too long')), 400), 'to must be a string; message too long');
check('error label', ApiException::messageOf(array('error' => 'Forbidden'), 403), 'Forbidden');
check('proxy page', ApiException::messageOf("<html><body>\n<h1>502 Bad Gateway</h1></body></html>", 502), '502 Bad Gateway');
check('no body', ApiException::messageOf(null, 503), 'SMSTunnel returned error: 503');
$e = new ApiException(403, array('message' => 'x', 'code' => 'PERMISSION_REQUIRED', 'permission' => 'sms:send'), array('retry-after' => '7'));
check('code, permission, retry-after', array($e->getStatus(), $e->getErrorCode(), $e->getPermission(), $e->getRetryAfter(), $e->getCode()), array(403, 'PERMISSION_REQUIRED', 'sms:send', 7, 403));
check('valid base url', Client::isValidBaseUrl('https://smstunnel.io/'), true);
check('invalid base url', Client::isValidBaseUrl('ftp://smstunnel.io'), false);

// ---------------------------------------------------------------------------------------------------
section('settings and the settings form');
$settings = new Settings(array(
    Settings::STATUS => '1', Settings::API_KEY => ' sk_test_abc ', Settings::COUNTRY => 'us',
    Settings::OWNER_NUMBERS => '+14155550100, +14155550101;+14155550102', Settings::CUSTOMER_STATUSES => '["3","5",0]',
    Settings::CUSTOMER_TEXTS => array('3' => ' Shipped! ', '5' => '', 'x' => 'bad'),
));
check('settings key trimmed', $settings->apiKey(), 'sk_test_abc');
check('settings default url', $settings->apiUrl(), 'https://smstunnel.io');
check('settings calling code', $settings->callingCode(), '1');
check('settings owner numbers', $settings->ownerNumbers(), array('+14155550100', '+14155550101', '+14155550102'));
check('settings statuses from JSON', $settings->customerStatuses(), array(3, 5));
check('settings texts', $settings->customerTexts(), array(3 => 'Shipped!'));

$t = function ($key) {
    return $key === 'error_owner_number' ? 'bad: %s' : ($key === 'error_owner_count' ? 'max %d' : ($key === 'error_text_length' ? 'max %d chars' : $key));
};
$form = new SettingsForm($t);
$saved = new Settings(array(Settings::API_KEY => $liveKey));
list($values, $errors) = $form->validate(array(
    'status' => '1', 'api_key' => '', 'device_id' => '6650c0ffee0000000000a001', 'api_url' => 'http://host.docker.internal:3999/',
    'country' => 'us', 'owner_status' => '1', 'owner_numbers' => '(415) 555-0100, +1 415 555 0100, 0040 721 123 456',
    'owner_text' => DefaultTexts::owner('ro'), 'customer_status' => '1', 'customer_statuses' => array('3', '5', '99'),
    'customer_texts' => array(3 => 'Shipped &amp; on its way: {comment}', 5 => DefaultTexts::customer(DefaultTexts::COMPLETE, 'en'), 7 => ''),
), $saved, array(1, 2, 3, 5, 7));
check('form valid', $errors, array());
check('form keeps the saved key', $values[Settings::API_KEY], $liveKey);
check('form url', $values[Settings::API_URL], 'http://host.docker.internal:3999');
check('form numbers normalized and deduplicated', $values[Settings::OWNER_NUMBERS], '+14155550100,+40721123456');
check('form suggestion not stored', $values[Settings::OWNER_TEXT], '');
check('form statuses of the store only', $values[Settings::CUSTOMER_STATUSES], array(3, 5));
check('form texts decoded, suggestions dropped', $values[Settings::CUSTOMER_TEXTS], array(3 => 'Shipped & on its way: {comment}'));
check('form values are strings', array($values[Settings::STATUS], $values[Settings::OWNER_STATUS]), array('1', '1'));
list($values, $errors) = $form->validate(array(
    'api_key' => 'not-a-key', 'device_id' => 'bad id!', 'api_url' => 'smstunnel.io', 'country' => 'ZZ',
    'owner_status' => '1', 'owner_numbers' => '12, +1 900 555 0123', 'owner_text' => str_repeat('x', 1601),
    'customer_texts' => array(3 => str_repeat('y', 1601)),
), $saved, array(3));
check('form errors', array_keys($errors), array('api_key', 'device_id', 'api_url', 'country', 'owner_numbers', 'owner_text', 'customer_text_3'));
check('form error lists the bad numbers', $errors['owner_numbers'], 'bad: 12, +1 900 555 0123');
list($values, $errors) = $form->validate(array('owner_status' => '1', 'owner_numbers' => ''), $saved, array());
check('form needs a number when owner texts are on', array_keys($errors), array('owner_numbers'));
list($values, $errors) = $form->validate(array('owner_numbers' => '+14155550100,+14155550101,+14155550102,+14155550103,+14155550104,+14155550105'), $saved, array());
check('form at most 5 numbers', $errors, array('owner_numbers' => 'max 5'));

// ---------------------------------------------------------------------------------------------------
section('log of texts (SQLite)');
$db = new SqliteDb();
$log = new TextLog($db, 'oc_');
$text = array('dedupe_key' => 'o:1:s:3', 'kind' => 'customer', 'order_id' => 1, 'order_status_id' => 3, 'store_id' => 0, 'recipient' => '+14155550123', 'message' => 'Hello');
$id = $log->claim($text);
check('claim a new text', $id > 0, true);
check('claim it again: nothing', $log->claim($text), 0);
$log->finish($id, TextLog::SENT, 'm1');
$row = $log->find($id);
check('a sent text keeps no number and no words', array($row['recipient'], $row['message'], $row['message_id']), array('+•••••••••23', null, 'm1'));
check('a sent text is never claimed again', $log->claim($text), 0);
$text2 = array_merge($text, array('dedupe_key' => 'o:2:s:3', 'order_id' => 2));
$id2 = $log->claim($text2);
$log->finish($id2, TextLog::FAILED, '', 'HTTP 500: boom for +14155550123');
$row = $log->find($id2);
check('a failed text keeps the number and words (to send again)', array($row['recipient'], $row['message']), array('+14155550123', 'Hello'));
check('a failed text\'s error hides numbers', $row['error'], 'HTTP 500: boom for +•••••••••23');
check('a failed text can be claimed again', $log->claim($text2), $id2);
check('attempts counted', (int) $log->find($id2)['attempts'], 2);
$log->finish($id2, TextLog::FAILED, '', 'again');
check('retry a failed text', $log->retry($id2), true);
check('retry twice: no', $log->retry($id2), false);
$log->finish($id2, TextLog::QUEUED, 'm2');
check('last failure', $log->lastFailure(), null);
$id3 = $log->claim(array_merge($text, array('dedupe_key' => null, 'kind' => 'test', 'order_id' => 0)));
$id4 = $log->claim(array_merge($text, array('dedupe_key' => null, 'kind' => 'test', 'order_id' => 0)));
check('texts without a key are never deduplicated', $id3 > 0 && $id4 > $id3, true);
check('recent newest first', array_map(function ($r) {
    return (int) $r['smstunnel_log_id'];
}, $log->recent(3)), array($id4, $id3, $id2));
$log->finish($id3, TextLog::SENT, 'm3');
$log->setDelivery($id3, 'delivered');
check('awaiting delivery', array_map(function ($r) {
    return $r['message_id'];
}, $log->awaitingDelivery()), array('m2', 'm1'));
$log->setDelivery($id, 'sent');
check('a text just asked about waits 2 minutes', array_map(function ($r) {
    return $r['message_id'];
}, $log->awaitingDelivery()), array('m2'));
check('isAwaiting agrees', array(TextLog::isAwaiting($log->find($id), time()), TextLog::isAwaiting($log->find($id), time() + 300)), array(false, true));
$db->query("UPDATE oc_smstunnel_log SET date_added = '" . date('Y-m-d H:i:s', time() - 7200) . "', date_modified = '" . date('Y-m-d H:i:s', time() - 7200) . "' WHERE smstunnel_log_id = $id");
check('a text the phone sent is not asked about after an hour', TextLog::isAwaiting($log->find($id), time()), false);
check('masking twice changes nothing', Redact::number(Redact::number('+14155550123')), '+•••••••••23');
for ($i = 0; $i < 1005; $i++) {
    $log->claim(array_merge($text, array('dedupe_key' => null, 'kind' => 'test', 'order_id' => 0)));
}
$log->prune();
check('prune keeps the newest 1000', (int) $db->query('SELECT COUNT(*) AS n FROM oc_smstunnel_log')->row['n'], 1000);
$kept = $log->claim(array_merge($text, array('dedupe_key' => 'o:9:s:3', 'order_id' => 9)));
$log->finish($kept, TextLog::SENT, 'm9');
$lost = $log->claim(array_merge($text, array('dedupe_key' => 'o:9:s:5', 'order_id' => 9, 'order_status_id' => 5)));
$log->finish($lost, TextLog::FAILED, '', 'HTTP 500: x');
$log->clear();
check('clear empties the list', count($log->recent()), 0);
check('clear: test texts go for good, order texts keep no number and no words', array((int) $db->query('SELECT COUNT(*) AS n FROM oc_smstunnel_log WHERE dedupe_key IS NULL')->row['n'], $log->find($lost)['recipient'], $log->find($lost)['message']), array(0, '', null));
check('clear: a text already sent is still never sent twice', $log->claim(array_merge($text, array('dedupe_key' => 'o:9:s:3', 'order_id' => 9))), 0);
check('clear: a text that did not go out can still go when the event comes again', $log->claim(array_merge($text, array('dedupe_key' => 'o:9:s:5', 'order_id' => 9, 'order_status_id' => 5))), $lost);
check('clear: and it is back in the list', count($log->recent()), 1);
$unconfirmed = $log->claim(array_merge($text, array('dedupe_key' => 'o:10:s:3', 'order_id' => 10)));
$log->finish($unconfirmed, TextLog::UNCONFIRMED, '', 'No answer from SMSTunnel at http://x: Operation timed out');
check('an unconfirmed text keeps its number and words, for Send again', array($log->find($unconfirmed)['recipient'], $log->find($unconfirmed)['message']), array('+14155550123', 'Hello'));
check('an unconfirmed text is never claimed again on its own', $log->claim(array_merge($text, array('dedupe_key' => 'o:10:s:3', 'order_id' => 10))), 0);
check('the store owner can send an unconfirmed text again', $log->retry($unconfirmed), true);

// ---------------------------------------------------------------------------------------------------
section('warnings never reach the store\'s error handler');
$outerCalls = 0;
set_error_handler(function () use (&$outerCalls) {
    // Like OpenCart's handler, which prints the warning or exits.
    ++$outerCalls;

    return true;
});
try {
    SmsTunnel\OpenCart\Guard::run(function () {
        trigger_error('a warning in the middle of a text', E_USER_WARNING);
    });
    check('guard: a warning becomes an exception', 'no exception', 'ErrorException');
} catch (ErrorException $e) {
    check('guard: a warning becomes an exception', $e->getMessage(), 'a warning in the middle of a text');
}
check('guard: the store\'s handler never sees it', $outerCalls, 0);
check('guard: the result comes back', SmsTunnel\OpenCart\Guard::run(function () {
    return 42;
}), 42);
SmsTunnel\OpenCart\Guard::quietly(function () {
    file_put_contents('/proc/not/writable/error.log', 'x');
});
check('quietly: a log that cannot be written is ignored', $outerCalls, 0);
trigger_error('after the guard', E_USER_NOTICE);
check('guard: the store\'s handler is back afterwards', $outerCalls, 1);
restore_error_handler();

// ---------------------------------------------------------------------------------------------------
section('admin wording');
$languages = array();
foreach (array('en-gb', 'ro-ro') as $code) {
    $_ = array();
    require $root . '/src/language/' . $code . '.php';
    $languages[$code] = $_;
}
check('both languages have the same keys', array_values(array_diff(array_keys($languages['en-gb']), array_keys($languages['ro-ro']))), array());
check('both languages have the same keys (2)', array_values(array_diff(array_keys($languages['ro-ro']), array_keys($languages['en-gb']))), array());
$used = array();
$sources = array_merge(glob($root . '/src/core/smstunnel/*.php'), glob($root . '/src/core/smstunnel/*/*.php'), array(
    $root . '/src/oc3/upload/admin/controller/extension/module/smstunnel.php', $root . '/src/oc4/admin/controller/module/smstunnel.php',
));
foreach ($sources as $file) {
    preg_match_all("/(?:->t|language->get)\\('([a-z0-9_]+)'\\)/", (string) file_get_contents($file), $matches);
    $used = array_merge($used, $matches[1]);
}
foreach (array($root . '/src/oc3/upload/admin/view/template/extension/module/smstunnel.twig', $root . '/src/oc4/admin/view/template/module/smstunnel.twig') as $file) {
    preg_match_all('/\{\{\s*((?:text|entry|column|button|error)_[a-z0-9_]+)/', (string) file_get_contents($file), $matches);
    $used = array_merge($used, $matches[1]);
}
// Provided by OpenCart's own language file, or computed by the controllers.
$fromOpenCart = array('column_left', 'text_home', 'text_enabled', 'text_disabled', 'text_yes', 'text_no', 'button_save', 'button_cancel', 'button_back', 'error_warning', 'text_key_hint');
$missing = array_values(array_diff(array_unique($used), array_keys($languages['en-gb']), $fromOpenCart));
check('every wording used exists', $missing, array());
$pageDb = new SqliteDb();
$page = new SmsTunnel\OpenCart\Admin\Page($pageDb, 'oc_', 'tests', function ($key) use ($languages) {
    return $languages['en-gb'][$key];
});
check('detail: skipped number', $page->detail('number:invalid'), $languages['en-gb']['text_reason_invalid']);
check('detail: refused key', $page->detail('HTTP 401: Invalid or expired API key'), $languages['en-gb']['error_api_401']);
check('detail: not tried after a refused key', $page->detail('Not tried: HTTP 401: Invalid or expired API key'), sprintf($languages['en-gb']['text_not_tried'], $languages['en-gb']['error_api_401']));
check('detail: rate limit', $page->detail('HTTP 429: ThrottlerException: Too Many Requests'), sprintf($languages['en-gb']['error_api_429'], 'ThrottlerException: Too Many Requests'));
check('detail: server error', $page->detail('HTTP 502: 502 Bad Gateway'), sprintf($languages['en-gb']['error_api_5xx'], 502));
check('detail: anything else as it is', $page->detail('HTTP 400: Invalid phone number'), 'HTTP 400: Invalid phone number');

// ---------------------------------------------------------------------------------------------------
$fake = getenv('SMSTUNNEL_FAKE_URL');
$fakeKey = getenv('SMSTUNNEL_FAKE_KEY') ?: 'sk_test_' . 'FakeOpenCartKey00000000000001';
if ($fake && preg_match('/smstunnel\.io/i', $fake)) {
    echo "Refusing to run against production.\n";
    exit(1);
}

function fake($path, $body = null)
{
    $curl = curl_init(rtrim(getenv('SMSTUNNEL_FAKE_URL'), '/') . $path);
    curl_setopt_array($curl, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5));
    if ($body !== null) {
        curl_setopt_array($curl, array(CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body), CURLOPT_HTTPHEADER => array('Content-Type: application/json')));
    }
    $raw = curl_exec($curl);

    return json_decode((string) $raw, true);
}

function fakeRequests($path = '/api/v1/sms/send')
{
    $all = fake('/__requests');

    return array_values(array_filter($all['requests'], function ($r) use ($path) {
        return $r['path'] === $path;
    }));
}

if (!$fake) {
    echo "(API part skipped: start tests/fake-api/server.mjs and set SMSTUNNEL_FAKE_URL to run it)\n";
} else {
    section('API client against the fake SMSTunnel API');
    fake('/__reset', array());
    $client = new Client($fakeKey, $fake, 5, 2, 'SMSTunnel-OpenCart-tests');
    $sent = $client->sendSms('+14155550123', 'Ünïcode ✓ test', '6650c0ffee0000000000a001', '+1');
    check('send: message id', strlen($sent['messageId']), 24);
    check('send: not queued', $sent['queued'], false);
    $request = fakeRequests()[0];
    check('send: body', $request['body'], array('to' => '+14155550123', 'message' => 'Ünïcode ✓ test', 'deviceId' => '6650c0ffee0000000000a001', 'defaultCountryCode' => '+1'));
    check('send: key, JSON, accept, user agent', array($request['keyOk'], $request['contentType'], $request['accept'], $request['userAgent']), array(true, 'application/json', 'application/json', 'SMSTunnel-OpenCart-tests'));
    $queued = $client->sendSms('+14155550124', 'x', '6650c0ffee0000000000a002');
    check('send to an offline phone: queued', $queued['queued'], true);
    $without = $client->sendSms('+14155550125', 'x');
    check('send without phone or country: only to and message', fakeRequests()[2]['body'], array('to' => '+14155550125', 'message' => 'x'));
    $devices = $client->getDevices();
    check('devices', array(count($devices), $devices[0]['id'], $devices[0]['isOnline'], $devices[1]['isOnline']), array(2, '6650c0ffee0000000000a001', true, false));
    $status = $client->getMessageStatus($sent['messageId']);
    check('status', array($status['id'], $status['phoneNumber'], $status['status'], $status['deliveryStatus']), array($sent['messageId'], '+14155550123', 'sent', 'sent'));
    $statusRequest = fakeRequests('/api/v1/sms/status/' . $sent['messageId'])[0];
    check('status: GET without a body', array($statusRequest['method'], $statusRequest['contentType'], $statusRequest['body']), array('GET', '', null));
    fake('/__control', array('allTextsStatus' => 'unconfirmed'));
    $status = $client->getMessageStatus($sent['messageId']);
    check('status unconfirmed', array($status['status'], $status['deliveryStatus'], $status['unconfirmed'], $status['errorMessage']), array('failed', 'unconfirmed', true, 'Not confirmed by the phone'));
    try {
        $client->getMessageStatus('000000000000000000000000');
        check('unknown message: 404', 'no exception', 404);
    } catch (ApiException $e) {
        check('unknown message: 404', $e->getStatus(), 404);
    }

    $errorsByMode = array(
        'auth' => array(401, null, 'Invalid or expired API key'),
        'forbidden' => array(403, 'PERMISSION_REQUIRED', "This API key lacks the 'sms:send' permission"),
        'rate' => array(429, null, 'ThrottlerException: Too Many Requests'),
        'daily' => array(429, null, "You reached today's limit of 500 texts on the Starter plan. More can go out tomorrow, or on a higher plan."),
        'error500' => array(500, null, 'Internal server error'),
        'html502' => array(502, null, '502 Bad Gateway502 Bad Gatewaynginx'),
        'notjson' => array(200, null, 'SMSTunnel answered with something that is not JSON.'),
    );
    foreach ($errorsByMode as $mode => $expected) {
        fake('/__control', array('sendMode' => $mode, 'times' => 1));
        try {
            $client->sendSms('+14155550123', 'x');
            check("$mode: refused", 'accepted', $expected[0]);
        } catch (ApiException $e) {
            check("$mode: refused", array($e->getStatus(), $e->getErrorCode(), $e->getMessage()), $expected);
            if ($mode === 'rate') {
                check('rate: Retry-After read', $e->getRetryAfter(), 42);
            }
        }
    }
    try {
        (new Client($wrongKey, $fake))->getDevices();
        check('wrong key: 401', 'accepted', 401);
    } catch (ApiException $e) {
        check('wrong key: 401', array($e->getStatus(), $e->getMessage()), array(401, 'Invalid or expired API key'));
    }
    try {
        (new Client('', $fake))->sendSms('+14155550123', 'x');
        check('empty key: 401 before any call', 'sent', 401);
    } catch (ApiException $e) {
        check('empty key: 401 before any call', $e->getStatus(), 401);
    }
    fake('/__control', array('sendMode' => 'hang', 'times' => 1));
    $started = microtime(true);
    try {
        (new Client($fakeKey, $fake, 2, 1))->sendSms('+14155550123', 'x');
        check('no answer: transport error', 'answered', 'TransportException');
    } catch (TransportException $e) {
        check('no answer: transport error, and the text may have arrived', array($e->mayHaveArrived(), strpos($e->getMessage(), 'No answer from SMSTunnel') === 0), array(true, true));
    }
    check('no answer: ends at the timeout', microtime(true) - $started < 3.5, true);
    fake('/__control', array('sendMode' => 'drop', 'times' => 1));
    try {
        $client->sendSms('+14155550123', 'x');
        check('dropped connection: transport error', 'answered', 'TransportException');
    } catch (TransportException $e) {
        check('dropped connection: transport error, and the text may have arrived', $e->mayHaveArrived(), true);
    }
    $started = microtime(true);
    try {
        (new Client($fakeKey, 'http://127.0.0.1:9', 3, 2))->sendSms('+14155550123', 'x');
        check('closed port: transport error', 'answered', 'TransportException');
    } catch (TransportException $e) {
        check('closed port: transport error, the text surely did not arrive', array(strpos($e->getMessage(), 'Could not reach SMSTunnel at http://127.0.0.1:9') === 0, $e->mayHaveArrived()), array(true, false));
    }
    check('closed port: fails fast', microtime(true) - $started < 3, true);

    // -----------------------------------------------------------------------------------------------
    section('order texts (SQLite store, fake SMSTunnel API)');
    fake('/__reset', array());
    Notifier::reset();
    $db = new SqliteDb();
    $db->query("INSERT INTO oc_language (language_id, code) VALUES (1, 'en-gb'), (2, 'ro-ro')");
    $db->query("INSERT INTO oc_country (country_id, name, iso_code_2) VALUES (223, 'United States', 'US'), (175, 'Romania', 'RO'), (97, 'Hungary', 'HU')");
    foreach (array(1 => 'Pending', 2 => 'Processing', 3 => 'Shipped', 5 => 'Complete', 7 => 'Canceled', 11 => 'Refunded') as $sid => $name) {
        $db->query("INSERT INTO oc_order_status (order_status_id, language_id, name) VALUES ($sid, 1, '$name')");
    }
    $db->query("INSERT INTO oc_order_status (order_status_id, language_id, name) VALUES (3, 2, 'Expediată')");
    $db->query("INSERT INTO oc_setting (store_id, code, `key`, value) VALUES (0, 'config', 'config_language_admin', 'en-gb'), (0, 'config', 'config_name', 'Demo Store'), (0, 'config', 'config_country_id', '223')");
    $db->query("INSERT INTO oc_order VALUES (101, 0, 'Demo Store', 'Ana', 'Pop', '(415) 555-0123', 105.0, 'USD', 1.0, 1, 0, 0, 223, 223)");
    $db->query("INSERT INTO oc_order VALUES (102, 0, 'Demo Store', 'Ion', 'Ionescu', '0721 123 456', 50.0, 'EUR', 0.9, 2, 0, 0, 175, 175)");
    $db->query("INSERT INTO oc_order VALUES (103, 0, 'Demo Store', 'Bad', 'Number', '12', 10.0, 'USD', 1.0, 1, 0, 0, 223, 223)");
    $db->query("INSERT INTO oc_order VALUES (104, 0, 'Demo Store', 'Pat', 'Premium', '+1 900 555 0123', 10.0, 'USD', 1.0, 1, 0, 0, 223, 223)");
    $settings = new Settings(array(
        Settings::STATUS => '1', Settings::API_KEY => $fakeKey, Settings::API_URL => $fake, Settings::COUNTRY => 'US',
        Settings::OWNER_STATUS => '1', Settings::OWNER_NUMBERS => '+14155550100,+14155550101',
        Settings::CUSTOMER_STATUS => '1', Settings::CUSTOMER_STATUSES => array(3, 5, 7, 11),
        Settings::CUSTOMER_TEXTS => array(5 => 'Order #{order_id} is complete. {comment}'),
    ));
    $lines = array();
    $notifier = new Notifier($db, 'oc_', 'SMSTunnel-OpenCart-tests', function ($amount, $currency, $value) {
        return ($currency === 'EUR' ? '' : '$') . number_format($amount * $value, 2) . ($currency === 'EUR' ? '€' : '');
    }, function ($line) use (&$lines) {
        $lines[] = $line;
    }, $settings);

    $changeStatus = function ($orderId, $statusId, $comment = '', $notify = false) use ($db, $notifier) {
        $notifier->before($orderId);
        $db->query("UPDATE oc_order SET order_status_id = $statusId WHERE order_id = $orderId");
        $db->query("INSERT INTO oc_order_history (order_id, order_status_id, notify, comment) VALUES ($orderId, $statusId, " . ($notify ? 1 : 0) . ", '" . $db->escape($comment) . "')");
        $notifier->after($orderId, $comment, $notify);
    };

    $changeStatus(101, 1); // checkout: Pending
    $sends = fakeRequests();
    check('new order: one text to each owner number', array_map(function ($r) {
        return $r['body']['to'];
    }, $sends), array('+14155550100', '+14155550101'));
    check('new order: owner text', $sends[0]['body']['message'], 'Demo Store: new order #101 from Ana Pop, $105.00.');
    check('new order: default country code sent', $sends[0]['body']['defaultCountryCode'], '+1');
    check('new order: Pending not ticked, no customer text', count($sends), 2);

    $changeStatus(101, 3, 'Tracking 1Z999AA10', true);
    $sends = fakeRequests();
    check('shipped: customer texted at the order telephone', $sends[2]['body']['to'], '+14155550123');
    check('shipped: suggested text with the comment', $sends[2]['body']['message'], 'Hello Ana, your order #101 from Demo Store is on its way. Tracking 1Z999AA10');
    $changeStatus(101, 3, 'Again', true);
    check('same status saved again: nothing', count(fakeRequests()), 3);
    $changeStatus(101, 2);
    check('unticked status: nothing', count(fakeRequests()), 3);
    $changeStatus(101, 3, 'Back to shipped', true);
    check('a status already texted: nothing', count(fakeRequests()), 3);
    $notifier->after(101, '', false);
    check('an after() without before(): nothing new', count(fakeRequests()), 3);
    $changeStatus(101, 5, 'Internal note', false);
    $sends = fakeRequests();
    check('complete: the owner\'s own text, comment not shared without Notify customer', $sends[3]['body']['message'], 'Order #101 is complete.');

    $changeStatus(102, 1);
    $changeStatus(102, 3);
    $sends = fakeRequests();
    check('romanian order: owner text in the admin language, order currency', $sends[4]['body']['message'], 'Demo Store: new order #102 from Ion Ionescu, 45.00€.');
    check('romanian order: number completed with the delivery country', $sends[6]['body']['to'], '+40721123456');
    check('romanian order: country code of the address', $sends[6]['body']['defaultCountryCode'], '+40');
    check('romanian order: suggested text in Romanian', $sends[6]['body']['message'], 'Buna, Ion! Comanda #102 de la Demo Store a fost expediata.');

    // A gift: billed in Romania, shipped to Hungary. The telephone is the customer's own: +40, not +36.
    $db->query("INSERT INTO oc_order VALUES (108, 0, 'Demo Store', 'Ana', 'Gift', '0721 123 457', 50.0, 'EUR', 0.9, 2, 0, 0, 97, 175)");
    $changeStatus(108, 1);
    $changeStatus(108, 3);
    $all = fakeRequests();
    check('a gift shipped abroad: the number gets the billing country code', array($all[count($all) - 1]['body']['to'], $all[count($all) - 1]['body']['defaultCountryCode']), array('+40721123457', '+40'));

    // No answer from SMSTunnel after the text left: not confirmed, never sent again on its own.
    $db->query("INSERT INTO oc_order VALUES (109, 0, 'Demo Store', 'Hang', 'Test', '(415) 555-0129', 10.0, 'USD', 1.0, 1, 0, 0, 223, 223)");
    $hangSettings = new Settings(array_merge($settings->toArray(), array(Settings::OWNER_STATUS => '0')));
    $hang = new Notifier($db, 'oc_', 'tests', null, null, $hangSettings);
    $changeStatus(109, 1);
    fake('/__control', array('sendMode' => 'hang', 'times' => 1));
    $hang->before(109);
    $db->query('UPDATE oc_order SET order_status_id = 3 WHERE order_id = 109');
    $started = microtime(true);
    $hang->after(109);
    $row = $hang->log()->forOrder(109)[count($hang->log()->forOrder(109)) - 1];
    check('no answer: the event ends at the 8 s timeout', microtime(true) - $started < 10, true);
    check('no answer: the text is marked not confirmed, number and words kept', array($row['status'], $row['recipient'], strpos((string) $row['error'], 'No answer from SMSTunnel') === 0), array('unconfirmed', '+14155550129', true));
    Notifier::reset();
    $sendsBefore = count(fakeRequests());
    $hang->before(109);
    $hang->after(109, 'again', true);
    $db->query('UPDATE oc_order SET order_status_id = 2 WHERE order_id = 109');
    $hang->before(109);
    $db->query('UPDATE oc_order SET order_status_id = 3 WHERE order_id = 109');
    $hang->after(109);
    check('no answer: the same status again sends nothing on its own', count(fakeRequests()), $sendsBefore);
    $result = $hang->resend($hangSettings, (int) $row['smstunnel_log_id']);
    check('no answer: Send again (the store owner\'s decision) sends it', $result['status'], TextLog::SENT);

    $before = count(fakeRequests());
    $changeStatus(103, 1);
    $changeStatus(103, 3);
    $changeStatus(104, 1);
    $changeStatus(104, 3);
    $customerSends = array_filter(array_slice(fakeRequests(), $before), function ($r) {
        return !in_array($r['body']['to'], array('+14155550100', '+14155550101'), true);
    });
    check('invalid and premium numbers: nothing sent to them', count($customerSends), 0);
    $skipped = array();
    foreach ($notifier->log()->forOrder(103) as $row) {
        $skipped[] = $row['kind'] . ':' . $row['status'] . ':' . $row['error'];
    }
    foreach ($notifier->log()->forOrder(104) as $row) {
        $skipped[] = $row['kind'] . ':' . $row['status'] . ':' . $row['error'];
    }
    check('invalid and premium numbers: skipped with the reason', array_values(array_filter($skipped, function ($s) {
        return strpos($s, 'customer:') === 0;
    })), array('customer:skipped:number:invalid', 'customer:skipped:number:premium'));

    // SMSTunnel down: the first text fails fast, the next ones of the request are not tried.
    $db->query("INSERT INTO oc_order VALUES (105, 0, 'Demo Store', 'Down', 'Test', '(415) 555-0126', 10.0, 'USD', 1.0, 1, 0, 0, 223, 223)");
    $downSettings = new Settings(array_merge($settings->toArray(), array(Settings::API_URL => 'http://127.0.0.1:9')));
    $down = new Notifier($db, 'oc_', 'tests', null, function ($line) use (&$lines) {
        $lines[] = $line;
    }, $downSettings);
    $started = microtime(true);
    $down->before(105);
    $db->query('UPDATE oc_order SET order_status_id = 1 WHERE order_id = 105');
    $down->after(105);
    check('SMSTunnel down: the event returns quickly', microtime(true) - $started < 4, true);
    $rows = $down->log()->forOrder(105);
    check('SMSTunnel down: both owner texts failed', array_map(function ($r) {
        return $r['status'];
    }, $rows), array('failed', 'failed'));
    check('SMSTunnel down: the second one was not tried', strpos($rows[1]['error'], 'Not tried: Could not reach SMSTunnel at http://127.0.0.1:9') === 0, true);
    check('SMSTunnel down: logged without numbers', count(array_filter($lines, function ($l) {
        return strpos($l, 'SMSTunnel: the owner text about order 105 to +•••••••••0') === 0 && strpos($l, '4155550100') === false;
    })), 2);
    Notifier::reset();
    $result = $notifier->resend($settings, (int) $rows[0]['smstunnel_log_id']);
    check('send again: sent', $result['status'], TextLog::SENT);
    $row = $notifier->log()->find((int) $rows[0]['smstunnel_log_id']);
    check('send again: the row now keeps no number', array($row['status'], $row['recipient'], $row['message'], (int) $row['attempts']), array('sent', '+•••••••••00', null, 2));
    check('send again twice: refused', $notifier->resend($settings, (int) $rows[0]['smstunnel_log_id']), null);
    $all = fakeRequests();
    check('send again: the same words', $all[count($all) - 1]['body']['message'], 'Demo Store: new order #105 from Down Test, 10.00 USD.');

    // The phone's answer, through GET /api/v1/sms/status/:id.
    fake('/__control', array('allTextsStatus' => 'delivered'));
    $updated = $notifier->refreshDeliveries($settings, 50);
    check('delivery states read', count($updated) > 0 && count(array_unique(array_values($updated))) === 1 && reset($updated) === 'delivered', true);
    $lastId = (int) $db->query('SELECT MAX(smstunnel_log_id) AS id FROM oc_smstunnel_log WHERE message_id IS NOT NULL')->row['id'];
    $db->query("UPDATE oc_smstunnel_log SET delivery = NULL WHERE smstunnel_log_id = $lastId");
    fake('/__reset', array());
    $notifier->refreshDeliveries($settings, 50);
    $row = $notifier->log()->find($lastId);
    check('a text SMSTunnel does not know (404) is not asked about again', array($row['delivery'], TextLog::isAwaiting($row, time() + 600)), array('unknown', false));

    // A refused key: failed, logged, and the error tells why.
    $authSettings = new Settings(array_merge($settings->toArray(), array(Settings::API_KEY => $wrongKey)));
    $auth = new Notifier($db, 'oc_', 'tests', null, null, $authSettings);
    $db->query("INSERT INTO oc_order VALUES (106, 0, 'Demo Store', 'Key', 'Test', '(415) 555-0127', 10.0, 'USD', 1.0, 1, 0, 0, 223, 223)");
    $auth->before(106);
    $db->query('UPDATE oc_order SET order_status_id = 1 WHERE order_id = 106');
    $auth->after(106);
    $rows = $auth->log()->forOrder(106);
    check('refused key: failed with the reason', $rows[0]['error'], 'HTTP 401: Invalid or expired API key');

    // Turned off, or no key: nothing.
    $before = count(fakeRequests());
    $off = new Notifier($db, 'oc_', 'tests', null, null, new Settings(array_merge($settings->toArray(), array(Settings::STATUS => '0'))));
    $db->query("INSERT INTO oc_order VALUES (107, 0, 'Demo Store', 'Off', 'Test', '(415) 555-0128', 10.0, 'USD', 1.0, 1, 0, 0, 223, 223)");
    $off->before(107);
    $db->query('UPDATE oc_order SET order_status_id = 1 WHERE order_id = 107');
    $off->after(107);
    check('turned off: nothing sent', count(fakeRequests()), $before);
    check('every send carried the right key', count(array_filter(fakeRequests(), function ($r) {
        return !$r['keyOk'];
    })), 1);
}

printf("PHP %s: %d passed, %d failed\n", PHP_VERSION, $passed, $failed);
exit($failed > 0 ? 1 : 0);
