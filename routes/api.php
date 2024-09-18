<?php

use App\Http\Controllers\Api\V1\App\AppController;
use App\Http\Controllers\Api\V1\Register\RegisterController;
use App\Http\Controllers\Api\V1\Login\LoginUserController;
use App\Http\Controllers\Api\V1\Password\RecoveryPasswordController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
//     return $request->user();
// });


Route::prefix("v1")->group(function () {
    //for associates
    Route::post('/login',[LoginUserController::class,'signIn']);
    Route::post('/register',[RegisterController::class,'register']);

    Route::post('/confirm/email',[RegisterController::class,'confirmEmail']);
    Route::post('/confirm/born',[RegisterController::class,'confirmBorn']);
    Route::post('/confirm/password',[RegisterController::class,'confirmPassword']);

    Route::post('/recovery-password/confirm-code',[RecoveryPasswordController::class,'confirmCode']);
    Route::post('/recovery-password/confirm-data',[RecoveryPasswordController::class,'confirmData']);
    Route::post('/recovery-password/change-password',[RecoveryPasswordController::class,'changePassword']);

    Route::prefix('app')->middleware(['auth.coop'])->group(function(){
        Route::post('search/socio',[AppController::class,'searchSocio']);
        Route::post('cobranza/register',[AppController::class,'registerCobranza']);
        Route::get('cobranza/history',[AppController::class,'history']);
        Route::post('search/cobranza',[AppController::class,'searchCobranza']);
    });

});
