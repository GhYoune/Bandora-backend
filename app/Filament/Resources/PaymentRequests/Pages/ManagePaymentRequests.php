<?php

namespace App\Filament\Resources\PaymentRequests\Pages;

use App\Filament\Resources\PaymentRequests\PaymentRequestResource;
use Filament\Resources\Pages\ManageRecords;

/** Payments are created by students in the app; here they are only approved or rejected. */
class ManagePaymentRequests extends ManageRecords
{
    protected static string $resource = PaymentRequestResource::class;
}
