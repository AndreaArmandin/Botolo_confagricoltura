<?php

use App\Enums\TemplateCategory;
use App\Enums\TemplateStatus;
use App\Filament\Resources\Templates\Pages\CreateTemplate;
use App\Filament\Resources\Templates\Pages\EditTemplate;
use App\Integrations\Kapso\KapsoException;
use App\Models\Template;
use App\Models\User;
use Database\Seeders\TemplateSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    config()->set([
        'kapso.base_url' => 'https://api.kapso.test/v24.0',
        'kapso.api_key' => 'test-key',
        'kapso.business_id' => 'business-123',
    ]);
    Http::preventStrayRequests();
});

it('submits the editor values on creation and stores the remote id and status', function () {
    $this->actingAs(User::factory()->create());
    Http::fake(['https://api.kapso.test/v24.0/business-123/message_templates' => Http::response([
        'id' => 'remote-123', 'status' => 'PENDING', 'category' => 'UTILITY',
    ])]);

    Livewire::test(CreateTemplate::class)
        ->fillForm([
            'name' => 'scadenza_quota',
            'language' => 'it',
            'category' => 'utility',
            'body' => 'Ciao {{1}}, scadenza {{2}}. Grazie {{1}}!',
            'body_examples' => [2 => '31/12/2026', 1 => 'Mario'],
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified('Template inviato a Meta');

    $this->assertDatabaseHas('templates', [
        'name' => 'scadenza_quota', 'external_id' => 'remote-123', 'status' => 'pending',
    ]);
    expect(Template::sole()->body_examples)->toBe([2 => '31/12/2026', 1 => 'Mario']);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->hasHeader('X-API-Key', 'test-key')
        && $request->data() === [
            'name' => 'scadenza_quota',
            'language' => 'it',
            'parameter_format' => 'POSITIONAL',
            'category' => 'UTILITY',
            'components' => [[
                'type' => 'BODY',
                'text' => 'Ciao {{1}}, scadenza {{2}}. Grazie {{1}}!',
                'example' => ['body_text' => [['Mario', '31/12/2026']]],
            ]],
        ]);
    Http::assertSentCount(1);
});

it('updates an existing remote template from the editor using hsm_id', function () {
    $this->actingAs(User::factory()->create());
    $template = Template::factory()->createQuietly([
        'external_id' => 'remote-123',
        'status' => TemplateStatus::Rejected,
    ]);
    Http::fake(['https://api.kapso.test/v24.0/business-123/message_templates?hsm_id=remote-123' => Http::response(['success' => true])]);

    Livewire::test(EditTemplate::class, ['record' => $template->getRouteKey()])
        ->fillForm(['body' => 'Ciao {{1}}, nuova scadenza {{2}}.'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertFormSet(['status' => 'pending'])
        ->assertNotified('Template salvato su Kapso');

    $this->assertDatabaseHas('templates', [
        'id' => $template->id, 'body' => 'Ciao {{1}}, nuova scadenza {{2}}.',
        'external_id' => 'remote-123', 'status' => 'pending', 'category' => 'utility',
    ]);
    Http::assertSent(fn (Request $request): bool => $request->data() === [
        'components' => [[
            'type' => 'BODY', 'text' => 'Ciao {{1}}, nuova scadenza {{2}}.',
            'example' => ['body_text' => [['Mario', '31/12/2026']]],
        ]],
    ]);
    Http::assertSentCount(1);
});

it('creates the remote template for an existing local draft and omits unused examples', function () {
    $template = Template::factory()->createQuietly(['body' => 'Avviso senza variabili.']);
    Http::fake(['https://api.kapso.test/v24.0/business-123/message_templates' => Http::response([
        'id' => 'remote-456', 'status' => 'APPROVED', 'category' => 'MARKETING',
    ])]);

    $template->save();

    expect($template->refresh()->status)->toBe(TemplateStatus::Approved)
        ->and($template->category)->toBe(TemplateCategory::Marketing);
    Http::assertSent(fn (Request $request): bool => $request['components'] === [
        ['type' => 'BODY', 'text' => 'Avviso senza variabili.'],
    ] && $request['name'] === $template->name);
});

it('does not submit unchanged content or status-only updates', function () {
    $template = Template::factory()->createQuietly(['external_id' => 'remote-123', 'status' => TemplateStatus::Pending]);

    $template->save();
    $template->update(['status' => TemplateStatus::Approved]);
    $template->touch();

    $this->assertDatabaseHas('templates', ['id' => $template->id, 'status' => 'approved']);
    Http::assertNothingSent();
});

it('submits changes to the examples', function () {
    $template = Template::factory()->createQuietly([
        'external_id' => 'remote-123',
        'status' => TemplateStatus::Rejected,
    ]);
    Http::fake(['https://api.kapso.test/v24.0/business-123/message_templates?hsm_id=remote-123' => Http::response(['success' => true])]);

    $template->update(['body_examples' => [1 => 'Anna', 2 => '01/01/2027']]);

    Http::assertSent(fn (Request $request): bool => $request['components'][0]['example']['body_text'] === [['Anna', '01/01/2027']]);
    expect($template->refresh()->status)->toBe(TemplateStatus::Pending);
});

it('keeps a failed creation in the editor without saving or showing success', function () {
    $this->actingAs(User::factory()->create());
    Http::fake(['https://api.kapso.test/v24.0/business-123/message_templates' => Http::response(['error' => 'Template non valido'], 400)]);

    Livewire::test(CreateTemplate::class)
        ->fillForm(['name' => 'avviso', 'language' => 'it', 'category' => 'utility', 'body' => 'Un avviso.'])
        ->call('create')
        ->assertHasFormErrors(['body'])
        ->assertSee('Template non valido')
        ->assertNotNotified();

    $this->assertDatabaseCount('templates', 0);
    Http::assertSentCount(1);
});

it('preserves the previously approved content if an update is refused', function () {
    $this->actingAs(User::factory()->create());
    $template = Template::factory()->createQuietly([
        'external_id' => 'remote-123',
        'status' => TemplateStatus::Rejected,
    ]);
    Http::fake(['https://api.kapso.test/v24.0/business-123/message_templates?hsm_id=remote-123' => Http::response(['error' => 'Modifica non consentita'], 400)]);

    Livewire::test(EditTemplate::class, ['record' => $template->getRouteKey()])
        ->fillForm(['body' => 'Nuovo testo.'])
        ->call('save')
        ->assertHasFormErrors(['body'])
        ->assertSee('Modifica non consentita')
        ->assertNotNotified();

    $this->assertDatabaseHas('templates', ['id' => $template->id, 'body' => $template->body, 'status' => 'rejected']);
    Http::assertSentCount(1);
});

it('shows missing example errors in the editor before sending', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(CreateTemplate::class)
        ->fillForm(['name' => 'avviso', 'language' => 'it', 'category' => 'utility', 'body' => 'Ciao {{1}}.'])
        ->call('create')
        ->assertHasFormErrors(['body_examples'])
        ->assertSee('Inserisci un valore di esempio');

    $this->assertDatabaseCount('templates', 0);
    Http::assertNothingSent();
});

it('rejects unsupported or nonconsecutive placeholders before sending', function (string $body) {
    expect(fn () => Template::factory()->create(['body' => $body]))
        ->toThrow(ValidationException::class, 'Usa variabili numeriche consecutive');

    $this->assertDatabaseCount('templates', 0);
    Http::assertNothingSent();
})->with(['Ciao {{name}}.', 'Scadenza {{2}}.', 'Ciao {{0}}.', 'Ciao {{01}}.', 'Ciao {{1}}, data {{3}}.', 'Ciao {{1}.']);

it('rejects placeholders at the beginning or end before sending', function (string $body) {
    expect(fn () => Template::factory()->create([
        'body' => $body,
        'body_examples' => [1 => 'Mario'],
    ]))->toThrow(ValidationException::class, 'Meta non accetta variabili all’inizio o alla fine');

    $this->assertDatabaseCount('templates', 0);
    Http::assertNothingSent();
})->with(['{{1}} ciao.', 'Ciao come stai {{1}}', 'Ciao {{1}}']);

it('rejects identity changes for a remote template', function (array $changes) {
    $template = Template::factory()->createQuietly(['external_id' => 'remote-123']);

    expect(fn () => $template->update($changes))
        ->toThrow(ValidationException::class, 'Nome e lingua');

    Http::assertNothingSent();
})->with([[['name' => 'nome_diverso']], [['language' => 'en_US']]]);

it('does not let the editor change a template until Meta rejects it', function (TemplateStatus $status) {
    $this->actingAs(User::factory()->create());
    $template = Template::factory()->createQuietly([
        'external_id' => 'remote-123',
        'status' => $status,
    ]);

    Livewire::test(EditTemplate::class, ['record' => $template->getRouteKey()])
        ->assertFormFieldIsDisabled('body')
        ->assertFormFieldIsDisabled('body_examples')
        ->assertSee('Meta permette di modificare solo i template rifiutati');

    expect(fn () => $template->update(['body' => 'Nuovo testo.']))
        ->toThrow(ValidationException::class, 'Meta permette di modificare solo i template rifiutati');

    $this->assertDatabaseHas('templates', [
        'id' => $template->id,
        'body' => 'Ciao {{1}}, la tua quota scade il {{2}}.',
        'status' => $status->value,
    ]);
    Http::assertNothingSent();
})->with([TemplateStatus::Pending, TemplateStatus::Approved]);

it('seeds a draft that still needs approval from Meta', function () {
    $this->seed(TemplateSeeder::class);

    $template = Template::sole();

    expect($template->refresh()->status)->toBe(TemplateStatus::Draft)
        ->and($template->external_id)->toBeNull();
    Http::assertNothingSent();
});

it('fails before sending when the credentials are missing', function (string $key) {
    config()->set($key, null);

    expect(fn () => Template::factory()->create())
        ->toThrow(KapsoException::class, 'Configura KAPSO_BUSINESS_ID');

    $this->assertDatabaseCount('templates', 0);
    Http::assertNothingSent();
})->with(['kapso.api_key', 'kapso.business_id']);

it('does not save an unconfirmed creation', function () {
    Http::fake(['https://api.kapso.test/v24.0/business-123/message_templates' => Http::response(['success' => true])]);

    expect(fn () => Template::factory()->create())->toThrow(KapsoException::class, 'non ha restituito');

    $this->assertDatabaseCount('templates', 0);
    Http::assertSentCount(1);
});

it('does not save an unconfirmed update', function () {
    $template = Template::factory()->createQuietly([
        'external_id' => 'remote-123',
        'status' => TemplateStatus::Rejected,
    ]);
    Http::fake(['https://api.kapso.test/v24.0/business-123/message_templates?hsm_id=remote-123' => Http::response([])]);

    expect(fn () => $template->update(['body' => 'Nuovo testo.']))->toThrow(KapsoException::class, 'non ha confermato');

    $this->assertDatabaseHas('templates', ['id' => $template->id, 'status' => 'rejected']);
    Http::assertSentCount(1);
});

it('does not save when the connection to Kapso fails', function () {
    Http::fake(['https://api.kapso.test/v24.0/business-123/message_templates' => Http::failedConnection()]);

    expect(fn () => Template::factory()->create())->toThrow(KapsoException::class, 'Connessione a Kapso non riuscita');

    $this->assertDatabaseCount('templates', 0);
});

it('creates a fixed-text template from the editor without asking for examples', function () {
    $this->actingAs(User::factory()->create());
    Http::fake(['https://api.kapso.test/v24.0/business-123/message_templates' => Http::response([
        'id' => 'remote-123', 'status' => 'PENDING',
    ])]);

    Livewire::test(CreateTemplate::class)
        ->fillForm(['name' => 'avviso', 'language' => 'it', 'category' => 'utility', 'body' => 'Un avviso.'])
        ->assertFormFieldIsHidden('body_examples')
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('templates', ['name' => 'avviso', 'status' => 'pending']);
    Http::assertSent(fn (Request $request): bool => $request['components'] === [['type' => 'BODY', 'text' => 'Un avviso.']]);
});

it('keeps an existing remote template unchanged when the seeder runs', function () {
    $template = Template::factory()->approved()->createQuietly([
        'name' => 'promemoria_scadenza_quota',
        'external_id' => 'remote-123',
        'body' => 'Testo approvato da Meta.',
    ]);

    $this->seed(TemplateSeeder::class);

    $this->assertDatabaseCount('templates', 1);
    $this->assertDatabaseHas('templates', [
        'id' => $template->id,
        'external_id' => 'remote-123',
        'status' => 'approved',
        'body' => 'Testo approvato da Meta.',
    ]);
    Http::assertNothingSent();
});
