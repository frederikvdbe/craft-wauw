<?php

namespace modules\lightspeed\services;

use yii\base\Exception;

/**
 * Reads the Lightspeed product CSV export (`;` separated, 1 row per variant).
 */
class ProductExport
{
    /**
     * @return array<string, array<int, array<string, string>>> rows grouped by product ID
     */
    public static function read(string $path): array
    {
        $file = @fopen($path, 'r') ?: throw new Exception("Cannot open $path");
        $header = fgetcsv($file, null, ';', '"', '');
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);

        $products = [];
        while (($row = fgetcsv($file, null, ';', '"', '')) !== false) {
            $row = array_combine($header, array_map('trim', $row));
            $products[$row['Internal_ID']][] = $row;
        }
        fclose($file);

        return $products;
    }
}
