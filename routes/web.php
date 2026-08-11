<?php

use Illuminate\Support\Facades\Route;

// This project is a JSON API with no frontend; the API routes live in api.php.
Route::get('/', fn () => response()->json(['status' => 'ok']));
