<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class NormalizeDecimalInputMiddleware
{
    private const DECIMAL_KEYS = [
        'BudgetAmount',
        'TargetValue',
        'ActualValue',
        'ProgressBar',
        'ExpenseAmount',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $data = $request->all();
        $normalized = $this->normalizePayload($data);
        $request->merge($normalized);

        return $next($request);
    }

    private function normalizePayload(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->normalizePayload($item);
                continue;
            }

            if (in_array((string) $key, self::DECIMAL_KEYS, true)) {
                $value[$key] = $this->normalizeDecimalValue($item);
            }
        }

        return $value;
    }

    private function normalizeDecimalValue(mixed $rawValue): mixed
    {
        if ($rawValue === null || $rawValue === '') {
            return $rawValue;
        }

        if (is_int($rawValue) || is_float($rawValue)) {
            return round((float) $rawValue, 2);
        }

        if (! is_string($rawValue)) {
            return $rawValue;
        }

        $value = trim($rawValue);
        if ($value === '') {
            return $rawValue;
        }

        $lastDot = strrpos($value, '.');
        $lastComma = strrpos($value, ',');

        if ($lastDot !== false && $lastComma !== false) {
            $decimalSeparator = $lastDot > $lastComma ? '.' : ',';
            $thousandSeparator = $decimalSeparator === '.' ? ',' : '.';
            $value = str_replace($thousandSeparator, '', $value);
            if ($decimalSeparator === ',') {
                $value = str_replace(',', '.', $value);
            }
        } elseif ($lastComma !== false) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } elseif ($lastDot !== false && substr_count($value, '.') > 1) {
            $parts = explode('.', $value);
            $decimal = array_pop($parts);
            $value = implode('', $parts) . '.' . $decimal;
        }

        if (! is_numeric($value)) {
            return $rawValue;
        }

        return round((float) $value, 2);
    }
}
