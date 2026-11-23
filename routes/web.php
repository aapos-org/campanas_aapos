<?php

use App\Enums\Permission;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\WebhookCallController;
use App\Http\Controllers\Auth\AcceptInvitationController;
use App\Http\Controllers\Campaigns\CampaignController;
use App\Http\Controllers\Campaigns\TemplateController;
use App\Http\Controllers\Connections\GoogleOAuthController;
use App\Http\Controllers\Connections\MailConnectionController;
use App\Http\Controllers\Contacts\AudienceController;
use App\Http\Controllers\Contacts\ContactController;
use App\Http\Controllers\Contacts\FormController;
use App\Http\Controllers\Contacts\MergeFieldController;
use App\Http\Controllers\Contacts\SuppressionController;
use App\Http\Controllers\Contacts\TagController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Imports\ExportController;
use App\Http\Controllers\Imports\ImportController;
use App\Http\Controllers\PublicSite\PreferencesController;
use App\Http\Controllers\PublicSite\PublicFormController;
use App\Http\Controllers\PublicSite\TrackingController;
use App\Http\Controllers\PublicSite\WebhookController;
use App\Http\Controllers\Reports\ReportController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::get('invitation/{user}/{hash}', AcceptInvitationController::class)
    ->middleware(['signed', 'throttle:10,1'])
    ->whereNumber('user')
    ->name('invitation.accept');

// ---------------------------------------------------------------- public (no login)
// Signup forms (hosted page, iframe and JS embeds). POST is CSRF-exempt so plain HTML
// embeds on other sites work; protected by honeypot, time trap, rate limit and Turnstile.
Route::prefix('f/{slug}')->where(['slug' => '[a-z0-9]{8,32}'])->name('forms.public.')->group(function () {
    Route::get('/', [PublicFormController::class, 'show'])->name('show');
    Route::post('/', [PublicFormController::class, 'submit'])->middleware('throttle:public-forms')->name('submit');
    Route::get('gracias', [PublicFormController::class, 'result'])->name('result');
    Route::get('embed.js', [PublicFormController::class, 'script'])->name('script');
});

Route::get('confirmar/{subscription}/{form}/{hash}', [PublicFormController::class, 'confirm'])
    ->middleware(['signed', 'throttle:30,1'])
    ->whereNumber(['subscription', 'form'])
    ->name('optin.confirm');

Route::middleware(['signed', 'throttle:30,1'])->whereNumber(['contact', 'audience'])->group(function () {
    Route::get('preferencias/{contact}/{hash}', [PreferencesController::class, 'show'])->name('preferences.show');
    Route::post('preferencias/{contact}/{hash}', [PreferencesController::class, 'update'])->name('preferences.update');
    // RFC 8058 one-click: mail clients POST here without a CSRF token.
    Route::get('baja/{contact}/{audience}/{hash}', [PreferencesController::class, 'unsubscribeShow'])->name('unsubscribe.show');
    Route::post('baja/{contact}/{audience}/{hash}', [PreferencesController::class, 'unsubscribe'])->name('unsubscribe.store');
});

// Open pixel and click tracking (signed; target read from campaign_links).
Route::get('t/o/{token}/{signature}.gif', [TrackingController::class, 'open'])
    ->where(['token' => '[a-f0-9]{32}', 'signature' => '[a-f0-9]{24}'])
    ->name('track.open');
Route::get('t/c/{token}/{link}/{signature}', [TrackingController::class, 'click'])
    ->where(['token' => '[a-f0-9]{32}', 'link' => '[a-f0-9]{16}', 'signature' => '[a-f0-9]{24}'])
    ->name('track.click');

// ElasticEmail notifications (secret token in the URL; CSRF-exempt).
Route::match(['get', 'post'], 'webhooks/elasticemail/{token}', [WebhookController::class, 'elasticEmail'])
    ->middleware('throttle:600,1')
    ->name('webhooks.elasticemail');

