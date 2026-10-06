<?php

namespace App\Filament\Resources\Templates\Schemas;

use App\Enums\TemplateCategory;
use App\Enums\TemplateStatus;
use App\Models\Template;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class TemplateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nome tecnico')
                    ->disabled(fn (?Template $record): bool => filled($record?->external_id))
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true)
                    ->rule('regex:/^[a-z0-9_]+$/')
                    ->helperText('Solo minuscole, cifre e trattini bassi: promemoria_scadenza')
                    ->validationMessages([
                        'regex' => 'Meta accetta solo minuscole, cifre e trattini bassi.',
                    ]),

                Select::make('category')
                    ->label('Categoria')
                    ->disabled(fn (?Template $record): bool => filled($record?->external_id))
                    ->options(collect(TemplateCategory::cases())
                        ->mapWithKeys(fn (TemplateCategory $c): array => [$c->value => $c->label()])
                        ->all())
                    ->default(TemplateCategory::Utility)
                    ->required()
                    ->helperText(fn (?Template $record): string => filled($record?->external_id)
                        ? 'Meta non permette di cambiare la categoria dopo l’invio.'
                        : 'Utility viene approvato più facilmente e costa meno.'),

                Select::make('language')
                    ->label('Lingua')
                    ->disabled(fn (?Template $record): bool => filled($record?->external_id))
                    ->options(['it' => 'Italiano', 'en_US' => 'Inglese (US)'])
                    ->default('it')
                    ->required(),

                Textarea::make('body')
                    ->label('Testo')
                    ->rows(6)
                    ->required()
                    ->maxLength(1024)
                    ->live(onBlur: true)
                    ->disabled(fn (?Template $record): bool => filled($record?->external_id) && $record->status !== TemplateStatus::Rejected)
                    ->helperText(fn (?Template $record): string => filled($record?->external_id) && $record->status !== TemplateStatus::Rejected
                        ? 'Meta permette di modificare solo i template rifiutati. Crea un nuovo template per inviare un’altra versione.'
                        : 'Usa {{1}}, {{2}}… dove il valore cambia da contatto a contatto; non metterle all’inizio o alla fine del testo.')
                    ->columnSpanFull(),

                KeyValue::make('body_examples')
                    ->label('Esempi delle variabili per Meta')
                    ->keyLabel('Numero variabile')
                    ->valueLabel('Valore di esempio')
                    ->addActionLabel('Aggiungi esempio')
                    ->disabled(fn (?Template $record): bool => filled($record?->external_id) && $record->status !== TemplateStatus::Rejected)
                    ->visible(fn (Get $get): bool => (new Template(['body' => $get('body')]))->placeholders() !== [])
                    ->helperText('Meta richiede esempi realistici per la revisione: per {{1}} inserisci 1 → Mario, per {{2}} inserisci 2 → 31/12/2026.')
                    ->columnSpanFull(),

                Select::make('status')
                    ->label('Stato approvazione')
                    ->options(collect(TemplateStatus::cases())
                        ->mapWithKeys(fn (TemplateStatus $s): array => [$s->value => $s->value])
                        ->all())
                    ->default(TemplateStatus::Draft)
                    ->required()
                    ->disabled(true)
                    ->helperText('Creazione e modifiche dei template rifiutati vengono inviate a Meta. Lo stato viene aggiornato dalla sincronizzazione.'),

                TextInput::make('external_id')
                    ->label('ID su Kapso')
                    ->disabled()
                    ->dehydrated(false),
            ]);
    }

    /**
     * Mostra il testo con dei valori d'esempio, per vedere come verra mentre scrivi.
     */
    protected static function preview(?string $body): string
    {
        if (blank($body)) {
            return '';
        }

        $samples = ['Mario', '31/12/2026', 'Cuneo', 'valore'];
        $template = new Template(['body' => $body]);

        $values = [];

        foreach ($template->placeholders() as $position => $number) {
            if (isset($samples[$position])) {
                $values[] = $samples[$position];
            } else {
                $values[] = 'valore'.$number;
            }
        }

        return $template->render($values);
    }
}
