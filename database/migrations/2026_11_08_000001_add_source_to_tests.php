<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tests', function (Blueprint $table) {
            // Where the content comes from (book, test number). Admin only, never sent to students.
            $table->string('source')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('tests', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
