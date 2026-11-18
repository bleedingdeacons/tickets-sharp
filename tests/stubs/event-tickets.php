<?php

/**
 * Stand-ins for the parts of Event Tickets (and its bundled Tribe Common) that
 * Tickets Sharp touches.
 *
 * Loaded by tests/bootstrap.php, after Patchwork, so Brain Monkey can redefine
 * the functions; and listed under scanFiles in phpstan.neon.dist. Signatures
 * and member names follow Event Tickets 5.31 (src/Tribe/Tickets.php and
 * friends). Bodies are the minimum that keeps a unit test meaningful: tests set
 * behaviour with Brain Monkey and Mockery, never by relying on a stub's return.
 */

declare(strict_types=1);

// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps
// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps
// phpcs:disable PSR2.Classes.PropertyDeclaration.Underscore

namespace {
    if (! class_exists('Tribe__Container')) {
        /**
         * The DI52-backed container behind tribe(). Records bindings so tests can
         * assert on what was bound.
         */
        class Tribe__Container
        {
            /** @var array<string, mixed> */
            public static array $bindings = [];

            /** @var array<string, mixed> */
            public static array $singletons = [];

            public static ?Tribe__Container $instance = null;

            public static function init(): Tribe__Container
            {
                return self::$instance ??= new self();
            }

            /**
             * @param string $id
             * @param mixed  $implementation
             * @param array<int, string>|null $afterBuildMethods
             */
            public function bind($id, $implementation = null, ?array $afterBuildMethods = null): void
            {
                self::$bindings[$id] = $implementation;
                unset(self::$singletons[$id]);
            }

            /**
             * @param string $id
             * @param mixed  $implementation
             * @param array<int, string>|null $afterBuildMethods
             */
            public function singleton($id, $implementation = null, ?array $afterBuildMethods = null): void
            {
                self::$singletons[$id] = $implementation;
            }

            /**
             * @param string $id
             * @return mixed
             */
            public function make($id)
            {
                $entry = self::$singletons[$id] ?? self::$bindings[$id] ?? null;
                if (is_object($entry)) {
                    return $entry;
                }
                if (is_string($entry) && class_exists($entry)) {
                    $object = new $entry();
                    if (isset(self::$singletons[$id])) {
                        self::$singletons[$id] = $object;
                    }
                    return $object;
                }

                return null;
            }

            public static function reset(): void
            {
                self::$bindings = [];
                self::$singletons = [];
            }
        }
    }

    if (! function_exists('tribe')) {
        /**
         * @param string|null $slug
         * @return mixed
         */
        function tribe($slug = null)
        {
            $container = Tribe__Container::init();

            return $slug === null ? $container : $container->make($slug);
        }
    }

    if (! function_exists('tribe_singleton')) {
        /**
         * @param string $slug
         * @param mixed  $class
         * @param array<int, string>|null $afterBuildMethods
         */
        function tribe_singleton($slug, $class, ?array $afterBuildMethods = null): void
        {
            Tribe__Container::init()->singleton($slug, $class, $afterBuildMethods);
        }
    }

    if (! function_exists('tribe_tickets_get_capacity')) {
        /**
         * @param int|WP_Post $post
         * @return int|null
         */
        function tribe_tickets_get_capacity($post)
        {
            return -1;
        }
    }

    if (! function_exists('tribe_tickets_update_capacity')) {
        /**
         * @param mixed $object
         * @param int   $capacity
         * @return bool|int
         */
        function tribe_tickets_update_capacity($object, $capacity)
        {
            return true;
        }
    }

    if (! class_exists('Tribe__Repository__Query_Filters')) {
        class Tribe__Repository__Query_Filters
        {
            /** @var array<string, string> */
            public array $wheres = [];

            /**
             * @param string      $where_clause
             * @param string|null $id
             * @param bool        $override
             */
            public function where($where_clause, $id = null, $override = false): void
            {
                $this->wheres[$id ?? (string) count($this->wheres)] = $where_clause;
            }

            /**
             * @param string      $join_clause
             * @param string|null $id
             * @param bool        $override
             */
            public function join($join_clause, $id = null, $override = false): void
            {
            }
        }
    }

