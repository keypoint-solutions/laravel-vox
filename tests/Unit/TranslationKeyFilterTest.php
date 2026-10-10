<?php

use KeypointSolutions\LaravelVox\Translation\TranslationKeyFilter;

it('allows everything when no key was requested', function (): void {
    $filter = TranslationKeyFilter::resolve(null, null, ['messages' => ['hello' => 'Hello']]);

    expect($filter->allowsGroup('messages'))->toBeTrue()
        ->and($filter->allowsKey('hello'))->toBeTrue()
        ->and($filter->allowsJsonKey(null, 'Anything'))->toBeTrue()
        ->and($filter->allowsVendorJsonNamespace('cashier'))->toBeTrue()
        ->and($filter->matchesAny([], [], [], null))->toBeTrue();
});

it('resolves a requested key to its group, vendor group or JSON namespace', function (string $requested, ?string $key, ?string $group, ?string $namespace): void {
    $filter = TranslationKeyFilter::resolve($requested, null, [
        'messages' => ['hello' => 'Hello'],
        'cashier::billing' => ['pay' => 'Pay'],
    ]);

    expect([$filter->key, $filter->group, $filter->jsonNamespace])->toBe([$key, $group, $namespace]);
})->with([
    'group key' => ['messages.hello', 'hello', 'messages', null],
    'vendor group key' => ['cashier::billing.pay', 'pay', 'cashier::billing', null],
    'vendor JSON key' => ['cashier::Checkout', 'Checkout', null, 'cashier'],
    'JSON sentence with a dot' => ['Hello. Welcome', 'Hello. Welcome', null, null],
    'unknown group stays a JSON key' => ['other.hello', 'other.hello', null, null],
]);

it('strips the targeted file prefix from the requested key', function (array $target, string $requested, string $key): void {
    expect(TranslationKeyFilter::resolve($requested, $target, [])->key)->toBe($key);
})->with([
    'group file' => [['type' => 'group', 'group' => 'messages'], 'messages.hello', 'hello'],
    'vendor group file with plain prefix' => [['type' => 'group', 'group' => 'cashier::billing'], 'billing.pay', 'pay'],
    'vendor JSON file' => [['type' => 'json', 'namespace' => 'cashier'], 'cashier::Checkout', 'Checkout'],
    'root JSON file' => [['type' => 'json', 'namespace' => null], 'Checkout', 'Checkout'],
]);

it('limits matching to the resolved scope', function (): void {
    $groups = ['messages' => ['hello' => 'Hello'], 'auth' => ['failed' => 'Failed']];
    $json = ['Checkout' => 'Checkout'];
    $vendorJson = ['cashier' => ['Receipt' => 'Receipt']];

    $group = TranslationKeyFilter::resolve('messages.hello', null, $groups);
    $vendor = TranslationKeyFilter::resolve('cashier::Receipt', null, $groups);

    expect($group->allowsGroup('messages'))->toBeTrue()
        ->and($group->allowsGroup('auth'))->toBeFalse()
        ->and($group->allowsKey('hello'))->toBeTrue()
        ->and($group->matchesAny($groups, $json, $vendorJson, null))->toBeTrue()
        ->and($vendor->allowsJsonKey('cashier', 'Receipt'))->toBeTrue()
        ->and($vendor->allowsJsonKey(null, 'Receipt'))->toBeFalse()
        ->and($vendor->allowsVendorJsonNamespace('other'))->toBeFalse()
        ->and($vendor->matchesAny($groups, $json, $vendorJson, null))->toBeTrue()
        ->and(TranslationKeyFilter::resolve('messages.absent', null, $groups)->matchesAny($groups, $json, $vendorJson, null))->toBeFalse()
        ->and(TranslationKeyFilter::resolve('absent', ['type' => 'json', 'namespace' => null], [])->matchesAny($groups, $json, $vendorJson, ['type' => 'json', 'namespace' => null]))->toBeFalse();
});
