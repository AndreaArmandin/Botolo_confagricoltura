<?php

use App\Enums\MessageStatus;
use App\Enums\TemplateStatus;
use App\Integrations\Kapso\Kapso;
use App\Integrations\Kapso\KapsoException;
use App\Jobs\SendMessageJob;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Template;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    config()->set('kapso.base_url', 'https://api.kapso.ai/meta/whatsapp/v24.0');
    config()->set('kapso.api_key', 'chiave-di-prova');
    config()->set('kapso.phone_id', '1388241601035411');
    Http::preventStrayRequests();
});

/** Un id diverso a ogni chiamata, come fa l'API vera. */
function fakeKapsoOk(): void
{
    Http::fake(fn () => Http::response(['messages' => [['id' => 'wamid.'.Str::upper(Str::random(10))]]], 200));
}

function approvedTemplate(string $body = 'Ciao {{1}}, la quota scade il {{2}}.'): Template
{
    return Template::factory()->createQuietly([
        'name' => 'promemoria_scadenza',
        'language' => 'it',
        'body' => $body,
        'status' => TemplateStatus::Approved,
    ]);
}

// --- preparazione del messaggio -------------------------------------------

it('prepara il messaggio con il testo gia composto e i parametri', function () {
    $contact = Contact::factory()->create(['name' => 'Mario', 'phone' => '+39 333 1234567']);

    $message = $contact->prepareMessage(approvedTemplate(), ['{nome}', '31/12/2026']);

    expect($message->to)->toBe('393331234567')
        ->and($message->parameters)->toBe(['Mario', '31/12/2026'])
        ->and($message->body)->toBe('Ciao Mario, la quota scade il 31/12/2026.')
        ->and($message->status)->toBe(MessageStatus::Queued)
        ->and($message->scheduled_at)->toBeNull();
});

it('sostituisce {nome} col nome di ogni singolo contatto', function () {
    $template = approvedTemplate();
    $mario = Contact::factory()->create(['name' => 'Mario', 'phone' => '+39 333 1111111']);
    $anna = Contact::factory()->create(['name' => 'Anna', 'phone' => '+39 333 2222222']);

    $mario->prepareMessage($template, ['{nome}', '31/12/2026']);
    $anna->prepareMessage($template, ['{nome}', '31/12/2026']);

    expect(Message::pluck('body')->all())->toBe([
        'Ciao Mario, la quota scade il 31/12/2026.',
        'Ciao Anna, la quota scade il 31/12/2026.',
    ]);
});

it('non prepara niente per un contatto senza telefono', function () {
    $contact = Contact::factory()->create(['phone' => null]);

    expect($contact->prepareMessage(approvedTemplate(), ['Mario', '31/12/2026']))->toBeNull()
        ->and(Message::count())->toBe(0);
});

it('prepara il messaggio personalizzato conservando il testo scritto', function () {
    $contact = Contact::factory()->create([
        'name' => 'Mario',
        'surname' => 'Rossi',
        'email' => 'mario@example.com',
        'phone' => '+39 333 1234567',
    ]);

    $message = $contact->prepareCustomMessage('Ciao {nome} {cognome}, la tua email è {email}.');

    expect($message->body)->toBe('Ciao {nome} {cognome}, la tua email è {email}.')
        ->and($message->template_id)->toBeNull()
        ->and($message->status)->toBe(MessageStatus::Queued);
});

it('mette in attesa il messaggio programmato invece che in coda', function () {
    $contact = Contact::factory()->create(['phone' => '+39 333 1234567']);
    $quando = now()->addDay();

    $message = $contact->prepareMessage(approvedTemplate(), ['Mario', '31/12/2026'], $quando);

    expect($message->status)->toBe(MessageStatus::Scheduled)
        ->and($message->scheduled_at->toDateTimeString())->toBe($quando->toDateTimeString());
});

// --- invio -----------------------------------------------------------------

