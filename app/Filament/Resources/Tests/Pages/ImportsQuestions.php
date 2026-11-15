<?php

namespace App\Filament\Resources\Tests\Pages;

use App\Enums\Skill;
use App\Services\Tests\QuestionTemplate;
use App\Services\Tests\SpreadsheetImporter;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Header actions of the test pages: download the question spreadsheet, and import a filled one
 * (plus its pictures) into the Part / Section tabs. Nothing is saved until the teacher saves.
 */
trait ImportsQuestions
{
    protected function questionImportActions(): array
    {
        return [
            Action::make('questionTemplate')
                ->label('Question template')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->visible(fn () => $this->importSkill() !== null)
                ->action(function () {
                    $path = tempnam(sys_get_temp_dir(), 'bandora').'.xlsx';
                    app(QuestionTemplate::class)->write($path);

                    return response()->download($path, 'bandora-questions-template.xlsx')->deleteFileAfterSend();
                }),

            Action::make('importQuestions')
                ->label('Import questions')
                ->icon(Heroicon::OutlinedTableCells)
                ->visible(fn () => $this->importSkill() !== null)
                ->modalHeading('Import questions from a spreadsheet')
                ->modalDescription('Fill in the question template in Excel or Google Sheets, then upload it here with its pictures (a full test workbook works too: its Questions sheet is used). The questions are added to the Part / Section tabs; check them, then save.')
                ->modalSubmitActionLabel('Add to the test')
                ->modalWidth('3xl')
                ->schema([
                    FileUpload::make('sheet')
                        ->label('Spreadsheet (.xlsx, .ods or .csv)')
                        ->acceptedFileTypes([
                            'text/csv', 'text/plain', 'application/csv',
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'application/vnd.oasis.opendocument.spreadsheet',
                        ])
                        ->storeFiles(false)
                        ->required()
                        ->live(),
                    FileUpload::make('pictures')
                        ->label('Pictures named in the Picture column (optional)')
                        ->multiple()
                        ->image()
                        ->disk('public')->directory('tests/images')->visibility('public')
                        ->maxSize(8 * 1024)
                        ->storeFileNamesIn('picture_names')
                        ->live(),
                    Toggle::make('replace')
                        ->label('Replace the questions already in these parts')
                        ->helperText('Off: the imported questions are added after the existing ones.'),
                    Text::make('Upload the spreadsheet to see what will be imported.')
                        ->color('gray')
                        ->visible(fn (Get $get) => ! $this->uploadedSheet($get('sheet'))),
                    Html::make(fn (Get $get) => $this->importPreview($get('sheet'), $get('pictures')))
                        ->visible(fn (Get $get) => (bool) $this->uploadedSheet($get('sheet'))),
                ])
                ->action(function (array $data, Action $action) {
                    $skill = $this->importSkill();
                    $sheet = $this->uploadedSheet($data['sheet'] ?? null);
                    $pictures = [];
                    foreach ((array) ($data['picture_names'] ?? []) as $path => $name) {
                        $pictures[strtolower(basename((string) $name))] = $path;
                    }
                    $importer = app(SpreadsheetImporter::class);
                    $result = $importer->parse($skill, $importer->read($sheet->getRealPath(), $sheet->getClientOriginalExtension()), $pictures);

                    if ($result['errors']) {
                        Notification::make()->danger()->title('The spreadsheet has mistakes')
                            ->body('Fix the rows listed in red, then import again.')->send();
                        $action->halt();
                    }

                    $this->addImportedQuestions($skill, $result['containers'], (bool) ($data['replace'] ?? false));

                    Notification::make()->success()
                        ->title("{$result['count']} questions added")
                        ->body('Check them in the tabs, then save.')
                        ->send();
                }),
        ];
    }

    private function importSkill(): ?Skill
    {
        $skill = $this->data['skill'] ?? null;
        $skill = $skill instanceof Skill ? $skill : Skill::tryFrom((string) $skill);

        return in_array($skill, [Skill::Listening, Skill::Reading], true) ? $skill : null;
    }

