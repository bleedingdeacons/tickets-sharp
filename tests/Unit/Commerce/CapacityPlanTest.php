<?php

declare(strict_types=1);

use TicketsSharp\Commerce\CapacityPlan;

it('gives an own-capacity ticket its own stock', function (): void {
    $plan = CapacityPlan::fromForm(['mode' => 'own', 'capacity' => '50'], false, -1, false, 0);

    expect($plan->manageStock)->toBeTrue()
        ->and($plan->mode)->toBe('own')
        ->and($plan->stock)->toBe(50)
        ->and($plan->capacity)->toBe(50)
        ->and($plan->setEventCapacity)->toBeFalse()
        ->and($plan->isUnlimited())->toBeFalse();
});

it('treats an empty mode as unlimited', function (): void {
    $plan = CapacityPlan::fromForm(['mode' => '', 'capacity' => '10'], false, -1, false, 0);

    expect($plan->manageStock)->toBeFalse()
        ->and($plan->isUnlimited())->toBeTrue()
        ->and($plan->stock)->toBe(-1)
        ->and($plan->capacity)->toBe(-1);
});

it('defaults the mode to own when the form leaves it out', function (): void {
    $plan = CapacityPlan::fromForm(['capacity' => '5'], false, -1, false, 0);

    expect($plan->mode)->toBe('own')->and($plan->stock)->toBe(5);
});

it('reads a blank capacity from the classic form, which sends no mode, as unlimited', function (array $form): void {
    $plan = CapacityPlan::fromForm($form, false, -1, false, 0);

    expect($plan->isUnlimited())->toBeTrue()->and($plan->capacity)->toBe(-1);
})->with([
    'blank' => [['capacity' => '']],
    'negative' => [['capacity' => '-1']],
    'absent' => [[]],
]);

it('turns shared capacity on when the form sends an event capacity', function (): void {
    $plan = CapacityPlan::fromForm(['mode' => 'global', 'event_capacity' => '100'], false, -1, false, 0);

    expect($plan->sharedCapacityOn)->toBeTrue()
        ->and($plan->setEventCapacity)->toBeTrue()
        ->and($plan->eventCapacity)->toBe(100)
        ->and($plan->capacity)->toBe(100)
        ->and($plan->stock)->toBe(100);
});

it('reads an empty event capacity as unlimited', function (): void {
    $plan = CapacityPlan::fromForm(['mode' => 'global', 'event_capacity' => ''], false, -1, false, 0);

    expect($plan->eventCapacity)->toBe(-1)->and($plan->setEventCapacity)->toBeTrue();
});

it('caps a capped ticket at the event capacity when asked for more', function (): void {
    $plan = CapacityPlan::fromForm(['mode' => 'capped', 'capacity' => '80', 'event_capacity' => '60'], true, 60, false, 0);

    expect($plan->capacity)->toBe(60)->and($plan->setEventCapacity)->toBeFalse();
});

it('keeps a capped ticket below the event capacity as it is', function (): void {
    $plan = CapacityPlan::fromForm(['mode' => 'capped', 'capacity' => '20', 'event_capacity' => '60'], true, 60, false, 0);

    expect($plan->capacity)->toBe(20);
});

it('changes the event capacity only when a different one is sent', function (): void {
    $same = CapacityPlan::fromForm(['mode' => 'global', 'event_capacity' => '60'], true, 60, false, 0);
    $changed = CapacityPlan::fromForm(['mode' => 'global', 'event_capacity' => '75'], true, 60, false, 0);

    expect($same->setEventCapacity)->toBeFalse()
        ->and($same->eventCapacity)->toBe(60)
        ->and($changed->setEventCapacity)->toBeTrue()
        ->and($changed->eventCapacity)->toBe(75);
});

it('does not let an own-capacity ticket be capped by the event', function (): void {
    $plan = CapacityPlan::fromForm(['mode' => 'own', 'capacity' => '80'], true, 60, false, 0);

    expect($plan->capacity)->toBe(80);
});

it('subtracts seats already taken when updating', function (): void {
    $plan = CapacityPlan::fromForm(['mode' => 'own', 'capacity' => '50'], false, -1, true, 12);

    expect($plan->stock)->toBe(38);
});

it('never takes the stock below zero on update', function (): void {
    $plan = CapacityPlan::fromForm(['mode' => 'own', 'capacity' => '5'], false, -1, true, 12);

    expect($plan->stock)->toBe(0);
});

it('normalises negative numbers to unlimited', function (): void {
    $plan = CapacityPlan::fromForm(['mode' => 'own', 'capacity' => '-5', 'stock' => '-3'], false, -1, false, 0);

    expect($plan->capacity)->toBe(-1)->and($plan->stock)->toBe(-1);
});

it('leaves the capacity alone when the field is blank', function (): void {
    $plan = CapacityPlan::fromForm(['mode' => 'own', 'capacity' => '   '], false, -1, false, 0);

    expect($plan->capacity)->toBeNull()->and($plan->stock)->toBe(0);
});

it('ignores non-scalar form values', function (): void {
    $plan = CapacityPlan::fromForm(['mode' => 'own', 'capacity' => ['x'], 'stock' => ''], false, -1, false, 0);

    expect($plan->capacity)->toBeNull()->and($plan->stock)->toBe(0);
});
