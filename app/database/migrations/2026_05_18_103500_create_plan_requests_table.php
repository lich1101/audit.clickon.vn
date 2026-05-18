<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('firebase_uid')->index();
            $table->string('user_email')->nullable();
            $table->string('plan_id')->index();
            $table->string('plan_name');
            $table->unsignedBigInteger('price');
            $table->unsignedInteger('credits');
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending')->index();
            $table->text('note')->nullable();
            $table->string('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_requests');
    }
};
