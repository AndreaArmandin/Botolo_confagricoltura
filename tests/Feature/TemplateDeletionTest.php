<?php

use App\Models\Template;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set([
        'kapso.base_url' => 'https://api.kapso.test/v24.0',
        'kapso.api_key' => 'test-key',
        'kapso.business_id' => 'business-123',
    ]);
    Http::preventStrayRequests();
});

it('deletes the local template after Kapso confirms deletion', function () {
    $template = Template::factory()->createQuietly([
        'external_id' => 'remote-123',
        'name' => 'promemoria_scadenza',
    ]);
    Http::fake([
        'https://api.kapso.test/v24.0/business-123/message_templates?hsm_id=remote-123&name=promemoria_scadenza' => Http::response(['success' => true]),
    ]);

    expect($template->delete())->toBeTrue();

    $this->assertDatabaseMissing('templates', ['id' => $template->id]);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && $request->url() === 'https://api.kapso.test/v24.0/business-123/message_templates?hsm_id=remote-123&name=promemoria_scadenza');
});

it('keeps the local template when Kapso does not confirm deletion', function () {
    $template = Template::factory()->createQuietly([
        'external_id' => 'remote-123',
        'name' => 'promemoria_scadenza',
    ]);
    Http::fake([
        'https://api.kapso.test/v24.0/business-123/message_templates*' => Http::response(['success' => false]),
    ]);

    expect($template->delete())->toBeFalse();

    $this->assertDatabaseHas('templates', ['id' => $template->id, 'external_id' => 'remote-123']);
    Http::assertSentCount(1);
});
