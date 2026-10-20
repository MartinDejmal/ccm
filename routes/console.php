<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

Artisan::command('ccm:user {email} {--name=Správce}', function () {
    $email = $this->argument('email');
    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->error('Neplatný e-mail.');

        return 1;
    }if (User::where('email', $email)->exists()) {
        $this->error('Účet již existuje.');

        return 1;
    }$password = $this->secret('Heslo (minimálně 12 znaků)');
    if (strlen($password) < 12) {
        $this->error('Heslo je příliš krátké.');

        return 1;
    }User::create(['name' => $this->option('name'), 'email' => $email, 'password' => Hash::make($password)]);
    $this->info('Účet vytvořen.');
});

Artisan::command('ccm:check-db', function () {
    if (! config('app.key')) {
        $this->error('APP_KEY is required.');

        return 1;
    }DB::select('SELECT 1');
    $this->info('Database connection OK');
});
