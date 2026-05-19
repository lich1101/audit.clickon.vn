<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_prompt_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('step')->unique();
            $table->string('title');
            $table->longText('developer_prompt');
            $table->longText('user_prompt');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_prompt_templates');
    }
};
