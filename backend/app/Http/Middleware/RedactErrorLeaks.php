<?php

namespace App\Http\Middleware;

use App\Support\SystemHealth;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * One choke point for PB-043: no error body leaves the API carrying SQL, a database host, a stack-trace fragment or an
 * internal class name. ~220 controller `catch` blocks hand `$e->getMessage()` to the browser; most carry deliberate
 * domain messages the UI needs ("Rates are pending", "Insufficient stock") and must stay, so this does not touch them.
 * It only rewrites text that is recognisably framework/database output, logs the original under a reference id, and
 * shows the user that reference instead.
 */
class RedactErrorLeaks
{
    private const LEAK = '/SQLSTATE\[|\(Connection: |Stack trace|#\d+ \/|\/var\/www|vendor\/|\.php\b|\bApp\\\\(Models|Services|Http)\b|\bIlluminate\\\\|Call to (undefined|a member)|Undefined (variable|array key|index|property|offset)|Class "|Attempt to read property|Trying to access array offset|must be of type|Argument #\d|Integrity constraint violation|Column not found|Base table or view|Unknown column|Data too long|Out of range value|Incorrect (integer|decimal|string|datetime) value|Duplicate entry/i';

    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        // Validation messages are ours and may echo what the user typed; local development wants the real detail.
        if (!$response instanceof JsonResponse || $response->getStatusCode() < 400 || $response->getStatusCode() === 422 || config('app.debug')) {
            return $response;
        }
        $body = $response->getData(true);
        if (!is_array($body)) {
            return $response;
        }

        $message = (string) ($body['message'] ?? '');
        if ($response->getStatusCode() === 404 && str_contains($message, 'No query results for model')) {
            $body['message'] = 'That record was not found.';
            return $response->setData($body);
        }

        $ref = null;
        $original = [];
        $scrub = function (&$value) use (&$ref, &$original) {
            if (is_string($value) && preg_match(self::LEAK, $value)) {
                $ref ??= substr(md5(uniqid('', true)), 0, 8);
                $original[] = $value;
                $value = "Details are in the server log (reference {$ref}).";
            }
        };

        $messageLeaked = $message !== '' && preg_match(self::LEAK, $message);
        if (isset($body['errors']) && is_array($body['errors'])) {
            array_walk_recursive($body['errors'], $scrub);
        }
        if ($messageLeaked) {
            $ref ??= substr(md5(uniqid('', true)), 0, 8);
            $original[] = $message;
            $body['message'] = "Something went wrong on our end. Please retry, or contact support with reference {$ref}.";
            $body['error_code'] = 'SERVER_ERROR';
            $response->setStatusCode(500);
        }

        if ($ref === null) {
            return $response;
        }
        $body['reference'] = $ref;
        Log::error("[{$ref}] internal error text withheld from the response", ['original' => $original, 'url' => $request->path()]);
        SystemHealth::recordError();

        return $response->setData($body);
    }
}
