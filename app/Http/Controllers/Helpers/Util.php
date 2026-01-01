<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Helpers\ArrayHelper;
use DOMDocument;

class Util {
    /**
     * Checks if a server file exists, is not empty and has the specified extension
     * @param {string} $path - path to the file
     * @param {string} $ext - file extension 
     * @return bool - true if the file passes all checks, false otherwise
     */
    public static function FileOK(string $path, string $ext): bool {
        // Check if the file exists
        if (!file_exists($path)) {
            return false;
        }

        // Check if the file is not empty
        if (filesize($path) == 0) {
            return false;
        }

        // Check if the file has the specified extension
        $fileExt = pathinfo($path, PATHINFO_EXTENSION);
        if ($fileExt != $ext) {
            return false;
        }

        // All checks passed, return true
        return true;
    }

    /**
     * Checks if a line is complete
     * @param {string} $line - line to check
     * @return bool - true if the line is complete
     */
    public function isLineComplete(string $line): bool {
        $splitted = explode(',', $line);
        if (count($splitted) != 8) {
            return false;
        }
        if ($splitted[0] == 1) {
            if (!ArrayHelper::isNullOrUndefined($splitted, 1, 1)) {
                return false;
            }
            if (!ArrayHelper::isNullOrUndefined($splitted, 3, 7)) {
                return false;
            }
            return true;
        }
        return false;
    }

    /**
     * Converts an object to an SQL array
     * @param {object} $obj - object to convert
     * @return array - SQL array representation of the object
     */
    public function toSQLArray(Object $obj): array {
        $arr = [];
        $cnt = 0;
        foreach ($obj as $prop => $value) {
            if (!property_exists($obj, $prop)) {
                continue;
            }
            $arr[$cnt] = $prop;
            $cnt++;
        }
        $arr = ArrayHelper::insertStrIntoArr(", ", $arr);
        return $arr;
    }

    /**
     * Checks if a form is complete by verifying if all required fields are present.
     *
     * @param array $data The associative array representing the form data.
     * @return bool True if the form is complete, false otherwise.
     */
    public static function isFormComplete(array $data): bool {
        // Check if all required fields are present
        foreach ($data as $value) {
            if (empty($value)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Concatenates the members of an object into a string.
     *
     * @param object $object The object whose members to concatenate.
     * @return string The concatenated string representation of the object members.
     */
    public static function concatenateObjectMembers(Object $object) {
        $result = '';
        foreach ($object as $key => $value) {
            $result .= "$key: $value, ";
        }
        // Remove the trailing comma and space
        $result = rtrim($result, ', ');
        return $result;
    }

    function generateJSFromHTML($htmlString) {
        $dom = new DOMDocument();
        @$dom->loadHTML($htmlString);
        $jsCode = '';

        function traverse($node) {
            global $jsCode;
            if ($node->nodeType === XML_ELEMENT_NODE) {
                $tagName = strtolower($node->nodeName);
                $jsCode .= "const {$tagName}Element = document.createElement('{$tagName}');\n";

                // Set attributes
                foreach ($node->attributes as $attr) {
                    $jsCode .= " {$tagName}Element.setAttribute('{$attr->nodeName}', '{$attr->nodeValue}');\n";
                }

                // Append children
                foreach ($node->childNodes as $child) {
                    traverse($child);
                    if ($child->nodeType === XML_ELEMENT_NODE) {
                        $jsCode .= " {$tagName}Element.appendChild({$child->nodeName});\n";
                    } elseif ($child->nodeType === XML_TEXT_NODE) {
                        $jsCode .= " {$tagName}Element.appendChild(document.createTextNode('{$child->nodeValue}'));\n";
                    }
                }
            }
        }

        traverse($dom->documentElement);
        return $jsCode;
    }
}
