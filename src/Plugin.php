<?php

declare(strict_types=1);

namespace TicketsSharp;

use TicketsSharp\Admin\Notices;
use TicketsSharp\Admin\OrderMetaBox;
use TicketsSharp\Admin\Settings as SettingsPage;
use TicketsSharp\Admin\TicketFormFields;
use TicketsSharp\Admin\TicketsTable;
use TicketsSharp\Attendees\AttendeeStore;
use TicketsSharp\Attendees\Columns;
use TicketsSharp\Attendees\DetailsPage;
use TicketsSharp\Attendees\Ledger;
use TicketsSharp\CheckIn\QrCode;
use TicketsSharp\Commerce\Availability;
use TicketsSharp\Commerce\CartHandler;
use TicketsSharp\Commerce\Provider;
use TicketsSharp\Commerce\TicketProducts;
use TicketsSharp\Commerce\Urls;
use TicketsSharp\Emails\OrderEmailTickets;
use TicketsSharp\Emails\TicketImage;
use TicketsSharp\Integration\Currency;
use TicketsSharp\Integration\EventTickets;
use TicketsSharp\Integration\ProviderMeta;
use TicketsSharp\Integration\Repositories\AttendeeRepository;
use TicketsSharp\Integration\Repositories\DefaultAttendeeRepository;
use TicketsSharp\Integration\Repositories\DefaultTicketRepository;
use TicketsSharp\Integration\Repositories\TicketRepository;
use TicketsSharp\Integration\Shop;
use TicketsSharp\Integration\StatusManager;
use TicketsSharp\Orders\AttendeeGenerator;
use TicketsSharp\Orders\Mailer;
use TicketsSharp\Orders\StatusMap;
use TicketsSharp\Orders\StatusSync;
use TicketsSharp\Stock\SharedCapacity;
use TicketsSharp\Stock\StockSync;
use TicketsSharp\Support\Keys;
use TicketsSharp\Support\Settings;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Boots Tickets Sharp into Event Tickets.
 *
 * Everything happens on `tec_tickets_bound_implementations`, the action Event
 * Tickets fires at the end of binding its own services (on `plugins_loaded`)
 * for exactly this purpose. That is early enough to be a provider before
 * Event Tickets' status manager reads the module list on `init`, and before
 * anything builds its Data_API map.
 */
final class Plugin
{
    public const ATTENDEE_REPOSITORY = 'tickets.attendee-repository.tickets-sharp';
    public const TICKET_REPOSITORY = 'tickets.ticket-repository.tickets-sharp';

    /** The commercial add-on's WooCommerce provider, as named in Event Tickets' own code. */
    public const CONFLICTING_PROVIDER = 'Tribe__Tickets_Plus__Commerce__WooCommerce__Main';

    private bool $integrated = false;

    private ?Provider $provider = null;

    /** @var \Closure(string): bool */
    private readonly \Closure $classExists;

    /**
     * @param (\Closure(string): bool)|null $classExists How to test for a class; injectable because
     *                                                  a class, once defined, cannot be undefined in a test.
     */
    public function __construct(private readonly Notices $notices = new Notices(), ?\Closure $classExists = null)
    {
        $this->classExists = $classExists ?? static fn (string $class): bool => class_exists($class);
    }

    public function boot(): void
    {
        $this->notices->register();
        add_action('before_woocommerce_init', [$this, 'declareCompatibility']);
        add_action('tec_tickets_bound_implementations', [$this, 'integrate']);
        add_action('plugins_loaded', [$this, 'reportIfNotIntegrated'], 99);
    }

    /**
     * Problems that stop the plugin running, as {@see Notices} codes.
     *
     * @return list<string>
     */
    public function requirementProblems(): array
    {
        $problems = [];
        $exists = $this->classExists;

        if (! $exists('WooCommerce')) {
            $problems[] = Notices::NO_WOOCOMMERCE;
        }
        if (! $exists('Tribe__Tickets__Tickets')) {
            $problems[] = Notices::NO_EVENT_TICKETS;
        } elseif (! StatusManager::isCompatible()) {
            $problems[] = Notices::INCOMPATIBLE_EVENT_TICKETS;
        }
        if ($exists(self::CONFLICTING_PROVIDER)) {
            $problems[] = Notices::CONFLICT;
        }

        return $problems;
    }

