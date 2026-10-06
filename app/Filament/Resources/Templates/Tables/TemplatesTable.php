<?php

namespace App\Filament\Resources\Templates\Tables;

use App\Enums\TemplateStatus;
use App\Models\Template;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TemplatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nome tecnico')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('category')
                    ->label('Categoria')
                    ->badge(),

                TextColumn::make('language')
                    ->label('Lingua'),

                TextColumn::make('status')
                    ->label('Approvazione')
                    ->badge()
                    ->color(fn(TemplateStatus $state): string => $state->color()),

                TextColumn::make('placeholders')
                    ->label('Campi')
                    ->state(fn(Template $record): int => count($record->placeholders())),

                TextColumn::make('messages_count')
                    ->label('Inviati')
                    ->counts('messages'),

                TextColumn::make('body')
                    ->label('Testo')
                    ->limit(60)
                    ->tooltip(fn(Template $record): ?string => $record->body),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Approvazione')
                    ->options(collect(TemplateStatus::cases())
                        ->mapWithKeys(fn(TemplateStatus $s): array => [$s->value => $s->value])
                        ->all()),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
