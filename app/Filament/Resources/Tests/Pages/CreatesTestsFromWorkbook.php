<?php

namespace App\Filament\Resources\Tests\Pages;

use App\Enums\Skill;
use App\Filament\Resources\Tests\TestForm;
use App\Filament\Resources\Tests\TestResource;
use App\Models\Test;
use App\Services\Tests\TestWorkbook;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Tests list: "New test from spreadsheet" (the whole test in one workbook + its recordings and
 * pictures) and the workbook template to download.
 */
trait CreatesTestsFromWorkbook
{
    private const AUDIO_TYPES = ['audio/mpeg', 'audio/mp3', 'audio/mp4', 'audio/x-m4a', 'audio/aac', 'audio/wav', 'audio/x-wav', 'audio/ogg'];

    protected function workbookActions(): array
    {
        return [
            Action::make('workbookTemplate')
                ->label('Test template')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->modalHeading('Download a test template')
                ->modalDescription('An Excel workbook with a worked example. Replace the example with your test, then use "New test from spreadsheet".')
                ->modalSubmitActionLabel('Download')
                ->modalWidth('md')
                ->schema([
                    Select::make('skill')->options(Skill::class)->required()->default(Skill::Listening->value),
                ])
                ->action(function (array $data) {
                    $skill = $data['skill'] instanceof Skill ? $data['skill'] : Skill::from($data['skill']);
                    $path = tempnam(sys_get_temp_dir(), 'bandora').'.xlsx';
                    app(TestWorkbook::class)->writeTemplate($path, $skill);

                    return response()->download($path, "bandora-{$skill->value}-test-template.xlsx")->deleteFileAfterSend();
                }),

            Action::make('importWorkbook')
                ->label('New test from spreadsheet')
                ->icon(Heroicon::OutlinedTableCells)
                ->modalHeading('New test from a spreadsheet')
                ->modalDescription('Upload the filled-in test template with its recordings and pictures. Check the preview, then create the test. You can still change anything afterwards.')
                ->modalSubmitActionLabel('Create the test')
                ->modalWidth('3xl')
                ->schema([
                    FileUpload::make('workbook')
                        ->label('Test workbook (.xlsx)')
                        ->acceptedFileTypes([
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'application/vnd.oasis.opendocument.spreadsheet',
                        ])
                        ->storeFiles(false)
                        ->required()
                        ->live(),
                    FileUpload::make('recordings')
                        ->label('Recordings (Listening)')
                        ->helperText('The full recording, or one file per part, with the names written in the Details sheet.')
                        ->multiple()
                        ->disk('public')->directory('tests/audio')->visibility('public')
                        ->acceptedFileTypes(self::AUDIO_TYPES)
                        ->maxSize(TestForm::AUDIO_MAX_KB)
                        ->storeFileNamesIn('recording_names')
                        ->live(),
                    FileUpload::make('pictures')
                        ->label('Pictures')
                        ->helperText('Every picture named in the workbook: maps, diagrams, scanned paragraphs, pictures above questions.')
                        ->multiple()
                        ->image()
                        ->disk('public')->directory('tests/images')->visibility('public')
                        ->maxSize(8 * 1024)
                        ->storeFileNamesIn('picture_names')
                        ->live(),
                    Text::make('Upload the workbook to see what will be created.')
                        ->color('gray')
                        ->visible(fn (Get $get) => ! $this->firstUpload($get('workbook'))),
                    Html::make(fn (Get $get) => $this->workbookPreview($get('workbook'), $get('recordings'), $get('pictures')))
                        ->visible(fn (Get $get) => (bool) $this->firstUpload($get('workbook'))),
                ])
                ->action(function (array $data, Action $action) {
                    $media = [];
                    foreach ([...(array) ($data['recording_names'] ?? []), ...(array) ($data['picture_names'] ?? [])] as $path => $name) {
                        $media[strtolower(basename((string) $name))] = $path;
                    }
                    $result = $this->parseWorkbook($data['workbook'] ?? null, $media);

                    if (! $result || $result['errors']) {
                        Notification::make()->danger()->title('The workbook has mistakes')
                            ->body('Fix the rows listed in red, then upload it again.')->send();
                        $action->halt();
                    }

                    try {
                        $values = TestForm::prepareRecordings($result['data']);
                    } catch (ValidationException $e) {
                        Notification::make()->danger()->title('The recording could not be cut into parts')
                            ->body(implode(' ', collect($e->errors())->flatten()->all()))->persistent()->send();
                        $action->halt();
                    }

                    $problems = $values['is_published'] ? Test::problemsFor(Skill::from($values['skill']), $values['form']) : [];
                    if ($problems) {
                        $values['is_published'] = false;
                    }
                    $test = Test::create($values);

                    $notification = $problems
                        ? Notification::make()->warning()->title('Test created as a draft, not published yet')->body(implode(' ', $problems))->persistent()
                        : Notification::make()->success()->title('Test created')->body($test->is_published ? 'It is published.' : 'It is a draft: switch on Published when it is ready.');
                    $notification->send();

                    $this->redirect(TestResource::getUrl('edit', ['record' => $test]));
                }),
        ];
    }

    private function firstUpload(mixed $state): ?TemporaryUploadedFile
    {
        $file = is_array($state) ? (array_values($state)[0] ?? null) : $state;

        return $file instanceof TemporaryUploadedFile ? $file : null;
    }

    /** @param  array<string, string>  $media  file name (lower case) → path */
    private function parseWorkbook(mixed $workbookState, array $media): ?array
    {
        $file = $this->firstUpload($workbookState);
        if (! $file) {
            return null;
        }
        $workbook = app(TestWorkbook::class);
        try {
            return $workbook->parse($workbook->read($file->getRealPath(), $file->getClientOriginalExtension()), $media);
        } catch (\Throwable) {
            return ['errors' => [['sheet' => 'File', 'row' => 1, 'message' => 'This file could not be read. Save it as .xlsx and try again.']], 'data' => null];
        }
    }

