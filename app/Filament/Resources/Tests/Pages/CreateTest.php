<?php

namespace App\Filament\Resources\Tests\Pages;

use App\Filament\Resources\Tests\TestForm;
use App\Filament\Resources\Tests\TestResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;

class CreateTest extends CreateRecord
{
    use ImportsQuestions;
    use SavesAsDraftWhenIncomplete;

    protected static string $resource = TestResource::class;

    protected function getHeaderActions(): array
    {
        return $this->questionImportActions();
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->keepAsDraftIfIncomplete(TestForm::prepareRecordings(TestForm::fromTabs($data)));
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label('Save');
    }

    protected function getRedirectUrl(): string
    {
        return TestResource::getUrl('edit', ['record' => $this->getRecord()]);
    }

    protected function afterCreate(): void
    {
        $this->notifyIfKeptAsDraft();
    }
}
