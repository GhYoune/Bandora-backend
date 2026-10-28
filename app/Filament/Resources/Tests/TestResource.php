<?php

namespace App\Filament\Resources\Tests;

use App\Enums\Skill;
use App\Filament\Resources\Tests\Pages\CreateTest;
use App\Filament\Resources\Tests\Pages\EditTest;
use App\Filament\Resources\Tests\Pages\ListTests;
use App\Models\Test;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ReplicateAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TestResource extends Resource
{
    protected static ?string $model = Test::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([...TestForm::details(), ...TestForm::content()]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->reorderable('sort')
            ->columns([
                TextColumn::make('skill')->badge()->color(fn (Skill $state) => match ($state) {
                    Skill::Listening => 'info', Skill::Reading => 'success', Skill::Writing => 'warning', Skill::Speaking => 'danger',
                }),
                TextColumn::make('title')->searchable()->description(fn (Test $record) => $record->description),
                TextColumn::make('question_count')
                    ->label('Questions')
                    ->numeric()
                    // Listening and Reading tests should have exactly 40.
                    ->color(fn (Test $record) => $record->skill->isObjective() && $record->question_count !== 40 ? 'danger' : null)
                    ->tooltip(fn (Test $record) => $record->skill->isObjective() && $record->question_count !== 40 ? 'A real test has 40 questions.' : null),
                IconColumn::make('is_free')->label('Free')->boolean(),
                ToggleColumn::make('is_published')->label('Published'),
                TextColumn::make('attempts_count')->counts('attempts')->label('Attempts')->numeric(),
                TextColumn::make('updated_at')->since()->label('Updated')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('skill')->options(Skill::class),
            ])
            ->recordActions([
                EditAction::make(),
                ReplicateAction::make()
                    ->label('Duplicate')
                    ->excludeAttributes(['attempts_count'])
                    ->beforeReplicaSaved(fn (Test $replica) => $replica->fill(['title' => $replica->title.' (copy)', 'is_published' => false])),
                DeleteAction::make()
                    ->modalDescription('Students lose the results of this test. Unpublish it instead to hide it.'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTests::route('/'),
            'create' => CreateTest::route('/create'),
            'edit' => EditTest::route('/{record}/edit'),
        ];
    }
}
