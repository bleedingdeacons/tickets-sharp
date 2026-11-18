<?php

declare(strict_types=1);

use TicketsSharp\Integration\Repositories\AttendeeRepository;
use TicketsSharp\Integration\Repositories\DefaultAttendeeRepository;
use TicketsSharp\Integration\Repositories\DefaultTicketRepository;
use TicketsSharp\Integration\Repositories\TicketRepository;
use TicketsSharp\Support\Keys;

beforeEach(function (): void {
    $GLOBALS['wpdb'] = (object) ['posts' => 'wp_posts', 'postmeta' => 'wp_postmeta'];
});

it('adds its attendees to Event Tickets\' default repository, keeping the others', function (): void {
    $repository = new DefaultAttendeeRepository();

    expect($repository->built['types'])->toMatchArray(['rsvp' => 'tribe_rsvp_attendees', Keys::ORM_PROVIDER => Keys::ATTENDEE_POST_TYPE])
        ->and($repository->built['event'])->toMatchArray(['tickets-commerce' => '_tec_tickets_commerce_event', Keys::ORM_PROVIDER => Keys::ATTENDEE_EVENT])
        ->and($repository->built['ticket'][Keys::ORM_PROVIDER])->toBe(Keys::ATTENDEE_PRODUCT)
        ->and($repository->built['order'][Keys::ORM_PROVIDER])->toBe(Keys::ATTENDEE_ORDER)
        ->and($repository->built['checkin'][Keys::ORM_PROVIDER])->toBe(Keys::ATTENDEE_CHECKIN)
        ->and($repository->purchaser_name_keys()[Keys::ORM_PROVIDER])->toBe(Keys::ATTENDEE_PURCHASER_NAME)
        ->and($repository->purchaser_email_keys()[Keys::ORM_PROVIDER])->toBe(Keys::ATTENDEE_PURCHASER_EMAIL)
        ->and($repository->holder_name_keys()[Keys::ORM_PROVIDER])->toBe(Keys::ATTENDEE_FULL_NAME)
        ->and($repository->holder_email_keys()[Keys::ORM_PROVIDER])->toBe(Keys::ATTENDEE_EMAIL)
        ->and($repository->security_code_keys()[Keys::ORM_PROVIDER])->toBe(Keys::ATTENDEE_SECURITY_CODE)
        ->and($repository->attendee_optout_keys()[Keys::ORM_PROVIDER])->toBe(Keys::ATTENDEE_OPTOUT)
        ->and($repository->holder_name_keys())->toHaveKey('rsvp');
});

it('narrows its own attendee repository to its attendees', function (): void {
    $repository = new AttendeeRepository();

    expect($repository->built['types'])->toBe([Keys::ORM_PROVIDER => Keys::ATTENDEE_POST_TYPE])
        ->and($repository->built['event'])->toBe([Keys::ORM_PROVIDER => Keys::ATTENDEE_EVENT])
        ->and($repository->built['order'])->toBe([Keys::ORM_PROVIDER => Keys::ATTENDEE_ORDER])
        ->and($repository->purchaser_name_keys())->toBe([Keys::ORM_PROVIDER => Keys::ATTENDEE_PURCHASER_NAME])
        ->and($repository->purchaser_email_keys())->toBe([Keys::ORM_PROVIDER => Keys::ATTENDEE_PURCHASER_EMAIL])
        ->and($repository->holder_name_keys())->toBe([Keys::ORM_PROVIDER => Keys::ATTENDEE_FULL_NAME])
        ->and($repository->holder_email_keys())->toBe([Keys::ORM_PROVIDER => Keys::ATTENDEE_EMAIL])
        ->and($repository->security_code_keys())->toBe([Keys::ORM_PROVIDER => Keys::ATTENDEE_SECURITY_CODE])
        ->and($repository->attendee_optout_keys())->toBe([Keys::ORM_PROVIDER => Keys::ATTENDEE_OPTOUT])
        ->and($repository->checked_in_keys())->toBe([Keys::ORM_PROVIDER => Keys::ATTENDEE_CHECKIN])
        ->and($repository->attendee_to_ticket_keys())->toBe([Keys::ORM_PROVIDER => Keys::ATTENDEE_PRODUCT])
        ->and($repository->get_create_args()['post_type'])->toBe(Keys::ATTENDEE_POST_TYPE)
        ->and($repository->get_update_fields_aliases())->toMatchArray([
            'ticket_id' => Keys::ATTENDEE_PRODUCT,
            'order_id' => Keys::ATTENDEE_ORDER,
            'attendee_status' => Keys::ATTENDEE_STATUS,
            'full_name' => Keys::ATTENDEE_FULL_NAME,
        ]);
});

it('counts only linked products as tickets in the default ticket repository', function (): void {
    $repository = new DefaultTicketRepository();

    expect($repository->ticket_types())->toMatchArray(['rsvp' => 'tribe_rsvp_tickets', Keys::ORM_PROVIDER => 'product'])
        ->and($repository->ticket_to_event_keys()[Keys::ORM_PROVIDER])->toBe(Keys::TICKET_EVENT)
        ->and($repository->filter_query->wheres['tsharp-only-ticket-products'])
            ->toContain("wp_posts.post_type <> 'product'")
            ->toContain("meta_key = '_tsharp_for_event'");
});

it('narrows its own ticket repository to ticket products', function (): void {
    $repository = new TicketRepository();

    expect($repository->ticket_types())->toBe([Keys::ORM_PROVIDER => 'product'])
        ->and($repository->ticket_to_event_keys())->toBe([Keys::ORM_PROVIDER => Keys::TICKET_EVENT]);
});
