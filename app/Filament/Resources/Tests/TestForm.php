<?php

namespace App\Filament\Resources\Tests;

use App\Enums\Skill;
use App\Models\Test;
use App\Services\Audio\RecordingSplitter;
use App\Services\Tests\TestCompiler;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * The teacher's test builder. Everything here is stored in the test's `form` column and compiled
 * by App\Services\Tests\TestCompiler. Question numbers are assigned automatically.
 */
class TestForm
{
    private const AUDIO_TYPES = ['audio/mpeg', 'audio/mp3', 'audio/mp4', 'audio/x-m4a', 'audio/aac', 'audio/wav', 'audio/x-wav', 'audio/ogg'];

    /** Full Cambridge-style recordings are often 60–100 MB. Keep in step with config/livewire.php. */
    public const AUDIO_MAX_KB = 200 * 1024;

    private const GAP_HELP = 'Write each gap with its answer in double brackets: "Name: Sarah [[Mitchell]]". '
        .'Accept several answers with "|": [[colour|color]]. Lines starting with "# " are headings, "- " are bullet points.';

    /**
     * One tab for the test details, then one tab per part, section or task of the chosen skill.
     * Each tab edits one slot of the `form` column (form.parts.0, form.sections.1, form.tasks.0…).
     */
    public static function schema(): array
    {
        $is = fn (Skill $skill) => fn (Get $get) => self::skillOf($get('skill')) === $skill;

        $tabs = [Tab::make('Test details')->icon(Heroicon::OutlinedCog6Tooth)->schema([
            ...self::details(),
            self::fullRecording()->visible($is(Skill::Listening)),
        ])];

        foreach (range(0, 3) as $i) {
            $tabs[] = Tab::make('Part '.($i + 1))
                ->icon(Heroicon::OutlinedSpeakerWave)
                ->statePath("form.parts.{$i}")
                ->visible($is(Skill::Listening))
                ->badge(fn ($livewire) => self::rangeBadge($livewire, 'parts', $i))
                ->schema(self::listeningPart($i));
        }
        foreach (range(0, 2) as $i) {
            $tabs[] = Tab::make('Section '.($i + 1))
                ->icon(Heroicon::OutlinedBookOpen)
                ->statePath("form.sections.{$i}")
                ->visible($is(Skill::Reading))
                ->badge(fn ($livewire) => self::rangeBadge($livewire, 'sections', $i))
                ->schema(self::readingSection($i));
        }
        foreach ([1, 2] as $n) {
            $tabs[] = Tab::make("Task {$n}")
                ->icon(Heroicon::OutlinedPencil)
                ->statePath('form.tasks.'.($n - 1))
                ->visible($is(Skill::Writing))
                ->schema(self::writingTask($n));
        }
        foreach (self::speakingParts() as $n => $schema) {
            $tabs[] = Tab::make("Part {$n}")
                ->icon(Heroicon::OutlinedMicrophone)
                ->statePath("form.part{$n}")
                ->visible($is(Skill::Speaking))
                ->schema($schema);
        }

        return [
            // What still blocks publishing, so the teacher can finish a draft.
            Callout::make('Not ready to publish yet')
                ->warning()
                ->description(fn (?Test $record) => $record ? implode(' ', $record->publishProblems()) : null)
                ->visible(fn (?Test $record) => $record && ! $record->is_published && $record->publishProblems() !== []),
            Tabs::make('Test')->tabs($tabs)->persistTabInQueryString(),
        ];
    }

