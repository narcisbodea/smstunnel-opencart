# Publishing SMSTunnel for OpenCart

| Where | State |
| --- | --- |
| **The OpenCart Marketplace** (what OpenCart store owners search, also shown in the admin's **Extensions > Marketplace**) | **Live since 9 November 2026**: [extension 50410](https://www.opencart.com/index.php?route=marketplace/extension/info&extension_id=50410), version 1.0.0, seller account `narcisbodea`. It was in the marketplace search the same day. |
| **GitHub releases** (`narcisbodea/smstunnel-opencart`) | Not yet. The repository is local only; making it public needs the owner's OK. |

## Before every release

1. Set the version in `src/core/smstunnel/Version.php` (`NUMBER`), `src/oc4/install.json` and `CHANGELOG.md` (the build refuses to run when they differ). A version that changes the table of texts needs an upgrade step in the controllers' `install()` (it runs again when the module is reinstalled).
2. Check and build:
   ```bash
   bash tools/check.sh      # php -l + unit tests on PHP 7.1, 7.4, 8.1, 8.2, 8.3, 8.4, 8.5; on PHP 5.6, php -l of the files OpenCart 3.0 loads first + tests/oldphp
   bash tools/build.sh      # build/smstunnel-oc3.ocmod.zip and build/smstunnel-oc4/smstunnel.ocmod.zip
   ```
3. Run the end-to-end tests on OpenCart 3.0.5.1, 3.0.3.8 (PHP 7.4), 4.0.2.3 and 4.1.0.4 (README, "Development"). Add `--shots` to the 3.0.5.1 and 4.1.0.4 runs to refresh the screenshots in `listing/`. `node tools/listing-images.mjs` redraws the marketplace thumbnail and banner (only needed when their words or the logo change).

The two packages:

| File | For | What is inside |
| --- | --- | --- |
| `build/smstunnel-oc3.ocmod.zip` | OpenCart 3.0.0.0 to 3.0.5.x | `upload/` (copied into the store by Extensions > Installer) and `LICENSE.txt`. No `install.xml`: the extension changes no OpenCart file, it only uses events. |
| `build/smstunnel-oc4/smstunnel.ocmod.zip` | OpenCart 4.0.2.0 to 4.1.x | `install.json`, `admin/`, `catalog/`, `system/library/smstunnel/`, `LICENSE.txt`. |

**Keep the name `smstunnel.ocmod.zip` for OpenCart 4.** OpenCart 4's installer names the extension's folder after the file name (`extension/smstunnel/`), and the extension's PHP namespaces use that name: a file uploaded as `smstunnel-oc4.ocmod.zip` would install into `extension/smstunnel-oc4/` and not work. That is why the OpenCart 4 package sits in its own folder `build/smstunnel-oc4/` instead of being called `build/smstunnel-oc4.ocmod.zip`. The admin's own **Extensions > Marketplace** page of OpenCart 4 also saves a download under the file name the marketplace gives it (the name the seller uploaded) and takes the extension's code from it, so upload it to opencart.com as `smstunnel.ocmod.zip` too. The OpenCart 3 package can have any name ending in `.ocmod.zip`. OpenCart's own guide asks for a package name prefixed with the brand, a `.ocmod.zip` ending and at most 32 MB, which both meet.

## The OpenCart Marketplace

### What OpenCart asks (checked on 9 November 2026)

- **Seller account.** Create an account at opencart.com (username, email, first and last name, country, password). Once signed in, apply to become a seller in the account's extension area: the form asks for email, first and last name, country, phone and payout details. OpenCart says the approval "normally take[s] up to 48 hours". Source: the "Extension Developer" page (opencart.com/index.php?route=cms/information&information_id=2).
- **Fees.** None for a free extension. For paid ones OpenCart keeps a commission that starts at 50% of monthly sales and falls to 25% above $1,000 a month (the rate follows the last 6 months), paid out monthly by PayPal or Payoneer.
- **Seller rules** (opencart.com Terms & Conditions): the work must be your own; it must be tested; the seller must provide support as long as the extension is on opencart.com (or name someone who does); the seller should include their own terms as a secondary term of use; a work that does not work or breaks the terms is removed (and buyers of a paid one refunded).
- **Packages the admin's Marketplace can install** ("Cloud Marketplace"): OpenCart 3.0 or newer, a zip, the files in a folder named `upload` (OpenCart 3), and any OCMOD file named `install.xml`. `smstunnel-oc3.ocmod.zip` follows this; it has no OCMOD file. OpenCart 4 packages follow the `install.json` layout above.
- **License.** The marketplace pages do not spell out a license rule. OpenCart is GPL-3.0, and an extension that runs inside it is expected to be GPL-compatible; this one is **GPL-3.0-or-later** (`LICENSE` here, `LICENSE.txt` in both packages, `@license` in every PHP file).
- Nothing in the extension is encrypted or obfuscated, it downloads nothing after installation, and it calls only the SMSTunnel API (smstunnel.io), and only when the store owner saved a key.

### The listing (submitted on 9 November 2026)

- **Page**: https://www.opencart.com/index.php?route=marketplace/extension/info&extension_id=50410. The seller area (**Account > Extensions**) lists it with VIEW, EDIT and DELETE.
- **Name**: SMSTunnel for OpenCart: order SMS from your own phone. **Category**: Modules. **License**: Free.
- **Tags**: SMS, SMS notification, order status SMS, new order SMS, text message, shipping notification, order alert, Android, own phone number.
- **Thumbnail** `listing/marketplace-thumb.png` (260 x 152) and **banner** `listing/marketplace-banner.png` (710 x 380), drawn by `node tools/listing-images.mjs` from the site's logo.
- **Screenshots**, in this order: `oc4-settings-page.png`, `oc4-phones-and-test-text.png`, `oc4-settings.png`, `oc4-recent-texts.png`, `oc3-settings-page.png`, `oc3-recent-texts.png` (sample data only, numbers `+1415555xxxx`).
- **Downloads** (the Cloud option off on both):
  - "SMSTunnel for OpenCart 1.0.0 (OpenCart 3.0.x)": `build/smstunnel-oc3.ocmod.zip` (70,652 bytes), for 3.0.0.0 to 3.0.5.1 and 3.x.x.x.
  - "SMSTunnel for OpenCart 1.0.0 (OpenCart 4.0.2+ and 4.1)": `build/smstunnel-oc4/smstunnel.ocmod.zip` (69,933 bytes), uploaded as `smstunnel.ocmod.zip`, for 4.0.2.0 to 4.1.0.4 and 4.x.x.x. Not 4.0.0.0 to 4.0.1.x: those used another route separator (`|`) and were not tested.
- **History**: "First version: SMSTunnel for OpenCart 1.0.0 for OpenCart 3.0.x and 4.0.2 to 4.1."
- The description and the documentation are below, as submitted.
- **Support**: buyers write through the listing's comments and **Get Support**. OpenCart's seller rules ask for support as long as the extension is listed.

### Updating the listing

1. Release as above (version, `CHANGELOG.md`, check, build, end-to-end tests).
2. In **Account > Extensions**, click **EDIT** on the listing. Under the downloads, upload the two new files (the OpenCart 4 one named exactly `smstunnel.ocmod.zip`), give them the new version in their names, tick the same OpenCart versions, and remove the old files or keep them for older versions.
3. Add a line from `CHANGELOG.md` under History, and save.

### Traps in the opencart.com form

- **Its firewall (Cloudflare) refuses a save whose words look like SQL**, with a Cloudflare page instead of the form. Two lines of the first documentation did: a quote followed by ` or ` (`keep "Any of my phones" or pick one`) and a closing parenthesis followed by ` or ` (`(PHP 7.1 or newer for the extension) or OpenCart`). Avoid `" or` and `) or`, and words such as select, union or drop near quotes.
- **Going back after a refusal keeps the words but drops every upload** (thumbnail, banner, screenshots, files), although each went to opencart.com as soon as it was chosen: upload them again before saving.
- **BBCode**: `[b]` works; `[list]` and `[*]` show up as they are, so the lists use `•` and `1.` lines. Plain https:// addresses are enough; the editor's link button opens a browser prompt that can be closed.

### Description, as submitted

```text
[b]SMSTunnel for OpenCart[/b] sends order texts from [b]your own Android phone, with your own number[/b]. Your store hands each text to your SMSTunnel account, and the free SMSTunnel app on your phone sends it from its own SIM: customers see a number they know and can simply reply.

[b]What it sends[/b]
• [b]To you, for every new order:[/b] one text to each of your numbers (up to 5), for example "Your Store: new order #1042 from Ana Pop, $106.00."
• [b]To the customer, when their order changes status:[/b] you tick the statuses (Shipped, Complete, Canceled and Refunded are ticked at install) and write a text for each one. The tracking number you type in the status comment goes with it.

[b]Built for real stores[/b]
• Checkout never waits long and never stops: short timeouts, and if SMSTunnel cannot be reached the order goes through and the text can be sent again with one click.
• One text per event, even if the same status is saved twice.
• Numbers are completed with the customer's country calling code; invalid, premium-rate and short numbers are skipped, with the reason.
• Your own wording with {firstname} {lastname} {order_id} {total} {store_name} {status} {comment}. Suggested texts in English and Romanian, picked by the language of each order.
• A test text, your phones with their state (online or offline), and the last texts with what your phone did with them (sent, delivered). Numbers show only their last 2 digits.
• OpenCart 3.0.x and 4.0.2 to 4.1. Admin pages in English and Romanian. Open source (GPL-3.0-or-later).

[b]You need[/b] an Android phone with the free SMSTunnel app (Google Play: https://play.google.com/store/apps/details?id=io.smstunnel.app) and an account at https://smstunnel.io. Plans start free.

[b]Setup in a minute[/b]
1. Install the SMSTunnel app on your Android phone and sign in.
2. In the SMSTunnel dashboard, open Apps, then Add an app, and copy the key.
3. Upload the package in Extensions > Installer, install SMSTunnel under Extensions > Extensions > Modules, paste the key and add your number.
4. Send yourself a test text from the extension's page.
On OpenCart 4, turn on Telephone Display and Telephone Required in System > Settings > Option, so checkout asks for a phone number.

Send only texts people expect from your store. SMSTunnel for OpenCart is not affiliated with, endorsed by, or sponsored by OpenCart Limited.
```

### Documentation, as submitted

```text
[b]Which file[/b]
• OpenCart 3.0.x: smstunnel-oc3.ocmod.zip
• OpenCart 4.0.2.x and 4.1.x: smstunnel.ocmod.zip. Keep this exact file name: OpenCart 4 names the extension's folder after the file.

[b]Install[/b]
1. Extensions > Installer: upload the zip (on OpenCart 4, then click the green + next to SMSTunnel in the list).
2. Extensions > Extensions, choose Modules, click the green + next to "SMSTunnel: SMS from your own phone", then the blue pencil.
3. OpenCart 4 only: System > Settings, edit your store, Option tab: turn on Telephone Display and Telephone Required, so checkout asks for a phone number.

[b]Setup[/b]
1. Install the SMSTunnel app from Google Play on your Android phone and sign in; the phone appears under Phones in your SMSTunnel dashboard.
2. In the dashboard (https://dashboard.smstunnel.io/apps) open Apps, then Add an app, and copy its key.
3. On the SMSTunnel page in OpenCart, paste the key into API key and save. Your phones then appear with their state; choose the one that sends your texts (Any of my phones lets SMSTunnel choose).
4. Check Default country (it starts as your store's country). Add your number(s) for new-order texts, in international format such as +14155550123.
5. Tick the order statuses that text the customer and adjust the texts. Placeholders: {firstname} {lastname} {order_id} {total} {store_name} {status} {comment}. {comment} is the comment typed with a status change, shared only when Notify Customer is ticked: put the tracking number there.
6. Save, then use Send a test text.

[b]How it behaves[/b]
• Texts go out while OpenCart saves the order or the status, with short timeouts (3 s to connect, 8 s in all). If SMSTunnel cannot be reached, the order still goes through and Recent texts offers Send again.
• Each text goes out once per order and status. Invalid, premium-rate and short numbers are skipped, with the reason.
• When your phone is offline, texts wait on your account and go out when it is back online.
• Recent texts keeps the last 1000 texts; numbers show only their last 2 digits. Uninstalling removes the list, the settings and the events.

[b]Requirements[/b]
OpenCart 3.0.x with PHP 7.1 and newer, OpenCart 4.0.2 to 4.1, PHP cURL, an Android phone with the SMSTunnel app and an SMSTunnel account. Full guide: https://smstunnel.io
```

## GitHub release

The repository is local only (branch `master`). To publish it on GitHub:

```bash
cd D:/ANDROID/integrari/smstunnel-opencart
git branch -M main
gh repo create narcisbodea/smstunnel-opencart --public --source . --remote origin \
  --description "SMSTunnel for OpenCart: order texts from your own phone and your own number"
git push -u origin main
bash tools/build.sh
cp build/smstunnel-oc4/smstunnel.ocmod.zip build/smstunnel.ocmod.zip
git tag -a v1.0.0 -m "SMSTunnel for OpenCart 1.0.0"
git push origin v1.0.0
gh release create v1.0.0 build/smstunnel-oc3.ocmod.zip build/smstunnel.ocmod.zip \
  --title "SMSTunnel for OpenCart 1.0.0" \
  --notes "First version. OpenCart 3.0.x: smstunnel-oc3.ocmod.zip. OpenCart 4.0.2+ and 4.1: smstunnel.ocmod.zip (keep the name). Upload in Extensions > Installer. See CHANGELOG.md."
```

The download links then work as `https://github.com/narcisbodea/smstunnel-opencart/releases/latest/download/smstunnel-oc3.ocmod.zip` and `.../smstunnel.ocmod.zip`, and the smstunnel.io page (`site-page.md`) can link to them.
