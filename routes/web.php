<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/messaging', function () {
    return view('messaging');
})->name('messaging');

Route::get('/messaging-enhanced', function () {
    return view('messaging-enhanced');
})->name('messaging.enhanced');
