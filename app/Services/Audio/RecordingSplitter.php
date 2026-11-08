<?php

namespace App\Services\Audio;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Cuts one full Listening recording into its parts with ffmpeg (stream copy: fast, no quality loss),
 * and measures recordings with ffprobe so the teacher never types a length.
 */
class RecordingSplitter
{
    private const DISK = 'public';

    /**
     * @param  list<float>  $starts  start of each part in seconds; the first is usually 0
     * @return list<array{path: string, duration: float}>
     */
    public function split(string $path, array $starts): array
    {
        $source = Storage::disk(self::DISK)->path($path);
        $total = $this->duration($path) ?? throw ValidationException::withMessages([
            'form.recording.file' => 'This recording could not be read. Try another file (MP3 or M4A).',
        ]);

        foreach ($starts as $i => $start) {
            if ($start >= $total || ($i > 0 && $start <= $starts[$i - 1])) {
                throw ValidationException::withMessages(['form.recording.part'.max(2, $i + 1) => sprintf(
                    'The start times must go up and stay inside the recording (%s long).', self::clock($total),
                )]);
            }
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION)) ?: 'mp3';
        $base = 'tests/audio/'.Str::uuid();
        $parts = [];
        foreach ($starts as $i => $start) {
            $end = $starts[$i + 1] ?? $total;
            $out = "{$base}-part".($i + 1).".{$ext}";
            $this->ffmpeg(['-ss', (string) $start, '-to', (string) $end, '-i', $source, '-map', '0:a', '-c', 'copy', Storage::disk(self::DISK)->path($out)]);
            $parts[] = ['path' => $out, 'duration' => round($this->duration($out) ?? ($end - $start), 1)];
        }

        return $parts;
    }

    /** Length in seconds, or null when the file cannot be read. */
    public function duration(string $path): ?float
    {
        $file = Storage::disk(self::DISK)->path($path);
        if (! is_file($file)) {
            return null;
        }
        $result = Process::timeout(60)->run(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', $file]);

        return $result->successful() && is_numeric(trim($result->output())) ? (float) trim($result->output()) : null;
    }

    public static function available(): bool
    {
        return Process::run(['ffmpeg', '-version'])->successful();
    }

    /** "7:42", "1:02:05" or "462" → seconds. */
    public static function seconds(mixed $value): ?float
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }
        if (! preg_match('/^(\d+):([0-5]?\d(?:\.\d+)?)$|^(\d+):([0-5]?\d):([0-5]?\d(?:\.\d+)?)$/', $value, $m)) {
            return null;
        }

        return isset($m[3]) && $m[3] !== '' ? $m[3] * 3600 + $m[4] * 60 + (float) $m[5] : $m[1] * 60 + (float) $m[2];
    }

    public static function clock(float $seconds): string
    {
        $s = (int) round($seconds);

        return $s >= 3600 ? sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60) : sprintf('%d:%02d', intdiv($s, 60), $s % 60);
    }

    private function ffmpeg(array $args): void
    {
        $result = Process::timeout(300)->run(['ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', ...$args]);
        if (! $result->failed()) {
            return;
        }
        throw ValidationException::withMessages([
            'form.recording.file' => 'The recording could not be cut into parts: '.Str::limit(trim($result->errorOutput()), 200),
        ]);
    }
}
