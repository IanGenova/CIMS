<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
 */

//------------------------------------------------------------------\\

Route::post('/login', [
    'uses' => 'Auth\LoginController@login',
    'middleware' => 'Is_Active',
]);

// Completes a sign-in that was held back for a second factor. The session
// carries the pending user id; no session exists until the code checks out.
Route::post('/two-factor-challenge', 'Auth\LoginController@twoFactorChallenge');

Route::get('password/find/{token}', 'PasswordResetController@find');

//------------------------------------------------------------------\\

$installed = Storage::disk('public')->exists('installed');

if ($installed === false) {
    Route::get('/setup', [
        'uses' => 'SetupController@viewCheck',
    ])->name('setup');

    Route::get('/setup/step-1', [
        'uses' => 'SetupController@viewStep1',
    ]);

    Route::post('/setup/step-2', [
        'as' => 'setupStep1', 'uses' => 'SetupController@setupStep1',
    ]);

    Route::post('/setup/testDB', [
        'as' => 'testDB', 'uses' => 'TestDbController@testDB',
    ]);

    Route::get('/setup/step-2', [
        'uses' => 'SetupController@viewStep2',
    ]);

    Route::get('/setup/step-3', [
        'uses' => 'SetupController@viewStep3',
    ]);

    Route::get('/setup/finish', function () {

        return view('setup.finishedSetup');
    });

    Route::get('/setup/getNewAppKey', [
        'as' => 'getNewAppKey', 'uses' => 'SetupController@getNewAppKey',
    ]);

    Route::post('/setup/step-3', [
        'as' => 'setupStep2', 'uses' => 'SetupController@setupStep2',
    ]);

    Route::post('/setup/lastStep', [
        'as' => 'lastStep', 'uses' => 'SetupController@lastStep',
    ]);

    Route::get('setup/lastStep', function () {
        return redirect('/setup', 301);
    });

} else {
    Route::any('/setup/{vue}', function () {
        abort(403);
    });
}

//------------------------------------------------------------------\\

Route::group(['middleware' => ['auth', 'Is_Active']], function () {

    // Shopee or Lazada sending the seller back after they approve the app.
    // Inside the signed-in group, and before the catch-all below.
    Route::get('/marketplace/callback/{channel}', 'MarketplaceController@callback')
        ->where('channel', 'shopee|lazada');

    Route::get('/{vue?}',
        function () {
            $installed = Storage::disk('public')->exists('installed');
            if ($installed === false) {
                return redirect('/setup');
            } else {
                return view('layouts.master');
            }
        })->where('vue', '^(?!setup|update|password|login|logout).*$');


    });
    
    Auth::routes([
        'register' => false,
    ]);


//------------------------------------------------------------------\\

// Signed-in administrators only (the controller checks System Settings): this
// runs migrations, and was open to anyone (UpdateControllerAccessTest).
Route::group(['middleware' => ['auth', 'Is_Active']], function () {
    Route::get('/update', 'UpdateController@viewStep1');

    Route::get('/update/finish', function () {

        return view('update.finishedUpdate');
    });

    Route::post('/update/lastStep', [
        'as' => 'update_lastStep', 'uses' => 'UpdateController@lastStep',
    ]);
});


