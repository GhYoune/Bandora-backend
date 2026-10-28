<?php

namespace Tests\Feature\Api;

use App\Enums\AttemptStatus;
use App\Enums\Skill;
use App\Models\User;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalog();
    }

    private function completed(User $user, Skill $skill, float $band, int $daysAgo, int $plays = 1): void
    {
        $user->attempts()->create([
            'test_id' => $this->firstOf($skill, free: $skill !== Skill::Speaking)->id,
            'status' => AttemptStatus::Completed,
            'listening_plays' => $plays,
            'answers' => [], 'flagged' => [], 'remaining_seconds' => 0, 'position' => ['section' => 0],
            'band' => $band,
            'started_at' => now()->subDays($daysAgo)->subHour(),
            'completed_at' => now()->subDays($daysAgo),
        ]);
    }

    public function test_band_is_the_average_of_the_last_three_and_overall_needs_all_skills(): void
    {
        $user = $this->student();
        $this->completed($user, Skill::Listening, 5.0, 10); // dropped: only the last 3 count
        $this->completed($user, Skill::Listening, 6.0, 8);
        $this->completed($user, Skill::Listening, 6.5, 6);
        $this->completed($user, Skill::Listening, 7.0, 4);
        $this->completed($user, Skill::Listening, 9.0, 3, plays: 2); // practice conditions: ignored
        $this->completed($user, Skill::Reading, 6.0, 5);

        $data = $this->getJson('/api/v1/dashboard')->assertOk()->json();
        $this->assertSame(['skill' => 'listening', 'band' => 6.5, 'testsCounted' => 3], $data['skills'][0]);
        $this->assertNull($data['overallBand']);
        $this->assertSame(['writing', 'speaking'], $data['missingSkills']);
        $this->assertCount(5, $data['trend']);
        $this->assertEquals(5.0, $data['trend'][0]['band']); // JSON has no int/float distinction

        $this->completed($user, Skill::Writing, 6.0, 2);
        $this->completed($user, Skill::Speaking, 6.5, 1);
        // (6.5 + 6.0 + 6.0 + 6.5) / 4 = 6.25 → 6.5 (IELTS rounds quarters up)
        $this->getJson('/api/v1/dashboard')->assertJsonPath('overallBand', 6.5)->assertJsonPath('missingSkills', []);
    }

    public function test_continue_points_to_the_last_touched_open_attempt(): void
    {
        $this->student();
        $reading = $this->firstOf(Skill::Reading, free: true);
        $id = $this->postJson("/api/v1/tests/{$reading->id}/attempts")->json('id');

        $this->getJson('/api/v1/dashboard')
            ->assertJsonPath('continueAttempt.id', $id)
            ->assertJsonPath('continueAttempt.title', 'Reading Test 1')
            ->assertJsonPath('recentAttempts', []);
    }
}
