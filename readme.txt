=== Dox Orbit ===
Contributors: doxstudio
Tags: email marketing, newsletter, woocommerce, subscribe form, double opt-in
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Email marketing for WordPress and WooCommerce from your own site: forms with double opt-in, a block editor, campaigns and reports.

== Description ==

Dox Orbit keeps your list inside your WordPress:

* Subscription forms in a page (shortcode or Elementor widget), as a pop-up or as a bottom bar.
* Double opt-in with a confirmation email you can edit.
* A block editor with a live preview of the exact email that goes out.
* Sending in batches through the WordPress email (the same one WooCommerce uses), with an hourly cap so the website emails always have room. Amazon SES optional.
* One-click unsubscribe (RFC 8058) in every email.
* Reports: opens, clicks, first 24 hours, links, email providers, and resend to who did not open.
* Automatic welcome email, one per language with Polylang.
* Bounce handling: reads a dedicated mailbox by IMAP every hour and marks addresses that do not exist as Bounced, so Orbit stops writing to them.

== Changelog ==

= 0.4.0 =
* Automatic bounce handling: Orbit sends with a dedicated mailbox as the return address, reads it by IMAP every hour (no PHP imap extension needed, encrypted connection with a verified certificate) and marks addresses that do not exist as Bounced. Mailbox full and similar temporary problems only warn: after 3 without a click in between, the address is marked as Bounced. Auto-replies and delay notices are ignored.
* Plus addressing (VERP): each email's return address is mailbox+token@domain, so bounces that do not quote the original message are still matched to the right person. Bounces that cannot be verified are never deleted.
* New Bounces tab in Settings with a Test connection button, and the last check shown under it.
* Reports show bounced emails (count, percentage and a list with the reason). The dashboard list health includes bounces.
* The welcome email cards always show the Report link, even with 0 sent.
* Reports count delivered emails without the bounced ones, and open, click and unsubscribe rates are measured on delivered emails.

= 0.3.0 =
* Welcome email per language (Polylang): one per site language, each sent to the subscribers who signed up in that language, with its own on/off switch. The unsubscribe link and footer also come out in that language.

= 0.2.2 =
* Logo: «orbit» in the same weight as the Dox Studio lockups.

= 0.2.1 =
* The Dox Orbit logo in the panel.

= 0.2.0 =
* New name: Dox Newsletter is now Dox Orbit. Shortcode [dox_orbit].

= 0.1.1 =
* The postal address hint now says what CAN-SPAM asks for.

= 0.1.0 =
* First version.
