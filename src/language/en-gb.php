<?php
// SMSTunnel for OpenCart. GPL-3.0-or-later, (c) 2026 NARBOWEB SRL.

// Heading
$_['heading_title']             = 'SMSTunnel: SMS from your own phone';

// Text
$_['text_extension']            = 'Extensions';
$_['text_edit']                 = 'Settings';
$_['text_success']              = 'Settings saved.';
$_['text_intro']                = 'Your store hands each text to your SMSTunnel account, and your Android phone sends it from its own SIM. Customers see your number and can reply to it.';
$_['text_panel_phone']          = 'SMSTunnel: texts from your own phone';
$_['text_test_server']          = 'Test mode: texts go to this test server, not to smstunnel.io. Change it under Advanced.';
$_['text_steps']                = 'Get started in three steps';
$_['text_step_app']             = 'Install the SMSTunnel app on your Android phone and sign in.';
$_['text_step_key']             = 'In your SMSTunnel dashboard, open Apps, then Add an app, and copy its key.';
$_['text_step_save']            = 'Paste the key below, add your number and save. Then send yourself a test text.';
$_['text_google_play']          = 'Get it on Google Play';
$_['text_dashboard']            = 'Open the dashboard';
$_['text_phones']               = 'Your phones';
$_['text_no_phones']            = 'No phone is linked to your account yet. Install the SMSTunnel app on your Android phone and sign in, or link it from Phones, then Add phone, in your dashboard.';
$_['text_online']               = 'Online';
$_['text_offline']              = 'Offline';
$_['text_sends_texts']          = 'Sends your texts';
$_['text_last_seen']            = 'Last seen %s';
$_['text_offline_help']         = 'When the phone is offline, texts wait on your account and go out as soon as it is back online.';
$_['text_device_unknown']       = 'The phone saved below is not one of these phones. Choose another one, or "Any of my phones".';
$_['text_telephone_off']        = 'Your checkout does not ask for a telephone number, so customers cannot get texts. Turn on Telephone Display and Telephone Required in System > Settings > edit your store > Option.';
$_['text_test_to']              = 'Send a test text to';
$_['text_last_failure']         = 'Last text that did not go out:';
$_['text_account']              = 'Your SMSTunnel account';
$_['text_key_saved']            = 'A key is saved (%s). Leave this field empty to keep it.';
$_['text_key_help']             = 'In your SMSTunnel dashboard, open Apps, then Add an app, and copy its key.';
$_['text_any_phone']            = 'Any of my phones';
$_['text_device_help']          = 'The phone that sends your texts. "Any of my phones" lets SMSTunnel use one of your phones that is online.';
$_['text_device_manual']        = 'Optional. Leave it empty to send from any of your phones, or copy a phone ID from Phones in your SMSTunnel dashboard.';
$_['text_country_help']         = 'Numbers written without + or 00 get the calling code of the order\'s billing country (then of its delivery country); when the order has neither, this one. Your own numbers below get this one too.';
$_['text_owner']                = 'New orders: a text to you';
$_['text_owner_numbers_help']   = 'One or more numbers (at most %d), separated by commas, in international format such as +14155550123.';
$_['text_placeholders']         = 'You can use: %s';
$_['text_customer']             = 'Order updates: a text to the customer';
$_['text_customer_help']        = 'The text goes to the telephone of the order. Customers can reply: the answer reaches your phone. {comment} is the comment typed with the status change, shared only when Notify customer is ticked (put the tracking number there).';
$_['text_statuses_help']        = 'Tick the statuses that text the customer. Each order gets each of these texts once.';
$_['text_for_status']           = 'Text for "%s"';
$_['text_suggested_help']       = 'Leave a text as suggested, or empty, and each customer gets the suggested text in the language of the order.';
$_['text_advanced']             = 'Advanced';
$_['text_api_url_help']         = 'For testing only. Your store uses https://smstunnel.io: change it only to try the extension with a test server.';
$_['text_recent']               = 'Recent texts';
$_['text_no_texts']             = 'No texts yet. They show up here as orders come in.';
$_['text_log_help']             = 'This list keeps the last 1000 texts. Numbers show their last 2 digits only; a text that went out keeps no words. Uninstalling the extension deletes the list.';
$_['text_tried']                = 'Tried %d times';
$_['text_clear_confirm']        = 'Delete the list of recent texts?';
$_['text_kind_owner']           = 'New order, text to you';
$_['text_kind_test']            = 'Test text';
$_['text_kind_customer']        = 'Order update: %s';
$_['text_status_sent']          = 'Sent to your phone';
$_['text_status_queued']        = 'Waiting for your phone';
$_['text_status_failed']        = 'Not sent';
$_['text_status_skipped']       = 'Skipped';
$_['text_status_sending']       = 'Sending';
$_['text_status_unconfirmed']   = 'Not confirmed';
$_['text_delivery_pending']     = 'Your phone has not sent it yet';
$_['text_delivery_sent']        = 'Sent by your phone';
$_['text_delivery_delivered']   = 'Delivered';
$_['text_delivery_failed']      = 'Your phone could not send it';
$_['text_delivery_unconfirmed'] = 'Handed to the SIM, not confirmed';
$_['text_reason_missing']       = 'The order has no telephone number.';
$_['text_reason_invalid']       = 'The order\'s telephone is not a mobile number SMSTunnel can text.';
$_['text_reason_premium']       = 'Premium-rate numbers and short codes are never texted.';
$_['text_reason_empty']         = 'The text is empty.';
$_['text_not_tried']            = 'Not tried, because the text before it did not go out: %s';
$_['text_test_sent']            = 'Test text sent to %s. It leaves from your phone in a moment.';
$_['text_test_queued']          = 'Your phone is offline. The test text to %s waits on your account and goes out when the phone is back online.';
$_['text_resend_sent']          = 'Text sent again to %s.';
$_['text_resend_queued']        = 'Your phone is offline. The text to %s waits on your account and goes out when the phone is back online.';
$_['text_cleared']              = 'The list of recent texts is empty now.';
$_['text_version']              = 'SMSTunnel for OpenCart %s. SMSTunnel for OpenCart is not affiliated with, endorsed by, or sponsored by OpenCart Limited.';

