<?php

namespace App\Filament\Resources\Tests\Pages;

use App\Enums\Skill;
use App\Filament\Resources\Tests\TestResource;
use App\Services\Tests\TestImporter;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

/**
 * Paste a whole Listening or Reading test written in the import format (docs/import-format.md).
 * The page checks it as you go, previews the questions with their answers, takes the pictures,
 * and saves the test as an unpublished draft. Recordings are added on the edit page.
 */
class ImportTest extends CreateRecord
{
    protected static string $resource = TestResource::class;

    protected static ?string $title = 'Import a test from text';

    protected static ?string $breadcrumb = 'Import';

    /** Parse results per (skill, text), so the preview and checks don't re-parse on every render. */
    private array $parsed = [];

    public function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Test')->columns(2)->schema([
                Select::make('skill')
                    ->options([Skill::Listening->value => 'Listening', Skill::Reading->value => 'Reading'])
                    ->required()
                    ->live(),
                TextInput::make('title')->required()->maxLength(255)->placeholder('Listening Test 5'),
                TextInput::make('description')->maxLength(255)->columnSpanFull()
                    ->helperText('Short summary shown in the test library, e.g. the topics.'),
                TextInput::make('source')->maxLength(255)->placeholder('Book 17, Test 1')
                    ->helperText('Only visible in the admin.'),
                TextInput::make('sort')->numeric()->default(0)->helperText('Lower numbers appear first.'),
                Toggle::make('is_free')->label('Free test')->helperText('Available on the free plan.'),
            ]),

            Section::make('Paste the test')
                ->description(new HtmlString('Write it in the import format: <b># Part 1</b> / <b># Section 1</b>, <b>## Text: …</b> for reading texts, then <b>Questions 1–5</b> with a <b>Type:</b> line. Answers go in <b>[[double brackets]]</b>, the right option gets a <b>*</b>. See docs/import-format.md for every question type.'))
                ->schema([
                    Textarea::make('text')
                        ->hiddenLabel()
                        ->required()
                        ->rows(28)
                        ->extraInputAttributes(['style' => 'font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 13px;'])
                        ->live(onBlur: true),
                ]),

            Callout::make(fn (Get $get) => $this->check($get, 'errors') ? 'Fix these before importing' : null)
                ->danger()
                ->description(fn (Get $get) => $this->problemList($this->check($get, 'errors')))
                ->visible(fn (Get $get) => (bool) $this->check($get, 'errors')),
            Callout::make('Not ready to publish yet. You can still save it as a draft')
                ->warning()
                ->description(fn (Get $get) => $this->problemList($this->check($get, 'warnings')))
                ->visible(fn (Get $get) => ! $this->check($get, 'errors') && $this->check($get, 'warnings')),

            Section::make('Pictures')
                ->description('These questions need their map or diagram. You can also add them later on the edit page.')
                ->visible(fn (Get $get) => (bool) $this->check($get, 'images'))
                ->schema(fn (Get $get) => array_map(
                    fn (array $image) => FileUpload::make('images.'.str_replace('.', '_', $image['key']))
                        ->label("Line {$image['line']}: {$image['description']}")
                        ->image()
                        ->disk('public')->directory('tests/images')->visibility('public'),
                    $this->check($get, 'images') ?: [],
                )),

            Section::make('Preview')
                ->description('Check the numbering and the answers. Students never see the answers.')
                ->visible(fn (Get $get) => $this->check($get, 'content') !== null && ! $this->check($get, 'errors'))
                ->schema([Html::make(fn (Get $get) => $this->preview($this->check($get, 'content'), $this->check($get, 'answerKey') ?? []))]),
        ]);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $skill = Skill::from($data['skill']);
        $importer = app(TestImporter::class);
        $result = $importer->parse($skill, (string) $data['text']);

        if ($result['errors']) {
            Notification::make()->title('The test has format errors')->body('Fix the problems listed in red, then import again.')->danger()->send();
            $this->halt();
        }

        $images = collect($data['images'] ?? [])->mapWithKeys(fn ($path, $key) => [str_replace('_', '.', $key) => $path])->all();

        return [
            'skill' => $skill,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'source' => $data['source'] ?? null,
            'sort' => (int) ($data['sort'] ?? 0),
            'is_free' => (bool) ($data['is_free'] ?? false),
            'is_published' => false, // published from the edit page once recordings and pictures are in
            'duration_seconds' => $skill === Skill::Listening ? 2400 : 3600,
            'form' => $importer->withImages($result['form'], $images),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return TestResource::getUrl('edit', ['record' => $this->getRecord()]);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Test imported as a draft. Add the recordings and pictures, then publish it.';
    }

    /* ---------- helpers ---------- */

    private function check(Get $get, string $part): mixed
    {
        $skill = Skill::tryFrom((string) $get('skill'));
        $text = (string) $get('text');
        if (! $skill || trim($text) === '') {
            return null;
        }
        $key = $skill->value.':'.md5($text);
        $this->parsed[$key] ??= app(TestImporter::class)->parse($skill, $text);

        return $this->parsed[$key][$part] ?? null;
    }

    private function problemList(?array $problems): ?HtmlString
    {
        if (! $problems) {
            return null;
        }
        $items = array_map(
            fn (array $p) => '<li>'.($p['line'] ? '<b>Line '.$p['line'].':</b> ' : '').e($p['message']).'</li>',
            array_slice($problems, 0, 30),
        );
        $more = count($problems) > 30 ? '<li>…and '.(count($problems) - 30).' more.</li>' : '';

        return new HtmlString('<ul style="list-style: disc; padding-inline-start: 1.25rem; display: grid; gap: .25rem;">'.implode('', $items).$more.'</ul>');
    }

    /** A compact, readable listing: each block with its questions and accepted answers. */
    private function preview(?array $content, array $key): HtmlString
    {
        if (! $content) {
            return new HtmlString('');
        }
        $containers = $content['parts'] ?? $content['sections'] ?? [];
        $html = '';
        foreach ($containers as $c) {
            $html .= '<h3 style="font-weight:700;font-size:1.05rem;margin:1rem 0 .25rem">'.e($c['title']).'</h3>';
            foreach ($c['passages'] ?? [] as $p) {
                $html .= '<p style="opacity:.8">Text: <b>'.e($p['title']).'</b>, '.count($p['paragraphs']).' paragraph(s)</p>';
            }
            foreach ($c['groups'] as $g) {
                $html .= '<p style="margin-top:.6rem"><b>Questions '.$g['firstNumber'].'–'.$g['lastNumber'].'</b> · '.e(str_replace('_', ' ', $g['type'])).'</p><ol style="padding-inline-start:1.25rem">';
                foreach ($this->questionsOf($g) as [$numbers, $prompt, $id]) {
                    $answers = array_map(fn ($a) => preg_match('/^[a-h]$|^[ivx]+$/', $a) ? strtoupper($a) : $a, $key[$id] ?? []);
                    $html .= '<li value="'.$numbers[0].'">'.e($prompt).' → <b style="color:#16a34a">'.e(implode(' / ', $answers) ?: '(no answer)').'</b></li>';
                }
                $html .= '</ol>';
            }
        }

        return new HtmlString($html);
    }

    /** @return list<array{0: list<int>, 1: string, 2: string}> */
    private function questionsOf(array $g): array
    {
        return array_map(function (array $q) use ($g) {
            $numbers = $q['numbers'] ?? [$q['number']];
            $prompt = $q['prompt'] ?? '';
            if ($prompt === '' && in_array($g['type'], ['gap_fill', 'table_completion'], true)) {
                $prompt = 'Gap '.$numbers[0];
            }

            return [$numbers, $prompt, $q['id']];
        }, $g['questions']);
    }
}
