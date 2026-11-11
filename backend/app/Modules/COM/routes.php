<?php

use App\Modules\COM\CommitteeController;
use App\Modules\COM\ElectionController;
use Illuminate\Support\Facades\Route;

// Comité SST (M-COM): elección electrónica, comité vigente y actas. Los registros (comités, integrantes, reuniones,
// acuerdos): /records/COM/...
Route::middleware(['auth:sanctum', 'company', 'module:COM'])->prefix('com')->group(function () {
    Route::get('committee', [CommitteeController::class, 'current'])->middleware('permission:com.committees.read');
    Route::post('meetings/{id}/notify', [CommitteeController::class, 'notify'])->whereUuid('id')->middleware('permission:com.committee_meetings.update');
    Route::get('meetings/{id}/minutes', [CommitteeController::class, 'minutes'])->whereUuid('id')->middleware('permission:com.committee_meetings.read');
    Route::post('meetings/{id}/attendance', [CommitteeController::class, 'attendance'])->whereUuid('id')->middleware('permission:com.committee_meetings.update');
    Route::post('meetings/{id}/request-signatures', [CommitteeController::class, 'requestSignatures'])->whereUuid('id')->middleware('permission:com.committee_meetings.update');

    Route::middleware('permission:com.elections.read')->group(function () {
        Route::get('elections', [ElectionController::class, 'index']);
        Route::get('elections/{id}', [ElectionController::class, 'show'])->whereUuid('id');
        Route::get('elections/{id}/roll', [ElectionController::class, 'roll'])->whereUuid('id');
        Route::get('elections/{id}/minutes', [ElectionController::class, 'minutes'])->whereUuid('id');
    });
    Route::post('elections', [ElectionController::class, 'store'])->middleware('permission:com.elections.create');
    Route::middleware('permission:com.elections.update')->group(function () {
        Route::post('elections/{id}/open-nominations', [ElectionController::class, 'openNominations'])->whereUuid('id');
        Route::post('elections/{id}/open-voting', [ElectionController::class, 'openVoting'])->whereUuid('id');
        Route::post('elections/{id}/publish', [ElectionController::class, 'publish'])->whereUuid('id');
        Route::post('elections/{id}/cancel', [ElectionController::class, 'cancel'])->whereUuid('id');
        Route::patch('elections/{id}/roll/{rollId}', [ElectionController::class, 'updateRoll'])->whereUuid('id');
        Route::post('elections/{id}/candidates', [ElectionController::class, 'addCandidate'])->whereUuid('id');
        Route::post('elections/{id}/candidates/{cid}/review', [ElectionController::class, 'review'])->whereUuid('id');
    });
    Route::post('elections/{id}/close', [ElectionController::class, 'close'])->whereUuid('id')->middleware('permission:com.elections.close');
    Route::post('elections/{id}/apply', [ElectionController::class, 'apply'])->whereUuid('id')->middleware('permission:com.election_candidates.create');
    Route::get('elections/{id}/ballot', [ElectionController::class, 'ballot'])->whereUuid('id')->middleware('permission:com.election_ballots.vote');
    Route::post('elections/{id}/vote', [ElectionController::class, 'vote'])->whereUuid('id')->middleware(['permission:com.election_ballots.vote', 'throttle:10,1']);
});
