<?php

namespace App\Services\Marking;

use App\Models\Recording;
use Illuminate\Support\Collection;

/**
 * AI marking for Writing and Speaking. Implementations return the shapes of the API contract
 * (WritingResult task / SpeakingResult). Bound in AppServiceProvider from config('bandora.marking.driver').
 */
interface AiMarker
{
    /**
     * @param  array  $task  a WritingTask from the test content
     * @return array{band: float, summary: string, criteria: list<array>, annotations: list<array>, improvedVersion: ?string}
     */
    public function markWriting(array $task, string $text): array;

    /**
     * @param  array  $content  SpeakingContent
     * @param  Collection<int, Recording>  $recordings
     * @return array{band: float, summary: string, criteria: list<array>, transcripts: array<string, string>}
     */
    public function markSpeaking(array $content, Collection $recordings): array;
}
