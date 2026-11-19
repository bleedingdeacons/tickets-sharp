=== Tickets Sharp ===
Contributors: thebleedingdeacons
Tags: event tickets, woocommerce, events, tickets, attendees
Requires at least: 6.9
Tested up to: 6.9
Stable tag: 0.4.3
Build date: 2026/11/19 16:49:18
Requires PHP: 8.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sell Event Tickets tickets through WooCommerce.

== Description ==

Tickets Sharp adds WooCommerce as a ticket provider to Event Tickets. Each ticket you create on an event becomes a hidden, virtual WooCommerce product, and buyers use the normal WooCommerce cart and checkout (classic or block) with any payment gateway.

* Tickets are created, edited and deleted from Event Tickets' own ticket editor.
* One attendee per ticket bought, listed in Event Tickets' Attendees list, CSV export and REST API.
* Ticket e-mails through Event Tickets' own e-mail once the order is paid, with a check-in QR code.
* Shared and capped event capacity, enforced across the whole basket.
* Per-ticket attendee information fields, collected between cart and checkout.
* Cancelled, failed and refunded orders release their seats; partial refunds mark individual attendees.
* Compatible with WooCommerce High-Performance Order Storage and the cart and checkout blocks.

Requires Event Tickets and WooCommerce. The Events Calendar is optional but expected.

== Installation ==

1. Activate Event Tickets and WooCommerce.
2. Upload and activate Tickets Sharp. Activation creates an "Attendee details" page.
3. Add tickets to an event and choose WooCommerce as the provider.
4. Settings: WooCommerce > Settings > Products > Event tickets.

== Frequently Asked Questions ==

= Where do I set attendee information fields? =

In the ticket form, in the "Attendee information" box under Capacity: one field per line, `Label | type | required | Option A, Option B`. New tickets ask for each attendee's name and email by default, and each attendee is sent their own ticket.

= When are tickets e-mailed? =

When the order reaches a status configured as paid (processing or completed by default).

== Changelog ==

= 0.1.0 =
* First release.
