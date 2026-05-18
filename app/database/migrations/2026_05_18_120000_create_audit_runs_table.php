<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id')->unique();
            $table->string('website_id')->index();
            $table->string('website_name')->nullable();
            $table->string('website_url')->nullable();
            $table->string('user_uid')->index();
            $table->string('user_email')->nullable();
            $table->enum('status', ['queued', 'processing', 'completed', 'partial', 'failed'])->default('queued')->index();
            $table->json('target_urls');
            $table->json('categories')->nullable();
            $table->longText('checklist_text')->nullable();
            $table->unsignedInteger('total_urls')->default(0);
            $table->unsignedInteger('processed_urls')->default(0);
            $table->unsignedInteger('completed_urls')->default(0);
            $table->unsignedInteger('failed_urls')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_runs');
    }
};
