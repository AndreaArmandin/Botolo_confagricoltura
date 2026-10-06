<?php

use App\Enums\MessageStatus;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Jobs\SendMessageJob;
use App\Models\Contact;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

it('mette in coda solo i messaggi la cui ora e arrivata', function () {
    Queue::fake();

    $scaduto = Message::factory()->create([
        'status' => MessageStatus::Scheduled,
        'scheduled_at' => now()->subMinute(),
    ]);

    $futuro = Message::factory()->create([
        'status' => MessageStatus::Scheduled,
        'scheduled_at' => now()->addDay(),
    ]);

    $this->artisan('messages:send-due')->assertSuccessful();

    Queue::assertPushed(SendMessageJob::class, 1);

    expect($scaduto->refresh()->status)->toBe(MessageStatus::Queued)
        ->and($futuro->refresh()->status)->toBe(MessageStatus::Scheduled);
});

it('non tocca i messaggi gia inviati', function () {
    Queue::fake();

    Message::factory()->create([
        'status' => MessageStatus::Sent,
        'scheduled_at' => now()->subDay(),
    ]);

    $this->artisan('messages:send-due')->assertSuccessful();

    Queue::assertNothingPushed();
});

it('mette in coda anche i messaggi liberi programmati quando arriva l ora', function () {
    Queue::fake();

    $contact = Contact::factory()->create(['phone' => '+39 333 1234567']);
    $message = $contact->prepareCustomMessage('Messaggio libero', null, now()->subMinute());

    $this->artisan('messages:send-due')->assertSuccessful();

    Queue::assertPushed(SendMessageJob::class, fn (SendMessageJob $job): bool => $job->message->is($message));

    expect($message->refresh()->status)->toBe(MessageStatus::Queued);
});

it('salva in UTC l ora italiana scelta per un messaggio libero', function () {
    $this->actingAs(User::factory()->create());

    $contact = Contact::factory()->create(['phone' => '+39 333 1234567']);

    Livewire::test(ListContacts::class)
        ->callTableBulkAction('sendMessage', [$contact], [
            'message_content_type' => 'custom',
            'custom_message' => 'Messaggio programmato',
            'schedule_send' => true,
            'scheduled_at' => '2030-01-10 12:19:45',
        ]);

    expect(Message::first()->scheduled_at->toDateTimeString())->toBe('2030-01-10 11:19:45');
});
