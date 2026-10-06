<?php

use App\Enums\MessageStatus;
use App\Enums\TemplateStatus;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Template;
use Illuminate\Database\UniqueConstraintViolationException;

it('fills the placeholders with the given values, in order', function () {
    $template = Template::factory()->createQuietly([
        'body' => 'Ciao {{1}}, la tua quota scade il {{2}}.',
    ]);

    expect($template->render(['Mario', '31/12/2026']))
        ->toBe('Ciao Mario, la tua quota scade il 31/12/2026.');
});

it('leaves a placeholder alone when no value is given for it', function () {
    $template = Template::factory()->createQuietly([
        'body' => 'Ciao {{1}}, la tua quota scade il {{2}}.',
    ]);

    expect($template->render(['Mario']))
        ->toBe('Ciao Mario, la tua quota scade il {{2}}.');
});

it('casts both statuses to their enum', function () {
    $message = Message::factory()->createQuietly(['status' => MessageStatus::Sent]);

    expect($message->refresh()->status)->toBe(MessageStatus::Sent)
        ->and($message->template->status)->toBe(TemplateStatus::Draft);
});

it('links a message to its contact and to its template', function () {
    $contact = Contact::factory()->create();
    $template = Template::factory()->createQuietly();

    $message = Message::factory()->createQuietly([
        'contact_id' => $contact->getKey(),
        'template_id' => $template->getKey(),
    ]);

    expect($message->contact->is($contact))->toBeTrue()
        ->and($message->template->is($template))->toBeTrue()
        ->and($template->messages->pluck('id')->all())->toBe([$message->getKey()])
        ->and($contact->messages->pluck('id')->all())->toBe([$message->getKey()]);
});

it('keeps sent messages when their template is deleted', function () {
    $template = Template::factory()->createQuietly();
    $message = Message::factory()->createQuietly([
        'template_id' => $template->getKey(),
        'body' => 'Ciao Mario, la tua quota scade il 31/12/2026.',
    ]);

    $template->delete();

    expect($message->refresh())->not->toBeNull()
        ->and($message->template_id)->toBeNull()
        ->and($message->body)->toBe('Ciao Mario, la tua quota scade il 31/12/2026.');
});

it('deletes the messages of a contact that is removed', function () {
    $contact = Contact::factory()->create();
    Message::factory()->createQuietly(['contact_id' => $contact->getKey()]);

    $contact->delete();

    expect(Message::count())->toBe(0);
});

it('refuses two messages carrying the same external id', function () {
    Message::factory()->createQuietly(['external_id' => 'wamid.ABC123']);

    expect(fn () => Message::factory()->createQuietly(['external_id' => 'wamid.ABC123']))
        ->toThrow(UniqueConstraintViolationException::class);
});