it('manda il nome del template e i valori, mai il testo', function () {
    fakeKapsoOk();
    $contact = Contact::factory()->create(['name' => 'Mario', 'phone' => '+39 333 1234567']);
    $message = $contact->prepareMessage(approvedTemplate(), ['{nome}', '31/12/2026']);

    (new SendMessageJob($message))->handle(app(Kapso::class));

    Http::assertSent(function (Request $request): bool {
        return $request['type'] === 'template'
            && $request['template']['name'] === 'promemoria_scadenza'
            && $request['template']['components'][0]['parameters'] === [
                ['type' => 'text', 'text' => 'Mario'],
                ['type' => 'text', 'text' => '31/12/2026'],
            ]
            && ! str_contains(json_encode($request->data()), 'la quota scade');
    });

    expect($message->refresh()->status)->toBe(MessageStatus::Sent)
        ->and($message->external_id)->toStartWith('wamid.')
        ->and($message->sent_at)->not->toBeNull();
});

it('manda il testo personalizzato al contatto', function () {
    fakeKapsoOk();
    $contact = Contact::factory()->create(['phone' => '+39 333 1234567']);
    $message = $contact->prepareCustomMessage('Ciao come stai?');

    (new SendMessageJob($message))->handle(app(Kapso::class));

    Http::assertSent(fn (Request $request): bool => $request['type'] === 'text'
        && $request['text']['body'] === 'Ciao come stai?');

    expect($message->refresh()->status)->toBe(MessageStatus::Sent)
        ->and($message->external_id)->toStartWith('wamid.');
});

it('carica una sola immagine e la invia con il testo personalizzato', function () {
    Storage::fake('local');
    Storage::disk('local')->put(
        'whatsapp-message-images/foto.png',
        base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lZ8AAAAASUVORK5CYII=', true),
    );
    Http::fake([
        'https://api.kapso.ai/meta/whatsapp/v24.0/1388241601035411/media' => Http::response(['id' => 'media-123']),
        'https://api.kapso.ai/meta/whatsapp/v24.0/1388241601035411/messages' => Http::response(['messages' => [['id' => 'wamid.image123']]]),
    ]);

    $mediaId = app(Kapso::class)->uploadMedia('whatsapp-message-images/foto.png', 'image/png', 'foto.png');
    $contact = Contact::factory()->create(['phone' => '+39 333 1234567']);
    $message = $contact->prepareCustomMessage('Ecco la foto.', $mediaId, mediaType: 'image', mediaFilename: 'foto.png');

    (new SendMessageJob($message))->handle(app(Kapso::class));

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/media')
        && str_contains($request->header('Content-Type')[0] ?? '', 'multipart/form-data'));
    Http::assertSent(fn (Request $request): bool => ($request['type'] ?? null) === 'image'
        && $request['image']['id'] === 'media-123'
        && $request['image']['caption'] === 'Ecco la foto.');

    expect($message->refresh()->status)->toBe(MessageStatus::Sent)
        ->and($message->media_id)->toBe('media-123');
});

it('carica un PDF e lo invia come documento con didascalia e nome file', function () {
    Storage::fake('local');
    Storage::disk('local')->put('whatsapp-message-attachments/fattura.pdf', "%PDF-1.4\nTest PDF\n%%EOF");
    Http::fake([
        'https://api.kapso.ai/meta/whatsapp/v24.0/1388241601035411/media' => Http::response(['id' => 'document-123']),
        'https://api.kapso.ai/meta/whatsapp/v24.0/1388241601035411/messages' => Http::response(['messages' => [['id' => 'wamid.document123']]]),
    ]);

    $mediaId = app(Kapso::class)->uploadMedia('whatsapp-message-attachments/fattura.pdf', 'application/pdf', 'fattura.pdf');
    $contact = Contact::factory()->create(['phone' => '+39 333 1234567']);
    $message = $contact->prepareCustomMessage(
        'Ecco la fattura.',
        $mediaId,
        mediaType: 'document',
        mediaFilename: 'fattura.pdf',
    );

    (new SendMessageJob($message))->handle(app(Kapso::class));

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/media')
        && $request->hasFile('file', filename: 'fattura.pdf')
        && str_contains($request->body(), 'application/pdf'));
    Http::assertSent(fn (Request $request): bool => ($request['type'] ?? null) === 'document'
        && $request['document']['id'] === 'document-123'
        && $request['document']['caption'] === 'Ecco la fattura.'
        && $request['document']['filename'] === 'fattura.pdf');

    expect($message->refresh()->status)->toBe(MessageStatus::Sent)
        ->and($message->media_type)->toBe('document')
        ->and($message->media_filename)->toBe('fattura.pdf');
});

