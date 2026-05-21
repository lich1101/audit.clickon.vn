<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->json('value');
            $table->timestamps();
        });

        $now = now();

        DB::table('system_settings')->insert([
            [
                'key' => 'audit',
                'value' => json_encode([
                    'aiProvider' => env('AUDIT_DEFAULT_AI_PROVIDER', 'openai'),
                    'aiModel' => env('OPENAI_MODEL', ''),
                    'maxParallelItems' => 3,
                    'step2BatchSize' => 60,
                    'step3BatchSize' => 30,
                ], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};
