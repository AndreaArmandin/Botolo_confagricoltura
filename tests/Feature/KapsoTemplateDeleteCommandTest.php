<?php

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

it('deletes a template using its id and name in the query string', function () {
    Http::fake([
        'https://api.kapso.test/v24.0/business-123/message_templates?hsm_id=2374175416665072&name=odo' => Http::response(['success' => true]),
    ]);

    $this->artisan('kapso:template-delete', [
        'hsm_id' => '2374175416665072',
        'name' => 'odo',
    ])->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && $request->url() === 'https://api.kapso.test/v24.0/business-123/message_templates?hsm_id=2374175416665072&name=odo');
});

it('reports failure when Kapso does not confirm the deletion', function () {
    Http::fake([
        'https://api.kapso.test/v24.0/business-123/message_templates*' => Http::response(['success' => false]),
    ]);

    $this->artisan('kapso:template-delete', [
        'hsm_id' => '2374175416665072',
        'name' => 'odo',
    ])->assertFailed();
});
