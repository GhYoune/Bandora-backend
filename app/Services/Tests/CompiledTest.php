<?php

namespace App\Services\Tests;

final readonly class CompiledTest
{
    /**
     * @param  array  $form  the teacher's form with generated ids written back
     * @param  array  $content  public content (API contract TestContent), no answers
     * @param  array<string, list<string>>  $answerKey  question id => accepted answers, lower-case
     */
    public function __construct(
        public array $form,
        public array $content,
        public array $answerKey,
        public int $questionCount,
    ) {}
}
