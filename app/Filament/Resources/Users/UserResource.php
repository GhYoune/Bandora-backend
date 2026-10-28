<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Filament\Resources\Users\RelationManagers\AttemptsRelationManager;
use App\Models\Pack;
use App\Models\User;
use App\Services\PaymentService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Students. Accounts are created by students themselves (sign-up or Google). */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $navigationLabel = 'Students';

    protected static ?string $modelLabel = 'student';

    protected static ?int $navigationSort = 2;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('is_admin', false);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(120),
            TextInput::make('email')->email()->required()->unique(ignoreRecord: true),
            TextInput::make('whatsapp')->tel()->maxLength(20),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Account')->columns(3)->schema([
                TextEntry::make('email')->copyable(),
                TextEntry::make('whatsapp')->copyable()->placeholder('—'),
                TextEntry::make('email_verified_at')->label('Email confirmed')->since()->placeholder('Not confirmed'),
                TextEntry::make('created_at')->label('Joined')->date(),
                TextEntry::make('google_id')->label('Google sign-in')->formatStateUsing(fn () => 'Yes')->placeholder('No'),
            ]),
            Section::make('Plan')->columns(3)->schema([
                TextEntry::make('tier')->state(fn (User $record) => ucfirst($record->tier()))->badge()
                    ->color(fn (User $record) => match ($record->tier()) {
                        'paid' => 'success', 'expired' => 'danger', default => 'gray'
                    }),
                TextEntry::make('pack_name')->label('Pack')->placeholder('—'),
                TextEntry::make('pack_expires_at')->label('Ends')->date()->placeholder('—'),
                TextEntry::make('credits')->label('AI credits')->state(fn (User $record) => $record->availableCredits()),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->searchable()->description(fn (User $record) => $record->email),
                TextColumn::make('whatsapp')->searchable()->placeholder('—'),
                TextColumn::make('tier')->label('Plan')->state(fn (User $record) => ucfirst($record->tier()))->badge()
                    ->color(fn (User $record) => match ($record->tier()) {
                        'paid' => 'success', 'expired' => 'danger', default => 'gray'
                    }),
                TextColumn::make('pack_expires_at')->label('Pack ends')->date()->sortable()->placeholder('—'),
                TextColumn::make('credits')->label('Credits')->state(fn (User $record) => $record->availableCredits()),
                TextColumn::make('attempts_count')->counts('attempts')->label('Tests')->sortable(),
                TextColumn::make('created_at')->label('Joined')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('plan')->options(['paid' => 'Pack running', 'expired' => 'Pack expired', 'free' => 'Free'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'paid' => $query->where('pack_expires_at', '>', now()),
                        'expired' => $query->where('pack_expires_at', '<=', now()),
                        'free' => $query->whereNull('pack_expires_at'),
                        default => $query,
                    }),
            ])
            ->recordActions([ViewAction::make(), EditAction::make()]);
    }

    /** Shared by the view page header: activate a pack without a payment request (e.g. paid in person). */
    public static function activatePackAction(): Action
    {
        return Action::make('activatePack')
            ->label('Activate a pack')
            ->icon(Heroicon::OutlinedTicket)
            ->schema([
                Select::make('pack_id')->label('Pack')->required()
                    ->options(Pack::where('is_active', true)->orderBy('sort')->pluck('name', 'id')),
            ])
            ->modalDescription('Use this for payments received outside WhatsApp. Days and credits are added to any running pack.')
            ->action(function (User $record, array $data) {
                app(PaymentService::class)->activate($record, Pack::findOrFail($data['pack_id']));
                Notification::make()->title('Pack activated')->success()->send();
            });
    }

    public static function adjustCreditsAction(): Action
    {
        return Action::make('adjustCredits')
            ->label('Adjust credits')
            ->icon(Heroicon::OutlinedSparkles)
            ->schema([
                TextInput::make('change')->label('Credits to add (negative to remove)')->integer()->required()->notIn([0]),
            ])
            ->action(function (User $record, array $data) {
                $record->forceFill(['credits' => max(0, $record->credits + (int) $data['change'])])->save();
                Notification::make()->title('Credits updated')->success()->send();
            });
    }

    public static function getRelations(): array
    {
        return [AttemptsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'view' => ViewUser::route('/{record}'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
