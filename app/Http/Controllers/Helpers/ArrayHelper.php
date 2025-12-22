<?php

namespace App\Http\Controllers\Helpers;

use Brick\Math\Exception\NegativeNumberException;
use Exception;
use Illuminate\Support\Facades\Log;
use OutOfBoundsException;

class ArrayHelper {

    /**
     * Checks if an array is null or any of its values are null or NaN
     * @param {*} $arr - array to check
     * @param {int} $from - lower limit to check - 0 if omitted
     * @param {int|null} $to - upper limit to check - length of array if omitted
     * @return bool - true if the array is null or contains null or NaN values
     */
    public static function isNullOrUndefined(array $arr, int $from = 0, int $to = null) {
        if (!$arr) {
            return true;
        }
        if ($to === null) {
            $to = count($arr);
        }
        for ($i = $from; $i < $to; $i++) {
            if ($arr[$i] === null || is_nan($arr[$i])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Inserts a string into an array at every position
     * @param {string} $str - string to insert
     * @param {array} $arr - array to insert into
     * @return array - array with the string inserted at every position
     */
    public static function insertStrIntoArr(string $str, array $arr): array {
        $cnt = 0;
        foreach ($arr as $item) {
            array_splice($arr, $cnt, 0, $str);
            $cnt++;
        }
        return $arr;
    }

    /**
     * Converts an associative array to a string representation.
     *
     * @param array $data The associative array to convert.
     * @return string The string representation of the array.
     */
    public static function arrayToString(array $data): string {
        $result = '';
        foreach ($data as $key => $value) {
            $result .= $key . '=' . $value . '&';
        }
        // Remove the last '&' character
        $result = rtrim($result, '&');
        return $result;
    }

    /**
     * Sets all the variables in the array null and returns them as single variables.
     *
     * @param array $arr The array containing the variables to be nullified.
     * @param string $set The value to set for each variable.
     *
     * @return int The number of variables successfully extracted from the array.
     *
     * @throws Exception If the extract() function fails to extract any variables.
     */
    function set_array_variables(array $arr, string $set) {
        foreach ($arr as &$var) {
            $var = $set;
        }
        $result = extract($arr);

        if ($result === 0) {
            throw new Exception('Failed to extract any variables from the array.');
        }
        return $result;
    }

    /**
     * Divides an array into smaller chunks of a specified size.
     *
     * @param array $data The array to be divided into chunks.
     * @param int $chunkSize The size of each chunk.
     *
     * @return void This function does not return a value, but it logs each chunk to the debug log.
     *
     * @throws NegativeNumberException If the chunk size is less than or equal to zero.
     */
    public static function logChunks(array $data, int $chunkSize, string $arrayName) {
        // Check if the chunk size is valid
        if ($chunkSize <= 0) {
            throw new NegativeNumberException('Chunk size must be greater than zero.');
        }

        // Divide the data array into chunks
        while (!empty($data)) {
            // Extract a chunk of the specified size from the data array
            $chunk = array_slice($data, 0, $chunkSize);

            // Log the chunk to the debug log
            AppLogger::debug($arrayName . " chunk: " . print_r($chunk, true));

            // Remove the chunk from the data array
            $data = array_slice($data, $chunkSize);
        }
    }

    /**
     * Removes a specified column from a multi-dimensional array.
     *
     * @param array $arr The multi-dimensional array from which to remove the column.
     * @param string $col The name of the column to remove.
     *
     * @return array The multi-dimensional array with the specified column removed.
     *
     * @throws Exception If the specified column name is not found in the array.
     */
    public static function removeArrayColumn(array $arr, $col) {
        // Check if the column name exists in the array
        if (is_int($col)) {
            $keys = array_keys($arr);
            if (!isset($keys[$col])) {
                throw new Exception('Column index not found in the array.');
            }
            $col = $keys[$col];
        } else {
            if (!array_key_exists($col, $arr[0])) {
                throw new Exception('Column name not found in the array.');
            }
        }

        foreach ($arr as &$row) {
            if (is_array($row)) {
                // Unset the specified column from the row
                unset($row[$col]);
            } else {
                // If the row is not an associative array, assume it's a numeric array
                // Unset the specified column index from the row
                unset($row[$col]);
            }
        }
        return $arr;
    }

    /**
     * Converts a multi-dimensional array to a single-dimensional array.
     *
     * @param array $arr The multi-dimensional array to convert.
     * @return array The single-dimensional array.
     */
    public static function flattenArray(array $arr): array {
        return array_reduce($arr, 'array_merge', []);
    }

    /**
     * Counts the number of elements in a multi-dimensional array.
     *
     * @param array $arr The multi-dimensional array.
     * @return int The total number of elements in the array.
     */
    public static function getArrayLevels(array $array): int {
        $maxDepth = 1;
        foreach ($array as $value) {
            if (is_array($value)) {
                $depth = self::getArrayLevels($value) + 1;
                $maxDepth = max($maxDepth, $depth);
            }
        }
        return $maxDepth;
    }

    /**
     * Checks if an array contains any false values.
     *
     * @param array $arr The array to check.
     * @return bool True if the array contains any false values, false otherwise.
     */
    public static function containsFalseValues(array $arr): bool {
        foreach ($arr as $value) {
            if ($value === false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Merges two arrays recursively. If a key exists in both arrays and the values are arrays,
     * the function will merge them recursively. If a key exists in both arrays but the values are not arrays,
     * the function will keep the value from the first array.
     *
     * @param array $data1 The first array to merge.
     * @param array $data2 The second array to merge.
     *
     * @return array The merged array.
     */
    public function mergeArrays(array $data1, array $data2): array {
        $merged = [];

        // Iterate over the first array
        foreach ($data1 as $key => $value) {
            // If the key exists in the second array
            if (array_key_exists($key, $data2)) {
                // If both values are arrays, merge them recursively
                if (is_array($value) && is_array($data2[$key])) {
                    $merged[$key] = $this->mergeArrays($value, $data2[$key]);
                } else {
                    // If not arrays, keep the value from the first array
                    $merged[$key] = $value;
                }
            } else {
                // If the key does not exist in the second array, keep the value from the first array
                $merged[$key] = $value;
            }
        }

        // Iterate over the second array
        foreach ($data2 as $key => $value) {
            // If the key does not exist in the merged array, add it from the second array
            if (!array_key_exists($key, $merged)) {
                $merged[$key] = $value;
            }
        }

        // Return the merged array
        return $merged;
    }
}