    private static function details(): array
    {
        return [
            Grid::make(2)->schema([
                Select::make('skill')
                    ->options(Skill::class)
                    ->required()
                    ->live()
                    ->helperText('Choose the skill first: the tabs for its parts appear next to this one.')
                    ->afterStateUpdated(fn ($state, $set) => $set('duration_seconds', match ($state instanceof Skill ? $state->value : $state) {
                        'listening' => 2400, 'reading' => 3600, 'writing' => 3600, 'speaking' => 840, default => null,
                    }))
                    // Changing the skill would orphan the content and existing attempts.
                    ->disabledOn('edit'),
                TextInput::make('title')->required()->maxLength(255),
                TextInput::make('description')
                    ->maxLength(255)
                    ->helperText('Short summary shown in the test library, e.g. the topics.')
                    ->columnSpanFull(),
                TextInput::make('source')
                    ->maxLength(255)
                    ->placeholder('Book 17, Test 1')
                    ->helperText('Where the content comes from. Only visible here in the admin, never to students.')
                    ->columnSpanFull(),
                TextInput::make('duration_seconds')
                    ->label('Time allowed (seconds)')
                    ->numeric()->required()->minValue(60)
                    ->helperText('Listening 2400 (40 min), Reading 3600, Writing 3600 (both tasks) / 1200 (Task 1) / 2400 (Task 2), Speaking 840.'),
                TextInput::make('sort')->numeric()->default(0)->helperText('Lower numbers appear first.'),
                Toggle::make('is_free')->label('Free test')->helperText('Available on the free plan.'),
                Toggle::make('is_published')->label('Published')
                    ->helperText('Students only see published tests. A test needs all its questions, answers, pictures and recordings first.'),
            ]),
        ];
    }

