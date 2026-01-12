<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use DateTimeInterface;

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
            $message = self::convertToString($message);
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

    /**
     * Convert complex data types to string representation.
     * Handles arrays, objects, DateTime objects, and arrays of objects.
     */
    protected static function convertToString($data): string {
        if (is_array($data)) {
            return self::convertArrayToString($data);
        }

        if (is_object($data)) {
            return self::convertObjectToString($data);
        }

        return (string) $data;
    }

    /**
     * Convert array to string representation.
     * Recursively handles arrays of objects.
     */
    protected static function convertArrayToString(array $array): string {
        $result = [];

        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $result[$key] = self::convertArrayToString($value);
            } elseif (is_object($value)) {
                $result[$key] = self::convertObjectToString($value);
            } else {
                $result[$key] = $value;
            }
        }

        return print_r($result, true);
    }

    /**
     * Convert object to string representation.
     * Special handling for DateTime objects and regular objects.
     */
    protected static function convertObjectToString(object $object): string {
        // Handle DateTime objects
        if ($object instanceof DateTimeInterface) {
            return $object->format('Y-m-d H:i:s');
        }

        // For other objects, only log public properties
        $reflection = new \ReflectionClass($object);
        $properties = $reflection->getProperties(\ReflectionProperty::IS_PUBLIC);

        $result = [
            'class' => get_class($object),
            'properties' => []
        ];

        foreach ($properties as $property) {
            $propertyName = $property->getName();
            if ($property->isPublic() && !$property->isStatic()) {
                $result['properties'][$propertyName] = $object->$propertyName ?? null;
            }
        }

        // Also include array-like accessible properties for objects that implement ArrayAccess
        if ($object instanceof \ArrayAccess) {
            $result['array_access'] = [];

            // Check if toArray method exists
            // Check if toArray method exists
            if (method_exists($object, 'toArray')) {
                try {
                    // Use reflection to invoke the method safely
                    $reflectionMethod = new \ReflectionMethod($object, 'toArray');
                    if ($reflectionMethod->isPublic()) {
                        $result['array_access'] = $reflectionMethod->invoke($object);
                    }
                } catch (\ReflectionException $e) {
                    $result['array_access'] = 'Error reflecting toArray(): ' . $e->getMessage();
                } catch (\Exception $e) {
                    $result['array_access'] = 'Error calling toArray(): ' . $e->getMessage();
                }
            } elseif ($object instanceof \Traversable || $object instanceof \stdClass) {
                $result['array_access'] = [];
                $count = 0;
                $limit = 10; // Limit to prevent huge outputs

                foreach ($object as $key => $value) {
                    if ($count >= $limit) {
                        $result['array_access']['_limit'] = "Stopped after {$limit} items";
                        break;
                    }

                    $result['array_access'][$key] = is_object($value) ?
                        self::convertObjectToString($value) : $value;
                    $count++;
                }
            }
        }

        return print_r($result, true);
    }
}
