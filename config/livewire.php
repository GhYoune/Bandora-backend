<?php

/*
 * Only what Bandora changes; everything else comes from Livewire's own config.
 * Livewire merges this file one level deep, so the whole temporary_file_upload block is repeated.
 */
return [
    'temporary_file_upload' => [
        'disk' => env('LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK'),
        // Full Listening recordings are often 60–100 MB (see TestForm::AUDIO_MAX_KB).
        'rules' => ['required', 'file', 'max:'.(200 * 1024)],
        'directory' => null,
        'middleware' => null,
        'preview_mimes' => [
            'png', 'gif', 'bmp', 'svg', 'wav', 'mp4',
            'mov', 'avi', 'wmv', 'mp3', 'm4a',
            'jpg', 'jpeg', 'mpga', 'webp', 'wma',
        ],
        // A 100 MB file on a slow connection takes a while.
        'max_upload_time' => 30,
        'cleanup' => true,
    ],
];
