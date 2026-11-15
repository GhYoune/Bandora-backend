<?php

namespace App\Filament\Resources\Tests\Pages;

use App\Filament\Resources\Tests\TestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTests extends ListRecords
{
    use CreatesTestsFromWorkbook;

    protected static string $resource = TestResource::class;

    protected function getHeaderActions(): array
    {
        [$template, $import] = $this->workbookActions();

        return [
            $template,
            // The text importer (/admin/tests/import) is kept for later but not linked.
            CreateAction::make()->label('New test (form)')->color('gray'),
            $import,
        ];
    }
}
