<?php

namespace Database\Seeders;

use App\Enums\Skill;
use App\Models\Pack;
use App\Models\Test;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /** Example packs, admin account, a demo student and the sample tests. Safe to run once on a fresh database. */
    public function run(): void
    {
        $packs = collect([
            ['name' => '1 month', 'duration_days' => 30, 'price_dzd' => 3500, 'credits' => 10, 'highlighted' => false, 'sort' => 1,
                'features' => ['Every Listening and Reading test', 'Every Writing and Speaking test', '10 AI marking credits']],
            ['name' => '3 months', 'duration_days' => 90, 'price_dzd' => 8000, 'credits' => 40, 'highlighted' => true, 'sort' => 2,
                'features' => ['Every Listening and Reading test', 'Every Writing and Speaking test', '40 AI marking credits', 'Full progress history']],
            ['name' => '6 months', 'duration_days' => 180, 'price_dzd' => 13000, 'credits' => 100, 'highlighted' => false, 'sort' => 3,
                'features' => ['Every Listening and Reading test', 'Every Writing and Speaking test', '100 AI marking credits', 'Full progress history']],
        ])->map(fn (array $p) => Pack::create($p));

        $admin = User::create([
            'name' => 'Bandora admin',
            'email' => env('ADMIN_EMAIL', 'admin@bandora.dz'),
            'password' => env('ADMIN_PASSWORD', 'password'),
        ]);
        $admin->forceFill(['is_admin' => true, 'email_verified_at' => now()])->save();

        $student = User::create(['name' => 'Amina Benali', 'email' => 'student@demo.dz', 'password' => 'password', 'whatsapp' => '+213550123456']);
        $student->forceFill(['email_verified_at' => now(), 'credits' => User::FREE_CREDITS])->save();
        app(PaymentService::class)->activate($student, $packs[1]);

        $this->seedTests();
    }

    private function seedTests(): void
    {
        $listening = require __DIR__.'/content/listening-1.php';
        $reading = require __DIR__.'/content/reading-1.php';
        $ws = require __DIR__.'/content/writing-speaking.php';

        // Tests 2–4 reuse the sample content so the library looks full in the prototype.
        $tests = [
            [Skill::Listening, 'Listening Test 1', 'Leisure centre membership · Greenway Park · student survey · urban bees', true, 40 * 60, $listening],
            [Skill::Listening, 'Listening Test 2', 'Hotel booking · museum tour · group assignment · ocean plastics', false, 40 * 60, $listening],
            [Skill::Listening, 'Listening Test 3', 'Car rental · city festival · tutorial discussion · sleep research', false, 40 * 60, $listening],
            [Skill::Reading, 'Reading Test 1', 'College courses · library rules · workplace induction · night trains', true, 60 * 60, $reading],
            [Skill::Reading, 'Reading Test 2', 'Gym timetable · tenancy notice · staff training · the honeybee', false, 60 * 60, $reading],
            [Skill::Reading, 'Reading Test 3', 'Ferry services · recycling rules · payslips explained · urban farming', false, 60 * 60, $reading],
            [Skill::Writing, 'Task 2 · Leaving the countryside', 'Causes and solutions essay', true, 40 * 60, $ws['writing-2']],
            [Skill::Writing, 'Writing Test 1', 'Letter: a lost item · Essay: working from home', false, 60 * 60, $ws['writing-1']],
            [Skill::Writing, 'Task 1 · Advice to a friend', 'Informal letter', false, 20 * 60, $ws['writing-3']],
            [Skill::Speaking, 'Speaking Test 1', 'Home town · a useful skill · learning new skills', false, 14 * 60, $ws['speaking-1']],
        ];

        foreach ($tests as $i => [$skill, $title, $description, $free, $duration, $form]) {
            Test::create([
                'skill' => $skill,
                'title' => $title,
                'description' => $description,
                'is_free' => $free,
                'is_published' => true,
                'duration_seconds' => $duration,
                'sort' => $i,
                'form' => $form,
            ]);
        }
    }
}
