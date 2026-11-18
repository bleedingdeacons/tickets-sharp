<?php

declare(strict_types=1);

namespace TicketsSharp\Tests\Support;

use BleedingDeacons\WpMocks\WpState;
use Mockery;
use Mockery\MockInterface;
use TicketsSharp\Attendees\AttendeeStore;
use TicketsSharp\Attendees\Ledger;
use TicketsSharp\Commerce\Provider;
use TicketsSharp\Commerce\TicketProducts;
use TicketsSharp\Commerce\Urls;
use TicketsSharp\Integration\EventTickets;
use TicketsSharp\Integration\Shop;
use TicketsSharp\Orders\StatusMap;
use TicketsSharp\Support\Keys;
use TicketsSharp\Support\Settings;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product_Simple;

/**
 * Builders for the objects most tests need.
 */
final class Make
{
    /**
     * A Provider whose collaborators are Mockery doubles unless given.
     *
     * @param array<string, object> $with shop, products, store, ledger, urls, statuses
     * @return array{provider: Provider, shop: Shop&MockInterface, products: TicketProducts&MockInterface, store: AttendeeStore&MockInterface, ledger: Ledger&MockInterface, urls: Urls&MockInterface}
     */
    public static function provider(array $with = []): array
    {
        $parts = [
            'shop' => $with['shop'] ?? Mockery::mock(Shop::class),
            'products' => $with['products'] ?? Mockery::mock(TicketProducts::class),
            'store' => $with['store'] ?? Mockery::mock(AttendeeStore::class)->makePartial(),
            'ledger' => $with['ledger'] ?? Mockery::mock(Ledger::class),
            'urls' => $with['urls'] ?? Mockery::mock(Urls::class),
        ];

        $statuses = $with['statuses'] ?? new StatusMap(new Settings());

        /** @var StatusMap $statuses */
        $provider = new Provider(
            $parts['shop'],
            $parts['products'],
            $parts['store'],
            $parts['ledger'],
            $parts['urls'],
            $statuses,
        );

        /** @phpstan-ignore-next-line */
        return ['provider' => $provider] + $parts;
    }

    /**
     * @param array<string, mixed> $props
     */
    public static function product(int $id, array $props = []): WC_Product_Simple
    {
        $product = new WC_Product_Simple($id);
        $product->props = array_merge($product->props, ['name' => 'Adult', 'price' => '10.00', 'regular_price' => '10.00'], $props);

        return $product;
    }

    /**
     * @param array<string, mixed>       $props
     * @param list<WC_Order_Item_Product> $items
     */
    public static function order(int $id, array $props = [], array $items = []): WC_Order
    {
        $order = new WC_Order($id);
        $order->props = array_merge($order->props, [
            'status' => 'pending',
            'billing_first_name' => 'Pat',
            'billing_last_name' => 'Buyer',
            'billing_email' => 'pat@example.test',
            'customer_id' => 3,
            'currency' => 'GBP',
        ], $props);
        foreach ($items as $item) {
            $order->items[$item->get_id()] = $item;
        }

        return $order;
    }

    /**
     * @param array<string, mixed> $meta
     */
    public static function item(int $id, int $productId, int $quantity, string $total = '20.00', array $meta = []): WC_Order_Item_Product
    {
        $item = new WC_Order_Item_Product($id);
        $item->props = array_merge($item->props, ['product_id' => $productId, 'quantity' => $quantity, 'total' => $total]);
        $item->meta = $meta;

        return $item;
    }

    /** Marks a product as a ticket for an event. */
    public static function ticketMeta(int $productId, int $eventId, array $extra = []): void
    {
        WpState::$postMeta[$productId][Keys::TICKET_EVENT] = $eventId;
        foreach ($extra as $key => $value) {
            WpState::$postMeta[$productId][$key] = $value;
        }
    }

    public static function eventTickets(): EventTickets&MockInterface
    {
        return Mockery::mock(EventTickets::class);
    }
}
