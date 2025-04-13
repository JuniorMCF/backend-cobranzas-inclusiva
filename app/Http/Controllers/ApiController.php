<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApiController extends Controller
{

        /**
     * Send a success response.
     *
     * @param  string|array  $data
     * @param  int           $code
     * @return \Illuminate\Http\JsonResponse
     */
    public function successResponse($data, $code = 200): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'status' => true
        ], $code);
    }

    /**
     * Send an error response.
     *
     * @param  string|array  $message
     * @param  int           $code
     * @return \Illuminate\Http\JsonResponse
     */
    public function errorResponse($message, $code): JsonResponse
    {
        return response()->json([
            'data' => [],
            'status' => false,
            'error' => $message
        ], $code);
    }
}
