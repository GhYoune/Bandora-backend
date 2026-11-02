<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Custom practice: the student's own task is stored as a private, never-published test.
        Schema::table('tests', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        Schema::create('decks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('title');
            $table->string('description')->nullable();
            $table->string('topic')->nullable();
            $table->boolean('is_free')->default(false);
            $table->boolean('is_published')->default(false);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('cards', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('deck_id')->constrained()->cascadeOnDelete();
            $table->string('word');
            $table->text('definition');
            $table->text('example')->nullable();
            $table->string('translation_fr')->nullable();
            $table->string('translation_ar')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('card_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('card_id')->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->timestamps();
            $table->unique(['user_id', 'card_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_progress');
        Schema::dropIfExists('cards');
        Schema::dropIfExists('decks');
        Schema::table('tests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
