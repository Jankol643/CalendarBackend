<?php

use Illuminate\Support\Facades\Log;

/**
 * Function to format and print a variable.
 *
 * This function uses print_r() to format the variable into a human-readable string.
 *
 * @param mixed $var The variable to be formatted.
 * @return string The formatted string representation of the variable.
 */
function format_var($var) {
    return print_r($var, true);
}

/**
 * Function to log debug messages.
 *
 * This function uses Laravel's Log facade to log debug messages.
 *
 * @param string $message The debug message to be logged.
 * @return void
 *
 * @throws \Exception If the Log facade is not available.
 */
function debug_log($message) {
    AppLogger::debug($message);
}

/**
 * Function to return a success JSON response.
 *
 * This function uses Laravel's response()->json() method to create a JSON response with a success status and the provided data.
 *
 * @param mixed $data The data to be included in the response.
 * @return \Illuminate\Http\JsonResponse The JSON response with a success status and the provided data.
 *
 * @throws \Exception If the response()->json() method is not available.
 */
function success_response($data) {
    return response()->json(['success' => true, 'data' => $data], 200);
}

/**
 * Function to return an error JSON response.
 *
 * This function uses Laravel's response()->json() method to create a JSON response with an error status, 
 * the provided error message, and an optional HTTP status code.
 *
 * @param string $message The error message to be included in the response.
 * @param int $code The optional HTTP status code for the response. Default is 400 (Bad Request).
 * @return \Illuminate\Http\JsonResponse The JSON response with an error status, the provided error message, 
 * and the optional HTTP status code.
 *
 * @throws \Exception If the response()->json() method is not available.
 */
function error_response($message, $code = 400) {
    return response()->json(['success' => false, 'error' => $message], $code);
}

// Include the bootstrap file in the composer autoload
require_once __DIR__ . '/bootstrap.php';
