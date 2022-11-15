<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::controller(App\Http\Controllers\SampleController::class)->group(function(){
    Route::get('registration', 'registration')->name('registration');
    Route::get('login', 'index')->name('login');
    Route::get('logout', 'logout')->name('logout');

    Route::post('validate_registration', 'validate_registration')->name('sample.validate_registration');
    Route::post('validate_login', 'validate_login')->name('sample.validate_login');

    Route::get('dashboard', 'dashboard')->name('dashboard');

    Route::get('profile', 'profile')->name('profile');
    Route::post('profile_validation', 'profile_validation')->name('sample.profile_validation');
});