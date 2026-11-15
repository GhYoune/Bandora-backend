<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** The packs are now called Bronze / Silver / Gold (client request, Nov 2026). Only renames the untouched example names. */
return new class extends Migration
{
    private const NAMES = ['1 month' => 'Bronze', '3 months' => 'Silver', '6 months' => 'Gold'];

    public function up(): void
    {
        foreach (self::NAMES as $old => $new) {
            DB::table('packs')->where('name', $old)->update(['name' => $new]);
        }
    }

    public function down(): void
    {
        foreach (self::NAMES as $old => $new) {
            DB::table('packs')->where('name', $new)->update(['name' => $old]);
        }
    }
};