// ---------------------------------------------------------------- panel
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

        Route::resource('forms', FormController::class)->except('show');

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

    // Module 3: templates and campaigns.
    Route::middleware('permission:'.Permission::CampaignsManage->value)->group(function () {
        Route::get('templates', [TemplateController::class, 'index'])->name('templates.index');
        Route::post('templates', [TemplateController::class, 'store'])->name('templates.store');
        Route::get('templates/{template}/edit', [TemplateController::class, 'edit'])->name('templates.edit');
        Route::put('templates/{template}', [TemplateController::class, 'update'])->name('templates.update');
        Route::post('templates/{template}/duplicate', [TemplateController::class, 'duplicate'])->name('templates.duplicate');
        Route::delete('templates/{template}', [TemplateController::class, 'destroy'])->name('templates.destroy');
        Route::get('templates/{template}/preview', [TemplateController::class, 'preview'])->name('templates.preview');

        Route::post('campaigns', [CampaignController::class, 'store'])->name('campaigns.store');
        Route::get('campaigns/{campaign}/edit', [CampaignController::class, 'edit'])->name('campaigns.edit');
        Route::put('campaigns/{campaign}', [CampaignController::class, 'update'])->name('campaigns.update');
        Route::get('campaigns/{campaign}/content', [CampaignController::class, 'content'])->name('campaigns.content');
        Route::put('campaigns/{campaign}/content', [CampaignController::class, 'updateContent'])->name('campaigns.content.update');
        Route::post('campaigns/{campaign}/template', [CampaignController::class, 'applyTemplate'])->name('campaigns.template');
        Route::get('campaigns/{campaign}/review', [CampaignController::class, 'review'])->name('campaigns.review');
        Route::post('campaigns/{campaign}/test', [CampaignController::class, 'sendTest'])->middleware('throttle:10,1')->name('campaigns.test');
        Route::post('campaigns/{campaign}/duplicate', [CampaignController::class, 'duplicate'])->name('campaigns.duplicate');
        Route::delete('campaigns/{campaign}', [CampaignController::class, 'destroy'])->name('campaigns.destroy');
    });

    Route::middleware('permission:'.Permission::CampaignsSend->value)->group(function () {
        Route::post('campaigns/{campaign}/send', [CampaignController::class, 'send'])->name('campaigns.send');
        Route::post('campaigns/{campaign}/unschedule', [CampaignController::class, 'unschedule'])->name('campaigns.unschedule');
        Route::post('campaigns/{campaign}/pause', [CampaignController::class, 'pause'])->name('campaigns.pause');
        Route::post('campaigns/{campaign}/resume', [CampaignController::class, 'resume'])->name('campaigns.resume');
        Route::post('campaigns/{campaign}/cancel', [CampaignController::class, 'cancel'])->name('campaigns.cancel');
    });

    Route::middleware('permission:'.Permission::CampaignsView->value)->group(function () {
        Route::get('campaigns', [CampaignController::class, 'index'])->name('campaigns.index');
        Route::get('campaigns/{campaign}', [CampaignController::class, 'show'])->name('campaigns.show');
        Route::get('campaigns/{campaign}/preview', [CampaignController::class, 'preview'])->name('campaigns.preview');
    });

    // Module 4: reports.
    Route::middleware('permission:'.Permission::ReportsView->value)->group(function () {
        Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('campaigns/{campaign}/export', [CampaignController::class, 'export'])
            ->middleware(['permission:'.Permission::ContactsView->value, 'throttle:10,1'])->name('campaigns.export');
    });

    Route::middleware('permission:'.Permission::ContactsView->value)->group(function () {
        Route::get('audiences', [AudienceController::class, 'index'])->name('audiences.index');
        Route::get('audiences/{audience}', [AudienceController::class, 'show'])->name('audiences.show');
        Route::get('contacts/{contact}', [ContactController::class, 'show'])->name('contacts.show');
    });

    Route::middleware('permission:'.Permission::ConnectionsManage->value)->group(function () {
        Route::get('webhooks', [WebhookCallController::class, 'index'])->name('webhooks.index');
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