    private function uploadedSheet(mixed $state): ?TemporaryUploadedFile
    {
        $file = is_array($state) ? (array_values($state)[0] ?? null) : $state;

        return $file instanceof TemporaryUploadedFile ? $file : null;
    }

    private function importPreview(mixed $sheetState, mixed $pictureState): HtmlString
    {
        $skill = $this->importSkill();
        $sheet = $this->uploadedSheet($sheetState);
        if (! $skill || ! $sheet) {
            return new HtmlString('');
        }
        // Before submitting, pictures are temporary files: match them by their original names.
        $pictures = [];
        foreach ((array) $pictureState as $file) {
            if ($file instanceof TemporaryUploadedFile) {
                $pictures[strtolower($file->getClientOriginalName())] = 'pending';
            }
        }
        $importer = app(SpreadsheetImporter::class);
        try {
            $result = $importer->parse($skill, $importer->read($sheet->getRealPath(), $sheet->getClientOriginalExtension()), $pictures);
        } catch (\Throwable $e) {
            return new HtmlString('<p style="color:#dc2626"><b>This file could not be read.</b> Save it as .xlsx or .csv and try again.</p>');
        }

        $unit = $skill === Skill::Listening ? 'Part' : 'Section';
        $html = '';
        if ($result['errors']) {
            $items = implode('', array_map(fn ($e) => '<li><b>Row '.$e['row'].':</b> '.e($e['message']).'</li>', array_slice($result['errors'], 0, 40)));
            $html .= '<div style="border:1px solid #fca5a5;background:rgba(220,38,38,.06);border-radius:.5rem;padding:.75rem 1rem;margin-bottom:1rem"><p style="font-weight:700;color:#dc2626">Fix these rows before importing</p><ul style="list-style:disc;padding-inline-start:1.25rem;display:grid;gap:.2rem;margin-top:.4rem">'.$items.'</ul></div>';
        }
        $html .= '<p style="margin-bottom:.5rem"><b>'.$result['count'].' questions</b> found.</p>';
        foreach ($result['containers'] as $i => $blocks) {
            $html .= '<p style="font-weight:700;margin-top:.75rem">'.$unit.' '.($i + 1).'</p><ul style="list-style:disc;padding-inline-start:1.25rem">';
            foreach ($blocks as $block) {
                $label = Str::headline($block['type']).(isset($block['data']['title']) && $block['data']['title'] ? ': '.e($block['data']['title']) : '');
                $n = $this->blockQuestionCount($block);
                $html .= "<li>{$label} · {$n} question".($n === 1 ? '' : 's').'</li>';
            }
            $html .= '</ul>';
        }

        return new HtmlString($html);
    }

    private function blockQuestionCount(array $block): int
    {
        $d = $block['data'];

        return match ($block['type']) {
            'gap_fill' => preg_match_all('/\[\[/', $d['text'] ?? ''),
            'table_completion' => preg_match_all('/\[\[/', implode("\n", $d['rows'] ?? [])),
            'multiple_choice' => collect($d['questions'] ?? [])->sum(fn ($q) => max(1, collect($q['options'])->where('correct', true)->count())),
            default => count($d['questions'] ?? []),
        };
    }

    /** @param  array<int, list<array{type: string, data: array}>>  $containers */
    private function addImportedQuestions(Skill $skill, array $containers, bool $replace): void
    {
        $key = $skill === Skill::Listening ? 'parts' : 'sections';
        $data = $this->data;
        foreach ($containers as $i => $blocks) {
            $slot = $data['form'][$key][$i] ?? [];
            $groups = $replace ? [] : (array) ($slot['groups'] ?? []);
            foreach ($blocks as $block) {
                $groups[(string) Str::uuid()] = $block;
            }
            $slot['groups'] = $groups;
            $data['form'][$key][$i] = $slot;
        }
        ksort($data['form'][$key]);
        $this->form->fill($data);
    }
}
