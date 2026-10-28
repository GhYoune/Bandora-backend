<?php

namespace App\Filament\Resources\Packs;

use App\Filament\Resources\Packs\Pages\ManagePacks;
use App\Models\Pack;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Packs shown on the landing page and in the app. Deactivate instead of deleting: payments refer to them. */
class PackResource extends Resource
{
    protected static ?string $model = Pack::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->required()->placeholder('3 months'),
            TextInput::make('price_dzd')->label('Price (DA)')->numeric()->required()->minValue(0),
            TextInput::make('duration_days')->label('Duration (days)')->numeric()->required()->minValue(1),
            TextInput::make('credits')->label('AI marking credits')->numeric()->required()->minValue(0),
            TextInput::make('sort')->numeric()->default(0),
            Toggle::make('highlighted')->label('Show as "Most chosen"')->inline(false),
            Toggle::make('is_active')->label('On sale')->default(true)->inline(false),
            Repeater::make('features')
                ->simple(TextInput::make('feature')->required())
                ->addActionLabel('Add feature')
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->reorderable('sort')
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('price_dzd')->label('Price')->numeric()->suffix(' DA'),
                TextColumn::make('duration_days')->label('Days')->numeric(),
                TextColumn::make('credits')->numeric(),
                IconColumn::make('highlighted')->boolean(),
                IconColumn::make('is_active')->label('On sale')->boolean(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManagePacks::route('/'),
        ];
    }
}
