<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::livewire('setup', 'pages::setup')->name('setup');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
    Route::livewire('forge', 'pages::forge')->name('forge.connection');
});

require __DIR__.'/settings.php';
