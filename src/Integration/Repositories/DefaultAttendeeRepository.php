<?php

declare(strict_types=1);

namespace TicketsSharp\Integration\Repositories;

use Tribe__Tickets__Attendee_Repository;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Event Tickets' cross-provider attendee repository (`tribe_attendees()`), with
 * Tickets Sharp's attendees included. Bound in place of
 * `tickets.attendee-repository`, which Event Tickets binds (not as a singleton)
 * precisely so it can be replaced. The Attendees admin page, the CSV export and
 * the REST attendees endpoint all read through it.
 */
class DefaultAttendeeRepository extends Tribe__Tickets__Attendee_Repository
{
    use AddsAttendeeKeys;
}
