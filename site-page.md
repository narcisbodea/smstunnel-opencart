# Site page: SMSTunnel for OpenCart

Content for a future page on smstunnel.io at `/integrations/opencart/`. The site belongs to another project; this
file only holds the words, the structure and the assets to use.

- **Page title (≤ 60 chars):** SMSTunnel for OpenCart: order texts from your own phone
- **Meta description (≤ 155 chars):** Text your OpenCart customers about their orders from your own Android phone and
  number: shipped, complete, canceled. Free extension for OpenCart 3 and 4.
- **Screenshots:** `listing/` of this repository (`oc4-settings-page.png`, `oc4-phones-and-test-text.png`,
  `oc4-settings.png`, `oc4-recent-texts.png`, and the `oc3-*` ones for OpenCart 3.0). Sample data only.
- **Downloads**: the OpenCart Marketplace listing, live since 9 November 2026, with both packages:
  `https://www.opencart.com/index.php?route=marketplace/extension/info&extension_id=50410`.
  Once the GitHub release exists (it needs the owner's OK), direct links can go next to it:
  - OpenCart 3.0.x: `https://github.com/narcisbodea/smstunnel-opencart/releases/latest/download/smstunnel-oc3.ocmod.zip`
  - OpenCart 4.0.2 and 4.1: `https://github.com/narcisbodea/smstunnel-opencart/releases/latest/download/smstunnel.ocmod.zip`
- **Non-affiliation line (keep it on the page):** SMSTunnel for OpenCart is not affiliated with, endorsed by, or
  sponsored by OpenCart Limited.

---

## Hero

**Order texts from your own phone, for OpenCart**

Your OpenCart store texts customers when their order ships, is complete or is canceled, and texts you for every new
order: from your own Android phone, with your own number. Customers see a number they know and can simply reply.

[Get it from the OpenCart Marketplace](https://www.opencart.com/index.php?route=marketplace/extension/info&extension_id=50410) (OpenCart 3 and 4) ·
[Get it on Google Play](https://play.google.com/store/apps/details?id=io.smstunnel.app) (badge: `/google-play-badge.png`)

## How it works

1. **Your phone.** Install the SMSTunnel app on your Android phone and sign in.
2. **Your store.** Upload the package in **Extensions > Installer**, install **SMSTunnel** under **Extensions >
   Extensions > Modules**, paste the key from **Apps → Add an app**, and send yourself a test text.
3. **Your customers.** When an order comes in or changes status, your store hands the text to your SMSTunnel account,
   and your phone sends it from its own SIM.

## What your store sends

| When | To | Example |
| --- | --- | --- |
| New order | you (up to 5 numbers) | Your Store: new order #1042 from Ana Pop, $106.00. |
| Shipped | the customer | Hello Ana, your order #1042 from Your Store is on its way. Tracking: 1Z999AA10123456784 |
| Complete | the customer | Hello Ana, your order #1042 from Your Store is complete. Thank you! |
| Canceled | the customer | Hello Ana, your order #1042 from Your Store has been canceled. Reply to this text if you have any questions. |
| Any status you choose | the customer | Hello Ana, your order #1042 from Your Store is now: Processing. |

Tick the statuses that text the customer and reword each text. Suggested texts come in English and Romanian, and each
customer gets the suggestion in the language they ordered in. The comment you type when you change a status (a
tracking number, say) goes into the text when you tick **Notify Customer**.

## Built for real stores

- **Checkout never waits long, and never stops.** Short timeouts, and if SMSTunnel cannot be reached the order goes
  through; the failure is written down, and the text can be sent again with one click.
- **Numbers that work.** Local numbers are completed with the calling code of the customer's country (`(415) 555-0123`
  in the United States becomes `+14155550123`). Invalid numbers, premium-rate numbers and short codes are skipped,
  with the reason.
- **Never twice.** Each text goes out once, even if an order gets the same status again.
- **Every text in one list.** The extension's page shows the last texts, whether they went out, what your phone did
  with them (sent, delivered), and why one did not, with Send again. Numbers show only their last 2 digits.
- **OpenCart 3.0 to 4.1.** Tested on OpenCart 3.0.3.8, 3.0.5.1, 4.0.2.3 and 4.1.0.4, with admin pages in English and Romanian.
- **Free and open source** (GPL-3.0-or-later).

## Install

1. Download the package for your OpenCart from the [OpenCart Marketplace](https://www.opencart.com/index.php?route=marketplace/extension/info&extension_id=50410): `smstunnel.ocmod.zip` for
   OpenCart 4 (keep the file name) or `smstunnel-oc3.ocmod.zip` for OpenCart 3.
2. In your admin, open **Extensions > Installer** and upload it (on OpenCart 4, then click the green **+** in the list).
3. Open **Extensions > Extensions > Modules**, install **SMSTunnel**, and edit it: paste the API key from
   **Apps → Add an app** in your [dashboard](https://dashboard.smstunnel.io/apps), add your number, and click
   **Send a test text**.
4. On OpenCart 4, turn on **Telephone Display** and **Telephone Required** in **System > Settings > Option**, so
   checkout asks for a phone number.

## Build more with an AI assistant

Need a text the extension does not send, such as a back-in-stock alert or a reminder for an unpaid bank transfer? The
README has a ready-to-copy prompt for Claude, ChatGPT, Cursor or Copilot that explains the SMSTunnel API (one recipient
per call, `X-API-Key`, codes of 4 to 10 digits for sign-in texts, `defaultCountryCode`, the errors to handle) and asks
for a small OpenCart extension with events that reads the key from the settings and never stops a checkout. Show the
prompt on the page in a copy box, from the README section "Build it with an AI assistant".

## FAQ

**Do I need a special SIM or number?** No. Texts leave from the phone and SIM you already have, so customers see your
usual number.

**Can customers reply?** Yes. Replies reach your phone like any text.

**What if my phone is offline?** Texts wait on your SMSTunnel account and go out as soon as the phone is back online.
The extension's page shows which phone is online.

**Does it slow down checkout?** A text takes a fraction of a second. If SMSTunnel cannot be reached, the extension
waits at most a few seconds once, writes the failure down, and the order goes through.

**Which numbers get texts?** The telephone of the order, for the customer, and the numbers you enter, for you. Numbers
that cannot be texted are skipped, and the extension says why.

**Which OpenCart versions?** OpenCart 3.0.x, 4.0.2.x and 4.1.x. There is one package for OpenCart 3 and one for
OpenCart 4.

**What does SMSTunnel receive?** The recipient's number and the text, nothing else from your store: no email, no
address, no order contents.

---

Wording for this page follows the shared rules: "your own phone", "your own number", one text per recipient, and never
imply that a server controls the phone.
