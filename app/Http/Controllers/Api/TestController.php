<?php

namespace App\Http\Controllers\Api;

use App\Enums\Skill;
use App\Http\Controllers\Controller;
use App\Http\Presenters\Api;
use App\Models\Test;
use App\Services\AttemptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TestController extends Controller
{
    public function __construct(private Api $present, private AttemptService $attempts) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['skill' => ['required', Rule::enum(Skill::class)]]);
        $user = $request->user();
        $tests = Test::published()->where('skill', $data['skill'])->orderBy('sort')->orderBy('created_at')->get();
        $mine = $user->attempts()->whereIn('test_id', $tests->modelKeys())->get()->groupBy('test_id');

        return response()->json($tests->map(fn (Test $t) => $this->present->testSummary($t, $user, $mine->get($t->id, collect())))->values());
    }

    public function show(Request $request, Test $test): JsonResponse
    {
        abort_unless($test->is_published, 404);
        $mine = $request->user()->attempts()->where('test_id', $test->id)->get();

        return response()->json($this->present->testSummary($test, $request->user(), $mine));
    }

    public function start(Request $request, Test $test): JsonResponse
    {
        abort_unless($test->is_published, 404);
        $data = $request->validate(['listeningPlays' => ['nullable', 'integer', 'in:1,2']]);
        [$attempt, $created] = $this->attempts->start($request->user(), $test, (int) ($data['listeningPlays'] ?? 1));

        return response()->json($this->present->attempt($attempt->load('test', 'recordings')), $created ? 201 : 200);
    }
}
