<?php

use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\WebpayReturnController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

// Staff page: the all-customers list plus pay/suspend/reactivate.
// Admins only (users.role = 'admin'); everyone else lands on /mi-cuenta.
Route::view('/payment-alert', 'payment-alert-page')
    ->middleware(['auth', 'admin'])
    ->name('payment-alert');

Route::match(['get', 'post'], '/payment-alert/webpay/return', [WebpayReturnController::class, 'handle'])
    ->name('webpay.return');

// Customer-facing portal: the logged-in user sees their own company's
// payment status and can pay it. Staff actions stay on /payment-alert.
Route::view('/login', 'auth-page')->middleware('guest')->name('login');
Route::post('/logout', LogoutController::class)->middleware('auth')->name('logout');
Route::view('/mi-cuenta', 'my-account-page')->middleware('auth')->name('mi-cuenta');
