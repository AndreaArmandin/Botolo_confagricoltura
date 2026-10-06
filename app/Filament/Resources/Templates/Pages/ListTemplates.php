<?php

namespace App\Filament\Resources\Templates\Pages;

use App\Filament\Resources\Templates\TemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTemplates extends ListRecords
{
    protected static string $resource = TemplateResource::class;
    protected static ?string $title = 'Lista Template';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Crea Template'),
        ];
    }
}
