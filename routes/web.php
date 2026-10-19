<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    Route::livewire('inventario', 'pages::inventory')->name('inventory');
    Route::livewire('registrar-compra', 'pages::pos')->name('pos');
});

require __DIR__.'/settings.php';
