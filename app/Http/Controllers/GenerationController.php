<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class GenerationController extends Controller
{
    public function store(): JsonResponse
    {
        // Temporary response.
        // Actual generation creation will be implemented next.
        return response()->json([
            'message' => 'Generation endpoint is ready.',
        ], 202);
    }
}
