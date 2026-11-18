<?php

declare(strict_types=1);

namespace TicketsSharp\Integration\Repositories;

use TicketsSharp\Support\Keys;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Adds Tickets Sharp's post type and meta keys to Event Tickets' attendee
 * repository maps.
 *
 * Each of these maps is a hard-coded array on `Tribe__Tickets__Attendee_Repository`
 * with no filter. Every override calls the parent and only adds one entry, so
 * whatever Event Tickets lists in a future release is kept.
 *
 * @phpstan-require-extends \Tribe__Tickets__Attendee_Repository
 */
trait AddsAttendeeKeys
{
    /** @return array<string, string> */
    public function attendee_types()
    {
        return array_merge(parent::attendee_types(), [Keys::ORM_PROVIDER => Keys::ATTENDEE_POST_TYPE]);
    }

    /** @return array<string, string> */
    public function attendee_to_event_keys()
    {
        return array_merge(parent::attendee_to_event_keys(), [Keys::ORM_PROVIDER => Keys::ATTENDEE_EVENT]);
    }

    /** @return array<string, string> */
    public function attendee_to_ticket_keys()
    {
        return array_merge(parent::attendee_to_ticket_keys(), [Keys::ORM_PROVIDER => Keys::ATTENDEE_PRODUCT]);
    }

    /** @return array<string, string> */
    protected function attendee_to_order_keys()
    {
        return array_merge(parent::attendee_to_order_keys(), [Keys::ORM_PROVIDER => Keys::ATTENDEE_ORDER]);
    }

    /** @return array<string, string> */
    public function purchaser_name_keys()
    {
        return array_merge(parent::purchaser_name_keys(), [Keys::ORM_PROVIDER => Keys::ATTENDEE_PURCHASER_NAME]);
    }

    /** @return array<string, string> */
    public function purchaser_email_keys()
    {
        return array_merge(parent::purchaser_email_keys(), [Keys::ORM_PROVIDER => Keys::ATTENDEE_PURCHASER_EMAIL]);
    }

    /** @return array<string, string> */
    public function holder_name_keys()
    {
        return array_merge(parent::holder_name_keys(), [Keys::ORM_PROVIDER => Keys::ATTENDEE_FULL_NAME]);
    }

    /** @return array<string, string> */
    public function holder_email_keys()
    {
        return array_merge(parent::holder_email_keys(), [Keys::ORM_PROVIDER => Keys::ATTENDEE_EMAIL]);
    }

    /** @return array<string, string> */
    public function security_code_keys()
    {
        return array_merge(parent::security_code_keys(), [Keys::ORM_PROVIDER => Keys::ATTENDEE_SECURITY_CODE]);
    }

    /** @return array<string, string> */
    public function attendee_optout_keys()
    {
        return array_merge(parent::attendee_optout_keys(), [Keys::ORM_PROVIDER => Keys::ATTENDEE_OPTOUT]);
    }

    /** @return array<string, string> */
    public function checked_in_keys()
    {
        return array_merge(parent::checked_in_keys(), [Keys::ORM_PROVIDER => Keys::ATTENDEE_CHECKIN]);
    }
}
