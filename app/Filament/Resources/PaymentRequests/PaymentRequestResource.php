<?php

namespace App\Filament\Resources\PaymentRequests;

use App\Enums\PaymentStatus;
use App\Exceptions\BusinessException;
use App\Filament\Resources\PaymentRequests\Pages\ManagePaymentRequests;
use App\Models\PaymentRequest;
use App\Services\PaymentService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/** The payments inbox: the teacher checks the WhatsApp receipt, then approves or rejects. */
class PaymentRequestResource extends Resource
{
    protected static ?string $model = PaymentRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Payments';

    protected static ?string $modelLabel = 'payment';

    protected static ?int $navigationSort = 1;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = PaymentRequest::where('status', PaymentStatus::Pending)->count();

        return $pending ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('reference')->fontFamily('mono')->weight('bold')->searchable()->copyable(),
                TextColumn::make('user.name')->label('Student')->description(fn (PaymentRequest $r) => $r->user->email)->searchable(),
                TextColumn::make('whatsapp')->searchable()->copyable(),
                TextColumn::make('pack_name')->label('Pack'),
                TextColumn::make('amount_dzd')->label('Amount')->numeric()->suffix(' DA'),
                TextColumn::make('status')->badge(),
                TextColumn::make('created_at')->label('Requested')->since()->sortable(),
                TextColumn::make('decided_at')->label('Handled')->since()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(PaymentStatus::class)->default(PaymentStatus::Pending->value),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('Approve')
                    ->icon(Heroicon::OutlinedCheck)
                    ->color('success')
                    ->visible(fn (PaymentRequest $r) => $r->status === PaymentStatus::Pending)
                    ->requiresConfirmation()
                    ->modalHeading(fn (PaymentRequest $r) => "Approve {$r->pack_name} for {$r->user->name}?")
                    ->modalDescription(fn (PaymentRequest $r) => "Only approve once you have received {$r->amount_dzd} DA with reference {$r->reference}. The pack starts now (or is added to the current one).")
                    ->action(fn (PaymentRequest $r) => self::decide(fn () => app(PaymentService::class)->approve($r, Auth::user()), 'Pack activated')),
                Action::make('reject')
                    ->label('Reject')
                    ->icon(Heroicon::OutlinedXMark)
                    ->color('danger')
                    ->visible(fn (PaymentRequest $r) => $r->status === PaymentStatus::Pending)
                    ->requiresConfirmation()
                    ->modalDescription('The student can then make a new request.')
                    ->action(fn (PaymentRequest $r) => self::decide(fn () => app(PaymentService::class)->reject($r, Auth::user()), 'Payment rejected')),
            ]);
    }

    private static function decide(callable $decision, string $done): void
    {
        try {
            $decision();
            Notification::make()->title($done)->success()->send();
        } catch (BusinessException $e) {
            Notification::make()->title($e->getMessage())->warning()->send();
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => ManagePaymentRequests::route('/'),
        ];
    }
}
