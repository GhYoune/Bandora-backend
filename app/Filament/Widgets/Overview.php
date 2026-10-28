<?php

namespace App\Filament\Widgets;

use App\Enums\AttemptStatus;
use App\Enums\PaymentStatus;
use App\Filament\Resources\PaymentRequests\PaymentRequestResource;
use App\Models\Attempt;
use App\Models\PaymentRequest;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class Overview extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $pending = PaymentRequest::where('status', PaymentStatus::Pending)->count();

        return [
            Stat::make('Payments to check', $pending)
                ->description($pending ? 'Open the payments inbox' : 'Nothing waiting')
                ->color($pending ? 'warning' : 'gray')
                ->url(PaymentRequestResource::getUrl()),
            Stat::make('Students', User::where('is_admin', false)->count()),
            Stat::make('Packs running', User::where('pack_expires_at', '>', now())->count()),
            Stat::make('Tests completed this week', Attempt::where('status', AttemptStatus::Completed)->where('completed_at', '>=', now()->subWeek())->count()),
        ];
    }
}
