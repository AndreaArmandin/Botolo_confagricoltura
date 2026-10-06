<?php

use App\Enums\TemplateStatus;
use App\Models\Template;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schedule;

beforeEach(function () {
    config()->set([
        'kapso.base_url' => 'https://api.kapso.test/v24.0/',
        'kapso.business_id' => 'business-123',
        'kapso.api_key' => 'test-key',
    ]);
    Http::preventStrayRequests();
});

function runTemplateStatusSchedule(): void
{
    $event = collect(Schedule::events())->first(fn ($event): bool => $event instanceof CallbackEvent);

    expect($event)->toBeInstanceOf(CallbackEvent::class);

    $event->run(app());
}

it('updates a template status without submitting it again', function () {
    $template = Template::factory()->createQuietly([
        'external_id' => 'remote-123',
        'status' => TemplateStatus::Pending,
    ]);
    Template::factory()->createQuietly(['external_id' => null]);
    Http::fake([
        'https://api.kapso.test/v24.0/business-123/message_templates/remote-123' => Http::response(['status' => 'APPROVED']),
    ]);

    runTemplateStatusSchedule();

    expect($template->refresh()->status)->toBe(TemplateStatus::Approved);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->hasHeader('X-API-Key', 'test-key'));
    Http::assertSentCount(1);
});

it('keeps the old status when Kapso responds with an error and checks the next template', function () {
    $unavailable = Template::factory()->createQuietly(['external_id' => 'remote-123', 'status' => TemplateStatus::Pending]);
    $approved = Template::factory()->createQuietly(['external_id' => 'remote-456', 'status' => TemplateStatus::Pending]);
    Http::fake([
        'https://api.kapso.test/v24.0/business-123/message_templates/remote-123' => Http::response(['error' => 'Unavailable'], 500),
        'https://api.kapso.test/v24.0/business-123/message_templates/remote-456' => Http::response(['status' => 'APPROVED']),
    ]);

    runTemplateStatusSchedule();

    expect($unavailable->refresh()->status)->toBe(TemplateStatus::Pending)
        ->and($approved->refresh()->status)->toBe(TemplateStatus::Approved);
    Http::assertSentCount(2);
});

it('keeps the old status when Kapso omits it', function () {
    $template = Template::factory()->createQuietly(['external_id' => 'remote-123', 'status' => TemplateStatus::Pending]);
    Http::fake([
        'https://api.kapso.test/v24.0/business-123/message_templates/remote-123' => Http::response(['id' => 'remote-123']),
    ]);

    runTemplateStatusSchedule();

    expect($template->refresh()->status)->toBe(TemplateStatus::Pending);
    Http::assertSentCount(1);
});

it('does not request templates when there are none', function () {

    runTemplateStatusSchedule();

    Http::assertNothingSent();
});
