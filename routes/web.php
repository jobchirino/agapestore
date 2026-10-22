<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');

    Route::livewire('inventario', 'pages::inventory')->name('inventory');
    Route::livewire('registrar-compra', 'pages::pos')->name('pos');
    Route::livewire('historial-ventas', 'pages::sales.history')->name('sales.history');
    Route::livewire('clientes', 'pages::clients')->name('clients');
});

require __DIR__.'/settings.php';
