# Changelog

All notable changes to SMSTunnel for OpenCart. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow [Semantic Versioning](https://semver.org/).

## [1.0.0] - 2026-11-09

First version, for OpenCart 3.0.x (`smstunnel-oc3.ocmod.zip`) and OpenCart 4.0.2.x / 4.1.x (`smstunnel.ocmod.zip`). Tested on OpenCart 3.0.5.1 (PHP 8.3), 3.0.3.8 (PHP 7.4), 4.0.2.3 (PHP 8.2) and 4.1.0.4 (PHP 8.3).

- A text to your own numbers (up to 5) for every new order.
- A text to the customer when the order moves to a status you tick (the store's Shipped, Complete, Canceled and Refunded statuses are ticked at install), with a text per status. Placeholders: `{firstname} {lastname} {order_id} {total} {store_name} {status} {comment}`; `{comment}` is the comment typed with the status change, shared only when Notify Customer is ticked.
- Suggested texts in English and Romanian, picked by the language of each order.
- The customer's number is the order's telephone, completed with the calling code of the billing country (then the delivery country, then the default country of the settings, preset from the store's country); `+49 (0) 170 …` loses its `(0)`. Invalid numbers, premium-rate numbers, short codes and satellite networks are skipped.
- Each order text is sent once, even when OpenCart runs the same event twice or an order gets the same status again.
- Checkout and status changes never wait long and never fail: 3 s to connect, 8 s in all; after a failure the rest of the page's texts are not tried; a PHP warning raised while a text is sent never reaches OpenCart's error handler (which would stop the page). Failures go to the extension's list and to OpenCart's error log, without numbers in full, words or the key.
- Settings page: API key (shown masked), your phones from SMSTunnel with their state and a phone picker, default country, a test text, the last 1000 texts with their status (numbers masked to the last 2 digits), what the phone did with them (`GET /api/v1/sms/status`), **Send again** for a text that did not go out or whose sending SMSTunnel did not confirm (a timeout after the text left is "not confirmed" and never sent again on its own), and **Clear the list** (which keeps the keys that stop a text going out twice). On OpenCart 4, a warning while checkout does not ask for a telephone.
- Admin pages in English and Romanian.
- On OpenCart 3.0 with a PHP older than 7.1, the extension does nothing: its page says which PHP it needs, installing it sets nothing up, and checkout is left alone. After a PHP upgrade, uninstall it and install it again.
- Uninstalling removes the events, the settings and the list of texts.

[1.0.0]: https://github.com/narcisbodea/smstunnel-opencart/releases/tag/v1.0.0
