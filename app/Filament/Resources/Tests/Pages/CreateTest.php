<?php

namespace App\Filament\Resources\Tests\Pages;

use App\Filament\Resources\Tests\TestForm;
use App\Filament\Resources\Tests\TestResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTest extends CreateRecord
{
    protected static string $resource = TestResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return TestForm::prepareRecordings(TestForm::fromTabs($data));
    }

    protected function getRedirectUrl(): string
    {
        return TestResource::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