    private function workbookPreview(mixed $workbookState, mixed $recordings, mixed $pictures): HtmlString
    {
        // Before submitting, media are temporary files: match them by their original names.
        $media = [];
        foreach ([...(array) $recordings, ...(array) $pictures] as $upload) {
            if ($upload instanceof TemporaryUploadedFile) {
                $media[strtolower($upload->getClientOriginalName())] = 'pending/'.$upload->getClientOriginalName();
            }
        }
        $result = $this->parseWorkbook($workbookState, $media);
        if (! $result) {
            return new HtmlString('');
        }

        $html = '';
        if ($result['errors']) {
            $items = implode('', array_map(fn ($e) => '<li><b>'.e($e['sheet']).' sheet, row '.$e['row'].':</b> '.e($e['message']).'</li>', array_slice($result['errors'], 0, 50)));
            $html .= '<div style="border:1px solid #fca5a5;background:rgba(220,38,38,.06);border-radius:.5rem;padding:.75rem 1rem;margin-bottom:1rem"><p style="font-weight:700;color:#dc2626">Fix these rows before creating the test</p><ul style="list-style:disc;padding-inline-start:1.25rem;display:grid;gap:.2rem;margin-top:.4rem">'.$items.'</ul></div>';
        }
        if (! $result['data']) {
            return new HtmlString($html);
        }

        $data = $result['data'];
        $skill = $result['skill'];
        $html .= '<p style="font-size:1.05rem"><b>'.e($data['title'] ?: 'Untitled').'</b> · '.e(ucfirst($skill->value)).' · '.round($data['duration_seconds'] / 60).' min'
            .($data['is_free'] ? ' · free' : '').' · '.($data['is_published'] ? 'published' : 'draft').'</p>';
        $html .= $this->contentSummary($skill, $result['content'] ?? [], $result['questionCount'], $data['form']);

        if ($result['problems']) {
            $title = $data['is_published'] ? 'It will be saved as a draft because:' : 'Saved as a draft. Before publishing:';
            $html .= '<div style="border:1px solid #fcd34d;background:rgba(245,158,11,.08);border-radius:.5rem;padding:.6rem 1rem;margin-top:1rem"><p style="font-weight:700">'.$title.'</p><ul style="list-style:disc;padding-inline-start:1.25rem">'
                .implode('', array_map(fn ($p) => '<li>'.e($p).'</li>', $result['problems'])).'</ul></div>';
        }
        $missing = array_filter($result['files'], fn ($f) => ! isset($media[strtolower(basename($f))]));
        if ($result['files']) {
            $html .= '<p style="margin-top:.75rem;color:#6b7280">Files named in the workbook: '.e(implode(', ', $result['files'])).($missing ? '' : ' (all uploaded)').'</p>';
        }

        return new HtmlString($html);
    }

    private function contentSummary(Skill $skill, array $content, int $questionCount, array $form): string
    {
        $li = fn (string $s) => '<li>'.$s.'</li>';
        $list = fn (array $items) => '<ul style="list-style:disc;padding-inline-start:1.25rem;margin-top:.25rem">'.implode('', $items).'</ul>';
        $range = fn (array $groups) => $groups ? 'questions '.$groups[0]['firstNumber'].'–'.end($groups)['lastNumber'] : 'no questions';
        $types = fn (array $groups) => implode(', ', array_map(fn ($g) => str_replace('_', ' ', $g['type']), $groups));

        return match ($skill) {
            Skill::Listening => '<p style="margin-top:.5rem"><b>'.$questionCount.' questions</b>'.(filled($form['recording']['file'] ?? null) ? ', full recording cut into 4 parts at '.e(implode(', ', [$form['recording']['part2'], $form['recording']['part3'], $form['recording']['part4']])) : '').'</p>'
                .$list(array_map(fn ($p) => $li('<b>'.e($p['title'] ?? 'Part '.$p['number']).'</b>: '.$range($p['groups']).' ('.e($types($p['groups'])).')'.(filled($p['audioUrl'] ?? null) ? ' · recording ✓' : (filled($form['recording']['file'] ?? null) ? ' · cut from the full recording' : ' · <span style="color:#b45309">no recording</span>'))), $content['parts'] ?? [])),
            Skill::Reading => '<p style="margin-top:.5rem"><b>'.$questionCount.' questions</b></p>'
                .$list(array_map(fn ($s) => $li('<b>'.e($s['title'] ?? 'Section '.$s['number']).'</b>: '
                    .implode('; ', array_map(fn ($p) => '"'.e($p['title']).'" ('.count($p['paragraphs']).' paragraph'.(count($p['paragraphs']) === 1 ? '' : 's').')', $s['passages'])).($s['passages'] ? ' · ' : 'no text · ')
                    .$range($s['groups']).' ('.e($types($s['groups'])).')'), $content['sections'] ?? [])),
            Skill::Writing => $list(array_map(fn ($t) => $li('<b>Task '.$t['number'].'</b> ('.$t['kind'].', at least '.$t['minWords'].' words): '.e(mb_strimwidth($t['prompt'], 0, 120, '…'))), $content['tasks'] ?? [])),
            Skill::Speaking => $list(array_map(fn ($p) => $li('<b>Part '.$p['number'].'</b>: '.e($p['topic']).' · '
                .($p['kind'] === 'cue_card' ? count($p['bullets']).' points, '.count($p['followUps']).' follow-up question(s)' : count($p['questions']).' questions')), $content['parts'] ?? [])),
        };
    }
}
