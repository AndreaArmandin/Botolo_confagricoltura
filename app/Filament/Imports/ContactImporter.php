<?php

namespace App\Filament\Imports;

use App\Models\Contact;
use App\Models\Tag;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Support\Number;

class ContactImporter extends Importer
{
    protected static ?string $model = Contact::class;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('name')
                ->label('Nome')
                ->guess(['Nome contatto', 'First name'])
                ->requiredMapping()
                ->rules(['required']),

            ImportColumn::make('surname')
                ->label('Cognome')
                ->guess(['Last name'])
                ->requiredMapping()
                ->rules(['required']),

            ImportColumn::make('email')
                ->label('Email')
                ->guess(['E-mail', 'Indirizzo email', 'Mail'])
                ->requiredMapping()
                ->rules(['required', 'email']),

            ImportColumn::make('phone')
                ->label('Telefono')
                ->guess(['Cellulare', 'Numero di telefono']),

            ImportColumn::make('address')
                ->label('Indirizzo')
                ->guess(['Via e città']),

            ImportColumn::make('tags')
                ->label('Tag')
                ->guess(['Etichette'])
                ->fillRecordUsing(fn () => null)
                ->multiple(',')
                ->saveRelationshipsUsing(function (Contact $record, array $state): void {
                    // $state arriva gia spezzato sulle virgole: ['Soci', ' Cuneo']
                    $tagIds = [];

                    foreach ($state as $name) {
                        $name = trim($name);

                        if ($name === '') {
                            continue;
                        }

                        // se il tag esiste lo riusa, altrimenti lo crea
                        $tag = Tag::firstOrCreate(['name' => $name]);
                        $tagIds[] = $tag->getKey();
                    }

                    // aggiunge solo i collegamenti mancanti, non toglie niente
                    $record->tags()->syncWithoutDetaching($tagIds);
                }),
        ];
    }

    public function resolveRecord(): ?Contact
    {
        return Contact::firstOrNew([
            'email' => $this->data['email'],
        ]);
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Your contact import has completed and '.Number::format($import->successful_rows).' '.str('row')->plural($import->successful_rows).' imported.';

        if ($failedRowsCount = $import->getFailedRowsCount()) {
            $body .= ' '.Number::format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to import.';
        }

        return $body;
    }
}
