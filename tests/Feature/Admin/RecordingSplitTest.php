<?php

namespace Tests\Feature\Admin;

use App\Enums\Skill;
use App\Filament\Resources\Tests\TestForm;
use App\Services\Audio\RecordingSplitter;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RecordingSplitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! RecordingSplitter::available()) {
            $this->markTestSkipped('ffmpeg is not installed.');
        }
        // An 8-second tone stands in for a full Listening recording.
        $disk = Storage::fake('public');
        $disk->makeDirectory('tests/audio');
        Process::run(['ffmpeg', '-loglevel', 'error', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=8', '-q:a', '9', $disk->path('tests/audio/full.mp3')])->throw();
    }

    private function listening(array $recording, array $parts = []): array
    {
        return ['skill' => Skill::Listening, 'form' => ['parts' => $parts, 'recording' => ['file' => 'tests/audio/full.mp3', ...$recording]]];
    }

    public function test_the_full_recording_is_cut_into_four_parts(): void
    {
        $data = TestForm::prepareRecordings($this->listening(['part2' => '0:02', 'part3' => '0:04', 'part4' => '6']));
        $parts = $data['form']['parts'];

        $this->assertCount(4, $parts);
        foreach ($parts as $i => $part) {
            Storage::disk('public')->assertExists($part['audio']);
            $this->assertStringEndsWith('-part'.($i + 1).'.mp3', $part['audio']);
            $this->assertEqualsWithDelta(2, $part['audio_duration'], 0.3);
        }

        // Saving again without changes does not cut it again.
        $again = TestForm::prepareRecordings($data);
        $this->assertSame(array_column($parts, 'audio'), array_column($again['form']['parts'], 'audio'));
    }

    public function test_new_start_times_replace_the_old_parts(): void
    {
        $first = TestForm::prepareRecordings($this->listening(['part2' => '0:02', 'part3' => '0:04', 'part4' => '0:06']));
        $data = $first;
        $data['form']['recording']['part4'] = '0:07';
        $second = TestForm::prepareRecordings($data);

        $this->assertEqualsWithDelta(1, $second['form']['parts'][3]['audio_duration'], 0.3);
        foreach ($first['form']['parts'] as $old) {
            Storage::disk('public')->assertMissing($old['audio']);
        }
    }

    public function test_start_times_must_go_up_and_fit_the_recording(): void
    {
        try {
            TestForm::prepareRecordings($this->listening(['part2' => '0:04', 'part3' => '0:03', 'part4' => '0:09']));
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('data.form.recording.part3', $e->errors());
            $this->assertStringContainsString('0:08 long', $e->errors()['data.form.recording.part3'][0]);
        }
    }

    public function test_a_part_uploaded_on_its_own_gets_its_length_measured(): void
    {
        $data = TestForm::prepareRecordings(['skill' => Skill::Listening, 'form' => ['parts' => [['audio' => 'tests/audio/full.mp3', 'groups' => []]]]]);

        $this->assertEqualsWithDelta(8, $data['form']['parts'][0]['audio_duration'], 0.3);
    }

    public function test_times_are_read_as_minutes_and_seconds(): void
    {
        $this->assertSame(462.0, RecordingSplitter::seconds('7:42'));
        $this->assertSame(3725.0, RecordingSplitter::seconds('1:02:05'));
        $this->assertSame(90.0, RecordingSplitter::seconds('90'));
        $this->assertNull(RecordingSplitter::seconds('7m42'));
    }
}
