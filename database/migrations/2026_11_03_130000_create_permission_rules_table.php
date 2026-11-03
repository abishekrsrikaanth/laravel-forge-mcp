<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permission_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('personal_access_token_id')->constrained()->cascadeOnDelete();
            $table->string('capability', 64);
            $table->string('scope_type', 16);
            $table->string('organization')->nullable();
            $table->unsignedBigInteger('server_id')->nullable();
            $table->unsignedBigInteger('site_id')->nullable();
            $table->boolean('allowed');
            $table->timestamps();

            $table->unique(
                ['personal_access_token_id', 'capability', 'scope_type', 'organization', 'server_id', 'site_id'],
                'permission_rules_unique_rule',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_rules');
    }
};
