<?php

use App\Http\Controllers\AdminReportController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\PixelforgeWebhookController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

Route::middleware(['auth'])->group(function () {
    Route::redirect('dashboard', '/generate')->name('dashboard');

    Route::get('generate', [GenerationController::class, 'create'])->name('generate');
    Route::post('generations', [GenerationController::class, 'store'])->middleware('throttle:20,1')->name('generations.store');
    Route::get('generations', [GenerationController::class, 'index'])->name('generations.index');

    Route::get('credits', [CreditController::class, 'index'])->name('credits');
    Route::post('credits', [CreditController::class, 'store'])->name('credits.store');

    Route::get('admin/report', AdminReportController::class)->name('admin.report');
});

// Pixelforge signs its webhooks; it has no CSRF token (excluded in bootstrap/app.php).
Route::post('webhooks/pixelforge', PixelforgeWebhookController::class)->name('webhooks.pixelforge');

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
