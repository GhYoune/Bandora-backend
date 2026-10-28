<?php

namespace App\Filament\Resources\Tests;

use App\Enums\Skill;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

/**
 * The teacher's test builder. Everything here is stored in the test's `form` column and compiled
 * by App\Services\Tests\TestCompiler. Question numbers are assigned automatically.
 */
class TestForm
{
    private const GAP_HELP = 'Write each gap with its answer in double brackets: "Name: Sarah [[Mitchell]]". '
        .'Accept several answers with "|": [[colour|color]]. Lines starting with "# " are headings, "- " are bullet points.';

    public static function details(): array
    {
        return [
            Section::make('Test')
                ->columns(2)
                ->schema([
                    Select::make('skill')
                        ->options(Skill::class)
                        ->required()
                        ->live()
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
                    TextInput::make('duration_seconds')
                        ->label('Time allowed (seconds)')
                        ->numeric()->required()->minValue(60)
                        ->helperText('Listening 2400 (40 min), Reading 3600, Writing 3600 (both tasks) / 1200 (Task 1) / 2400 (Task 2), Speaking 840.'),
                    TextInput::make('sort')->numeric()->default(0)->helperText('Lower numbers appear first.'),
                    Toggle::make('is_free')->label('Free test')->helperText('Available on the free plan.'),
                    Toggle::make('is_published')->label('Published')->helperText('Students only see published tests.'),
                ]),
        ];
    }

    public static function content(): array
    {
        $is = fn (Skill $skill) => fn (Get $get) => self::skillOf($get('skill')) === $skill;

        return [
            Group::make(self::listening())->statePath('form')->visible($is(Skill::Listening)),
            Group::make(self::reading())->statePath('form')->visible($is(Skill::Reading)),
            Group::make(self::writing())->statePath('form')->visible($is(Skill::Writing)),
            Group::make(self::speaking())->statePath('form')->visible($is(Skill::Speaking)),
        ];
    }

    private static function skillOf(mixed $state): ?Skill
    {
        return $state instanceof Skill ? $state : Skill::tryFrom((string) $state);
    }

    /* ---------- skills ---------- */

    private static function listening(): array
    {
        return [
            Repeater::make('parts')
                ->label('Parts')
                ->helperText('IELTS Listening has 4 parts and 40 questions.')
                ->addActionLabel('Add part')
                ->defaultItems(4)
                ->collapsible()
                ->itemLabel('Part')
                ->itemNumbers()
                ->schema([
                    Hidden::make('id'),
                    Grid::make(2)->schema([
                        FileUpload::make('audio')
                            ->label('Recording')
                            ->disk('public')->directory('tests/audio')->visibility('public')
                            ->acceptedFileTypes(['audio/mpeg', 'audio/mp3', 'audio/mp4', 'audio/x-m4a', 'audio/wav', 'audio/ogg'])
                            ->maxSize(30 * 1024),
                        TextInput::make('audio_duration')->label('Recording length (seconds)')->numeric()->minValue(0),
                    ]),
                    self::questionGroups(),
                ]),
        ];
    }

    private static function reading(): array
    {
        return [
            Repeater::make('sections')
                ->label('Sections')
                ->helperText('General Training Reading has 3 sections and 40 questions: everyday texts, workplace texts, one long text.')
                ->addActionLabel('Add section')
                ->defaultItems(3)
                ->collapsible()
                ->itemLabel('Section')
                ->itemNumbers()
                ->schema([
                    Hidden::make('id'),
                    Repeater::make('passages')
                        ->label('Texts')
                        ->addActionLabel('Add text')
                        ->collapsible()
                        ->itemLabel(fn (array $state) => $state['title'] ?? 'Text')
                        ->schema([
                            Hidden::make('id'),
                            Grid::make(2)->schema([
                                TextInput::make('title')->required(),
                                TextInput::make('subtitle'),
                            ]),
                            Repeater::make('paragraphs')
                                ->addActionLabel('Add paragraph')
                                ->columns(12)
                                ->schema([
                                    TextInput::make('label')->label('Letter')->placeholder('A')->maxLength(2)
                                        ->helperText('For matching tasks')->columnSpan(2),
                                    Textarea::make('text')->required()->rows(4)->columnSpan(10),
                                ]),
                        ]),
                    self::questionGroups(),
                ]),
        ];
    }

    private static function writing(): array
    {
        return [
            Repeater::make('tasks')
                ->addActionLabel('Add task')
                ->maxItems(2)
                ->itemLabel(fn (array $state) => 'Task '.($state['number'] ?? ''))
                ->schema([
                    Hidden::make('id'),
                    Grid::make(4)->schema([
                        Select::make('number')->options([1 => 'Task 1', 2 => 'Task 2'])->required()->live(),
                        Select::make('kind')->options(['letter' => 'Letter', 'essay' => 'Essay'])->required(),
                        TextInput::make('min_words')->numeric()->placeholder('150 / 250'),
                        TextInput::make('suggested_minutes')->numeric()->placeholder('20 / 40'),
                    ]),
                    Textarea::make('prompt')->required()->rows(6)
                        ->helperText('The situation and question. Leave an empty line between paragraphs.'),
                    Textarea::make('bullets')->rows(3)
                        ->helperText('Letter bullet points, one per line.')
                        ->visible(fn (Get $get) => (int) $get('number') === 1),
                    TextInput::make('closing')
                        ->placeholder('Write at least 150 words. Begin your letter as follows: Dear Sir or Madam,'),
                ]),
        ];
    }

    private static function speaking(): array
    {
        // Not simple() repeaters: each question keeps a hidden id so recordings stay linked after edits.
        $questions = fn (string $name) => Repeater::make($name)
            ->schema([Hidden::make('id'), TextInput::make('text')->hiddenLabel()->required()])
            ->addActionLabel('Add question');

        return [
            Section::make('Part 1 · Interview')->statePath('part1')->schema([
                Grid::make(2)->schema([
                    TextInput::make('topic')->required(),
                    TextInput::make('answer_seconds')->label('Max answer length (seconds)')->numeric()->default(45),
                ]),
                $questions('questions'),
            ]),
            Section::make('Part 2 · Cue card')->statePath('part2')->schema([
                Hidden::make('id'),
                TextInput::make('topic')->required()->placeholder('Describe a useful skill you have learned.'),
                Textarea::make('bullets')->rows(4)->helperText('"You should say:" points, one per line.'),
                TextInput::make('closing')->placeholder('and explain why this skill has been useful to you.'),
                Grid::make(2)->schema([
                    TextInput::make('prep_seconds')->label('Preparation (seconds)')->numeric()->default(60),
                    TextInput::make('speak_seconds')->label('Speaking time (seconds)')->numeric()->default(120),
                ]),
                $questions('follow_ups')->label('Follow-up questions'),
            ]),
            Section::make('Part 3 · Discussion')->statePath('part3')->schema([
                Grid::make(2)->schema([
                    TextInput::make('topic')->required(),
                    TextInput::make('answer_seconds')->label('Max answer length (seconds)')->numeric()->default(75),
                ]),
                $questions('questions'),
            ]),
        ];
    }

    /* ---------- the 7 question types ---------- */

    private static function questionGroups(): Builder
    {
        // Factories, not shared instances: a component can only belong to one parent.
        $instructions = fn () => TextInput::make('instructions')
            ->placeholder('Leave empty for the standard IELTS wording')
            ->columnSpanFull();
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
                        Hidden::make('id'),
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
                        Hidden::make('id'),
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
                        Hidden::make('id'),
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
                        Hidden::make('id'),
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
                        Hidden::make('id'),
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
                        Hidden::make('id'),
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
