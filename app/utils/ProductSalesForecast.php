<?php

namespace App\utils;

/** Simple least-squares forecasts over equally spaced, completed weeks. */
class ProductSalesForecast
{
    public static function fit(array $weekly, int $horizon): array
    {
        $values = array_map('floatval', array_values($weekly));
        $count = count($values);
        if ($count < 2) {
            return ['slope' => 0.0, 'r_squared' => null, 'forecast' => array_fill(0, $horizon, 0.0)];
        }

        $meanX = ($count - 1) / 2;
        $meanY = array_sum($values) / $count;
        $numerator = 0.0;
        $denominator = 0.0;
        $variation = 0.0;
        foreach ($values as $index => $value) {
            $numerator += ($index - $meanX) * ($value - $meanY);
            $denominator += ($index - $meanX) ** 2;
            $variation += ($value - $meanY) ** 2;
        }

        $slope = $denominator > 0 ? $numerator / $denominator : 0.0;
        $intercept = $meanY - $slope * $meanX;
        $error = 0.0;
        foreach ($values as $index => $value) {
            $error += ($value - ($intercept + $slope * $index)) ** 2;
        }

        $forecast = [];
        for ($index = $count; $index < $count + $horizon; $index++) {
            // A linear model can extrapolate below zero; sales cannot.
            $forecast[] = round(max(0, $intercept + $slope * $index), 2);
        }

        return [
            'slope' => round($slope, 4),
            'r_squared' => $variation > 0 ? round(max(0, min(1, 1 - $error / $variation)), 3) : 1.0,
            'forecast' => $forecast,
        ];
    }

    public static function analyze(array $weeklyUnits, array $weeklyRevenue, int $horizon): array
    {
        $units = self::fit($weeklyUnits, $horizon);
        $revenue = self::fit($weeklyRevenue, $horizon);
        $activeWeeks = count(array_filter($weeklyUnits, fn ($value) => $value > 0));
        $recent = array_slice($weeklyUnits, -min(4, count($weeklyUnits)));
        $recentAverage = count($recent) ? array_sum($recent) / count($recent) : 0.0;
        $nextAverage = $horizon ? array_sum($units['forecast']) / $horizon : 0.0;

        $trend = 'steady';
        if ($activeWeeks < 4) {
            $trend = 'limited';
        } elseif ($units['r_squared'] >= 0.25) {
            $threshold = max(0.1, $recentAverage * 0.05);
            if ($units['slope'] > $threshold && $nextAverage > $recentAverage * 1.05) {
                $trend = 'rising';
            } elseif ($units['slope'] < -$threshold && $nextAverage < $recentAverage * 0.95) {
                $trend = 'falling';
            }
        }

        return [
            'weekly_units' => array_map(fn ($value) => round((float) $value, 2), $weeklyUnits),
            'weekly_revenue' => array_map(fn ($value) => round((float) $value, 2), $weeklyRevenue),
            'forecast_units' => $units['forecast'],
            'forecast_revenue' => $revenue['forecast'],
            'observed_units' => round(array_sum($weeklyUnits), 2),
            'observed_revenue' => round(array_sum($weeklyRevenue), 2),
            'predicted_units' => round(array_sum($units['forecast']), 2),
            'predicted_revenue' => round(array_sum($revenue['forecast']), 2),
            'unit_slope_per_week' => $units['slope'],
            'unit_fit_r_squared' => $units['r_squared'],
            'active_weeks' => $activeWeeks,
            'trend' => $trend,
        ];
    }
}