    if (! class_exists('Tribe__Repository')) {
        class Tribe__Repository
        {
            /** @var Tribe__Repository__Query_Filters */
            public $filter_query;

            /** @var array<string, mixed> */
            protected $default_args = [];

            /** @var array<string, mixed> */
            protected $create_args = [];

            /** @var array<string, string> */
            protected $update_fields_aliases = [];

            public function __construct()
            {
                $this->filter_query = new Tribe__Repository__Query_Filters();
            }

            /** @return array<string, mixed> */
            public function get_create_args(): array
            {
                return $this->create_args;
            }

            /** @return array<string, string> */
            public function get_update_fields_aliases(): array
            {
                return $this->update_fields_aliases;
            }
        }
    }

    if (! class_exists('Tribe__Tickets__Attendee_Repository')) {
        class Tribe__Tickets__Attendee_Repository extends Tribe__Repository
        {
            /** @var string */
            protected $key_name = '';

            /** @var mixed */
            protected $attendee_provider;

            /** @var array<string, array<string, string>> What each key map returned at construction. */
            public array $built = [];

            public function __construct()
            {
                parent::__construct();
                $this->create_args['post_type'] = current($this->attendee_types());
                $this->update_fields_aliases = [
                    'ticket_id' => '_tribe_tickets_ticket_id',
                    'full_name' => '_tribe_tickets_full_name',
                ];
                $this->built = [
                    'types' => $this->attendee_types(),
                    'event' => $this->attendee_to_event_keys(),
                    'ticket' => $this->attendee_to_ticket_keys(),
                    'order' => $this->attendee_to_order_keys(),
                    'checkin' => $this->checked_in_keys(),
                ];
            }

            /** @return array<string, string> */
            public function attendee_types()
            {
                return ['rsvp' => 'tribe_rsvp_attendees', 'tribe-commerce' => 'tribe_tpp_attendees', 'tickets-commerce' => 'tec_tc_attendee'];
            }

            /** @return array<string, string> */
            public function attendee_to_event_keys()
            {
                return ['rsvp' => '_tribe_rsvp_event', 'tribe-commerce' => '_tribe_tpp_event', 'tickets-commerce' => '_tec_tickets_commerce_event'];
            }

            /** @return array<string, string> */
            public function attendee_to_ticket_keys()
            {
                return ['rsvp' => '_tribe_rsvp_product', 'tribe-commerce' => '_tribe_tpp_product', 'tickets-commerce' => '_tec_tickets_commerce_ticket'];
            }

            /** @return array<string, string> */
            protected function attendee_to_order_keys()
            {
                return ['rsvp' => '_tribe_rsvp_order', 'tribe-commerce' => '_tribe_tpp_order', 'tickets-commerce' => '_tec_tickets_commerce_order'];
            }

            /** @return array<string, string> */
            public function purchaser_name_keys()
            {
                return ['rsvp' => '_tribe_rsvp_full_name'];
            }

            /** @return array<string, string> */
            public function purchaser_email_keys()
            {
                return ['rsvp' => '_tribe_rsvp_email'];
            }

            /** @return array<string, string> */
            public function holder_name_keys()
            {
                return ['rsvp' => '_tribe_rsvp_full_name'];
            }

            /** @return array<string, string> */
            public function holder_email_keys()
            {
                return ['rsvp' => '_tribe_rsvp_email'];
            }

            /** @return array<string, string> */
            public function security_code_keys()
            {
                return ['rsvp' => '_tribe_rsvp_security_code'];
            }

            /** @return array<string, string> */
            public function attendee_optout_keys()
            {
                return ['rsvp' => '_tribe_rsvp_attendee_optout'];
            }

            /** @return array<string, string> */
            public function checked_in_keys()
            {
                return ['rsvp' => '_tribe_rsvp_checkedin'];
            }

            /**
             * @param string               $key
             * @param array<string, string> $list
             * @return array<string, string>
             */
            protected function limit_list($key, $list)
            {
                return array_key_exists($key, $list) ? [$key => $list[$key]] : [];
            }
        }
    }

