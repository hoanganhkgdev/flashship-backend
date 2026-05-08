<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn() => view('welcome'));

Route::get('/privacy', function () {
    return response(view('legal.privacy'))->header('Content-Type', 'text/html');
});

Route::get('/terms', function () {
    return response(view('legal.terms'))->header('Content-Type', 'text/html');
});
