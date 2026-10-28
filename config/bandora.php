<?php

return [
    /** Where the Next.js app lives; used for links in emails and OAuth redirects. */
    'frontend_url' => rtrim(env('FRONTEND_URL', 'http://localhost:3000'), '/'),

    /** The teacher's WhatsApp number in international format without "+", e.g. 213550000000. */
    'teacher_whatsapp' => env('TEACHER_WHATSAPP', '213550000000'),

    'marking' => [
        /** "fake" produces rule-based feedback for development. Add a real AI driver in app/Services/Marking. */
        'driver' => env('MARKING_DRIVER', 'fake'),
    ],
];
