<?php

use App\Http\Controllers\Api\V1\AudienceController;
use App\Http\Controllers\Api\V1\CampaignController;
use App\Http\Controllers\Api\V1\ContactController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\TagController;
use App\Http\Middleware\SetCurrentOrganization;
use Illuminate\Support\Facades\Route;

/*
| Public API v1 (prefix /api/v1). Bearer tokens (Sanctum) created in Administración → API.
| Every route checks the token ability and the owner's role (api.ability).
*/
Route::middleware(['auth:sanctum', SetCurrentOrganization::class, 'throttle:api'])->name('api.')->group(function () {
    Route::middleware('api.ability:audiences:read')->group(function () {
        Route::get('audiences', [AudienceController::class, 'index'])->name('audiences.index');
        Route::get('audiences/{audience}', [AudienceController::class, 'show'])->name('audiences.show');
    });

    Route::middleware('api.ability:contacts:read')->group(function () {
        Route::get('contacts', [ContactController::class, 'index'])->name('contacts.index');
        Route::get('contacts/{contact}', [ContactController::class, 'show'])->name('contacts.show');
    });

    Route::middleware('api.ability:contacts:write')->group(function () {
        Route::post('contacts', [ContactController::class, 'store'])->name('contacts.store');
        Route::patch('contacts/{contact}', [ContactController::class, 'update'])->name('contacts.update');
        Route::post('contacts/{contact}/tags', [TagController::class, 'attach'])->name('contacts.tags.attach');
        Route::delete('contacts/{contact}/tags/{tag}', [TagController::class, 'detach'])->name('contacts.tags.detach');
    });

    Route::middleware('api.ability:subscriptions:write')->group(function () {
        Route::post('audiences/{audience}/subscribe', [SubscriptionController::class, 'subscribe'])->name('audiences.subscribe');
        Route::post('audiences/{audience}/unsubscribe', [SubscriptionController::class, 'unsubscribe'])->name('audiences.unsubscribe');
    });

    Route::get('tags', [TagController::class, 'index'])->middleware('api.ability:tags:read')->name('tags.index');

    Route::middleware('api.ability:campaigns:read')->group(function () {
        Route::get('campaigns', [CampaignController::class, 'index'])->name('campaigns.index');
        Route::get('campaigns/{campaign}', [CampaignController::class, 'show'])->name('campaigns.show');
    });
});
