<?php

use App\Filament\Resources\Contacts\Pages\CreateContact;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Filament\Resources\Templates\Pages\CreateTemplate;
use App\Filament\Resources\Templates\Pages\ListTemplates;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('renders the filament pages without a schema error', function (string $page) {
    Livewire::test($page)->assertOk();
})->with([
    ListContacts::class,
    CreateContact::class,
    ListTemplates::class,
    CreateTemplate::class,
]);
