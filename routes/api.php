<?php

use App\Http\Controllers\COAChatController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CoaController;
use App\Http\Middleware\ValidateServiceAuth;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

//todo: to setup logic so that coa-children generations can use a different db setup different from the default.
Route::prefix('books')->middleware([ValidateServiceAuth::class])->group(function (){
    Route::get('/assistant/coa-hierarchy', [CoaController::class, 'getCoaHierarchy']);

    Route::post('/assistant/coa/suggestions', [CoaController::class, 'coaSuggestions']);
    Route::post('/assistant/coa/linked', [CoaController::class, 'coaLinked']);

    Route::post('/assistant/coa-children', [CoaController::class, 'coaChildrenPost']);
    Route::get('/assistant/coa-children/{organizationType}', [CoaController::class, 'coaChildrenGet']);
    Route::get('/assistant/coa-children/{organizationType}/detail/{detailId}', [CoaController::class, 'coaChildrenForDetail'])->whereNumber('detailId');
    Route::post('/assistant/coa-children/single/{detailId}', [CoaController::class, 'coaChildrenSinglePost'])->whereNumber('detailId');

    Route::post('/assistant/coa/chat', COAChatController::class);
    Route::post('/assistant/coa/chat/feedback', [CoaController::class, 'coaChatFeedback']);
});
