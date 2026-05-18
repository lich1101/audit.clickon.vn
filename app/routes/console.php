<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('clickon:seed-admin {uid} {email}', function (\App\Services\FirestoreService $firestoreService, string $uid, string $email) {
    $firestoreService->seedAdmin($uid, $email);

    $this->info("Admin user seeded for UID {$uid}.");
})->purpose('Seed the first admin profile into Firestore');
