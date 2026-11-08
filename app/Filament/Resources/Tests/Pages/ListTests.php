<?php

namespace App\Filament\Resources\Tests\Pages;

use App\Filament\Resources\Tests\TestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTests extends ListRecords
{
    protected static string $resource = TestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // The text importer (/admin/tests/import) is kept for later but not linked: the tabbed form is the way in.
            CreateAction::make()->label('New test'),
        ];
    }
}
