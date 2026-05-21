<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('clickon:seed-admin {uid} {email} {--name=}', function (\App\Services\AdminAccountService $adminAccountService, string $uid, string $email) {
    $adminAccountService->seedExistingAdminProfile($uid, $email, $this->option('name'));

    $this->info("Admin user seeded for UID {$uid} in MySQL.");
})->purpose('Seed the first admin profile into MySQL');

Artisan::command('clickon:create-admin {email} {password} {--name=} {--uid=} {--unverified}', function (\App\Services\AdminAccountService $adminAccountService, string $email, string $password) {
    $result = $adminAccountService->createOrUpdateAdmin(
        email: $email,
        password: $password,
        displayName: $this->option('name'),
        uid: $this->option('uid'),
        emailVerified: ! (bool) $this->option('unverified'),
    );

    $status = $result['created'] ? 'created' : 'updated';

    $this->info("Admin account {$status}: {$result['email']} ({$result['uid']})");
})->purpose('Create or update a Firebase Authentication admin account and seed its MySQL admin profile');
