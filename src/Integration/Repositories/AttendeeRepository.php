<?php

declare(strict_types=1);

namespace TicketsSharp\Integration\Repositories;

use TicketsSharp\Support\Keys;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * `tribe_attendees('tickets-sharp')`: the default repository narrowed to Tickets
 * Sharp's attendees only, the way Event Tickets' own PayPal repository narrows
 * itself to `tribe-commerce`.
 */
final class AttendeeRepository extends DefaultAttendeeRepository
{
    /** @var string */
    protected $key_name = Keys::ORM_PROVIDER;

    public function __construct()
    {
        parent::__construct();

        $this->create_args['post_type'] = Keys::ATTENDEE_POST_TYPE;

        $this->update_fields_aliases = array_merge(
            $this->update_fields_aliases,
            [
                'ticket_id' => Keys::ATTENDEE_PRODUCT,
                'event_id' => Keys::ATTENDEE_EVENT,
                'post_id' => Keys::ATTENDEE_EVENT,
                'security_code' => Keys::ATTENDEE_SECURITY_CODE,
                'order_id' => Keys::ATTENDEE_ORDER,
                'optout' => Keys::ATTENDEE_OPTOUT,
                'user_id' => Keys::ATTENDEE_USER_ID,
                'price_paid' => Keys::ATTENDEE_PRICE_PAID,
                'price_currency' => Keys::ATTENDEE_CURRENCY,
                'full_name' => Keys::ATTENDEE_FULL_NAME,
                'email' => Keys::ATTENDEE_EMAIL,
                'attendee_status' => Keys::ATTENDEE_STATUS,
                'check_in' => Keys::ATTENDEE_CHECKIN,
            ]
        );
    }

    /** @return array<string, string> */
    public function attendee_types()
    {
        return $this->limit_list($this->key_name, parent::attendee_types());
    }

    /** @return array<string, string> */
    public function attendee_to_event_keys()
    {
        return $this->limit_list($this->key_name, parent::attendee_to_event_keys());
    }

    /** @return array<string, string> */
    public function attendee_to_ticket_keys()
    {
        return $this->limit_list($this->key_name, parent::attendee_to_ticket_keys());
    }

    /** @return array<string, string> */
    protected function attendee_to_order_keys()
    {
        return $this->limit_list($this->key_name, parent::attendee_to_order_keys());
    }

    /** @return array<string, string> */
    public function purchaser_name_keys()
    {
        return $this->limit_list($this->key_name, parent::purchaser_name_keys());
    }

    /** @return array<string, string> */
    public function purchaser_email_keys()
    {
        return $this->limit_list($this->key_name, parent::purchaser_email_keys());
    }

    /** @return array<string, string> */
    public function holder_name_keys()
    {
        return $this->limit_list($this->key_name, parent::holder_name_keys());
    }

    /** @return array<string, string> */
    public function holder_email_keys()
    {
        return $this->limit_list($this->key_name, parent::holder_email_keys());
    }

    /** @return array<string, string> */
    public function security_code_keys()
    {
        return $this->limit_list($this->key_name, parent::security_code_keys());
    }

    /** @return array<string, string> */
    public function attendee_optout_keys()
    {
        return $this->limit_list($this->key_name, parent::attendee_optout_keys());
    }

    /** @return array<string, string> */
    public function checked_in_keys()
    {
        return $this->limit_list($this->key_name, parent::checked_in_keys());
    }
}
