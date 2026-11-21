<?php

declare(strict_types=1);

namespace TicketsSharp\Integration;

use TicketsSharp\Commerce\Provider;
use TicketsSharp\Support\Keys;
use Tribe__Tickets__Commerce__Currency;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Event Tickets' currency service, answering with WooCommerce's settings
 * whenever Tickets Sharp is the provider.
 *
 * Event Tickets formats every ticket price (the ticket form, the admin tickets
 * list, the editor, REST) through `tickets.commerce.currency`. Its per-provider
 * lookups know WooCommerce only for the commercial add-on's provider class;
 * any other provider gets Tickets Commerce's currency, so a site charging in
 * pounds through WooCommerce showed dollars. None of those lookups is
 * filterable, so this replaces the service with a subclass that answers for
 * Tickets Sharp and defers to Event Tickets for everyone else.
 *
 * Its filters run at priority 20, after any instance of the original class
 * that was built and hooked at the default priority, so this one has the last
 * word whichever was resolved first.
 */
class Currency extends Tribe__Tickets__Commerce__Currency
{
    public const CONTAINER_ID = 'tickets.commerce.currency';

    public function __construct(private readonly Shop $shop, private readonly EventTickets $eventTickets)
    {
        parent::__construct();
    }

    /**
     * Puts this in place of Event Tickets' own currency service and hooks it.
     */
    public function replace(): void
    {
        tribe_singleton(self::CONTAINER_ID, $this);
        tribe_singleton(Tribe__Tickets__Commerce__Currency::class, $this);
        $this->hook();
        add_filter('tec_tickets_commerce_tickets_currency_code', [$this, 'ticketCurrencyCode'], 20, 2);
    }

    public function hook()
    {
        parent::hook();

        add_filter('tribe_currency_symbol', [$this, 'filter_currency_symbol'], 20, 2);
        add_filter('tribe_reverse_currency_position', [$this, 'reverse_currency_symbol_position'], 20, 2);
        add_filter('get_post_metadata', [$this, 'filter_cost_meta'], 20, 4);
    }

    /**
     * @param mixed $provider
     * @param mixed $object_id
     * @return string
     */
    public function get_provider_symbol($provider, $object_id = null)
    {
        return self::isOurs($provider) ? $this->shop->currencySymbol() : parent::get_provider_symbol($provider, $object_id);
    }

    /**
     * @param mixed $provider
     * @param mixed $object_id
     * @return string
     */
    public function get_provider_symbol_position($provider, $object_id)
    {
        return self::isOurs($provider) ? $this->position() : parent::get_provider_symbol_position($provider, $object_id);
    }

    /**
     * @param mixed $provider
     * @return string
     */
    public function get_currency_decimal_point($provider = null)
    {
        return self::isOurs($provider) ? $this->shop->decimalSeparator() : parent::get_currency_decimal_point($provider);
    }

    /**
     * @param mixed $provider
     * @return string
     */
    public function get_currency_thousands_sep($provider = null)
    {
        return self::isOurs($provider) ? $this->shop->thousandSeparator() : parent::get_currency_thousands_sep($provider);
    }

    /**
     * @param mixed $provider
     * @return int|string
     */
    public function get_number_of_decimals($provider = null)
    {
        return self::isOurs($provider) ? $this->shop->decimals() : parent::get_number_of_decimals($provider);
    }

    /**
     * The symbol for a post, ignoring its provider in Event Tickets. Used by
     * `format_currency()` and as the fallback of the provider lookups.
     *
     * @param mixed $post_id
     * @param mixed $decode
     * @return string
     */
    public function get_currency_symbol($post_id = null, $decode = false)
    {
        if (! $this->sellsThroughUs($post_id)) {
            return parent::get_currency_symbol($post_id, $decode);
        }

        $symbol = $this->shop->currencySymbol();

        return $decode ? html_entity_decode($symbol, ENT_COMPAT) : $symbol;
    }

    /**
     * @param mixed $post_id
     * @return string
     */
    public function get_currency_symbol_position($post_id = null)
    {
        return $this->sellsThroughUs($post_id) ? $this->position() : parent::get_currency_symbol_position($post_id);
    }

    /**
     * The currency code Event Tickets reports for a ticket.
     *
     * @param mixed $code
     * @param mixed $ticketId
     * @return mixed
     */
    public function ticketCurrencyCode($code, $ticketId)
    {
        if (! is_numeric($ticketId) || ! $this->isOurTicket((int) $ticketId)) {
            return $code;
        }

        $ours = $this->shop->currency();

        return strlen($ours) === 3 ? $ours : $code;
    }

    /** WooCommerce's position, in Event Tickets' terms. */
    private function position(): string
    {
        return in_array($this->shop->currencyPosition(), ['left', 'left_space'], true) ? 'prefix' : 'postfix';
    }

    /**
     * Whether a post is a Tickets Sharp ticket, or an event whose provider is
     * Tickets Sharp.
     *
     * @param mixed $postId
     */
    private function sellsThroughUs($postId): bool
    {
        if (! is_numeric($postId) || (int) $postId <= 0) {
            return false;
        }

        $id = (int) $postId;

        return $this->isOurTicket($id) || $this->eventTickets->eventProvider($id) === Provider::class;
    }

    private function isOurTicket(int $postId): bool
    {
        return $postId > 0 && (int) get_post_meta($postId, Keys::TICKET_EVENT, true) > 0;
    }

    /**
     * @param mixed $provider A provider class name or instance.
     */
    private static function isOurs($provider): bool
    {
        if (is_object($provider)) {
            $provider = get_class($provider);
        }

        return $provider === Provider::class;
    }
}
