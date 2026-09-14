<?php

use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    $this->withoutVite();
    app()->detectEnvironment(fn () => 'local');
    config()->set('vox.system.bypass_auth_in_local', true);
});

it('resolves supported page sizes on every paginated view', function (string $route, string $payload, int $requested, int $expected): void {
    $this->get($route.'?per_page='.$requested)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where($payload.'.per_page', $expected));
})->with([
    'manage' => ['/vox/manage', 'translations'],
    'audit' => ['/vox/audit', 'audits'],
    'reconciliation' => ['/vox/sync', 'reconciliation'],
])->with([
    '25' => [25, 25],
    '50' => [50, 50],
    '100' => [100, 100],
    'unsupported' => [75, 25],
]);
