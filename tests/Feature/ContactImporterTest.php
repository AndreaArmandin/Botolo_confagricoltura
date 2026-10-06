<?php

use App\Filament\Imports\ContactImporter;
use App\Models\Contact;
use App\Models\Tag;
use App\Models\User;
use Filament\Actions\Imports\Models\Import;

/**
 * Runs a single CSV row through the importer, the same way the queued job does.
 *
 * @param  array<string, string>  $row
 */
function importRow(array $row): void
{
    $import = new Import;
    $import->file_name = 'contatti.csv';
    $import->file_path = 'contatti.csv';
    $import->importer = ContactImporter::class;
    $import->total_rows = 1;
    $import->user_id = User::factory()->create()->getKey();
    $import->save();

    $importer = new ContactImporter(
        import: $import,
        columnMap: [
            'name' => 'name',
            'surname' => 'surname',
            'email' => 'email',
            'phone' => 'phone',
            'address' => 'address',
            'tags' => 'tags',
        ],
        options: [],
    );

    $importer($row);
}

it('imports a contact and creates the tags listed in the row', function () {
    importRow([
        'name' => 'Mario',
        'surname' => 'Rossi',
        'email' => 'm.rossi@agricolarossi.it',
        'phone' => '+39 333 1234567',
        'address' => 'Via Roma 12, Cuneo',
        'tags' => 'Soci, Cuneo, Vitivinicolo',
    ]);

    $contact = Contact::firstWhere('email', 'm.rossi@agricolarossi.it');

    expect($contact)->not->toBeNull()
        ->and($contact->name)->toBe('Mario')
        ->and($contact->tags->pluck('name')->all())
        ->toBe(['Soci', 'Cuneo', 'Vitivinicolo']);
});

it('recognizes Italian headings in contact CSV files', function () {
    $columns = collect(ContactImporter::getColumns())
        ->keyBy(fn ($column): string => $column->getName());

    expect($columns['name']->getGuesses())->toContain('nome contatto')
        ->and($columns['email']->getGuesses())->toContain('indirizzo email')
        ->and($columns['phone']->getGuesses())->toContain('numero di telefono')
        ->and($columns['tags']->getGuesses())->toContain('etichette');
});

it('trims the spaces that follow the separator', function () {
    importRow([
        'name' => 'Anna',
        'surname' => 'Bianchi',
        'email' => 'a.bianchi@cascinabianchi.it',
        'phone' => '',
        'address' => '',
        'tags' => 'Soci, Cuneo',
    ]);

    expect(Tag::pluck('name')->all())->toEqualCanonicalizing(['Soci', 'Cuneo']);
});

it('reuses an existing tag instead of creating a duplicate', function () {
    importRow([
        'name' => 'Mario',
        'surname' => 'Rossi',
        'email' => 'm.rossi@agricolarossi.it',
        'phone' => '',
        'address' => '',
        'tags' => 'Soci, Cuneo',
    ]);

    importRow([
        'name' => 'Anna',
        'surname' => 'Bianchi',
        'email' => 'a.bianchi@cascinabianchi.it',
        'phone' => '',
        'address' => '',
        'tags' => 'Cuneo, Asti',
    ]);

    expect(Tag::count())->toBe(3)
        ->and(Contact::count())->toBe(2);
});

it('updates the existing contact instead of duplicating it on a second import', function () {
    $row = [
        'name' => 'Mario',
        'surname' => 'Rossi',
        'email' => 'm.rossi@agricolarossi.it',
        'phone' => '+39 333 1234567',
        'address' => 'Via Roma 12, Cuneo',
        'tags' => 'Soci, Cuneo',
    ];

    importRow($row);
    importRow([...$row, 'phone' => '+39 333 9999999']);

    expect(Contact::count())->toBe(1)
        ->and(Contact::first()->phone)->toBe('+39 333 9999999')
        ->and(DB::table('contact_tag')->count())->toBe(2);
});

it('imports a contact whose tags cell is empty', function () {
    importRow([
        'name' => 'Davide',
        'surname' => 'Conti',
        'email' => 'd.conti@contiagri.it',
        'phone' => '+39 348 6665544',
        'address' => 'Via Torino 22, Alessandria',
        'tags' => '',
    ]);

    expect(Contact::count())->toBe(1)
        ->and(Tag::count())->toBe(0);
});
