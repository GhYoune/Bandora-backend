<?php

namespace App\Filament\Resources\Tests\Pages;

use App\Filament\Resources\Tests\TestForm;
use App\Filament\Resources\Tests\TestResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTest extends EditRecord
{
    protected static string $resource = TestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return TestForm::toTabs($data);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // The skill field is disabled on edit, so it is not in $data.
        return TestForm::prepareRecordings(TestForm::fromTabs(['skill' => $this->getRecord()->skill] + $data));
    }
}
