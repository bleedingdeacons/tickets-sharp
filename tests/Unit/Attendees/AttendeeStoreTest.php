<?php

declare(strict_types=1);

use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Functions;
use TicketsSharp\Attendees\AttendeeStore;
use TicketsSharp\Support\Keys;
use TicketsSharp\Tests\Support\TestPost;

beforeEach(function (): void {
    $this->store = new AttendeeStore();
});

function attendeeData(array $overrides = []): array
{
    return array_merge([
        'event_id' => 10,
        'product_id' => 5,
        'order_id' => 900,
        'order_item_id' => 1,
        'status' => 'pending',
        'full_name' => 'Ann',
        'email' => 'ann@example.test',
        'purchaser_name' => 'Pat',
        'purchaser_email' => 'pat@example.test',
        'user_id' => 3,
        'price_paid' => '10.00',
        'currency' => 'GBP',
        'optout' => true,
        'fields' => ['diet' => 'Veg'],
        'security_code' => 'abc',
    ], $overrides);
}

it('registers a private post type', function (): void {
    Functions\expect('register_post_type')->once()->with(Keys::ATTENDEE_POST_TYPE, Mockery::on(
        static fn (array $args): bool => $args['public'] === false && $args['show_in_rest'] === false
    ))->andReturn((object) ['name' => Keys::ATTENDEE_POST_TYPE]);

    $this->store->registerPostType();
});

it('creates an attendee with its meta', function (): void {
    $id = $this->store->create(attendeeData());

    expect($id)->toBe(100)
        ->and(WpState::$insertedPosts[0])->toMatchArray(['post_type' => Keys::ATTENDEE_POST_TYPE, 'post_title' => '900 | Ann | 5'])
        ->and(WpState::$postMeta[100])->toMatchArray([
            Keys::ATTENDEE_EVENT => 10,
            Keys::ATTENDEE_PRODUCT => 5,
            Keys::ATTENDEE_ORDER => 900,
            Keys::ATTENDEE_STATUS => 'pending',
            Keys::ATTENDEE_FULL_NAME => 'Ann',
            Keys::ATTENDEE_OPTOUT => '1',
            Keys::ATTENDEE_FIELDS => ['diet' => 'Veg'],
            Keys::ATTENDEE_SECURITY_CODE => 'abc',
            Keys::ATTENDEE_CHECKIN => '',
        ]);

    $this->store->create(attendeeData(['optout' => false]));
    expect(WpState::$postMeta[101][Keys::ATTENDEE_OPTOUT])->toBe('');
});

it('returns 0 when the post cannot be inserted', function (): void {
    Functions\when('wp_insert_post')->justReturn(new WP_Error('db', 'nope'));

    expect($this->store->create(attendeeData()))->toBe(0);
});

it('queries attendees by meta', function (string $method, string $key): void {
    $post = TestPost::attendee(80);
    Functions\expect('get_posts')->once()->with(Mockery::on(
        static fn (array $args): bool => $args['post_type'] === Keys::ATTENDEE_POST_TYPE
            && $args['meta_query'][0] === ['key' => $key, 'value' => 7]
    ))->andReturn([$post, 'junk']);

    expect($this->store->{$method}(7))->toBe([$post]);
})->with([
    ['byOrder', Keys::ATTENDEE_ORDER],
    ['byTicket', Keys::ATTENDEE_PRODUCT],
    ['byEvent', Keys::ATTENDEE_EVENT],
    ['byOrderItem', Keys::ATTENDEE_ORDER_ITEM],
]);

it('does not query for ID 0', function (): void {
    Functions\expect('get_posts')->never();

    expect($this->store->byOrder(0))->toBe([]);
});

it('gets only its own posts', function (): void {
    $attendee = TestPost::attendee(80);
    TestPost::add(81, 'post');

    expect($this->store->get(80))->toBe($attendee)
        ->and($this->store->get(81))->toBeNull()
        ->and($this->store->get(82))->toBeNull();
});

it('reads and writes status, check-in and meta', function (): void {
    TestPost::attendee(80, [Keys::ATTENDEE_CHECKIN => '1', Keys::ATTENDEE_ORDER => '900', 'arr' => ['x']]);
    $this->store->setStatus(80, 'completed');

    expect($this->store->status(80))->toBe('completed')
        ->and($this->store->isCheckedIn(80))->toBeTrue()
        ->and($this->store->isCheckedIn(81))->toBeFalse()
        ->and($this->store->metaInt(80, Keys::ATTENDEE_ORDER))->toBe(900)
        ->and($this->store->metaInt(80, 'missing'))->toBe(0)
        ->and($this->store->metaString(80, 'arr'))->toBe('');
});

it('reads only well-formed field answers', function (): void {
    TestPost::attendee(80, [Keys::ATTENDEE_FIELDS => ['diet' => 'Veg', 0 => 'x', 'n' => ['no'], 'age' => 30]]);
    TestPost::attendee(81, [Keys::ATTENDEE_FIELDS => 'not an array']);

    expect($this->store->fields(80))->toBe(['diet' => 'Veg', 'age' => '30'])
        ->and($this->store->fields(81))->toBe([]);
});
