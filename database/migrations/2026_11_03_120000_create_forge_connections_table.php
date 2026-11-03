<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forge_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('token');
            $table->string('token_last_four', 4);
            $table->string('forge_user_name');
            $table->string('forge_user_email')->nullable();
            $table->json('organizations');
            $table->json('exposed_organizations');
            $table->text('last_error')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forge_connections');
    }
};
