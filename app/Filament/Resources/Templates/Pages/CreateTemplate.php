<?php

namespace App\Filament\Resources\Templates\Pages;

use App\Filament\Resources\Templates\TemplateResource;
use App\Integrations\Kapso\KapsoException;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateTemplate extends CreateRecord
{
    protected static string $resource = TemplateResource::class;

    protected static ?string $title = 'Crea nuovo Template';

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Template inviato a Meta';
    }

    /**
     * Salva usando Filament e mostra gli eventuali errori nei campi del form.
     * Il campo "body" del modello corrisponde a "data.body" nel form.
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return parent::handleRecordCreation($data);
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
}