    if (! class_exists('Tribe__Tickets__Ticket_Repository')) {
        class Tribe__Tickets__Ticket_Repository extends Tribe__Repository
        {
            /** @return array<string, string> */
            public function ticket_types()
            {
                return ['rsvp' => 'tribe_rsvp_tickets', 'tribe-commerce' => 'tribe_tpp_tickets', 'tickets-commerce' => 'tec_tc_ticket'];
            }

            /** @return array<string, string> */
            public function ticket_to_event_keys()
            {
                return ['rsvp' => '_tribe_rsvp_for_event', 'tribe-commerce' => '_tribe_tpp_for_event', 'tickets-commerce' => '_tec_tickets_commerce_event'];
            }
        }
    }

    if (! class_exists('Tribe__Tickets__Status__Abstract')) {
        abstract class Tribe__Tickets__Status__Abstract
        {
            /** @var string */
            public $name = '';
            /** @var string */
            public $provider_name = '';
            /** @var array<int, string> */
            public $additional_names = [];
            /** @var string */
            public $post_type = '';
            /** @var bool */
            public $incomplete = false;
            /** @var bool */
            public $warning = false;
            /** @var bool */
            public $trigger_option = false;
            /** @var bool */
            public $attendee_generation = false;
            /** @var bool */
            public $attendee_dispatch = false;
            /** @var bool */
            public $stock_reduced = false;
            /** @var bool */
            public $count_attendee = false;
            /** @var bool */
            public $count_sales = false;
            /** @var bool */
            public $count_completed = false;
            /** @var bool */
            public $count_canceled = false;
            /** @var bool */
            public $count_incomplete = false;
            /** @var bool */
            public $count_refunded = false;
            /** @var bool */
            public $count_not_going = false;
        }
    }

    if (! class_exists('Tribe__Tickets__Status__Abstract_Commerce')) {
        class Tribe__Tickets__Status__Abstract_Commerce
        {
            /** @var string */
            public $completed_status_id;
            /** @var array<int, string> */
            public $status_names = [];
            /** @var array<string, Tribe__Tickets__Status__Abstract> */
            public $statuses = [];

            /** @return void */
            public function initialize_status_classes()
            {
            }
        }
    }

    if (! class_exists('Tribe__Tickets__Status__Manager')) {
        class Tribe__Tickets__Status__Manager
        {
            /** @var array<string, string> */
            protected $module_slugs = [
                'Tribe__Tickets__RSVP' => 'rsvp',
                'Tribe__Tickets__Commerce__PayPal__Main' => 'tpp',
            ];

            /** @var array<string, string> */
            protected $status_managers = [
                'rsvp' => 'Tribe__Tickets__RSVP__Status_Manager',
                'tpp' => 'Tribe__Tickets__Commerce__PayPal__Status_Manager',
            ];

            /**
             * @param mixed $module
             * @return string|false
             */
            public function get_provider_slug($module)
            {
                return is_string($module) && isset($this->module_slugs[$module]) ? $this->module_slugs[$module] : false;
            }

            /** @return array<string, string> */
            public function get_status_managers()
            {
                return $this->status_managers;
            }
        }
    }

    if (! class_exists('Tribe__Tickets__Global_Stock')) {
        class Tribe__Tickets__Global_Stock
        {
            public const GLOBAL_STOCK_ENABLED = '_tribe_ticket_use_global_stock';
            public const GLOBAL_STOCK_LEVEL = '_tribe_ticket_global_stock_level';
            public const GLOBAL_STOCK_MODE = 'global';
            public const CAPPED_STOCK_MODE = 'capped';
            public const OWN_STOCK_MODE = 'own';
            public const UNLIMITED_STOCK_MODE = 'unlimited';
            public const TICKET_STOCK_MODE = '_global_stock_mode';
            public const TICKET_STOCK_CAP = '_global_stock_cap';

            /** @param int $post_id */
            public function __construct($post_id)
            {
            }

            /** @param bool $yes */
            public function enable($yes = true): void
            {
            }

            /** @return bool */
            public function is_enabled()
            {
                return false;
            }

            /**
             * @param int  $quantity
             * @param bool $force
             */
            public function set_stock_level($quantity, $force = false): void
            {
            }

            /** @return int */
            public function get_stock_level()
            {
                return 0;
            }
        }
    }

