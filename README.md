# Bandora — backend (Laravel 13 + Filament 5)

The API for the Next.js app (`../frontend`) and the teacher's admin panel. It implements
`../contract/openapi.yaml`; the business rules are written in that file's description.

## Run it

```bash
composer install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite          # SQLite in development; MySQL in production
php artisan migrate --seed
php artisan storage:link
php artisan serve                       # http://localhost:8000
php artisan queue:work                  # AI marking runs on the queue
```

- Admin: http://localhost:8000/admin — `ADMIN_EMAIL` / `ADMIN_PASSWORD` from `.env` (seeded).
- Demo student: `student@demo.dz` / `password` (3-month pack).
- Frontend against this API: in `../frontend/.env.local` set
  `NEXT_PUBLIC_API_URL=http://localhost:8000` and `NEXT_PUBLIC_API_MOCKING=disabled`, then restart `pnpm dev`.
- Emails (verification, password reset) go to `storage/logs/laravel.log` while `MAIL_MAILER=log`.

Tests: `php artisan test` (API, business rules, compiler, Filament admin).

## Where things are

| Path | What it is |
|---|---|
| `routes/api.php` | Every endpoint of the contract (`/api/v1`). Browser redirects (Google, email link, recording playback) are in `routes/web.php`. |
| `app/Http/Presenters/Api.php` | The JSON shapes, one method per contract schema. |
| `app/Services/Tests/TestCompiler.php` | Turns the teacher's form into public test content + answer key, numbers questions 1–40, keeps ids stable. |
| `app/Services/AttemptService.php` | Start / autosave / submit, locking, credit charging. |
| `app/Services/PaymentService.php` | WhatsApp payment requests, approval, pack activation and extension. |
| `app/Services/DashboardService.php` | Estimated bands (average of last 3, practice attempts excluded). |
| `app/Services/Marking/` | `ObjectiveMarker` (Listening/Reading), `AiMarker` interface, `FakeAiMarker`. |
| `app/Jobs/MarkAttempt.php` | Queued AI marking; refunds credits if marking fails. |
| `app/Support/Bands.php` | Raw score → band tables, rounding, Writing task weighting. |
| `app/Filament/` | Admin: Payments inbox, Students, Tests (builder), Packs. |
| `database/seeders/content/` | Sample tests in the teacher's format — a good reference for writing real ones. |

## Writing tests (for the teacher)

In **Admin → Tests → New test**, pick the skill, then add question blocks. Answers are written inline:

- Gaps: `Name: Sarah [[Mitchell]]`; several accepted answers: `[[colour|color]]`.
- Tables: one row per line, cells separated by `|`: `Pilot | Check the [[clarity]] | Monday`.
- Multiple choice: tick the correct option; tick two for a "Choose TWO" question.
- Matching and map labelling: the answer is the letter (or `i`, `ii`… for headings).

Question numbers are added automatically. Listening and Reading tests show in red in the list until they have 40 questions.

## Still to do before launch

- **AI marking driver.** `MARKING_DRIVER=fake` gives rule-based feedback so the flow works. Add a class implementing
  `App\Services\Marking\AiMarker` (Writing: criteria bands, comments, annotations, improved version; Speaking:
  transcription + criteria) and register it in `AppServiceProvider`.
- **Google OAuth keys** (`GOOGLE_CLIENT_ID/SECRET`), callback `{APP_URL}/api/v1/auth/google/callback`.
- **Mail** (`MAIL_MAILER`, sender address) for verification and reset emails.
- **Production env:** MySQL, `APP_URL=https://api.<domain>`, `FRONTEND_URL`, `SESSION_DOMAIN=.<domain>`,
  `SANCTUM_STATEFUL_DOMAINS`, `CORS_ALLOWED_ORIGINS`, `TEACHER_WHATSAPP`, a queue worker under a process manager.
- Remove or replace the seeded sample tests; their audio paths point at the frontend's placeholder files.
