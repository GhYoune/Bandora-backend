<?php

namespace App\Filament\Resources\Packs\Pages;

use App\Filament\Resources\Packs\PackResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManagePacks extends ManageRecords
{
    protected static string $resource = PackResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
