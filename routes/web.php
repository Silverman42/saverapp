<?php

use App\Support\RoleDestinationResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', function (Request $request) {
        return redirect()->route(RoleDestinationResolver::resolveRouteName($request->user()));
    })->name('dashboard');

    Route::get('customer/dashboard', fn () => Inertia::render('Dashboard'))
        ->middleware('role:customer')
        ->name('customer.dashboard');

    Route::get('agent/dashboard', fn () => Inertia::render('Dashboard'))
        ->middleware('role:agent')
        ->name('agent.dashboard');

    Route::get('admin/dashboard', fn () => Inertia::render('Dashboard'))
        ->middleware('role:admin')
        ->name('admin.dashboard');
});

require __DIR__.'/settings.php';
