<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->unsignedInteger('duration_days');
            $table->unsignedInteger('price_dzd');
            $table->unsignedInteger('credits');
            $table->boolean('highlighted')->default(false);
            $table->json('features');
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
            $table->string('google_id')->nullable()->unique()->after('email');
            $table->string('whatsapp', 20)->nullable()->after('google_id');
            $table->boolean('is_admin')->default(false);
            $table->unsignedInteger('credits')->default(0);
            // The current (or most recently expired) pack. History lives in payment_requests.
            $table->foreignUlid('pack_id')->nullable()->constrained()->nullOnDelete();
            $table->string('pack_name')->nullable();
            $table->timestamp('pack_started_at')->nullable();
            $table->timestamp('pack_expires_at')->nullable();
        });

        Schema::create('payment_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('pack_id')->constrained()->restrictOnDelete();
            $table->string('reference', 8)->unique();
            $table->string('pack_name');
            $table->unsignedInteger('amount_dzd');
            $table->string('whatsapp', 20);
            $table->string('status')->default('pending')->index();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('tests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('skill')->index();
            $table->string('title');
            $table->string('description')->nullable();
            $table->boolean('is_free')->default(false);
            $table->boolean('is_published')->default(false);
            $table->unsignedInteger('duration_seconds');
            $table->unsignedInteger('sort')->default(0);
            // What the teacher edits in Filament (answers written inline).
            $table->json('form');
            // Compiled from `form` on save: the public content (no answers) and the answer key.
            $table->json('content');
            $table->json('answer_key');
            $table->unsignedInteger('question_count')->default(0);
            $table->timestamps();
        });

        Schema::create('attempts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('test_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('in_progress');
            $table->unsignedTinyInteger('listening_plays')->default(1);
            $table->json('answers');
            $table->json('flagged');
            $table->unsignedInteger('remaining_seconds');
            $table->json('position');
            $table->unsignedSmallInteger('raw_score')->nullable();
            $table->decimal('band', 2, 1)->nullable();
            $table->unsignedSmallInteger('credits_charged')->default(0);
            // AI feedback for Writing / Speaking, in the contract's result shape.
            $table->json('result')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });

        Schema::create('recordings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('attempt_id')->constrained()->cascadeOnDelete();
            $table->string('question_id');
            $table->string('path');
            $table->decimal('duration_seconds', 6, 1);
            $table->timestamps();
            $table->unique(['attempt_id', 'question_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recordings');
        Schema::dropIfExists('attempts');
        Schema::dropIfExists('tests');
        Schema::dropIfExists('payment_requests');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pack_id');
            $table->dropColumn(['google_id', 'whatsapp', 'is_admin', 'credits', 'pack_name', 'pack_started_at', 'pack_expires_at']);
        });
        Schema::dropIfExists('packs');
    }
};
