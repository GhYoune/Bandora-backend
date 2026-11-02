<?php

namespace App\Filament\Resources\Decks;

use App\Filament\Resources\Decks\Pages\CreateDeck;
use App\Filament\Resources\Decks\Pages\EditDeck;
use App\Filament\Resources\Decks\Pages\ListDecks;
use App\Models\Deck;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

/** Vocabulary decks. Free decks are open to everyone; the others need a pack, like tests. */
class DeckResource extends Resource
{
    protected static ?string $model = Deck::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Vocabulary decks';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Deck')->columns(2)->schema([
                TextInput::make('title')->required()->placeholder('Work and jobs'),
                TextInput::make('topic')->placeholder('Work')->helperText('A short tag shown on the deck.'),
                TextInput::make('description')->columnSpanFull()->placeholder('Words for letters to employers and essays about work.'),
                Toggle::make('is_free')->label('Free for everyone')->helperText('Otherwise students need a pack.'),
                Toggle::make('is_published')->label('Published'),
            ]),
            Repeater::make('cards')
                ->relationship()
                ->orderColumn('sort')
                ->collapsible()
                ->itemLabel(fn (array $state) => $state['word'] ?? null)
                ->addActionLabel('Add card')
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('word')->required(),
                        TextInput::make('example')->placeholder('A sentence that uses the word'),
                    ]),
                    Textarea::make('definition')->required()->rows(2),
                    Grid::make(2)->schema([
                        TextInput::make('translation_fr')->label('French (optional)'),
                        TextInput::make('translation_ar')->label('Arabic (optional)')->extraInputAttributes(['dir' => 'rtl']),
                    ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->reorderable('sort')
            ->columns([
                TextColumn::make('title')->searchable()->description(fn (Deck $record) => $record->topic),
                TextColumn::make('cards_count')->counts('cards')->label('Cards')->numeric(),
                IconColumn::make('is_free')->label('Free')->boolean(),
                ToggleColumn::make('is_published')->label('Published'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->modalDescription('Students lose their progress on this deck. Unpublish it instead to hide it.'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDecks::route('/'),
            'create' => CreateDeck::route('/create'),
            'edit' => EditDeck::route('/{record}/edit'),
        ];
    }
}
