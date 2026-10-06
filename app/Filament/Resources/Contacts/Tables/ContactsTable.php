<?php

namespace App\Filament\Resources\Contacts\Tables;

use App\Integrations\Kapso\Kapso;
use App\Integrations\Kapso\KapsoException;
use App\Jobs\SendMessageJob;
use App\Models\Contact;
use App\Models\Template;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class ContactsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('surname')
                    ->searchable(),
                TextColumn::make('email')
                    ->searchable(),
                TextColumn::make('phone')
                    ->searchable(),
                TextColumn::make('address')
                    ->searchable(),
            ])
            ->filters([
                SelectFilter::make('tags')
                    ->relationship('tags', 'name')
                    ->label('Tags'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkAction::make('sendMessage')
                    ->label('Invia messaggio')
                    ->icon(Heroicon::OutlinedPaperAirplane)
                    ->schema([

                        Select::make('message_content_type')
                            ->label('Tipo di contenuto')
                            ->default('template')
                            ->required()
                            ->live()
                            ->options([
                                'template' => 'Template',
                                'custom' => 'Messaggio personalizzato',
                            ]),

                        Textarea::make('custom_message')
                            ->label('Messaggio o didascalia')
                            ->rows(5)
                            ->required(fn (Get $get): bool => $get('message_content_type') === 'custom' && blank($get('custom_attachment')))
                            ->maxLength(fn (Get $get): int => filled($get('custom_attachment')) ? 1024 : 4096)
                            ->visible(fn (Get $get): bool => $get('message_content_type') === 'custom')
                            ->helperText('Scrivi un testo oppure allega un’immagine o un PDF. Il testo diventa la didascalia dell’allegato. L’invio è consentito entro 24 ore dall’ultimo messaggio del contatto.'),

                        FileUpload::make('custom_attachment')
                            ->label('Allega immagine o PDF (facoltativo)')
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'application/pdf'])
                            ->maxSize(5120)
                            ->disk('local')
                            ->directory('whatsapp-message-attachments')
                            ->storeFileNamesIn('custom_attachment_name')
                            ->visibility('private')
                            ->deleteUploadedFileUsing(fn (string $file): bool => Storage::disk('local')->delete($file))
                            ->live()
                            ->visible(fn (Get $get): bool => $get('message_content_type') === 'custom')
                            ->helperText('JPG, PNG o PDF. Massimo 5 MB.'),

                        Select::make('template_id')
                            ->label('Template')
                            ->options(fn (): array => Template::approved()->pluck('name', 'id')->all())
                            ->required(fn (Get $get): bool => $get('message_content_type') === 'template')
                            ->live()
                            ->visible(fn (Get $get): bool => $get('message_content_type') === 'template')
                            ->helperText('Solo i template approvati da Meta possono partire.'),

                        Placeholder::make('anteprima')
                            ->label('Testo che verrà inviato')
                            ->content(fn (Get $get): string => static::previewFor($get))
                            ->visible(fn (Get $get): bool => $get('message_content_type') === 'template'),

                        ...static::valueFields(),

                        Toggle::make('schedule_send')
                            ->label('Invia in un secondo momento')
                            ->live(),

                        DateTimePicker::make('scheduled_at')
                            ->label('Data e ora di invio (Italia)')
                            ->timezone('Europe/Rome')
                            ->required(fn (Get $get): bool => (bool) $get('schedule_send'))
                            ->visible(fn (Get $get): bool => (bool) $get('schedule_send')),

                    ])
                    ->action(function (Collection $records, array $data, Kapso $kapso) {
                        static::sendMessages($records, $data, $kapso);
                    }),
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    protected static function sendMessages(Collection $contacts, array $data, Kapso $kapso): void
    {
        $template = null;
        $values = [];

        // Se il tipo di contenuto del messaggio è un template, recupera il template approvato e i valori associati.
        if ($data['message_content_type'] === 'template') {
            $template = Template::approved()->findOrFail($data['template_id']);
            $values = static::valuesFrom($data, $template);
        }

        try {
            // Prova a caricare l'allegato personalizzato se presente.
            $attachment = static::uploadCustomAttachment($data, $contacts, $template, $kapso);
        } catch (KapsoException $exception) {
            Notification::make()
                ->title('Allegato non caricato')
                ->body($exception->getMessage())
                ->status('danger')
                ->send();

            return;
        }

        // Determina la data e l'ora di invio programmata, se presente.
        $scheduledAt = empty($data['schedule_send'])
            ? null
            : Carbon::parse($data['scheduled_at']);

        // Prepara i messaggi per l'invio, tenendo conto della data e ora programmata.
        $counts = static::prepareMessages($contacts, $data, $template, $values, $attachment, $scheduledAt);
        $title = $scheduledAt === null
            ? "{$counts['prepared']} messaggi in coda"
            : "{$counts['prepared']} messaggi programmati";

        Notification::make()
            ->title($title)
            ->body($counts['skipped'] > 0 ? "{$counts['skipped']} saltati perche senza telefono." : null)
            ->status($counts['skipped'] > 0 ? 'warning' : 'success')
            ->send();
    }

    protected static function uploadCustomAttachment(array $data, Collection $contacts, ?Template $template, Kapso $kapso): ?array
    {
        $filePath = $data['custom_attachment'] ?? null;

        if (! is_string($filePath) || blank($filePath)) {
            return null;
        }

        $disk = Storage::disk('local');

        try {
            $mimeType = $disk->mimeType($filePath);
            $mediaType = match ($mimeType) {
                'image/jpeg', 'image/png' => 'image',
                'application/pdf' => 'document',
                default => throw new KapsoException('Tipo di file non supportato.'),
            };

            if ($template !== null || ! $contacts->contains(fn (Contact $contact): bool => $contact->whatsAppNumber() !== null)) {
                return null;
            }

            $filename = basename((string) ($data['custom_attachment_name'] ?? $filePath));

            return [
                'id' => $kapso->uploadMedia($filePath, (string) $mimeType, $filename),
                'type' => $mediaType,
                'filename' => $filename,
            ];
        } finally {
            $disk->delete($filePath);
        }
    }

    // Costruisce i messaggi da inviare per ciascun contatto, tenendo conto del template, dei valori e della data e ora programmata.
    // prepareCustomMessage: quando non è selezionato alcun template
    // prepareMessage: quando è selezionato un template
    protected static function prepareMessages(
        Collection $contacts,
        array $data,
        ?Template $template,
        array $values,
        ?array $attachment,
        ?Carbon $scheduledAt,
    ): array {
        $prepared = 0;
        $skipped = 0;

        foreach ($contacts as $contact) {
            $message = $template === null
                ? $contact->prepareCustomMessage(
                    body: (string) ($data['custom_message'] ?? ''),
                    mediaId: $attachment['id'] ?? null,
                    scheduledAt: $scheduledAt,
                    mediaType: $attachment['type'] ?? null,
                    mediaFilename: $attachment['filename'] ?? null,
                )
                : $contact->prepareMessage($template, $values, $scheduledAt);

            if ($message === null) {
                $skipped++;

                continue;
            }

            if ($scheduledAt === null) {

                // Job per inviare il messaggio immediatamente se non è programmato.
                SendMessageJob::dispatch($message);
            }

            $prepared++;
        }

        return ['prepared' => $prepared, 'skipped' => $skipped];
    }

    // Funzioni per gestire i campi dei valori dei template e la loro anteprima.
    protected static function valueFields(): array
    {
        $fields = [];

        for ($n = 1; $n <= 4; $n++) {
            $field = TextInput::make("value_{$n}")
                ->label("Valore {{{$n}}}")
                ->required(fn (Get $get): bool => $get('message_content_type') === 'template' && count(static::placeholdersOf($get)) >= $n)
                ->maxLength(255)
                ->visible(function (Get $get) use ($n): bool {
                    return $get('message_content_type') === 'template' && count(static::placeholdersOf($get)) >= $n;
                });

            // la prima casella e quasi sempre il nome, la precompiliamo
            if ($n === 1) {
                $field->default('{nome}')
                    ->helperText('Puoi usare {nome}, {cognome} o {email}: vengono sostituiti contatto per contatto.');
            }

            $fields[] = $field;
        }

        return $fields;
    }

    /**
     * I buchi del template attualmente scelto nella tendina.
     *
     * @return array<int, int>
     */
    protected static function placeholdersOf(Get $get): array
    {
        $template = static::selectedTemplate($get);

        if ($template === null) {
            return [];
        }

        return $template->placeholders();
    }

    /**
     * Il testo come apparira, con dentro i valori scritti finora.
     */
    protected static function previewFor(Get $get): string
    {
        $template = static::selectedTemplate($get);

        if ($template === null) {
            return 'Scegli un template.';
        }

        $values = [];

        foreach ($template->placeholders() as $position => $number) {
            // se la casella e ancora vuota lasciamo vedere il buco
            $values[] = (string) ($get('value_'.($position + 1)) ?? '{{'.$number.'}}');
        }

        return $template->render($values);
    }

    /**
     * I valori scritti nella modale, in ordine di buco.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    protected static function valuesFrom(array $data, Template $template): array
    {
        $values = [];

        foreach ($template->placeholders() as $position => $number) {
            $values[] = (string) ($data['value_'.($position + 1)] ?? '');
        }

        return $values;
    }

    protected static function selectedTemplate(Get $get): ?Template
    {
        $id = $get('template_id');

        if (blank($id)) {
            return null;
        }

        return Template::find($id);
    }
}
