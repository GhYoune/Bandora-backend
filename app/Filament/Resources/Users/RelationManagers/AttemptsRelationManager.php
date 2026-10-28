<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Enums\AttemptStatus;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Read-only: the teacher monitors progress, she does not mark or edit attempts. */
class AttemptsRelationManager extends RelationManager
{
    protected static string $relationship = 'attempts';

    protected static ?string $title = 'Tests taken';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('test'))
            ->defaultSort('started_at', 'desc')
            ->columns([
                TextColumn::make('test.skill')->label('Skill')->badge(),
                TextColumn::make('test.title')->label('Test'),
                TextColumn::make('status')->badge()->formatStateUsing(fn (AttemptStatus $state) => str_replace('_', ' ', $state->value))
                    ->color(fn (AttemptStatus $state) => match ($state) {
                        AttemptStatus::Completed => 'success', AttemptStatus::InProgress => 'gray',
                        AttemptStatus::Marking => 'warning', AttemptStatus::MarkingFailed => 'danger',
                    }),
                TextColumn::make('raw_score')->label('Score')->suffix(' / 40')->placeholder('—'),
                TextColumn::make('band')->numeric(1)->placeholder('—'),
                TextColumn::make('listening_plays')->label('Plays')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('started_at')->dateTime()->sortable(),
                TextColumn::make('completed_at')->since()->placeholder('—'),
            ]);
    }
}
