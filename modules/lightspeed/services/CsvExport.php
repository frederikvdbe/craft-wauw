<?php

namespace modules\lightspeed\services;

use yii\base\Exception;

/**
 * Reads a Lightspeed CSV export (`;` separated, UTF-8 with BOM).
 */
class CsvExport
{
    /**
     * @return array<int, array<string, string>> rows keyed by column name
     */
    public static function read(string $path): array
    {
        $file = @fopen($path, 'r') ?: throw new Exception("Cannot open $path");
        $header = fgetcsv($file, null, ';', '"', '');
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);

        $rows = [];
        while (($row = fgetcsv($file, null, ';', '"', '')) !== false) {
            $rows[] = array_combine($header, array_map('trim', $row));
        }
        fclose($file);

        return $rows;
    }

    /**
     * The product export has 1 row per variant.
     *
     * @return array<string, array<int, array<string, string>>> rows grouped by product ID
     */
    public static function readProducts(string $path): array
    {
        $products = [];
        foreach (self::read($path) as $row) {
            $products[$row['Internal_ID']][] = $row;
        }

        return $products;
    }

    /**
     * The order export has 1 row per line item; order fields are only filled on the first row.
     *
     * @return array<string, array<int, array<string, string>>> rows grouped by order ID
     */
    public static function readOrders(string $path): array
    {
        $orders = [];
        foreach (self::read($path) as $row) {
            $orders[$row['Order_ID']][] = $row;
        }

        return $orders;
    }

    /**
     * The order export uses a decimal comma (`1.234,50`), except in some columns (`0.000`).
     */
    public static function number(string $value): float
    {
        return (float)(str_contains($value, ',') ? str_replace(['.', ','], ['', '.'], $value) : $value);
    }
}