// Column
$_['column_date']               = 'Date';
$_['column_text']               = 'Text';
$_['column_order']              = 'Order';
$_['column_number']             = 'Number';
$_['column_status']             = 'Status';
$_['column_phone']              = 'Phone';
$_['column_state']              = 'State';
$_['column_phone_id']           = 'Phone ID';

// Entry
$_['entry_status']              = 'Send texts';
$_['entry_api_key']             = 'API key';
$_['entry_device']              = 'Phone';
$_['entry_country']             = 'Default country';
$_['entry_owner_status']        = 'Text me about new orders';
$_['entry_owner_numbers']       = 'Your numbers';
$_['entry_owner_text']          = 'Text';
$_['entry_customer_status']     = 'Text customers about their orders';
$_['entry_statuses']            = 'When the order status becomes';
$_['entry_api_url']             = 'SMSTunnel server';

// Button
$_['button_send_test']          = 'Send a test text';
$_['button_resend']             = 'Send again';
$_['button_clear']              = 'Clear the list';

// Error
$_['error_permission']          = 'Warning: You do not have permission to modify SMSTunnel!';
$_['error_php']                 = 'SMSTunnel for OpenCart needs PHP 7.1 or newer, and this store runs PHP %s. Ask your host for a newer PHP; until then the extension sends nothing and changes nothing at checkout.';
$_['error_form']                = 'Please check the fields marked in red.';
$_['error_api_key']             = 'This does not look like an SMSTunnel API key. Copy it again from Apps in your SMSTunnel dashboard.';
$_['error_device_id']           = 'This does not look like a phone ID. Choose a phone from the list, or leave it empty.';
$_['error_api_url']             = 'The server address must start with https:// (or http:// for a test server).';
$_['error_country']             = 'Choose a country from the list.';
$_['error_owner_number']        = 'Not a mobile number SMSTunnel can text: %s. Write it in international format, such as +14155550123.';
$_['error_owner_count']         = 'New-order texts can go to at most %d numbers.';
$_['error_owner_none']          = 'Add your number for new-order texts, or turn them off.';
$_['error_text_length']         = 'A text can be at most %d characters long.';
$_['error_no_key']              = 'Save your API key first.';
$_['error_test_number']         = '%s is not a mobile number SMSTunnel can text. Write it in international format, such as +14155550123.';
$_['error_resend']              = 'Only a text that did not go out can be sent again.';
$_['error_api_401']             = 'SMSTunnel refused the API key. Check it in your SMSTunnel dashboard, under Apps.';
$_['error_api_403']             = 'This API key may not send texts. Create a new one under Apps, then Add an app.';
$_['error_api_429']             = 'Too many texts at once, or the daily limit of your plan is reached. SMSTunnel says: %s';
$_['error_api_5xx']             = 'SMSTunnel had a problem (HTTP %d). Try again in a few minutes.';
$_['error_api_refused']         = 'SMSTunnel refused the text: %s';
$_['error_api_unreachable']     = 'SMSTunnel could not be reached: %s';
$_['error_api_no_answer']       = 'SMSTunnel did not answer in time, so the text may have gone out anyway. It is not sent again on its own: check Messages in your SMSTunnel dashboard before you use Send again. (%s)';
