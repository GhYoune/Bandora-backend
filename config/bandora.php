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
        /** "gemini" marks with Google Gemini; "fake" produces rule-based feedback for development. */
        'driver' => env('MARKING_DRIVER', 'fake'),

        'gemini' => [
            /** From Google AI Studio (aistudio.google.com), created on the teacher's Google account. */
            'key' => env('GEMINI_API_KEY', ''),
            'model' => env('GEMINI_MODEL', 'gemini-3.8-flash'),
            'timeout' => (int) env('GEMINI_TIMEOUT', 120),
        ],
    ],
];
