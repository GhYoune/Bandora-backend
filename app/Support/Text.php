<?php

namespace App\Support;

final class Text
{
    /** Same rule as the frontend word counter: any run of non-space characters is a word. */
    public static function wordCount(string $text): int
    {
        return preg_match_all('/\S+/u', $text);
    }
}
