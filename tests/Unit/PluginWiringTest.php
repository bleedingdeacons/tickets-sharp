<?php

declare(strict_types=1);

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use TicketsSharp\Admin\Notices;
use TicketsSharp\Commerce\Provider;
use TicketsSharp\Integration\OrderStatuses;
use TicketsSharp\Integration\Repositories\AttendeeRepository;
use TicketsSharp\Integration\Repositories\DefaultAttendeeRepository;
use TicketsSharp\Integration\Repositories\DefaultTicketRepository;
use TicketsSharp\Integration\Repositories\TicketRepository;
use TicketsSharp\Plugin;
use TicketsSharp\Support\Keys;

beforeEach(function (): void {
    $this->statusManager = new Tribe__Tickets__Status__Manager();
    tribe_singleton('tickets.status', $this->statusManager);
});

function pluginWith(array $missing = [], ?Notices $notices = null): Plugin
{
    return new Plugin(
        $notices ?? new Notices(),
        static fn (string $class): bool => ! in_array($class, $missing, true) && $class !== Plugin::CONFLICTING_PROVIDER
    );
}

it('waits for Event Tickets to bind its services', function (): void {
    pluginWith()->boot();

    $this->assertActionAdded('tec_tickets_bound_implementations');
    $this->assertActionAdded('before_woocommerce_init');
    $this->assertActionAdded('plugins_loaded');
    $this->assertActionAdded('admin_notices');
});

it('registers the provider, its statuses, repositories and hooks', function (): void {
    Actions\expectDone('tickets_sharp/loaded')->once();
    $plugin = pluginWith();
    $plugin->integrate();

    expect($plugin->isIntegrated())->toBeTrue()
        ->and(tribe(Provider::class))->toBe($plugin->provider())
        ->and(Tribe__Tickets__Tickets::modules())->toHaveKey(Provider::class)
        ->and($this->statusManager->get_provider_slug(Provider::class))->toBe(Keys::PROVIDER_SLUG)
        ->and($this->statusManager->get_status_managers()[Keys::PROVIDER_SLUG])->toBe(OrderStatuses::class)
        ->and(Tribe__Container::$bindings)->toMatchArray([
            'tickets.attendee-repository' => DefaultAttendeeRepository::class,
            'tickets.ticket-repository' => DefaultTicketRepository::class,
            Plugin::ATTENDEE_REPOSITORY => AttendeeRepository::class,
            Plugin::TICKET_REPOSITORY => TicketRepository::class,
        ]);

    foreach (['init', 'woocommerce_checkout_order_processed', 'woocommerce_order_status_changed', 'woocommerce_order_refunded', 'template_redirect', 'tickets_sharp/attendees_changed', 'add_meta_boxes'] as $action) {
        $this->assertActionAdded($action);
    }
    $this->assertFilterAdded('woocommerce_add_to_cart_validation');
    $this->assertFilterAdded('tribe_tickets_attendee_repository_map');
    $this->assertFilterAdded('tec_tickets_completed_status_by_provider_name');
    $this->assertActionAdded('tribe_events_tickets_metabox_edit_main');
    $this->assertFilterAdded('tec_tickets_admin_tickets_table_query_args');
    $this->assertActionAdded('woocommerce_email_after_order_table');
});

it('leaves the admin screens out of front-end requests', function (): void {
    Functions\when('is_admin')->justReturn(false);

    pluginWith()->integrate();

    expect(has_filter('tec_tickets_admin_tickets_table_query_args'))->toBeFalse();
});

it('stands down and says why when a requirement is missing', function (): void {
    $notices = new Notices();
    $plugin = pluginWith(['WooCommerce', 'Tribe__Tickets__Tickets'], $notices);
    Actions\expectDone('tickets_sharp/loaded')->never();

    $plugin->integrate();

    expect($plugin->isIntegrated())->toBeFalse()
        ->and($notices->codes())->toBe([Notices::NO_WOOCOMMERCE, Notices::NO_EVENT_TICKETS]);
});

it('stands down beside the other WooCommerce provider', function (): void {
    $notices = new Notices();
    $plugin = new Plugin($notices, static fn (string $class): bool => true);

    expect($plugin->requirementProblems())->toBe([Notices::CONFLICT]);
});

it('reports a missing Event Tickets when its action never fired', function (): void {
    $notices = new Notices();
    $plugin = pluginWith([], $notices);
    Functions\when('did_action')->justReturn(0);

    $plugin->reportIfNotIntegrated();

    expect($notices->codes())->toBe([Notices::NO_EVENT_TICKETS]);
});

it('has nothing to report once Event Tickets has run', function (): void {
    $notices = new Notices();
    Functions\when('did_action')->justReturn(1);

    pluginWith([], $notices)->reportIfNotIntegrated();

    expect($notices->codes())->toBe([]);
});

it('maps its repositories', function (): void {
    $plugin = pluginWith();

    expect($plugin->attendeeRepositoryMap(['default' => 'x']))->toBe(['default' => 'x', Keys::ORM_PROVIDER => Plugin::ATTENDEE_REPOSITORY])
        ->and($plugin->ticketRepositoryMap([]))->toBe([Keys::ORM_PROVIDER => Plugin::TICKET_REPOSITORY])
        ->and($plugin->attendeeRepositoryMap('x'))->toBe('x')
        ->and($plugin->ticketRepositoryMap('x'))->toBe('x');
});

it('tells QR check-in which statuses are paid, for its own provider only', function (): void {
    $plugin = pluginWith();

    expect($plugin->completedStatuses(['x'], Provider::class))->toBe(['processing', 'completed'])
        ->and($plugin->completedStatuses(['x'], Keys::PROVIDER_SLUG))->toBe(['processing', 'completed'])
        ->and($plugin->completedStatuses(['x'], new stdClass()))->toBe(['x'])
        ->and($plugin->completedStatuses(['x'], 'Tribe__Tickets__RSVP'))->toBe(['x']);
});

it('declares HPOS and block checkout compatibility', function (): void {
    pluginWith()->declareCompatibility();

    expect(FeaturesUtil::$declared)->toBe(['custom_order_tables' => true, 'cart_checkout_blocks' => true]);
});
