<?php

namespace App\Http\Controllers\Api;

use App\Enums\AttemptStatus;
use App\Enums\Skill;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Presenters\Api;
use App\Models\Attempt;
use App\Models\Recording;
use App\Services\AttemptService;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttemptController extends Controller
{
    public function __construct(private Api $present, private AttemptService $attempts) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'skill' => ['nullable', Rule::enum(Skill::class)],
            'status' => ['nullable', Rule::enum(AttemptStatus::class)],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $page = $request->user()->attempts()
            ->with('test')->withCount('recordings')
            ->when($data['skill'] ?? null, fn ($q, $skill) => $q->whereHas('test', fn ($t) => $t->where('skill', $skill)))
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->latest('started_at')
            ->paginate(20, page: $data['page'] ?? 1);

        return response()->json([
            'data' => collect($page->items())->map(fn (Attempt $a) => $this->present->attemptSummary($a))->all(),
            'meta' => ['page' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function show(Request $request, Attempt $attempt): JsonResponse
    {
        $this->authorizeOwner($request, $attempt);

        return response()->json($this->present->attempt($attempt->load('test', 'recordings')));
    }

    public function save(Request $request, Attempt $attempt): JsonResponse
    {
        $this->authorizeOwner($request, $attempt);
        $this->attempts->save($attempt, $this->progress($request));

        return response()->json(['savedAt' => $attempt->updated_at->toIso8601String()]);
    }

    public function submit(Request $request, Attempt $attempt): JsonResponse
    {
        $this->authorizeOwner($request, $attempt);
        $attempt = $this->attempts->submit($attempt, $this->progress($request));

        return response()->json($this->present->attemptSummary($attempt->load('test')->loadCount('recordings')));
    }

    public function uploadRecording(Request $request, Attempt $attempt): JsonResponse
    {
        $this->authorizeOwner($request, $attempt);
        $this->attempts->ensureOpen($attempt);
        abort_unless($attempt->test->skill === Skill::Speaking, 404);

        $data = $request->validate([
            'questionId' => ['required', 'string', 'max:64'],
            'durationSeconds' => ['nullable', 'numeric', 'min:0', 'max:600'],
            // MediaRecorder output: webm/opus (Chrome, Firefox) or mp4/aac (Safari)
            'audio' => ['required', 'file', 'max:5120', 'mimetypes:audio/webm,video/webm,audio/ogg,audio/mp4,video/mp4,audio/x-m4a,audio/aac,audio/mpeg'],
        ], ['audio.required' => 'The recording is missing.']);

        $existing = $attempt->recordings()->where('question_id', $data['questionId'])->first();
        $path = $request->file('audio')->store("recordings/{$attempt->id}", 'local');
        if ($existing) {
            Storage::disk('local')->delete($existing->path);
        }
        $recording = $attempt->recordings()->updateOrCreate(
            ['question_id' => $data['questionId']],
            ['path' => $path, 'duration_seconds' => (float) ($data['durationSeconds'] ?? 0)],
        );

        return response()->json($this->present->recording($recording), 201);
    }

    public function result(Request $request, Attempt $attempt): JsonResponse
    {
        $this->authorizeOwner($request, $attempt);
        if ($attempt->isOpen()) {
            throw new BusinessException('attempt_open', 'This test has not been submitted yet.', 409);
        }

        return response()->json($this->present->result($attempt->load('test', 'recordings')));
    }

    /** Signed link (see Recording::url) so <audio> can play it without extra auth. */
    public function audio(Recording $recording): StreamedResponse
    {
        return Storage::disk('local')->response($recording->path);
    }

    public function dashboard(Request $request, DashboardService $dashboard): JsonResponse
    {
        return response()->json($dashboard->build($request->user()));
    }

    private function progress(Request $request): array
    {
        return $request->validate([
            'answers' => ['sometimes', 'array'],
            'answers.*' => ['nullable'],
            'flagged' => ['sometimes', 'array'],
            'flagged.*' => ['string'],
            'remainingSeconds' => ['sometimes', 'integer', 'min:0'],
            'position' => ['sometimes', 'array'],
            'position.section' => ['required_with:position', 'integer', 'min:0'],
        ]);
    }

    private function authorizeOwner(Request $request, Attempt $attempt): void
    {
        abort_unless($attempt->user_id === $request->user()->id, 404);
    }
}
