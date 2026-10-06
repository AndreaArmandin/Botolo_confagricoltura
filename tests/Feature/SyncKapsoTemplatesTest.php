<?php

use App\Enums\TemplateCategory;
use App\Enums\TemplateStatus;
use App\Models\Template;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('kapso.base_url', 'https://api.kapso.ai/meta/whatsapp/v24.0');
    config()->set('kapso.api_key', 'chiave-di-prova');
    config()->set('kapso.business_id', '999888777');
    Http::preventStrayRequests();
});

function fakeTemplates(array $rows): void
{
    Http::fake(['api.kapso.ai/*' => Http::response(['data' => $rows], 200)]);
}

function metaTemplate(array $overrides = []): array
{
    return [
        'id' => '111',
        'name' => 'promemoria_scadenza_quota',
        'language' => 'it',
        'status' => 'APPROVED',
        'category' => 'UTILITY',
        'components' => [
            ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Confagricoltura'],
            ['type' => 'BODY', 'text' => 'Ciao {{1}}, la quota scade il {{2}}.', 'example' => ['body_text' => [['Mario', '31/12/2026']]]],
            ['type' => 'FOOTER', 'text' => 'Non rispondere'],
        ],
        ...$overrides,
    ];
}

it('imports a template, taking the text from the body component only', function () {
    fakeTemplates([metaTemplate()]);

    $this->artisan('kapso:sync-templates')->assertSuccessful();

    $template = Template::sole();

    expect($template->name)->toBe('promemoria_scadenza_quota')
        ->and($template->body)->toBe('Ciao {{1}}, la quota scade il {{2}}.')
        ->and($template->status)->toBe(TemplateStatus::Approved)
        ->and($template->category)->toBe(TemplateCategory::Utility)
        ->and($template->external_id)->toBe('111')
        ->and($template->body_examples)->toBe([1 => 'Mario', 2 => '31/12/2026'])
        ->and($template->placeholders())->toBe([1, 2]);

    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
});

it('asks the business account, not the phone number', function () {
    fakeTemplates([metaTemplate()]);

    $this->artisan('kapso:sync-templates')->assertSuccessful();

    Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '999888777/message_templates'));
});

it('updates the local copy instead of duplicating it', function () {
    Template::factory()->createQuietly([
        'name' => 'promemoria_scadenza_quota',
        'language' => 'it',
        'status' => TemplateStatus::Pending,
    ]);

    fakeTemplates([metaTemplate()]);

    $this->artisan('kapso:sync-templates')->assertSuccessful();

    expect(Template::count())->toBe(1)
        ->and(Template::sole()->status)->toBe(TemplateStatus::Approved);

    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
});

it('understands every status meta can report', function (string $meta, TemplateStatus $expected) {
    fakeTemplates([metaTemplate(['status' => $meta])]);

    $this->artisan('kapso:sync-templates')->assertSuccessful();

    expect(Template::sole()->status)->toBe($expected);
})->with([
    ['APPROVED', TemplateStatus::Approved],
    ['REJECTED', TemplateStatus::Rejected],
    ['PENDING', TemplateStatus::Pending],
    ['DISABLED', TemplateStatus::Disabled],
    ['PAUSED', TemplateStatus::Disabled],
]);

it('fails with a readable message when the business id is missing', function () {
    config()->set('kapso.business_id', null);

    $this->artisan('kapso:sync-templates')
        ->expectsOutputToContain('KAPSO_BUSINESS_ID non configurato')
        ->assertFailed();
});

it('preserves local examples when Kapso omits them from the sync response', function () {
    $template = Template::factory()->createQuietly(['name' => 'promemoria_scadenza_quota']);
    fakeTemplates([metaTemplate(['components' => [
        ['type' => 'BODY', 'text' => 'Ciao {{1}}, la quota scade il {{2}}.'],
    ]])]);

    $this->artisan('kapso:sync-templates')->assertSuccessful();

    expect($template->refresh()->body_examples)->toBe([1 => 'Mario', 2 => '31/12/2026']);
    Http::assertSentCount(1);
});
