<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/live-test', function () {
    return view('live-test');
});

if (app()->environment('local')) {
    Route::get('/dev/api-tester', function () {
        return view('dev-tester');
    });

    Route::get('/dev/chat-tester', function () {
        return view('chat-tester');
    });
    
    Route::get('/admin/dashboard', function () {
        return view('admin-dashboard');
    });
}
