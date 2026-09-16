<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;

class HealthController extends Controller
{
    public function show()
    {
        return response()->json(['data' => [
            'app' => config('app.name'), 'php' => PHP_VERSION, 'laravel' => app()->version(),
        ]]);
    }
}
