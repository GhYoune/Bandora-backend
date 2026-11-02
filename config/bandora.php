<?php

return [
    /** Where the Next.js app lives; used for links in emails and OAuth redirects. */
    'frontend_url' => rtrim(env('FRONTEND_URL', 'http://localhost:3000'), '/'),

    /** The teacher's WhatsApp number in international format without "+", e.g. 213550000000. */
    'teacher_whatsapp' => env('TEACHER_WHATSAPP', '213550000000'),

    /** The teacher's Telegram username without "@". Empty = the Telegram option is hidden. */
    'teacher_telegram' => env('TEACHER_TELEGRAM'),

    /** The teacher's number for calls and SMS, e.g. +213550000000. Empty = the call/SMS option is hidden. */
    'teacher_phone' => env('TEACHER_PHONE'),

    'marking' => [
        /** "fake" produces rule-based feedback for development. Add a real AI driver in app/Services/Marking. */
        'driver' => env('MARKING_DRIVER', 'fake'),
    ],
];
