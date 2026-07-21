<?php

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

Route::view('/payment-alert', 'payment-alert-page')->name('payment-alert');

Route::match(['get', 'post'], '/payment-alert/webpay/return', [WebpayReturnController::class, 'handle'])
    ->name('webpay.return');
