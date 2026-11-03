<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Entries outlive their token; token_name keeps them readable.
            $table->foreignId('personal_access_token_id')->nullable()->constrained()->nullOnDelete();
            $table->string('token_name');
            $table->string('tool', 64);
            $table->string('organization')->nullable();
            $table->unsignedBigInteger('server_id')->nullable();
            $table->unsignedBigInteger('site_id')->nullable();
            $table->string('outcome', 16);
            $table->text('summary');
            $table->json('arguments');
            $table->unsignedInteger('duration_ms');
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_entries');
    }
};