    public function integrate(): void
    {
        if ($this->integrated) {
            return;
        }

        $problems = $this->requirementProblems();
        if ($problems !== []) {
            foreach ($problems as $problem) {
                $this->notices->add($problem);
            }
            return;
        }

        $settings = new Settings();
        $statuses = new StatusMap($settings);
        $store = new AttendeeStore();
        $ledger = new Ledger($store, $statuses);
        $shop = new Shop();
        $eventTickets = new EventTickets();
        $urls = new Urls($settings);
        $products = new TicketProducts($shop, $eventTickets, $ledger);

        // Constructing the provider registers it with Event Tickets.
        $provider = new Provider($shop, $products, $store, $ledger, $urls, $statuses);
        tribe_singleton(Provider::class, $provider);
        $this->provider = $provider;

        StatusManager::register(tribe('tickets.status'));
        add_filter('tec_tickets_completed_status_by_provider_name', [$this, 'completedStatuses'], 10, 2);
        $this->bindRepositories();
        (new ProviderMeta())->register();
        (new Currency($shop, $eventTickets))->replace();

        $shared = new SharedCapacity($eventTickets, $ledger);
        $generator = new AttendeeGenerator($store, $products, $shop);
        $mailer = new Mailer($provider, $statuses);
        $details = new DetailsPage($shop, $urls);

        add_action('init', [$store, 'registerPostType']);

        $products->register();
        (new Availability($provider, $products, $shared, $shop))->register();
        (new StockSync($provider, $ledger, $shared, $eventTickets))->register();
        $generator->register();
        (new StatusSync($store, $generator, $statuses, $mailer, $shop))->register();
        $details->register();
        (new CartHandler($shop, $products, $urls, $details))->register();
        $qrCode = new QrCode($eventTickets);
        $qrCode->register();
        $ticketImage = new TicketImage();
        $ticketImage->register();
        (new OrderEmailTickets($provider, $statuses, $qrCode, $ticketImage))->register();
        (new Columns())->register();

        if (is_admin()) {
            (new TicketFormFields($provider))->register();
            (new SettingsPage())->register();
            (new OrderMetaBox($provider, $mailer, $shop))->register();
            (new TicketsTable())->register();
        }

        $this->integrated = true;

        /**
         * Fires once Tickets Sharp is registered with Event Tickets.
         *
         * @param Plugin $plugin
         */
        do_action('tickets_sharp/loaded', $this);
    }

    /**
     * Event Tickets' repositories are plain bindings (not singletons) so that
     * they can be replaced. The defaults gain Tickets Sharp's keys; the
     * provider's own repositories are added to the `tribe_tickets()` /
     * `tribe_attendees()` maps.
     */
    public function bindRepositories(): void
    {
        $container = tribe();
        $container->bind('tickets.attendee-repository', DefaultAttendeeRepository::class);
        $container->bind('tickets.ticket-repository', DefaultTicketRepository::class);
        $container->bind(self::ATTENDEE_REPOSITORY, AttendeeRepository::class);
        $container->bind(self::TICKET_REPOSITORY, TicketRepository::class);

        add_filter('tribe_tickets_attendee_repository_map', [$this, 'attendeeRepositoryMap']);
        add_filter('tribe_tickets_ticket_repository_map', [$this, 'ticketRepositoryMap']);
    }

    /**
     * @param mixed $map
     * @return mixed
     */
    public function attendeeRepositoryMap($map)
    {
        if (is_array($map)) {
            $map[Keys::ORM_PROVIDER] = self::ATTENDEE_REPOSITORY;
        }

        return $map;
    }

    /**
     * @param mixed $map
     * @return mixed
     */
    public function ticketRepositoryMap($map)
    {
        if (is_array($map)) {
            $map[Keys::ORM_PROVIDER] = self::TICKET_REPOSITORY;
        }

        return $map;
    }

    /**
     * The statuses in which Event Tickets' QR check-in accepts a Tickets Sharp
     * attendee.
     *
     * @param mixed $statuses
     * @param mixed $provider
     * @return mixed
     */
    public function completedStatuses($statuses, $provider)
    {
        $class = is_object($provider) ? get_class($provider) : $provider;
        if ($class !== Provider::class && $class !== Keys::PROVIDER_SLUG) {
            return $statuses;
        }

        return (new Settings())->paidStatuses();
    }

    public function declareCompatibility(): void
    {
        if (! class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class) || ! defined('TICKETS_SHARP_FILE')) {
            return;
        }

        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', TICKETS_SHARP_FILE, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', TICKETS_SHARP_FILE, true);
    }

    /**
     * Event Tickets missing altogether means its action never fired.
     */
    public function reportIfNotIntegrated(): void
    {
        if ($this->integrated || did_action('tec_tickets_bound_implementations') > 0) {
            return;
        }

        foreach ($this->requirementProblems() ?: [Notices::NO_EVENT_TICKETS] as $problem) {
            $this->notices->add($problem);
        }
    }

    public function isIntegrated(): bool
    {
        return $this->integrated;
    }

    public function provider(): ?Provider
    {
        return $this->provider;
    }
}
