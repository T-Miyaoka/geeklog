<?php
/**
 * Happa X-Post reply sync runner: retry helper shared by run-xpost-sync.php and its tests.
 * Compatible with PHP 7.4 and later.
 */

namespace Happa\XPostSync;

/**
 * True for failures that a later attempt can succeed on: connection-level cURL errors
 * (proxy/DNS resolution, connect, timeout, TLS handshake, empty reply, receive error)
 * and HTTP 429 / 5xx from X. Authentication, permission, budget and data errors are not retried.
 */
function isTransient($message)
{
    if (preg_match('/（cURL (5|6|7|28|35|52|56)）/u', $message)) {
        return true;
    }
    return (bool) preg_match('/（HTTP (429|5[0-9][0-9])、/u', $message);
}

/**
 * Runs $attempt until it returns, retrying transient failures after each delay in $delays.
 * $log receives one line per failed attempt. Returns array(result, attempts).
 */
function runWithRetry(callable $attempt, array $delays, callable $sleep, callable $log)
{
    for ($n = 1; ; $n++) {
        try {
            return array(call_user_func($attempt), $n);
        } catch (\Throwable $e) {
            $message = $e instanceof \RuntimeException ? $e->getMessage() : get_class($e);
            if (!isTransient($message) || $n > count($delays)) {
                throw $e;
            }
            call_user_func($log, 'RETRY: attempt ' . $n . ' failed (' . $message . '); retrying in ' . $delays[$n - 1] . 's.');
            call_user_func($sleep, $delays[$n - 1]);
        }
    }
}
