<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Presenters\Api;
use App\Services\CustomPracticeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomPracticeController extends Controller
{
    public function store(Request $request, CustomPracticeService $practice, Api $present): JsonResponse
    {
        $data = $request->validate([
            'skill' => ['required', 'in:writing,speaking'],
            // Writing
            'taskNumber' => ['required_if:skill,writing', 'integer', 'in:1,2'],
            'prompt' => ['required_if:skill,writing', 'string', 'min:20', 'max:3000'],
            'timed' => ['required_if:skill,writing', 'boolean'],
            // Speaking
            'part' => ['required_if:skill,speaking', 'integer', 'in:1,2,3'],
            'questions' => ['exclude_unless:part,1,3', 'exclude_unless:skill,speaking', 'required', 'array', 'min:1', 'max:8'],
            'questions.*' => ['required', 'string', 'max:300'],
            'cueCard' => ['exclude_unless:part,2', 'exclude_unless:skill,speaking', 'required', 'array'],
            'cueCard.topic' => ['required', 'string', 'max:300'],
            'cueCard.bullets' => ['sometimes', 'array', 'max:6'],
            'cueCard.bullets.*' => ['string', 'max:200'],
            'cueCard.closing' => ['nullable', 'string', 'max:300'],
        ], [
            'prompt.required_if' => 'Paste the task you want to practise.',
            'prompt.min' => 'The task looks too short. Paste the full question.',
            'questions.required' => 'Add at least one question.',
            'questions.*.required' => 'Remove empty questions or fill them in.',
            'cueCard.topic.required' => 'Write the cue card topic.',
        ]);

        $attempt = $practice->start($request->user(), $data);

        return response()->json($present->attempt($attempt->load('test', 'recordings')), 201);
    }
}
