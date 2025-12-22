<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Arr;

class AppLogger {
    /**
     * @var bool
     */
    protected static $withStackTrace = false;

    /**
     * Set whether to include stack trace in logs.
     *
     * @param bool $withStackTrace
     */
    public static function setStackTrace($withStackTrace): void {
        self::$withStackTrace = (bool) $withStackTrace;
    }

    /**
     * Log an error message.
     */
    public static function error($message, array $context = []): void {
        self::log('error', $message, $context);
    }

    /**
     * Log a warning message.
     */
    public static function warning($message, array $context = []): void {
        self::log('warning', $message, $context);
    }

    /**
     * Log an info message.
     */
    public static function info($message, array $context = []): void {
        self::log('info', $message, $context);
    }

    /**
     * Log a debug message.
     */
    public static function debug($message, array $context = []): void {
        self::log('debug', $message, $context);
    }

    /**
     * Core logging method.
     */
    protected static function log(string $level, $message, array $context = []): void {
        // Convert messages that are array or object into string
        if (is_array($message) || is_object($message)) {
            $message = print_r($message, true);
        }

        // Extract caller info for precise reporting
        $debugBacktrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4);
        // Find the caller outside of the logger class
        $caller = self::findCaller($debugBacktrace);
        $context['file'] = $caller['file'] ?? null;
        $context['line'] = $caller['line'] ?? null;
        $context['method'] = $caller['function'] ?? null;

        // Include full stack trace if enabled
        if (self::$withStackTrace) {
            $fullTrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
            $traceStrings = array_map(function ($trace) {
                return isset($trace['file'], $trace['line'], $trace['function']) ?
                    "{$trace['file']}:{$trace['line']} in {$trace['function']}" :
                    json_encode($trace);
            }, $fullTrace);
            $context['full_stack_trace'] = implode("\n", $traceStrings);
        }

        Log::channel('stack')->log($level, $message, $context);
    }

    /**
     * Helper to find the actual caller outside the logger class.
     */
    protected static function findCaller(array $backtrace): array {
        foreach ($backtrace as $index => $trace) {
            if (!isset($trace['class']) || $trace['class'] !== self::class) {
                return $trace;
            }
        }
        // fallback if no external caller found
        return $backtrace[0] ?? [];
    }
}
