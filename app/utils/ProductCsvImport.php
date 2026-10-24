<?php

namespace App\utils;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class ProductCsvImport
{
    private const REQUIRED = ['name', 'code', 'category', 'cost', 'price', 'unit'];
    private const OPTIONAL = ['brand', 'stock_alert', 'note'];
    private const MAX_ROWS = 2000;

    public static function inspect(UploadedFile $file): array
    {
        $result = [
            'rows' => [], 'preview_rows' => [], 'errors' => [], 'error_count' => 0,
            'total_rows' => 0, 'valid_rows' => 0,
        ];
        if (strtolower($file->getClientOriginalExtension()) !== 'csv' || $file->getSize() > 1048576) {
            self::error($result, 0, 'Choose a CSV file smaller than 1 MB.');
            return $result;
        }

        $handle = fopen($file->getRealPath(), 'r');
        if ($handle === false) {
            self::error($result, 0, 'The CSV file could not be opened.');
            return $result;
        }
        try {
            $header = fgetcsv($handle);
            if ($header === false) {
                self::error($result, 0, 'The CSV file is empty.');
                return $result;
            }
            $header = array_map(function ($value) {
                return strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $value)));
            }, $header);
            $missing = array_diff(self::REQUIRED, $header);
            $unknown = array_diff($header, array_merge(self::REQUIRED, self::OPTIONAL));
            if ($missing || $unknown || count($header) !== count(array_unique($header))) {
                if ($missing) self::error($result, 1, 'Missing columns: ' . implode(', ', $missing) . '.');
                if ($unknown) self::error($result, 1, 'Unknown columns: ' . implode(', ', $unknown) . '.');
                if (count($header) !== count(array_unique($header))) self::error($result, 1, 'Column names must be unique.');
                return $result;
            }

            $raw = [];
            $line = 1;
            while (($values = fgetcsv($handle)) !== false) {
                $line++;
                if (count($values) === 1 && trim((string) $values[0]) === '') continue;
                $result['total_rows']++;
                if ($result['total_rows'] > self::MAX_ROWS) {
                    self::error($result, $line, 'This import is limited to 2,000 products. Split the file and try again.');
                    break;
                }
                if (count($values) !== count($header)) {
                    self::error($result, $line, 'The number of columns does not match the header.');
                    continue;
                }
                $raw[] = ['line' => $line, 'fields' => array_map('trim', array_combine($header, $values))];
            }
        } finally {
            fclose($handle);
        }
        if ($result['total_rows'] === 0) self::error($result, 0, 'Add at least one product below the header.');

        $categories = Category::whereNull('deleted_at')->get(['id', 'name'])->keyBy(fn ($item) => strtolower(trim($item->name)));
        $brands = Brand::whereNull('deleted_at')->get(['id', 'name'])->keyBy(fn ($item) => strtolower(trim($item->name)));
        $units = Unit::whereNull('deleted_at')->get(['id', 'name', 'ShortName']);
        $unitNames = [];
        foreach ($units as $unit) {
            $unitNames[strtolower(trim($unit->name))] = $unit;
            $unitNames[strtolower(trim($unit->ShortName))] = $unit;
        }
        $codes = array_values(array_unique(array_filter(
            array_map(fn ($row) => strtolower($row['fields']['code'] ?? ''), $raw),
            fn ($code) => $code !== ''
        )));
        $taken = Product::whereNull('deleted_at')->whereIn(DB::raw('LOWER(code)'), $codes)->pluck('code')
            ->map(fn ($code) => strtolower($code))->all();
        $seen = [];

        foreach ($raw as $row) {
            $fields = $row['fields'];
            $line = $row['line'];
            $issues = [];
            foreach (self::REQUIRED as $column) {
                if (($fields[$column] ?? '') === '') $issues[] = "{$column} is required.";
            }
            foreach (['name', 'code', 'category', 'brand', 'unit'] as $column) {
                if (strlen($fields[$column] ?? '') > 192) $issues[] = "{$column} is too long (maximum 192 characters).";
            }
            $codeKey = strtolower($fields['code'] ?? '');
            if ($codeKey !== '') {
                if (isset($seen[$codeKey])) $issues[] = "Product code {$fields['code']} is repeated in this file.";
                if (in_array($codeKey, $taken, true)) $issues[] = "Product code {$fields['code']} already exists.";
                $seen[$codeKey] = true;
            }
            $category = $categories[strtolower($fields['category'] ?? '')] ?? null;
            $brandName = $fields['brand'] ?? '';
            $brand = $brandName === '' || strtoupper($brandName) === 'N/A' ? null : ($brands[strtolower($brandName)] ?? null);
            $unit = $unitNames[strtolower($fields['unit'] ?? '')] ?? null;
            if (($fields['category'] ?? '') !== '' && ! $category) $issues[] = "Category {$fields['category']} does not exist.";
            if ($brandName !== '' && strtoupper($brandName) !== 'N/A' && ! $brand) $issues[] = "Brand {$brandName} does not exist.";
            if (($fields['unit'] ?? '') !== '' && ! $unit) $issues[] = "Unit {$fields['unit']} does not exist. Use its short name.";
            foreach (['cost', 'price', 'stock_alert'] as $column) {
                $value = $fields[$column] ?? '';
                if ($column === 'stock_alert' && $value === '') continue;
                if ($value !== '' && (! is_numeric($value) || (float) $value < 0 || (float) $value > 99999999)) {
                    $issues[] = "{$column} must be a nonnegative number below 100 million.";
                }
            }
            foreach ($issues as $issue) self::error($result, $line, $issue);
            $preview = [
                'line' => $line, 'name' => $fields['name'] ?? '', 'code' => $fields['code'] ?? '',
                'category' => $fields['category'] ?? '', 'unit' => $fields['unit'] ?? '',
                'cost' => $fields['cost'] ?? '', 'price' => $fields['price'] ?? '',
                'errors' => $issues,
            ];
            if (count($result['preview_rows']) < 20) $result['preview_rows'][] = $preview;
            if (! $issues) {
                $result['valid_rows']++;
                $result['rows'][] = [
                    'fields' => $fields, 'category_id' => $category->id,
                    'brand_id' => $brand ? $brand->id : null, 'unit_id' => $unit->id,
                ];
            }
        }
        return $result;
    }

    private static function error(array &$result, int $line, string $message): void
    {
        $result['error_count']++;
        if (count($result['errors']) < 50) $result['errors'][] = ['line' => $line, 'message' => $message];
    }
}