    if (! class_exists('Tribe__Tickets__Tickets_Handler')) {
        class Tribe__Tickets__Tickets_Handler
        {
            /** @var string */
            public $key_capacity = '_tribe_ticket_capacity';
            /** @var string */
            public $key_show_description = '_tribe_ticket_show_description';
            /** @var string */
            public $key_provider_field = '_tribe_default_ticket_provider';

            /**
             * @param int|WP_Post                    $post
             * @param string|Tribe__Tickets__Tickets $provider
             * @return bool
             */
            public function is_correct_provider($post, $provider)
            {
                return false;
            }
        }
    }

    if (! class_exists('Tribe__Tickets__Ticket_Object')) {
        /**
         * @property string $post_type
         */
        class Tribe__Tickets__Ticket_Object
        {
            /** @var int|string|null */
            public $ID;
            /** @var string */
            public $name = '';
            /** @var string */
            public $description = '';
            /** @var bool|string */
            public $show_description = true;
            /** @var string|float|int */
            public $price = '';
            /** @var string|float|int */
            public $regular_price = '';
            /** @var bool */
            public $on_sale = false;
            /** @var string */
            public $admin_link = '';
            /** @var string */
            public $frontend_link = '';
            /** @var string */
            public $provider_class = '';
            /** @var string */
            public $sku = '';
            /** @var int|string */
            public $menu_order = 0;
            /** @var string */
            public $start_date = '';
            /** @var string */
            public $start_time = '';
            /** @var string */
            public $end_date = '';
            /** @var string */
            public $end_time = '';

            /** @var array<string, mixed> */
            private array $values = [
                'manage_stock' => false,
                'stock' => -1,
                'global_stock_mode' => '',
                'global_stock_cap' => 0,
                'qty_sold' => 0,
                'qty_pending' => 0,
                'qty_cancelled' => 0,
                'qty_refunded' => 0,
            ];

            /** @var array<string, mixed> */
            private array $dynamic = [];

            /** @param array<string, mixed>|null $data */
            public function __construct(?array $data = null)
            {
            }

            /**
             * @param bool|null $manages_stock
             * @return bool
             */
            public function manage_stock($manages_stock = null)
            {
                return (bool) $this->accessor('manage_stock', $manages_stock);
            }

            /** @return bool */
            public function managing_stock()
            {
                return (bool) $this->values['manage_stock'];
            }

            /**
             * @param int|null $value
             * @return int
             */
            public function stock($value = null)
            {
                return (int) $this->accessor('stock', $value);
            }

            /**
             * @param string|null $mode
             * @return string
             */
            public function global_stock_mode($mode = null)
            {
                return (string) $this->accessor('global_stock_mode', $mode);
            }

            /**
             * @param int|null $cap
             * @return int
             */
            public function global_stock_cap($cap = null)
            {
                return (int) $this->accessor('global_stock_cap', $cap);
            }

            /**
             * @param int|null $value
             * @return int
             */
            public function qty_sold($value = null)
            {
                return (int) $this->accessor('qty_sold', $value);
            }

            /**
             * @param int|null $value
             * @return int
             */
            public function qty_pending($value = null)
            {
                return (int) $this->accessor('qty_pending', $value);
            }

            /**
             * @param int|null $value
             * @return int
             */
            public function qty_cancelled($value = null)
            {
                return (int) $this->accessor('qty_cancelled', $value);
            }

            /**
             * @param int|null $value
             * @return int
             */
            public function qty_refunded($value = null)
            {
                return (int) $this->accessor('qty_refunded', $value);
            }

            /** @return bool */
            public function show_description()
            {
                return true;
            }

            /** @return int */
            public function available()
            {
                return -1;
            }

            /** @return int */
            public function inventory()
            {
                return -1;
            }

            /**
             * @param mixed $datetime
             * @return bool
             */
            public function date_in_range($datetime = null)
            {
                return true;
            }

            /** @return int */
            public function get_event_id()
            {
                return 0;
            }

            /** @return array<string, mixed> */
            public function to_array(): array
            {
                return get_object_vars($this);
            }

            /**
             * @param string $var
             * @return mixed
             */
            public function __get($var)
            {
                return $this->dynamic[$var] ?? null;
            }

            /**
             * @param string $var
             * @param mixed  $value
             */
            public function __set($var, $value): void
            {
                $this->dynamic[$var] = $value;
            }

            /**
             * @param string $var
             */
            public function __isset($var): bool
            {
                return isset($this->dynamic[$var]);
            }

            private function accessor(string $key, mixed $value): mixed
            {
                if ($value !== null) {
                    $this->values[$key] = $value;
                }

                return $this->values[$key];
            }
        }
    }

