<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;
use KeypointSolutions\LaravelVox\Models\VoxSetting;

it('saves provider-neutral AI guidance with visible feedback', function (): void {
    config()->set('vox.translate.providers.openai.api_key', 'browser-test-key');
    config()->set('vox.translate.model', 'gpt-5.4-mini');
    Http::fake([
        'https://api.openai.com/v1/models' => Http::response([
            'data' => [
                ['id' => 'gpt-5.4-mini'],
                ['id' => 'gpt-5.4-nano'],
            ],
        ]),
    ]);

    $this->actingAs(User::factory()->create(['email' => 'admin@keypoint.ro']));

    visit('/vox/settings')
        ->assertSee('AI translation')
        ->assertSee('Active provider')
        ->pressAndWaitFor('Refresh & test')
        ->assertSee('Connection verified')
        ->fill('#translate_guidance', 'Use concise browser-test wording.')
        ->pressAndWaitFor('Save AI settings')
        ->assertSee('AI settings saved')
        ->assertNoJavaScriptErrors();

    expect(json_decode(VoxSetting::query()->findOrFail('translate_guidance')->value, true))
        ->toBe('Use concise browser-test wording.');
});