    /** One file for the whole Listening test, cut into the 4 parts on save. */
    private static function fullRecording(): Section
    {
        $start = fn (int $n) => TextInput::make("part{$n}")
            ->label("Part {$n} starts at")
            ->placeholder('7:42')
            ->requiredWith('file')
            ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail) {
                if (filled($value) && RecordingSplitter::seconds($value) === null) {
                    $fail('Write the time as minutes:seconds, e.g. 7:42.');
                }
            });

        return Section::make('Recording')
            ->description('Upload the whole recording once and say where each part starts: when you save, it is cut into the 4 parts. If you have one file per part, skip this and upload them in the Part tabs instead.')
            ->statePath('form.recording')
            ->columns(3)
            ->schema([
                FileUpload::make('file')
                    ->label('Full recording (all 4 parts)')
                    ->disk('public')->directory('tests/audio')->visibility('public')
                    ->acceptedFileTypes(self::AUDIO_TYPES)
                    ->maxSize(self::AUDIO_MAX_KB)
                    ->columnSpanFull(),
                $start(2), $start(3), $start(4),
                Hidden::make('signature'),
                Hidden::make('generated'),
            ]);
    }

    private static function skillOf(mixed $state): ?Skill
    {
        return $state instanceof Skill ? $state : Skill::tryFrom((string) $state);
    }

    /* ---------- one tab per part / section / task ---------- */

    private static function listeningPart(int $i): array
    {
        $hints = [
            'A conversation between two people in an everyday situation, e.g. booking or enquiring. Usually form or note completion.',
            'One person talking about an everyday topic, e.g. a tour or a local facility. Often maps and multiple choice.',
            'A conversation in a training or education context. Often multiple choice and matching.',
            'A talk or lecture on a general topic. Usually notes completion, no break in the recording.',
        ];

        return [
            Text::make($hints[$i])->color('gray'),
            Hidden::make('id'),
            Section::make('Recording')
                ->description('Students hear it once, as in the real test.')
                ->compact()
                ->columns(2)
                ->schema([
                    FileUpload::make('audio')
                        ->label('Audio file')
                        ->helperText('Filled automatically if you uploaded the full recording in Test details.')
                        ->disk('public')->directory('tests/audio')->visibility('public')
                        ->acceptedFileTypes(self::AUDIO_TYPES)
                        ->maxSize(self::AUDIO_MAX_KB),
                    TextInput::make('audio_duration')->label('Recording length (seconds)')->numeric()->minValue(0)
                        ->helperText('Measured automatically when you save.'),
                ]),
            self::questionGroups(),
        ];
    }

    private static function readingSection(int $i): array
    {
        $hints = [
            'Section 1: short everyday texts, e.g. notices, adverts, timetables. Usually 2 or 3 texts.',
            'Section 2: workplace texts, e.g. job descriptions, staff policies, training. Usually 2 texts.',
            'Section 3: one long text on a general topic.',
        ];

        return [
            Text::make($hints[$i])->color('gray'),
            Hidden::make('id'),
            Repeater::make('passages')
                ->label('Texts')
                ->helperText('Students read these on the left of the screen, the questions are on the right.')
                ->addActionLabel('Add text')
                ->defaultItems(1)
                ->collapsible()
                ->itemLabel(fn (array $state) => $state['title'] ?? 'Text')
                ->schema([
                    Hidden::make('id'),
                    Grid::make(2)->schema([
                        TextInput::make('title')->required(),
                        TextInput::make('subtitle'),
                    ]),
                    Repeater::make('paragraphs')
                        ->helperText('Type each paragraph, or upload a photo or scan of it instead. One picture can hold a whole page. Typed text lets students highlight and take notes.')
                        ->addActionLabel('Add paragraph')
                        ->columns(12)
                        ->schema([
                            TextInput::make('label')->label('Letter')->placeholder('A')->maxLength(2)
                                ->helperText('For matching tasks')->columnSpan(2),
                            Textarea::make('text')->rows(4)->columnSpan(10)
                                ->requiredWithout('image')
                                ->validationMessages(['required_without' => 'Type the paragraph or upload a picture of it.']),
                            FileUpload::make('image')->label('…or a picture of it')->image()
                                ->disk('public')->directory('tests/images')->visibility('public')->maxSize(8 * 1024)
                                ->columnStart(3)->columnSpan(5),
                            TextInput::make('image_alt')->label('Picture description')
                                ->helperText('One sentence, for students using a screen reader.')
                                ->columnSpan(5),
                        ]),
                ]),
            self::questionGroups(),
        ];
    }

    private static function writingTask(int $n): array
    {
        $letter = $n === 1;

        return [
            Text::make($letter
                ? 'Task 1: a letter (informal, semi-formal or formal) with three bullet points. At least 150 words, about 20 minutes.'
                : 'Task 2: an essay giving an opinion or discussing a problem. At least 250 words, about 40 minutes.')->color('gray'),
            Text::make('Leave this tab empty if the test only has the other task.')->color('gray'),
            Hidden::make('id'),
            Hidden::make('number')->default($n),
            Hidden::make('kind')->default($letter ? 'letter' : 'essay'),
            Textarea::make('prompt')->label('Situation and question')->rows(6)
                ->helperText('Leave an empty line between paragraphs.'),
            Textarea::make('bullets')->label('Bullet points')->rows(3)
                ->helperText('"In your letter:" points, one per line.')
                ->visible($letter),
            TextInput::make('closing')
                ->placeholder($letter ? 'Write at least 150 words. Begin your letter as follows: Dear Sir or Madam,' : 'Give reasons for your answer and include any relevant examples.'),
            Grid::make(2)->schema([
                TextInput::make('min_words')->label('Minimum words')->numeric()->default($letter ? 150 : 250),
                TextInput::make('suggested_minutes')->label('Suggested minutes')->numeric()->default($letter ? 20 : 40),
            ]),
        ];
    }

    /** @return array<int, array> */
    private static function speakingParts(): array
    {
        // Not simple() repeaters: each question keeps a hidden id so recordings stay linked after edits.
        $questions = fn (string $name) => Repeater::make($name)
            ->schema([Hidden::make('id'), TextInput::make('text')->hiddenLabel()->required()])
            ->addActionLabel('Add question');

        return [
            1 => [
                Text::make('Interview: short questions about familiar topics. Each question is shown, the student records an answer.')->color('gray'),
                Grid::make(2)->schema([
                    TextInput::make('topic')->required(),
                    TextInput::make('answer_seconds')->label('Max answer length (seconds)')->numeric()->default(45),
                ]),
                $questions('questions'),
            ],
            2 => [
                Text::make('Cue card: the student gets a minute to prepare, then speaks for up to two minutes.')->color('gray'),
                Hidden::make('id'),
                TextInput::make('topic')->required()->placeholder('Describe a useful skill you have learned.'),
                Textarea::make('bullets')->rows(4)->helperText('"You should say:" points, one per line.'),
                TextInput::make('closing')->placeholder('and explain why this skill has been useful to you.'),
                Grid::make(2)->schema([
                    TextInput::make('prep_seconds')->label('Preparation (seconds)')->numeric()->default(60),
                    TextInput::make('speak_seconds')->label('Speaking time (seconds)')->numeric()->default(120),
                ]),
                $questions('follow_ups')->label('Follow-up questions')
                    ->helperText('One or two short questions asked after the long turn, as in the real test.'),
                TextInput::make('follow_up_seconds')->label('Max answer length per follow-up (seconds)')->numeric()->default(20),
            ],
            3 => [
                Text::make('Discussion: longer, more abstract questions linked to the Part 2 topic.')->color('gray'),
                Grid::make(2)->schema([
                    TextInput::make('topic')->required(),
                    TextInput::make('answer_seconds')->label('Max answer length (seconds)')->numeric()->default(75),
                ]),
                $questions('questions'),
            ],
        ];
    }

    /* ---------- loading and saving the fixed slots ---------- */

    /** Before filling the form: one slot per tab, Writing tasks in the slot of their number. */
    public static function toTabs(array $data): array
    {
        $form = $data['form'] ?? [];
        $skill = self::skillOf($data['skill'] ?? null);

        if ($skill === Skill::Listening) {
            $parts = array_values($form['parts'] ?? []);
            foreach (range(0, 3) as $i) {
                $parts[$i] ??= ['groups' => []];
            }
            $form['parts'] = $parts;
        } elseif ($skill === Skill::Reading) {
            $sections = array_values($form['sections'] ?? []);
            foreach (range(0, 2) as $i) {
                $sections[$i] ??= ['passages' => [], 'groups' => []];
            }
            $form['sections'] = $sections;
        } elseif ($skill === Skill::Writing) {
            $tasks = [0 => ['number' => 1, 'kind' => 'letter'], 1 => ['number' => 2, 'kind' => 'essay']];
            foreach ($form['tasks'] ?? [] as $task) {
                $slot = ((int) ($task['number'] ?? 2)) === 1 ? 0 : 1;
                $tasks[$slot] = $task;
            }
            $form['tasks'] = $tasks;
        }
        $data['form'] = $form;

        return $data;
    }

    /** Before saving: keep only the parts the teacher filled in. */
    public static function fromTabs(array $data): array
    {
        $skill = self::skillOf($data['skill'] ?? null);
        $form = $data['form'] ?? [];

        // Only the chosen skill's slots belong to this test.
        $keep = match ($skill) {
            Skill::Listening => ['parts', 'recording'],
            Skill::Reading => ['sections'],
            Skill::Writing => ['tasks'],
            Skill::Speaking => ['part1', 'part2', 'part3'],
            default => array_keys($form),
        };
        $form = array_intersect_key($form, array_flip($keep));

        if ($skill === Skill::Writing) {
            $form['tasks'] = array_values(array_filter($form['tasks'] ?? [], fn ($t) => filled($t['prompt'] ?? null)));
        }
        if (in_array($skill, [Skill::Listening, Skill::Reading], true)) {
            $key = $skill === Skill::Listening ? 'parts' : 'sections';
            $containers = array_values($form[$key] ?? []);
            // Empty trailing parts of a draft are dropped; publishing then reports them as missing.
            while ($containers && self::isEmptyContainer(end($containers))) {
                array_pop($containers);
            }
            $form[$key] = $containers;
        }
        $data['form'] = $form;

        return $data;
    }

    /**
     * Listening, after fromTabs(): cut the full recording into the parts when it or the start
     * times changed, and measure any part recording that has no length yet.
     */
    public static function prepareRecordings(array $data, ?RecordingSplitter $splitter = null): array
    {
        if (self::skillOf($data['skill'] ?? null) !== Skill::Listening) {
            return $data;
        }
        $splitter ??= app(RecordingSplitter::class);
        $form = $data['form'] ?? [];
        $rec = $form['recording'] ?? [];
        $file = is_array($rec['file'] ?? null) ? (reset($rec['file']) ?: null) : ($rec['file'] ?? null);

        if (filled($file)) {
            $starts = [0.0, ...array_map(fn ($n) => RecordingSplitter::seconds($rec["part{$n}"] ?? null) ?? -1.0, [2, 3, 4])];
            $signature = md5($file.'|'.implode(',', $starts));
            $parts = array_values($form['parts'] ?? []);
            $generated = (array) json_decode((string) ($rec['generated'] ?? '[]'), true);
            $stillThere = collect(range(0, 3))->every(fn ($i) => in_array($parts[$i]['audio'] ?? null, $generated, true));

            if (($rec['signature'] ?? null) !== $signature || ! $stillThere) {
                try {
                    $pieces = $splitter->split($file, $starts);
                } catch (ValidationException $e) {
                    // Point the message at the field on the page (Filament keeps form state under "data").
                    throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => ["data.{$k}" => $m])->all());
                }
                foreach ($pieces as $i => $piece) {
                    $parts[$i] = ($parts[$i] ?? ['groups' => []]);
                    $parts[$i]['audio'] = $piece['path'];
                    $parts[$i]['audio_duration'] = $piece['duration'];
                }
                // The previous cut is replaced; files the teacher uploaded by hand are never deleted.
                $old = array_diff($generated, array_column($pieces, 'path'));
                if ($old) {
                    Storage::disk('public')->delete(array_values($old));
                }
                $rec['signature'] = $signature;
                $rec['generated'] = json_encode(array_column($pieces, 'path'));
            }
            $rec['file'] = $file;
            $form['parts'] = $parts;
            $form['recording'] = $rec;
        }

        foreach ($form['parts'] ?? [] as $i => $part) {
            $audio = is_array($part['audio'] ?? null) ? (reset($part['audio']) ?: null) : ($part['audio'] ?? null);
            if (filled($audio) && empty($part['audio_duration']) && ($length = $splitter->duration($audio))) {
                $form['parts'][$i]['audio_duration'] = round($length, 1);
            }
        }
        $data['form'] = $form;

        return $data;
    }

    private static function isEmptyContainer(mixed $c): bool
    {
        return ! is_array($c) || (empty($c['groups']) && empty($c['passages']) && empty($c['audio']));
    }

    /** "Q 1–10" on a part or section tab, from the numbering the compiler gives the current form. */
    private static function rangeBadge(mixed $livewire, string $key, int $i): ?string
    {
        $data = $livewire->data ?? null;
        $skill = self::skillOf($data['skill'] ?? null);
        if (! is_array($data) || ! $skill?->isObjective()) {
            return null;
        }
        try {
            $content = app(TestCompiler::class)->compile($skill, self::fromTabs(['skill' => $skill] + $data)['form'])->content;
        } catch (\Throwable) {
            return null;
        }
        $groups = $content[$key][$i]['groups'] ?? [];
        if (! $groups) {
            return null;
        }

        return 'Q '.$groups[0]['firstNumber'].'–'.end($groups)['lastNumber'];
    }

    /* ---------- the 7 question types ---------- */

    private static function questionGroups(): Builder
    {
        // Factories, not shared instances: a component can only belong to one parent.
        $instructions = fn () => TextInput::make('instructions')
            ->placeholder('Leave empty for the standard IELTS wording')
            ->columnSpanFull();
        $picture = fn () => Section::make('Picture (optional)')
            ->description('Shown above these questions, e.g. a photo, a poster or a timetable.')
            ->collapsible()->collapsed(fn (Get $get) => blank($get('picture')))
            ->compact()
            ->columns(2)
            ->schema([
                FileUpload::make('picture')->hiddenLabel()->image()
                    ->disk('public')->directory('tests/images')->visibility('public')->maxSize(8 * 1024),
                TextInput::make('picture_alt')->label('Picture description')
                    ->helperText('One sentence, for students using a screen reader.'),
            ]);
        $wordLimit = fn () => TextInput::make('word_limit')->placeholder('Write ONE WORD AND/OR A NUMBER for each answer.');

        return Builder::make('groups')
            ->label('Questions')
            ->addActionLabel('Add questions')
            ->collapsible()
            ->blockIcons()
            ->blocks([
                Block::make('multiple_choice')
                    ->label('Multiple choice')
                    ->icon(Heroicon::OutlinedListBullet)
                    ->schema([
                        Text::make('Students see each question with its options as radio buttons (A, B, C…). With two correct options it becomes a "Choose TWO letters" question with checkboxes.')->color('gray'),
                        Hidden::make('id'),
                        $picture(),
                        $instructions(),
                        Repeater::make('questions')
                            ->addActionLabel('Add question')
                            ->itemLabel(fn (array $state) => $state['prompt'] ?? null)
                            ->schema([
                                Hidden::make('id'),
                                TextInput::make('prompt')->required(),
                                Repeater::make('options')
                                    ->helperText('Tick the correct option. Tick two for a "Choose TWO" question (it counts as two questions).')
                                    ->addActionLabel('Add option')
                                    ->defaultItems(3)
                                    ->columns(6)
                                    ->schema([
                                        TextInput::make('text')->required()->columnSpan(5),
                                        Toggle::make('correct')->inline(false),
                                    ]),
                            ]),
                    ]),

                Block::make('true_false_not_given')
                    ->label('True / False / Not Given')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->schema([
                        Text::make('Students see each statement with three buttons: TRUE, FALSE, NOT GIVEN (or YES, NO, NOT GIVEN).')->color('gray'),
                        Hidden::make('id'),
                        $picture(),
                        Select::make('variant')
                            ->options(['tfng' => 'TRUE / FALSE / NOT GIVEN', 'ynng' => 'YES / NO / NOT GIVEN'])
                            ->default('tfng')->required()->live(),
                        $instructions(),
                        Repeater::make('questions')
                            ->addActionLabel('Add statement')
                            ->columns(4)
                            ->schema([
                                Hidden::make('id'),
                                TextInput::make('statement')->required()->columnSpan(3),
                                Select::make('answer')->required()->options(fn (Get $get) => $get('../../variant') === 'ynng'
                                    ? ['yes' => 'YES', 'no' => 'NO', 'not given' => 'NOT GIVEN']
                                    : ['true' => 'TRUE', 'false' => 'FALSE', 'not given' => 'NOT GIVEN']),
                            ]),
                    ]),

                Block::make('matching')
                    ->label('Matching (headings, information, features, endings)')
                    ->icon(Heroicon::OutlinedArrowsRightLeft)
                    ->schema([
                        Text::make('Students see the list of options in a box, and a dropdown (A, B, C… or i, ii, iii…) next to each question.')->color('gray'),
                        Hidden::make('id'),
                        $picture(),
                        Grid::make(3)->schema([
                            Select::make('variant')->required()->default('general')->options([
                                'headings' => 'Matching headings (i, ii, iii…)',
                                'information' => 'Matching information',
                                'features' => 'Matching features',
                                'sentence_endings' => 'Matching sentence endings',
                                'general' => 'Other matching',
                            ]),
                            TextInput::make('options_title')->placeholder('List of Headings'),
                            Toggle::make('allow_reuse')->label('Options can be used more than once')->inline(false),
                        ]),
                        $instructions(),
                        Repeater::make('options')
                            ->helperText('Keys are added automatically: A, B, C… (or i, ii, iii… for headings).')
                            ->simple(TextInput::make('text')->required())
                            ->addActionLabel('Add option'),
                        Repeater::make('questions')
                            ->addActionLabel('Add question')
                            ->columns(4)
                            ->schema([
                                Hidden::make('id'),
                                TextInput::make('prompt')->required()->columnSpan(3)->placeholder('Paragraph B'),
                                TextInput::make('answer')->required()->placeholder('B or iv'),
                            ]),
                    ]),

                Block::make('gap_fill')
                    ->label('Note, form, sentence or summary completion')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->schema([
                        Text::make('Students see your text with an empty box at each [[gap]], numbered automatically. With a word bank, each box becomes a dropdown of the words.')->color('gray'),
                        Hidden::make('id'),
                        $picture(),
                        Grid::make(3)->schema([
                            Select::make('layout')->required()->default('notes')->options([
                                'notes' => 'Notes', 'form' => 'Form', 'sentences' => 'Sentences', 'summary' => 'Summary', 'flow_chart' => 'Flow chart',
                            ]),
                            TextInput::make('title'),
                            $wordLimit(),
                        ]),
                        $instructions(),
                        Textarea::make('text')->required()->rows(10)->helperText(self::GAP_HELP),
                        Repeater::make('word_bank')
                            ->label('Word bank (optional)')
                            ->helperText('If you add words here, students pick from the list (A, B, C…). In the text, write the word or its letter as the answer.')
                            ->simple(TextInput::make('text'))
                            ->defaultItems(0)
                            ->addActionLabel('Add word'),
                    ]),

                Block::make('table_completion')
                    ->label('Table completion')
                    ->icon(Heroicon::OutlinedTableCells)
                    ->schema([
                        Text::make('Students see the table with an empty box at each [[gap]].')->color('gray'),
                        Hidden::make('id'),
                        $picture(),
                        Grid::make(2)->schema([TextInput::make('title'), $wordLimit()]),
                        $instructions(),
                        TextInput::make('headers')->required()->placeholder('Stage | Details | Deadline')
                            ->helperText('Column headings separated by "|".'),
                        Repeater::make('rows')
                            ->simple(TextInput::make('cells')->required()->placeholder('Pilot | Check the [[clarity]] of the questions | Monday'))
                            ->helperText('One row per line, cells separated by "|". Gaps: [[answer]].')
                            ->addActionLabel('Add row'),
                    ]),

                Block::make('diagram_label')
                    ->label('Map, plan or diagram labelling')
                    ->icon(Heroicon::OutlinedMap)
                    ->schema([
                        Text::make('Map: students see the picture and choose a letter for each place. Diagram: they type the label into a box placed on the picture (X % / Y % from the top-left).')->color('gray'),
                        Hidden::make('id'),
                        Grid::make(3)->schema([
                            TextInput::make('title'),
                            Select::make('mode')->required()->default('letters')->live()->options([
                                'letters' => 'Map: letters are on the image',
                                'words' => 'Diagram: students write words',
                            ]),
                            TextInput::make('letters')->label('Number of letters on the image')->numeric()->default(8)
                                ->visible(fn (Get $get) => $get('mode') === 'letters'),
                        ]),
                        $instructions(),
                        FileUpload::make('image')->image()->required()
                            ->disk('public')->directory('tests/images')->visibility('public')->maxSize(5 * 1024),
                        TextInput::make('image_alt')->label('Image description')->required()
                            ->helperText('One sentence describing the image, for screen readers.'),
                        $wordLimit()->visible(fn (Get $get) => $get('mode') === 'words'),
                        Repeater::make('questions')
                            ->addActionLabel('Add label')
                            ->columns(6)
                            ->schema([
                                Hidden::make('id'),
                                TextInput::make('prompt')->columnSpan(3)->placeholder('Café'),
                                TextInput::make('answer')->required()->placeholder('C'),
                                TextInput::make('x')->label('X %')->numeric()->helperText('Diagram only'),
                                TextInput::make('y')->label('Y %')->numeric(),
                            ]),
                    ]),

                Block::make('short_answer')
                    ->label('Short-answer questions')
                    ->icon(Heroicon::OutlinedChatBubbleBottomCenterText)
                    ->schema([
                        Text::make('Students see each question with a box for a short written answer.')->color('gray'),
                        Hidden::make('id'),
                        $picture(),
                        $wordLimit(),
                        $instructions(),
                        Repeater::make('questions')
                            ->addActionLabel('Add question')
                            ->columns(3)
                            ->schema([
                                Hidden::make('id'),
                                TextInput::make('prompt')->required()->columnSpan(2),
                                TextInput::make('answer')->required()->helperText('Alternatives with "|"'),
                            ]),
                    ]),
            ]);
    }
}