    if (! class_exists('Tribe__Tickets__Tickets_View')) {
        class Tribe__Tickets__Tickets_View
        {
            public static ?Tribe__Tickets__Tickets_View $instance = null;

            /** @var list<int> */
            public array $rendered = [];

            /** @return Tribe__Tickets__Tickets_View */
            public static function instance()
            {
                return self::$instance ??= new self();
            }

            /**
             * @param int|WP_Post $post
             * @param bool        $echo
             * @return string
             */
            public function get_tickets_block($post, $echo = true)
            {
                $this->rendered[] = is_object($post) ? (int) $post->ID : (int) $post;

                return '';
            }
        }
    }

    if (! class_exists('Tribe__Tickets__Tickets')) {
        /**
         * The provider base class. Its constructor registers the provider, as
         * Event Tickets' does; the rest are the methods Tickets Sharp calls on
         * its parent.
         */
        class Tribe__Tickets__Tickets
        {
            /** @var array<string, string> */
            protected static $active_modules = [];

            /** @var string */
            public $class_name;
            /** @var string */
            public $plugin_name;
            /** @var string */
            public $ticket_object = '';
            /** @var string */
            public $attendee_subscribed = '_tribe_tickets_subscribed';
            /** @var string */
            public $attendee_user_id = '_tribe_tickets_attendee_user_id';
            /** @var string */
            public $order_object = '';
            /** @var string */
            public $attendee_object = '';
            /** @var string */
            public $attendee_event_key = '';
            /** @var string */
            public $attendee_product_key = '';
            /** @var string */
            public $attendee_ticket_sent = '_tribe_attendee_ticket_sent';
            /** @var string */
            public $attendee_activity_log = '_tribe_attendee_activity_log';
            /** @var string */
            public $attendee_optout_key = '';
            /** @var string */
            public $full_name = '_tribe_tickets_full_name';
            /** @var string */
            public $email = '_tribe_tickets_email';
            /** @var string */
            public $security_code = '_tribe_tickets_security_code';
            /** @var string */
            public $price_paid = '_paid_price';
            /** @var string */
            public $price_currency = '_price_currency_symbol';
            /** @var string */
            public $orm_provider = 'default';
            /** @var string */
            public $checkin_key = '';
            /** @var string */
            public $event_key;

            /** @var list<array{string, mixed}> Calls that reached the base class, for assertions. */
            public array $parentCalls = [];

            public function __construct()
            {
                $this->class_name = get_class($this);
                self::$active_modules[$this->class_name] = $this->plugin_name;
            }

            /** @return array<string, string> */
            public static function modules()
            {
                return self::$active_modules;
            }

            public static function resetModules(): void
            {
                self::$active_modules = [];
            }

            /** @return mixed */
            public static function get_instance()
            {
                return null;
            }

            /**
             * @param int|null $event_id
             * @return string|false
             */
            public static function get_event_ticket_provider($event_id = null)
            {
                return false;
            }

            /**
             * @param int|null $post_id
             * @return Tribe__Tickets__Tickets|false
             */
            public static function get_event_ticket_provider_object($post_id = null)
            {
                return false;
            }

            /**
             * @param int|WP_Post|null $post_id
             * @param int|string       $ticket_id
             * @return Tribe__Tickets__Ticket_Object|null
             */
            public function get_ticket($post_id, $ticket_id)
            {
                return null;
            }

            /**
             * @param int|WP_Post $post
             * @return array<int, int>|false
             */
            public function get_tickets_ids($post = 0, ?string $context = null)
            {
                return [];
            }

            /**
             * @param int|string $post_id
             * @return array<int, Tribe__Tickets__Ticket_Object>
             */
            public function get_tickets($post_id, ?string $context = null)
            {
                $ids = $this->get_tickets_ids($post_id, $context);
                $tickets = [];
                foreach (is_array($ids) ? $ids : [] as $id) {
                    $ticket = $this->get_ticket($post_id, $id);
                    if ($ticket instanceof Tribe__Tickets__Ticket_Object) {
                        $tickets[] = $ticket;
                    }
                }

                return $tickets;
            }

            /**
             * @param int|string                    $post_id
             * @param Tribe__Tickets__Ticket_Object $ticket
             * @param array<string, mixed>          $raw_data
             * @return int|false
             */
            public function save_ticket($post_id, $ticket, $raw_data = [])
            {
                $this->parentCalls[] = ['save_ticket', $post_id];

                return false;
            }

            /**
             * @param int|string $post_id
             * @param int|string $ticket_id
             * @return bool
             */
            public function delete_ticket($post_id, $ticket_id)
            {
                $this->parentCalls[] = ['delete_ticket', $ticket_id];

                return true;
            }

            /**
             * @param int|WP_Post $attendee
             * @param int         $post_id
             * @return array<string, mixed>|false
             */
            public function get_attendee($attendee, $post_id = 0)
            {
                return false;
            }

            /**
             * @param int|string $attendee_id
             * @param mixed      $qr
             * @param mixed      $event_id
             * @param array<string, mixed> $details
             * @return bool
             */
            public function checkin($attendee_id, $qr = null, $event_id = null, $details = [])
            {
                $this->parentCalls[] = ['checkin', $attendee_id];

                return true;
            }

            /**
             * @param int|string $attendee_id
             * @param bool       $app
             * @return bool
             */
            public function uncheckin($attendee_id, $app = false)
            {
                $this->parentCalls[] = ['uncheckin', $attendee_id];

                return true;
            }

            /**
             * @param array<int, mixed>    $attendees
             * @param array<string, mixed> $args
             * @return bool
             */
            public function send_tickets_email_for_attendees($attendees, $args = [])
            {
                $this->parentCalls[] = ['send_tickets_email_for_attendees', $args];

                return true;
            }

            /** @param int|string $post_id */
            public function clear_ticket_cache_for_post($post_id): void
            {
            }

            /** @param int|string $post_id */
            public function clear_attendees_cache($post_id): void
            {
            }
        }
    }
}

namespace TEC\Common\QR {
    if (! class_exists(QR::class)) {
        class QR
        {
            public int $size = 3;
            public int $margin = 4;

            public function size(int $value): self
            {
                $this->size = $value;

                return $this;
            }

            public function margin(int $value): self
            {
                $this->margin = $value;

                return $this;
            }

            /**
             * @return array{file: string, url: string, type: string, error: string|false}
             */
            public function get_png_as_file(string $data, string $name, string $folder = 'tec-qr-codes'): array
            {
                return ['file' => '', 'url' => '', 'type' => 'image/png', 'error' => false];
            }
        }
    }
}

namespace TEC\Tickets\QR {
    if (! class_exists(Connector::class)) {
        class Connector
        {
            /**
             * @param int|string $ticket_id
             * @param int|string $event_id
             */
            public function get_checkin_url($ticket_id, $event_id, string $security_code): string
            {
                return '';
            }

            public function get_image_url_for_link(?string $link): ?string
            {
                return null;
            }
        }
    }
}
