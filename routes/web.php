<?php

use App\Enums\Permission;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\AcceptInvitationController;
use App\Http\Controllers\Connections\GoogleOAuthController;
use App\Http\Controllers\Connections\MailConnectionController;
use App\Http\Controllers\Contacts\AudienceController;
use App\Http\Controllers\Contacts\ContactController;
use App\Http\Controllers\Contacts\MergeFieldController;
use App\Http\Controllers\Contacts\SuppressionController;
use App\Http\Controllers\Contacts\TagController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Imports\ExportController;
use App\Http\Controllers\Imports\ImportController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::get('invitation/{user}/{hash}', AcceptInvitationController::class)
    ->middleware(['signed', 'throttle:10,1'])
    ->whereNumber('user')
    ->name('invitation.accept');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    // Module 1: audiences, contacts, fields, tags, suppressions, imports and exports.
    Route::middleware('permission:'.Permission::ContactsManage->value)->group(function () {
        Route::get('audiences/create', [AudienceController::class, 'create'])->name('audiences.create');
        Route::post('audiences', [AudienceController::class, 'store'])->name('audiences.store');
        Route::get('audiences/{audience}/edit', [AudienceController::class, 'edit'])->name('audiences.edit');
        Route::put('audiences/{audience}', [AudienceController::class, 'update'])->name('audiences.update');
        Route::delete('audiences/{audience}', [AudienceController::class, 'destroy'])->name('audiences.destroy');
        Route::post('audiences/{audience}/bulk', [ContactController::class, 'bulk'])->name('audiences.bulk');
        Route::get('audiences/{audience}/export', [ExportController::class, 'audience'])
            ->middleware('throttle:10,1')
            ->name('audiences.export');

        Route::get('audiences/{audience}/contacts/create', [ContactController::class, 'create'])->name('contacts.create');
        Route::post('audiences/{audience}/contacts', [ContactController::class, 'store'])->name('contacts.store');
        Route::get('contacts/{contact}/edit', [ContactController::class, 'edit'])->name('contacts.edit');
        Route::put('contacts/{contact}', [ContactController::class, 'update'])->name('contacts.update');
        Route::delete('contacts/{contact}', [ContactController::class, 'destroy'])->name('contacts.destroy');
        Route::put('subscriptions/{subscription}/status', [ContactController::class, 'updateStatus'])->name('subscriptions.status');

        Route::get('fields', [MergeFieldController::class, 'index'])->name('fields.index');
        Route::post('fields', [MergeFieldController::class, 'store'])->name('fields.store');
        Route::put('fields/{field}', [MergeFieldController::class, 'update'])->name('fields.update');
        Route::delete('fields/{field}', [MergeFieldController::class, 'destroy'])->name('fields.destroy');

        Route::get('tags', [TagController::class, 'index'])->name('tags.index');
        Route::post('tags', [TagController::class, 'store'])->name('tags.store');
        Route::put('tags/{tag}', [TagController::class, 'update'])->name('tags.update');
        Route::delete('tags/{tag}', [TagController::class, 'destroy'])->name('tags.destroy');

        Route::get('suppressions', [SuppressionController::class, 'index'])->name('suppressions.index');
        Route::post('suppressions', [SuppressionController::class, 'store'])->name('suppressions.store');
        Route::delete('suppressions/{suppression}', [SuppressionController::class, 'destroy'])->name('suppressions.destroy');

        Route::get('imports', [ImportController::class, 'index'])->name('imports.index');
        Route::get('audiences/{audience}/imports/create', [ImportController::class, 'create'])->name('imports.create');
        Route::post('audiences/{audience}/imports', [ImportController::class, 'store'])
            ->middleware('throttle:20,1')
            ->name('imports.store');
        Route::get('imports/{import}', [ImportController::class, 'show'])->name('imports.show');
        Route::get('imports/{import}/map', [ImportController::class, 'map'])->name('imports.map');
        Route::post('imports/{import}/start', [ImportController::class, 'start'])->name('imports.start');
        Route::post('imports/{import}/cancel', [ImportController::class, 'cancel'])->name('imports.cancel');
        Route::get('imports/{import}/report', [ImportController::class, 'report'])->name('imports.report');
    });

    Route::middleware('permission:'.Permission::ContactsView->value)->group(function () {
        Route::get('audiences', [AudienceController::class, 'index'])->name('audiences.index');
        Route::get('audiences/{audience}', [AudienceController::class, 'show'])->name('audiences.show');
        Route::get('contacts/{contact}', [ContactController::class, 'show'])->name('contacts.show');
    });

    Route::middleware('permission:'.Permission::ConnectionsManage->value)->group(function () {
        Route::get('connections/google/callback', [GoogleOAuthController::class, 'callback'])->name('connections.google.callback');
        Route::get('connections/{connection}/google', [GoogleOAuthController::class, 'redirect'])->name('connections.google.redirect');
        Route::post('connections/{connection}/verify', [MailConnectionController::class, 'verify'])
            ->middleware('throttle:10,1')
            ->name('connections.verify');
        Route::post('connections/{connection}/test', [MailConnectionController::class, 'test'])
            ->middleware('throttle:10,1')
            ->name('connections.test');
        Route::resource('connections', MailConnectionController::class)->except('show');
    });

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
