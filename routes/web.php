<?php

use App\Http\Controllers\WorkspaceInvitationAcceptController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('invitations/accept/{token}', WorkspaceInvitationAcceptController::class)
    ->name('invitations.accept');
