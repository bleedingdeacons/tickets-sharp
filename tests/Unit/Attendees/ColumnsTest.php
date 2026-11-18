<?php

declare(strict_types=1);

use BleedingDeacons\WpMocks\WpState;
use TicketsSharp\Attendees\Columns;
use TicketsSharp\Attendees\FieldSet;
use TicketsSharp\Commerce\Provider;
use TicketsSharp\Support\Keys;

beforeEach(function (): void {
    $this->columns = new Columns();
});

it('adds an attendee information column to the list and the export', function (): void {
    $this->columns->register();

    $this->assertFilterAdded('tribe_tickets_attendee_table_columns');
    $this->assertFilterAdded('tribe_events_tickets_attendees_table_column');
    $this->assertFilterAdded('tribe_events_tickets_attendees_csv_export_columns');

    expect($this->columns->addColumn(['name' => 'Name']))->toBe(['name' => 'Name', Columns::COLUMN => 'Attendee information'])
        ->and($this->columns->addExportColumn([]))->toBe([Columns::COLUMN => 'Attendee information'])
        ->and($this->columns->addColumn('x'))->toBe('x');
});

it('lists the answers under their labels', function (): void {
    WpState::$postMeta[5][Keys::PRODUCT_FIELDS] = FieldSet::fromText("Dietary needs | text\nT-shirt | text");
    $item = ['provider' => Provider::class, 'product_id' => 5, 'attendee_meta' => ['dietary_needs' => 'Veg <b>', 't_shirt' => '', 'old_field' => 'L']];

    expect($this->columns->value('', $item, Columns::COLUMN))->toBe('Dietary needs: Veg &lt;b&gt;; Old field: L');
});

it('leaves other columns and other providers alone', function (): void {
    expect($this->columns->value('kept', ['provider' => Provider::class], 'name'))->toBe('kept')
        ->and($this->columns->value('kept', ['provider' => 'Other'], Columns::COLUMN))->toBe('')
        ->and($this->columns->value('kept', 'x', Columns::COLUMN))->toBe('kept');
});
