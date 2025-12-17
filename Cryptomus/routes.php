<?php

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Gateways\Cryptomus\Cryptomus;

Route::post('/extensions/gateways/cryptomus/webhook', [Cryptomus::class, 'webhook'])
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->name('extensions.gateways.cryptomus.webhook');
