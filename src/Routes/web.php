<?php

use App\Http\Controllers\EntityController;
use Illuminate\Support\Facades\Route;

Route::group(['prefix' => 'cms', 'middleware' => 'web'], function () {


    Route::get('/login', function () {
        return view("CMSView::pages.login");
    })->name('cms-login');

    Route::get('/run-migrate', [twa\cmsv2\Http\Controllers\EntityController::class, 'migrate']);

    // Route::prefix('payment')->group(function () {
    //     Route::get('/initialize/{payment_attempt_id}', [App\Http\Controllers\API\PaymentController::class, 'initialize'])->name('payment.initialize');
    //     Route::get('/callback/{payment_attempt_id}', [App\Http\Controllers\API\PaymentController::class, 'callback'])->name('payment.callback');
    //     Route::get('/response', function(){  echo request()->input('type');  })->name('payment.response');

    // });

    Route::group(['middleware' => \twa\cmsv2\Http\Middleware\CmsAuthMiddleware::class], function () {

        Route::post('/logout', function () {
            session(['cms_user' => null]);
            return redirect('/cms');
        })->name('cms-logout');

        Route::get('/', function () {
            return view("CMSView::pages.dashboard");
        })->name('cms-dashboard');


        Route::get('/{slug}/sorting', [twa\cmsv2\Http\Controllers\EntitySortingController::class, 'render'])->name('cms-entity.sorting');
        Route::post('/{slug}/sorting', [twa\cmsv2\Http\Controllers\EntitySortingController::class, 'save'])->name('cms-entity.sorting.post');

        Route::get('reports', [twa\cmsv2\Http\Controllers\ReportsController::class, 'render'])->name('cms-list-reports');
        Route::get('reports/{slug}', [twa\cmsv2\Http\Controllers\ReportsController::class, 'show'])->name('cms-show-report');


        Route::get('role/{id}/set-permissions', [twa\cmsv2\Http\Controllers\RolePermissionsController::class, 'render'])->name('cms-set-permissions');
        Route::get('{id}/send-notification', [twa\cmsv2\Http\Controllers\NotificationController::class, 'sendNotification'])->name('cms-send-notification');

        // Route::get('/settings',function(){ return view("pages.settings"); })->name('settings');
        Route::get('/settings', [twa\cmsv2\Http\Controllers\SettingsController::class, 'render'])->name('settings');
        Route::get('/{slug}', [twa\cmsv2\Http\Controllers\EntityController::class, 'render'])->name('entity');
        Route::get('/{slug}/create', [twa\cmsv2\Http\Controllers\EntityController::class, 'create'])->name('entity.create');
        Route::get('/{slug}/update/{id}', [twa\cmsv2\Http\Controllers\EntityController::class, 'update'])->name('entity.update');


        Route::get('{slug}/import', [twa\cmsv2\Http\Controllers\EntityController::class, 'importForm'])->name('entity.import');
  });
});
