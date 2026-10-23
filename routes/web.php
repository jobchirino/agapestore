<?php

use App\Http\Controllers\SaleProofController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');

    Route::livewire('inventario', 'pages::inventory')->name('inventory');
    Route::livewire('registrar-compra', 'pages::pos')->name('pos');
    Route::livewire('historial-ventas', 'pages::sales.history')->name('sales.history');
    Route::get('historial-ventas/{sale}/comprobante', [SaleProofController::class, 'sale'])->name('sales.proof');
    Route::get('historial-ventas/{sale}/comprobante/ver', [SaleProofController::class, 'showSale'])->name('sales.proof.show');
    Route::get('historial-ventas/{sale}/abonos/{payment}/comprobante', [SaleProofController::class, 'payment'])->name('sales.payments.proof');
    Route::get('historial-ventas/{sale}/abonos/{payment}/comprobante/ver', [SaleProofController::class, 'showPayment'])->name('sales.payments.proof.show');
    Route::livewire('clientes', 'pages::clients')->name('clients');
    Route::livewire('calculadora', 'pages::calculator')->name('calculator');
});

require __DIR__.'/settings.php';
