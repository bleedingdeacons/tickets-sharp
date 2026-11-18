<?php

declare(strict_types=1);

// Pest configuration.
//
// Every test runs on TicketsSharp\Tests\TestCase, which wraps wp-mocks':
// Brain Monkey's lifecycle, Mockery integration and the hook assertions, plus
// a reset of the Event Tickets and WooCommerce stubs' own state.

use TicketsSharp\Tests\TestCase;

pest()->extend(TestCase::class)->in('Unit');

/**
 * Runs $render inside an output buffer and returns what it printed, with
 * Windows line endings normalised. The buffer is closed in a finally, so a
 * render that throws cannot leave it open and have PHPUnit flag the test risky.
 */
function captureOutput(callable $render): string
{
    ob_start();

    try {
        $render();
    } finally {
        $html = (string) ob_get_clean();
    }

    return str_replace("\r\n", "\n", $html);
}
