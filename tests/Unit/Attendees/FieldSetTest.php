<?php

declare(strict_types=1);

use TicketsSharp\Attendees\FieldSet;

it('parses the admin text format', function (): void {
    $fields = FieldSet::fromText("Name | name | required\nEmail | email | yes\n# a comment\n\nDietary needs | select | optional | None, Vegan , None\nT-shirt");

    expect($fields)->toBe([
        ['key' => 'name', 'label' => 'Name', 'type' => 'name', 'required' => true, 'options' => []],
        ['key' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'options' => []],
        ['key' => 'dietary_needs', 'label' => 'Dietary needs', 'type' => 'select', 'required' => false, 'options' => ['None', 'Vegan']],
        ['key' => 't_shirt', 'label' => 'T-shirt', 'type' => 'text', 'required' => false, 'options' => []],
    ]);
});

it('round-trips through text', function (): void {
    $text = "Name | name | required\nDiet | select | optional | Meat, Veg";

    expect(FieldSet::toText(FieldSet::fromText($text)))->toBe($text);
});

it('falls back to text for unknown types and for selects without options', function (): void {
    $fields = FieldSet::fromText("Colour | rainbow\nSize | select");

    expect(array_column($fields, 'type'))->toBe(['text', 'text']);
});

it('drops duplicate keys, blank labels and junk', function (): void {
    $fields = FieldSet::parse([
        ['label' => 'Name', 'type' => 'name'],
        ['label' => 'name', 'type' => 'text'],
        ['label' => '   '],
        ['label' => '!!!'],
        'not an array',
    ]);

    expect($fields)->toHaveCount(1);
});

it('accepts stored JSON and an explicit key', function (): void {
    $fields = FieldSet::parse(json_encode([['label' => 'Your club', 'key' => 'Club', 'type' => 'text']]));

    expect($fields[0]['key'])->toBe('club');
});

it('returns nothing for empty or invalid input', function (): void {
    expect(FieldSet::parse(''))->toBe([])
        ->and(FieldSet::parse('{not json'))->toBe([])
        ->and(FieldSet::parse(42))->toBe([]);
});

it('validates required fields, emails, selects and phone numbers', function (): void {
    $fields = FieldSet::fromText("Name | name | required\nEmail | email | required\nDiet | select | optional | Meat, Veg\nPhone | tel");

    $result = FieldSet::validate($fields, ['name' => '', 'email' => 'not-an-email', 'diet' => 'Fish', 'phone' => 'abc']);

    expect($result['errors'])->toBe([
        'Name is required.',
        'Email must be an email address.',
        'Choose one of the options for Diet.',
        'Phone must be a telephone number.',
    ]);
});

it('cleans and keeps valid answers', function (): void {
    $fields = FieldSet::fromText("Name | name | required\nNotes | textarea\nAgree | checkbox\nDiet | select | optional | Veg\nPhone | tel");

    $result = FieldSet::validate($fields, ['name' => ' Ann <b>Lee</b> ', 'notes' => "line one\nline two", 'agree' => '1', 'diet' => 'Veg', 'phone' => '+44 117 000 0000']);

    expect($result['errors'])->toBe([])
        ->and($result['values'])->toBe([
            'name' => 'Ann Lee',
            'notes' => "line one\nline two",
            'agree' => 'yes',
            'diet' => 'Veg',
            'phone' => '+44 117 000 0000',
        ]);
});

it('treats an unticked checkbox and non-scalar input as empty', function (): void {
    $fields = FieldSet::fromText("Agree | checkbox\nName | text");

    expect(FieldSet::validate($fields, ['agree' => '0', 'name' => ['x']])['values'])->toBe(['agree' => '', 'name' => '']);
});

it('finds the holder name and email', function (): void {
    $fields = FieldSet::fromText("Full name | name\nWork email | email");
    $values = ['full_name' => 'Ann', 'work_email' => 'ann@example.test'];

    expect(FieldSet::holderName($fields, $values))->toBe('Ann')
        ->and(FieldSet::holderEmail($fields, $values))->toBe('ann@example.test')
        ->and(FieldSet::holderName([], $values))->toBe('');
});
