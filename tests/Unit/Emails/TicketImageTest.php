<?php

declare(strict_types=1);

use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Functions;
use TicketsSharp\Emails\TicketImage;
use TicketsSharp\Tests\Support\TestPost;

beforeEach(function (): void {
    $this->image = new TicketImage();
    Functions\when('wp_get_attachment_image_url')->alias(static fn (int $id, string $size): string|false => $id === 300 ? "https://example.test/uploads/ticket-{$size}.jpg" : false);
    Functions\when('wp_nonce_field')->justReturn('<input type="hidden" name="tsharp_ticket_image_nonce" value="n">');
    TestPost::add(10, 'tribe_events', ['post_title' => 'Winter Convention']);
    TestPost::add(300, 'attachment');
});

function emailTemplate(array $values): object
{
    return new class ($values) {
        public function __construct(private array $values)
        {
        }

        public function get(string $key): mixed
        {
            return $this->values[$key] ?? null;
        }
    };
}

it('hooks the e-mail, the event editor and its save', function (): void {
    $this->image->register();

    $this->assertActionAdded(TicketImage::HOOK);
    $this->assertActionAdded('add_meta_boxes');
    $this->assertActionAdded('save_post');
    $this->assertActionAdded('admin_enqueue_scripts');
});

it('shows the event\'s image in the ticket e-mail', function (): void {
    WpState::$postMeta[10][TicketImage::META] = 300;
    WpState::$postMeta[300]['_wp_attachment_image_alt'] = 'Snowy hall';

    expect($this->image->row(10))
        ->toContain('src="https://example.test/uploads/ticket-large.jpg"')
        ->toContain('alt="Snowy hall"')
        ->toStartWith('<tr><td');
});

it('uses the event title as alt text when the image has none', function (): void {
    WpState::$postMeta[10][TicketImage::META] = 300;

    expect($this->image->row(10))->toContain('alt="Winter Convention"');
});

it('adds nothing for an event without an image, or with a missing one', function (): void {
    expect($this->image->row(10))->toBe('')
        ->and($this->image->row(0))->toBe('');

    WpState::$postMeta[10][TicketImage::META] = 999;
    expect($this->image->row(10))->toBe('');
});

it('finds the e-mail\'s event from its post or its tickets', function (): void {
    WpState::$postMeta[10][TicketImage::META] = 300;

    expect(captureOutput(fn () => $this->image->renderForTemplate('', [], emailTemplate(['post_id' => 10]))))->toContain('ticket-large.jpg')
        ->and(captureOutput(fn () => $this->image->renderForTemplate('', [], emailTemplate(['tickets' => [['event_id' => '10']]]))))->toContain('ticket-large.jpg')
        ->and(captureOutput(fn () => $this->image->renderForTemplate('', [], emailTemplate([]))))->toBe('')
        ->and(captureOutput(fn () => $this->image->renderForTemplate('', [], 'not a template')))->toBe('');
});

it('adds a meta box to events', function (): void {
    Functions\expect('add_meta_box')->once()->with('tickets-sharp-ticket-image', 'Ticket e-mail image', Mockery::type('array'), 'tribe_events', 'side', 'low');

    $this->image->addMetaBox();
});

it('renders the picker with the current image', function (): void {
    WpState::$postMeta[10][TicketImage::META] = 300;

    $html = captureOutput(fn () => $this->image->renderMetaBox(WpState::$posts[10]));

    expect($html)->toContain('name="tsharp_ticket_image_id" value="300"')
        ->toContain('src="https://example.test/uploads/ticket-medium.jpg"')
        ->toContain('Choose image')
        ->not->toContain('display:none;"');

    expect($this->image->metaBoxHtml(11))->toContain('value=""')->toContain('display:none;');
    expect(captureOutput(fn () => $this->image->renderMetaBox('x')))->toBe('');
});

it('saves a chosen image and clears a removed one', function (): void {
    Functions\when('wp_verify_nonce')->justReturn(1);
    $_POST = [TicketImage::NONCE => 'n', TicketImage::INPUT => '300'];

    $this->image->save(10, WpState::$posts[10]);
    expect(WpState::$postMeta[10][TicketImage::META])->toBe(300);

    $_POST[TicketImage::INPUT] = '';
    $this->image->save(10, WpState::$posts[10]);
    expect(WpState::$postMeta[10])->not->toHaveKey(TicketImage::META);
});

it('refuses a non-image, a bad nonce, another post type or a user without rights', function (): void {
    Functions\when('wp_verify_nonce')->justReturn(1);
    TestPost::add(11, 'post');
    $_POST = [TicketImage::NONCE => 'n', TicketImage::INPUT => '11'];

    $this->image->save(10, WpState::$posts[10]);
    expect(WpState::$postMeta[10] ?? [])->not->toHaveKey(TicketImage::META);

    $_POST[TicketImage::INPUT] = '300';
    $this->image->save(11, WpState::$posts[11]);
    $this->image->save(10, 'not a post');
    WpState::$userCan = false;
    $this->image->save(10, WpState::$posts[10]);
    WpState::$userCan = true;
    Functions\when('wp_verify_nonce')->justReturn(false);
    $this->image->save(10, WpState::$posts[10]);

    expect(WpState::$postMeta[10] ?? [])->not->toHaveKey(TicketImage::META)
        ->and(WpState::$postMeta[11] ?? [])->not->toHaveKey(TicketImage::META);
});

it('loads the media picker on event screens only', function (): void {
    Functions\expect('wp_enqueue_media')->once();
    Functions\expect('wp_enqueue_script')->once()->with('tickets-sharp-ticket-image', Mockery::type('string'), ['jquery'], Mockery::any(), true);

    WpState::$screen = new WP_Screen(['post_type' => 'tribe_events']);
    $this->image->enqueue('post.php');

    WpState::$screen = new WP_Screen(['post_type' => 'page']);
    $this->image->enqueue('post.php');

    expect(is_readable(TICKETS_SHARP_DIR . 'assets/js/ticket-image.js'))->toBeTrue();
});
