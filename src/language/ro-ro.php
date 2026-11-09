<?php
// SMSTunnel for OpenCart. GPL-3.0-or-later, (c) 2026 NARBOWEB SRL.

// Heading
$_['heading_title']             = 'SMSTunnel: SMS de pe telefonul tău';

// Text
$_['text_extension']            = 'Extensii';
$_['text_edit']                 = 'Setări';
$_['text_success']              = 'Setările au fost salvate.';
$_['text_intro']                = 'Magazinul dă fiecare mesaj contului tău SMSTunnel, iar telefonul tău Android îl trimite de pe propria cartelă SIM. Clienții văd numărul tău și îți pot răspunde.';
$_['text_panel_phone']          = 'SMSTunnel: mesaje de pe telefonul tău';
$_['text_test_server']          = 'Mod de test: mesajele merg la acest server de test, nu la smstunnel.io. Îl schimbi la Avansat.';
$_['text_steps']                = 'Începe în trei pași';
$_['text_step_app']             = 'Instalează aplicația SMSTunnel pe telefonul tău Android și conectează-te.';
$_['text_step_key']             = 'În panoul SMSTunnel, deschide Apps, apoi Add an app, și copiază cheia.';
$_['text_step_save']            = 'Lipește cheia mai jos, adaugă numărul tău și salvează. Apoi trimite-ți un mesaj de test.';
$_['text_google_play']          = 'Descarcă din Google Play';
$_['text_dashboard']            = 'Deschide panoul';
$_['text_phones']               = 'Telefoanele tale';
$_['text_no_phones']            = 'Niciun telefon nu este legat încă de cont. Instalează aplicația SMSTunnel pe telefonul tău Android și conectează-te, sau leagă-l din Phones, apoi Add phone, în panou.';
$_['text_online']               = 'Online';
$_['text_offline']              = 'Offline';
$_['text_sends_texts']          = 'Trimite mesajele';
$_['text_last_seen']            = 'Văzut ultima dată %s';
$_['text_offline_help']         = 'Cât telefonul e offline, mesajele așteaptă în cont și pleacă imediat ce revine online.';
$_['text_device_unknown']       = 'Telefonul salvat mai jos nu este printre acestea. Alege altul, sau „Oricare dintre telefoanele mele”.';
$_['text_telephone_off']        = 'Comanda nu cere un număr de telefon, deci clienții nu pot primi mesaje. Pornește Telephone Display și Telephone Required în System > Settings > editează magazinul > Option.';
$_['text_test_to']              = 'Trimite un mesaj de test la';
$_['text_last_failure']         = 'Ultimul mesaj care nu a plecat:';
$_['text_account']              = 'Contul tău SMSTunnel';
$_['text_key_saved']            = 'O cheie este salvată (%s). Lasă câmpul gol ca s-o păstrezi.';
$_['text_key_help']             = 'În panoul SMSTunnel, deschide Apps, apoi Add an app, și copiază cheia.';
$_['text_any_phone']            = 'Oricare dintre telefoanele mele';
$_['text_device_help']          = 'Telefonul care trimite mesajele. „Oricare dintre telefoanele mele” lasă SMSTunnel să folosească un telefon de-al tău care e online.';
$_['text_device_manual']        = 'Opțional. Lasă gol ca să trimiți de pe oricare telefon, sau copiază ID-ul unui telefon din Phones, în panou.';
$_['text_country_help']         = 'Numerele scrise fără + sau 00 primesc prefixul țării de facturare a comenzii (apoi al țării de livrare); dacă comanda nu are niciuna, pe acesta. Numerele tale de mai jos îl primesc tot pe acesta.';
$_['text_owner']                = 'Comenzi noi: un mesaj pentru tine';
$_['text_owner_numbers_help']   = 'Unul sau mai multe numere (cel mult %d), separate prin virgulă, în format internațional, de exemplu +40721123456.';
$_['text_placeholders']         = 'Poți folosi: %s';
$_['text_customer']             = 'Starea comenzii: un mesaj pentru client';
$_['text_customer_help']        = 'Mesajul merge la telefonul din comandă. Clienții pot răspunde: răspunsul ajunge pe telefonul tău. {comment} este comentariul scris la schimbarea stării, trimis doar când e bifat Notify customer (pune acolo numărul AWB).';
$_['text_statuses_help']        = 'Bifează stările pentru care clientul primește un mesaj. Fiecare comandă primește fiecare mesaj o singură dată.';
$_['text_for_status']           = 'Mesaj pentru „%s”';
$_['text_suggested_help']       = 'Lasă un mesaj așa cum e sugerat, sau gol, și fiecare client primește mesajul sugerat în limba comenzii.';
$_['text_advanced']             = 'Avansat';
$_['text_api_url_help']         = 'Doar pentru teste. Magazinul folosește https://smstunnel.io: schimbă-l doar ca să încerci extensia cu un server de test.';
$_['text_recent']               = 'Mesaje recente';
$_['text_no_texts']             = 'Încă niciun mesaj. Apar aici pe măsură ce vin comenzile.';
$_['text_log_help']             = 'Lista păstrează ultimele 1000 de mesaje. Numerele arată doar ultimele 2 cifre; un mesaj care a plecat nu își păstrează textul. Dezinstalarea extensiei șterge lista.';
$_['text_tried']                = 'Încercat de %d ori';
$_['text_clear_confirm']        = 'Ștergi lista mesajelor recente?';
$_['text_kind_owner']           = 'Comandă nouă, mesaj pentru tine';
$_['text_kind_test']            = 'Mesaj de test';
$_['text_kind_customer']        = 'Starea comenzii: %s';
$_['text_status_sent']          = 'Trimis la telefonul tău';
$_['text_status_queued']        = 'Așteaptă telefonul tău';
$_['text_status_failed']        = 'Netrimis';
$_['text_status_skipped']       = 'Sărit';
$_['text_status_sending']       = 'Se trimite';
$_['text_status_unconfirmed']   = 'Neconfirmat';
$_['text_delivery_pending']     = 'Telefonul nu l-a trimis încă';
$_['text_delivery_sent']        = 'Trimis de telefonul tău';
$_['text_delivery_delivered']   = 'Livrat';
$_['text_delivery_failed']      = 'Telefonul nu l-a putut trimite';
$_['text_delivery_unconfirmed'] = 'Dat cartelei SIM, neconfirmat';
$_['text_reason_missing']       = 'Comanda nu are un număr de telefon.';
$_['text_reason_invalid']       = 'Telefonul din comandă nu este un număr mobil la care SMSTunnel poate trimite.';
$_['text_reason_premium']       = 'Numerele cu tarif special și numerele scurte nu primesc niciodată mesaje.';
$_['text_reason_empty']         = 'Mesajul este gol.';
$_['text_not_tried']            = 'Neîncercat, pentru că mesajul dinaintea lui nu a plecat: %s';
$_['text_test_sent']            = 'Mesajul de test a fost trimis la %s. Pleacă de pe telefonul tău în câteva clipe.';
$_['text_test_queued']          = 'Telefonul tău e offline. Mesajul de test pentru %s așteaptă în cont și pleacă imediat ce telefonul revine online.';
$_['text_resend_sent']          = 'Mesajul a fost trimis din nou la %s.';
$_['text_resend_queued']        = 'Telefonul tău e offline. Mesajul pentru %s așteaptă în cont și pleacă imediat ce telefonul revine online.';
$_['text_cleared']              = 'Lista mesajelor recente este goală acum.';
$_['text_version']              = 'SMSTunnel for OpenCart %s. SMSTunnel for OpenCart nu este afiliat cu, aprobat sau sponsorizat de OpenCart Limited.';

