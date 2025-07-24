<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/messaging', function () {
    return view('messaging');
})->name('messaging');