it('rifiuta un allegato con tipo non supportato senza chiamare Kapso', function () {
    expect(fn () => app(Kapso::class)->uploadMedia('file.txt', 'text/plain', 'file.txt'))
        ->toThrow(KapsoException::class);

    Http::assertSentCount(0);
});

it('non invia come testo libero un messaggio il cui template è stato eliminato', function () {
    Http::fake([
        'https://api.kapso.ai/meta/whatsapp/v24.0/*' => Http::response(['messages' => [['id' => 'wamid.test']]]),
    ]);
    $contact = Contact::factory()->create(['phone' => '+39 333 1234567']);
    $message = $contact->prepareMessage(approvedTemplate(), ['Mario', '31/12/2026']);

    $message->update(['template_id' => null]);

    (new SendMessageJob($message))->handle(app(Kapso::class));

    expect($message->refresh()->status)->toBe(MessageStatus::Failed)
        ->and($message->error)->toContain('template collegato');

    Http::assertSentCount(0);
});

it('segna fallito e conserva il motivo quando l api rifiuta', function () {
    Http::fake(['api.kapso.ai/*' => Http::response(['error' => 'Template non approvato'], 400)]);
    $contact = Contact::factory()->create(['phone' => '+39 333 1234567']);
    $message = $contact->prepareMessage(approvedTemplate(), ['Mario', '31/12/2026']);

    (new SendMessageJob($message))->handle(app(Kapso::class));

    expect($message->refresh()->status)->toBe(MessageStatus::Failed)
        ->and($message->error)->toContain('Template non approvato');
});

it('non rimanda un messaggio gia inviato, se il job viene ritentato', function () {
    fakeKapsoOk();
    $contact = Contact::factory()->create(['phone' => '+39 333 1234567']);
    $message = $contact->prepareMessage(approvedTemplate(), ['Mario', '31/12/2026']);

    $job = new SendMessageJob($message);
    $job->handle(app(Kapso::class));
    $job->handle(app(Kapso::class));

    Http::assertSentCount(1);
});

it('segna fallito se Kapso non restituisce un id del messaggio', function () {
    Http::fake([
        'https://api.kapso.ai/meta/whatsapp/v24.0/1388241601035411/messages' => Http::response(['messages' => []]),
    ]);

    $contact = Contact::factory()->create(['phone' => '+39 333 1234567']);
    $message = $contact->prepareMessage(approvedTemplate(), ['Mario', '31/12/2026']);

    (new SendMessageJob($message))->handle(app(Kapso::class));

    expect($message->refresh()->status)->toBe(MessageStatus::Failed)
        ->and($message->external_id)->toBeNull()
        ->and($message->sent_at)->toBeNull()
        ->and($message->error)->toContain('no message id');
    Http::assertSentCount(1);
});

it('conserva il testo composto anche se il template cambia dopo', function () {
    fakeKapsoOk();
    $template = approvedTemplate();
    $contact = Contact::factory()->create(['name' => 'Mario', 'phone' => '+39 333 1234567']);
    $message = $contact->prepareMessage($template, ['{nome}', '31/12/2026']);

    $template->updateQuietly(['body' => 'Testo completamente diverso.']);

    expect($message->refresh()->body)->toBe('Ciao Mario, la quota scade il 31/12/2026.')
        ->and($message->template_id)->toBe($template->getKey());
});
