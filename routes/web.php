<?php

use App\Http\Controllers\AppController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\WorkController;
use App\Http\Middleware\SetContext;
use Illuminate\Support\Facades\Route;

Route::get('/login', [AuthController::class, 'form'])->name('login');
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout']);

Route::middleware(['auth', SetContext::class])->group(function () {
    Route::get('/', [AppController::class, 'shell']);
    Route::prefix('api')->group(function () {
        Route::get('boot', [AppController::class, 'boot']);
        Route::post('company', [AppController::class, 'company']);
        Route::get('form/{key}', [AppController::class, 'form']);
        Route::get('options/{key}/{field}', [AppController::class, 'options']);
        Route::get('suggest/{key}/{field}', [AppController::class, 'suggest']);
        Route::get('list/{key}', [AppController::class, 'list']);
        Route::get('record/{key}/{id}', [AppController::class, 'record'])->whereNumber('id');
        Route::post('record/{key}/{id?}', [AppController::class, 'save'])->whereNumber('id');
        Route::get('page/{key}/{id}', [WorkController::class, 'page'])->whereNumber('id');
        Route::get('search', [WorkController::class, 'search']);
        Route::get('export/{key}', [WorkController::class, 'export']);
        Route::get('tasks', [WorkController::class, 'tasks']);
        Route::post('tasks/{id}/status', [WorkController::class, 'taskStatus'])->whereNumber('id');
    });
});
