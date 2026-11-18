<?php

declare(strict_types=1);

namespace TicketsSharp\Tests;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use BleedingDeacons\WpMocks\TestCase as WpMocksTestCase;
use Tribe__Container;
use Tribe__Tickets__Tickets;
use Tribe__Tickets__Tickets_View;

/**
 * Base TestCase: wp-mocks' (Brain Monkey's lifecycle, Mockery integration, hook
 * assertions, WpState reset) plus a reset of the state the Event Tickets and
 * WooCommerce stubs keep between tests.
 *
 * The tests are closure-based Pest files; tests/Pest.php binds them to this.
 */
abstract class TestCase extends WpMocksTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Tribe__Container::reset();
        Tribe__Tickets__Tickets::resetModules();
        Tribe__Tickets__Tickets_View::$instance = null;
        FeaturesUtil::$declared = [];
        $_GET = [];
        $_POST = [];
    }
}
