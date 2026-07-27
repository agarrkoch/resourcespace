<?php

/**
 * Shared debug logger for the faces plugin pages.
 *
 * Pulled out of unnamed_faces.php / collection_faces.php, where it was
 * defined identically in both. function_exists() guard means it's safe
 * for any page to include this file even if something else in the
 * request already defined it.
 */
if (!function_exists('debug_log'))
    {
    function debug_log($message)
        {
        $log_file = "/Users/libraryad/Desktop/debuglog2.txt";

        try
            {
            $timestamp = date("Y-m-d\TH:i:s.u");

            // Convert arrays/objects safely
            if (is_array($message) || is_object($message))
                {
                $message = print_r($message, true);
                }
            elseif ($message instanceof Exception)
                {
                $message = $message->getMessage() . "\n" . $message->getTraceAsString();
                }

            file_put_contents(
                $log_file,
                "[$timestamp] " . $message . PHP_EOL,
                FILE_APPEND
            );
            }
        catch (Exception $e)
            {
            // never break execution if logging fails
            }
        }
    }
