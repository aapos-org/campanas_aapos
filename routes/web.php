<?php

use App\Enums\Permission;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\AcceptInvitationController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::get('invitation/{user}/{hash}', AcceptInvitationController::class)
    ->middleware(['signed', 'throttle:10,1'])
    ->whereNumber('user')
    ->name('invitation.accept');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::middleware('permission:'.Permission::UsersManage->value)->group(function () {
            Route::get('users', [UserController::class, 'index'])->name('users.index');
            Route::get('users/create', [UserController::class, 'create'])->name('users.create');
            Route::post('users', [UserController::class, 'store'])->name('users.store');
            Route::get('users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
            Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
            Route::post('users/{user}/invitation', [UserController::class, 'resendInvitation'])
                ->middleware('throttle:6,1')
                ->name('users.invitation');
        });

        Route::get('audit', [AuditLogController::class, 'index'])
            ->middleware('permission:'.Permission::AuditView->value)
            ->name('audit.index');
    });
});

require __DIR__.'/settings.php';
