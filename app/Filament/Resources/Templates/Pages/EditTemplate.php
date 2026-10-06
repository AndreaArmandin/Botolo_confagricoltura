<?php

namespace App\Filament\Resources\Templates\Pages;

use App\Filament\Resources\Templates\TemplateResource;
use App\Integrations\Kapso\KapsoException;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditTemplate extends EditRecord
{
    protected static string $resource = TemplateResource::class;

    protected static ?string $title = 'Modifica Template';

    protected function afterSave(): void
    {
        $this->refreshFormData(['status', 'external_id', 'category']);
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Template salvato su Kapso';
    }

    /**
     * Salva usando Filament e mostra gli eventuali errori nei campi del form.
     * Il campo "body" del modello corrisponde a "data.body" nel form.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return parent::handleRecordUpdate($record, $data);
        } catch (KapsoException $exception) {
            throw ValidationException::withMessages([
                'data.body' => $exception->getMessage(),
            ]);
        } catch (ValidationException $exception) {
            $formErrors = [];

            foreach ($exception->errors() as $field => $messages) {
                $formErrors['data.'.$field] = $messages;
            }

            throw ValidationException::withMessages($formErrors);
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->failureNotificationTitle('Template non eliminato')
                ->failureNotificationBody('Kapso non ha confermato l’eliminazione. Il template è rimasto salvato.'),
        ];
    }
}
