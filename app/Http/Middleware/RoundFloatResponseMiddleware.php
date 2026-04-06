<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoundFloatResponseMiddleware
{
    private const CEIL_KEYS = [
        'BudgetAmount',
        'TargetValue',
        'ActualValue',
        'ProgressBar',
        'ExpenseAmount',
    ];

    /**
     * Round up all float values in JSON response payloads.
     * DB values are unchanged because this runs only at response layer.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response instanceof JsonResponse) {
            return $response;
        }

        $payload = $response->getData(true);
        $response->setData($this->roundValuesForFrontend($payload));

        return $response;
    }

    private function roundValuesForFrontend(mixed $value, ?string $parentKey = null): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->roundValuesForFrontend($item, is_string($key) ? $key : null);
            }

            return $value;
        }

        if ($parentKey !== null && in_array($parentKey, self::CEIL_KEYS, true)) {
            if (is_int($value)) {
                return $value;
            }

            if (is_float($value) || is_numeric($value)) {
                return (int) ceil((float) $value);
            }
        }

        return $value;
    }
}
