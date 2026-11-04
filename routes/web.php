<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['service' => 'billing-service', 'docs' => '/openapi.yaml']));