// Column
$_['column_date']               = 'Data';
$_['column_text']               = 'Mesaj';
$_['column_order']              = 'Comanda';
$_['column_number']             = 'Număr';
$_['column_status']             = 'Stare';
$_['column_phone']              = 'Telefon';
$_['column_state']              = 'Stare';
$_['column_phone_id']           = 'ID telefon';

// Entry
$_['entry_status']              = 'Trimite mesaje';
$_['entry_api_key']             = 'Cheie API';
$_['entry_device']              = 'Telefon';
$_['entry_country']             = 'Țara implicită';
$_['entry_owner_status']        = 'Anunță-mă despre comenzile noi';
$_['entry_owner_numbers']       = 'Numerele tale';
$_['entry_owner_text']          = 'Mesaj';
$_['entry_customer_status']     = 'Anunță clienții despre comenzile lor';
$_['entry_statuses']            = 'Când starea comenzii devine';
$_['entry_api_url']             = 'Server SMSTunnel';

// Button
$_['button_send_test']          = 'Trimite un mesaj de test';
$_['button_resend']             = 'Trimite din nou';
$_['button_clear']              = 'Golește lista';

// Error
$_['error_permission']          = 'Atenție: nu ai dreptul să modifici SMSTunnel!';
$_['error_php']                 = 'SMSTunnel for OpenCart are nevoie de PHP 7.1 sau mai nou, iar magazinul rulează PHP %s. Cere hostingului un PHP mai nou; până atunci extensia nu trimite nimic și nu schimbă nimic la plasarea comenzilor.';
$_['error_form']                = 'Verifică câmpurile marcate cu roșu.';
$_['error_api_key']             = 'Aceasta nu pare o cheie API SMSTunnel. Copiaz-o din nou din Apps, în panoul SMSTunnel.';
$_['error_device_id']           = 'Acesta nu pare un ID de telefon. Alege un telefon din listă, sau lasă gol.';
$_['error_api_url']             = 'Adresa serverului trebuie să înceapă cu https:// (sau http:// pentru un server de test).';
$_['error_country']             = 'Alege o țară din listă.';
$_['error_owner_number']        = 'Nu este un număr mobil la care SMSTunnel poate trimite: %s. Scrie-l în format internațional, de exemplu +40721123456.';
$_['error_owner_count']         = 'Mesajele despre comenzi noi pot merge la cel mult %d numere.';
$_['error_owner_none']          = 'Adaugă numărul tău pentru comenzile noi, sau oprește aceste mesaje.';
$_['error_text_length']         = 'Un mesaj poate avea cel mult %d caractere.';
$_['error_no_key']              = 'Salvează întâi cheia API.';
$_['error_test_number']         = '%s nu este un număr mobil la care SMSTunnel poate trimite. Scrie-l în format internațional, de exemplu +40721123456.';
$_['error_resend']              = 'Doar un mesaj care nu a plecat poate fi trimis din nou.';
$_['error_api_401']             = 'SMSTunnel a refuzat cheia API. Verific-o în panoul SMSTunnel, la Apps.';
$_['error_api_403']             = 'Această cheie API nu poate trimite mesaje. Creează una nouă din Apps, apoi Add an app.';
$_['error_api_429']             = 'Prea multe mesaje deodată, sau s-a atins limita zilnică a planului. SMSTunnel spune: %s';
$_['error_api_5xx']             = 'SMSTunnel a avut o problemă (HTTP %d). Încearcă din nou peste câteva minute.';
$_['error_api_refused']         = 'SMSTunnel a refuzat mesajul: %s';
$_['error_api_unreachable']     = 'SMSTunnel nu a putut fi contactat: %s';
$_['error_api_no_answer']       = 'SMSTunnel nu a răspuns la timp, deci mesajul poate să fi plecat totuși. Nu se trimite din nou de la sine: verifică Messages în panoul SMSTunnel înainte să folosești Trimite din nou. (%s)';
