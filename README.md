# Tickets Sharp

[![CI](https://github.com/bleedingdeacons/tickets-sharp/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/bleedingdeacons/tickets-sharp/actions/workflows/ci.yml)
[![Semgrep](https://github.com/bleedingdeacons/tickets-sharp/actions/workflows/semgrep.yml/badge.svg?branch=main)](https://github.com/bleedingdeacons/tickets-sharp/actions/workflows/semgrep.yml)
[![Coverage Status](https://coveralls.io/repos/github/bleedingdeacons/tickets-sharp/badge.svg?branch=main)](https://coveralls.io/github/bleedingdeacons/tickets-sharp?branch=main)
![PHPStan](https://img.shields.io/badge/dynamic/yaml?url=https%3A%2F%2Fraw.githubusercontent.com%2Fbleedingdeacons%2Ftickets-sharp%2Fmain%2Fphpstan.neon.dist&query=%24.parameters.level&label=PHPStan&prefix=level%20&color=brightgreen)
![PHPCS](https://img.shields.io/badge/dynamic/xml?url=https%3A%2F%2Fraw.githubusercontent.com%2Fbleedingdeacons%2Ftickets-sharp%2Fmain%2F.phpcs.xml.dist&query=%2Fruleset%2Frule%5B1%5D%2F%40ref&label=PHPCS&color=brightgreen)
![Version](https://img.shields.io/badge/version-0.6.3-blue)
![PHP](https://img.shields.io/badge/php-8.4%2B-777bb4)
![Licence](https://img.shields.io/badge/licence-GPL--2.0--or--later-green)

**Sell [Event Tickets](https://wordpress.org/plugins/event-tickets/) tickets through WooCommerce.**

Tickets Sharp adds WooCommerce as a ticket provider to Event Tickets. Each ticket you create on an
event — in the block editor or the classic ticket form — becomes a hidden, virtual WooCommerce
product. Buyers use the normal WooCommerce cart and checkout (classic or block), with any payment
gateway, coupon or tax setup the shop already has.

## Features

- **Tickets are WooCommerce products.** Created, edited and deleted from Event Tickets' own ticket
  editor. They are hidden from the shop catalogue and search, and their price, description, sale
  window and capacity come from the ticket.
- **WooCommerce cart and checkout.** The event page's "Get tickets" form adds tickets to the
  WooCommerce cart. Both the classic and the block checkout work. Back on the event page, the form
  shows the tickets already in the basket for that event; changing the numbers and pressing
  "Get tickets" again sets the basket to them, so attendees are added to or removed from the
  attendee details. Nothing already entered is lost: details of attendees left out, or of a
  ticket taken out of the basket, come back if the numbers go up again, until checkout.
- **Attendees.** One attendee per ticket bought, created as soon as the order exists, so an unpaid
  order holds its seats. They appear in Event Tickets' Attendees list, CSV export and REST API.
- **Ticket e-mails.** Sent through Event Tickets' own ticket e-mail once the order is paid.
  Settings decide which WooCommerce statuses count as paid (processing and completed by default).
- **Shared capacity.** Event Tickets' shared ("global") and capped capacity modes work, including
  across tickets bought together in one basket.
- **Attendee information.** Each ticket can ask every attendee for a name, an email address, a
  phone number, text, a choice from a list or a checkbox. The fields are set in the ticket form's
  **Attendee information** box, under Capacity; a new ticket asks for each attendee's name and
  email by default. Buyers fill them in on an attendee details page between the cart and the
  checkout. The buyer is e-mailed all their tickets; where a ticket requires the attendee's email,
  each attendee is also sent their own ticket at the address they gave, even when that is the
  buyer's own. Attendees who gave the same address (ignoring case and spaces) get their tickets
  together in one e-mail per event, whichever line of the order they are on. The answers show in
  the Attendees list, the CSV export and on the order.
- **QR check-in.** Each ticket e-mail carries a QR code encoding Event Tickets' check-in link.
  Scanning it while signed in as an event manager checks the attendee in. The Event Tickets app
  reads the same codes. Only paid attendees can be checked in.
- **Refunds and cancellations.** A cancelled, failed or fully refunded order releases its seats and
  its tickets stop passing check-in. A partial refund marks that many attendees of the refunded
  line as refunded. It picks attendees who have not checked in first, most recent first.
- **HPOS.** Orders are only ever read through WooCommerce, never as posts. The plugin declares
  compatibility with High-Performance Order Storage and the cart and checkout blocks.

## Requirements

- WordPress 6.9+, PHP 8.4+
- Event Tickets (tested with 5.31) and WooCommerce (9.0+)
- The Events Calendar is optional (Event Tickets sells tickets on any post type) but expected.

## Usage

1. Activate Event Tickets, WooCommerce and Tickets Sharp. Activation creates an **Attendee
   details** page holding the `[tickets_sharp_attendees]` shortcode.
2. On an event, add tickets in the Tickets section and choose **WooCommerce** as the provider
   (Event Tickets only shows the choice when more than one provider is active).
3. In the same ticket form, the **Attendee information** box says what to ask each attendee, one
   field per line: `Label | type | required | Option A, Option B`. New tickets start with
   `Name | name | required` and `Email | email | required`; empty the box to ask nothing. (The block editor's ticket form has no
   such box: tickets made there get the default, and can be changed in the classic form.)
4. Settings live under **WooCommerce › Settings › Products › Event tickets**:
   - which order statuses issue tickets;
   - how long unpaid orders hold their seats;
   - which page is the attendee details page.

## How it plugs into Event Tickets

Event Tickets has no registration API for a new commerce provider. A few of its lists are
hard-coded, so the plugin works around them in four ways. Every subclass calls its parent and only
adds to it.

| Event Tickets | Hard-coded where | Tickets Sharp |
|---|---|---|
| Provider is "active" | `Tribe__Tickets__Status__Manager::$module_slugs` | Patches the live instance on `tec_tickets_bound_implementations` (`Integration\StatusManager`) |
| Order statuses per provider | `…::$status_managers` | Same patch adds `Integration\OrderStatuses` |
| Attendee / ticket repositories | `Attendee_Repository::*_keys()`, `Ticket_Repository::ticket_types()` | Rebinds `tickets.attendee-repository` / `tickets.ticket-repository` to subclasses, and adds its own to `tribe_attendees()` / `tribe_tickets()` |
| Tickets form provider map | `tickets-block.js` | Reads the posted JSON itself (`Commerce\CartHandler`) |

If Event Tickets renames the status manager's members, the plugin stands down with an admin notice
rather than half-working.

**Stock.** WooCommerce is told, in "view" context only, that ticket products do not manage stock,
so it never holds, reduces or restores it. Availability is Event Tickets' own: capacity minus the
attendees who hold a seat. `Stock\StockSync` mirrors that into `_stock` and the event's global
stock level, for the Event Tickets code that reads that meta directly. It recomputes from the
attendees each time, rather than keeping a running total that could drift.

**Known limits**
- Two buyers checking out the very last seat at the same moment can both succeed. WooCommerce has
  no lock to take here.
- Event Tickets' REST filter by order status matches only RSVP and PayPal status meta keys.
- The block editor's sale-price UI is only offered for Event Tickets' own providers.

## Provenance

Tickets Sharp is a clean-room implementation. It was written only from the free Event Tickets and
The Events Calendar code (from wordpress.org) and from WooCommerce's public APIs. No code,
documentation, video or other material from any commercial Event Tickets add-on was consulted.

The free Event Tickets code names a commercial WooCommerce add-on's classes in a few hard-coded
maps (a `woo` provider slug, `_tribe_wooticket_*` meta keys). Tickets Sharp reuses none of those
names, so the two can never read each other's data. If that add-on's WooCommerce provider is
present, Tickets Sharp does not register itself.

## Development

Dev site: `aa-bristol-future` (Local), through `bin/dev.ps1`. That script runs WP-CLI and Composer
with the site's own PHP, which has zip, OpenSSL and xdebug.

```powershell
.\bin\dev.ps1 composer install
.\bin\dev.ps1 composer test
.\bin\dev.ps1 composer phpstan
.\bin\dev.ps1 composer phpcs
.\bin\dev.ps1 setup      # link, activate, seed a sample event (needs TEC, Event Tickets and WooCommerce active)
```

- **Tests:** Pest over [wp-mocks](https://github.com/bleedingdeacons/wp-mocks): Brain Monkey for
  functions and hooks, Mockery for objects.
  - Event Tickets, The Events Calendar and WooCommerce are not installed. `tests/stubs/*.php`
    declares exactly the parts the plugin touches, and every test sets the behaviour it needs.
  - The same stub files are PHPStan's `scanFiles`.
- **Coverage:** `composer test -- --coverage` (xdebug or pcov). CI uploads Clover to Coveralls.
- **Standards:** PSR-12 via PHPCS; PHPStan level 8, no baseline.
- **CI:** the suite's standard workflow, from Scrutiny's. It gates on PHP 8.4 and 8.5: composer
  validate and audit, Semgrep, PHP lint, the production build, PHPStan, Pest with coverage and
  PHPCS. A merge to `main` raises the version and publishes `tickets-sharp-production-<v>.zip` as
  a GitHub Release. A weekly Semgrep full scan gates on critical and high findings.

## Hooks

| Hook | Type | Arguments |
|---|---|---|
| `tickets_sharp/loaded` | action | `Plugin` |
| `tickets_sharp/ticket` | filter | `Ticket_Object`, event, product ID |
| `tickets_sharp/ticket_saved` | action | event ID, product ID, `create`/`update` |
| `tickets_sharp/added_to_cart` | action | event ID, `[product ID => quantity]` |
| `tickets_sharp/attendees_generated` | action | order ID, attendee IDs |
| `tickets_sharp/attendees_changed` | action | event IDs |
| `tickets_sharp/attendee_refunded` | action | attendee ID, order ID |
| `tickets_sharp/tickets_sent` | action | order ID |
| `tickets_sharp/shared_capacity_synced` | action | event ID, seats left |

Kill switch: `define('TICKETS_SHARP_KILL', true);` in `wp-config.php`.

## Licence

GPL-2.0-or-later, as for the GPL plugins it extends.
