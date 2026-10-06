<?php

use App\Filament\Resources\Contacts\Pages\CreateContact;
use App\Filament\Resources\Contacts\Pages\EditContact;
use App\Models\Contact;
use App\Models\Tag;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

/**
 * @param  array<string, mixed>  $overrides
 */
function makeContact(array $overrides = []): Contact
{
    return Contact::create([
        'name' => 'Mario',
        'surname' => 'Rossi',
        'email' => 'm.rossi@agricolarossi.it',
        'phone' => '+39 333 1234567',
        'address' => 'Via Roma 12, Cuneo',
        ...$overrides,
    ]);
}

it('refuses an empty name, an empty surname and a malformed email', function () {
    Livewire::test(CreateContact::class)
        ->fillForm(['name' => '', 'surname' => '', 'email' => 'non-e-una-email'])
        ->call('create')
        ->assertHasFormErrors([
            'name' => 'required',
            'surname' => 'required',
            'email' => 'email',
        ]);

    expect(Contact::count())->toBe(0);
});

it('refuses an email already used by another contact', function () {
    makeContact();

    Livewire::test(CreateContact::class)
        ->fillForm([
            'name' => 'Anna',
            'surname' => 'Bianchi',
            'email' => 'm.rossi@agricolarossi.it',
        ])
        ->call('create')
        ->assertHasFormErrors(['email' => 'unique']);

    expect(Contact::count())->toBe(1);
});

it('lets a contact keep its own email while being edited', function () {
    $contact = makeContact();

    Livewire::test(EditContact::class, ['record' => $contact->getKey()])
        ->fillForm(['name' => 'Mario Aggiornato'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($contact->refresh()->name)->toBe('Mario Aggiornato')
        ->and($contact->email)->toBe('m.rossi@agricolarossi.it');
});

it('writes the selected tags onto the pivot table', function () {
    $soci = Tag::create(['name' => 'Soci']);
    $cuneo = Tag::create(['name' => 'Cuneo']);

    Livewire::test(CreateContact::class)
        ->fillForm([
            'name' => 'Mario',
            'surname' => 'Rossi',
            'email' => 'm.rossi@agricolarossi.it',
            'tags' => [$soci->getKey(), $cuneo->getKey()],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Contact::first()->tags->pluck('name')->all())
        ->toEqualCanonicalizing(['Soci', 'Cuneo']);
});

it('loads the existing tags when the edit page opens', function () {
    $contact = makeContact();
    $soci = Tag::create(['name' => 'Soci']);
    $contact->tags()->attach($soci);

    Livewire::test(EditContact::class, ['record' => $contact->getKey()])
        ->assertFormSet(['tags' => [$soci->getKey()]]);
});
