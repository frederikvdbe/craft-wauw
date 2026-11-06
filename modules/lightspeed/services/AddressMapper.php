<?php

namespace modules\lightspeed\services;

use Craft;
use craft\elements\Address;

/**
 * Turns the address columns of the customer export into Craft address values.
 * Order rows are converted to this format first (see OrderImporter::customerRow()).
 */
class AddressMapper
{
    /**
     * Lightspeed country names that differ from Craft's list
     */
    private const COUNTRY_ALIASES = ['Turkey' => 'TR'];

    /**
     * @var array<string, array<string, string>> locale => lower-case country name => ISO code
     */
    private static array $countries = [];

    /**
     * @param string $prefix `''` for the billing address, `'Shipping '` for the shipping address
     * @return array|null null when the address is empty; `countryCode` is null when the country is unknown
     */
    public static function values(array $row, string $prefix): ?array
    {
        $column = fn(string $name) => $row[$prefix . $name];

        if ($column('Streetname') === '' && $column('City') === '') {
            return null;
        }

        return [
            'fullName' => $column('Attn') ?: trim("{$row['Firstname']} " . self::lastName($row)),
            'organization' => ($prefix ? $row['Shipping company'] : $row['Company']) ?: null,
            'organizationTaxId' => $row['Vat number'] ?: null,
            'addressLine1' => trim($column('Streetname') . ' ' . self::houseNumber($column('Number'), $column('Extension'))),
            'addressLine2' => $column('Address 2') ?: null,
            'postalCode' => $column('Zipcode') ?: null,
            'locality' => $column('City') ?: null,
            'administrativeArea' => $column('Region') ?: null,
            'countryCode' => self::countryCode($column('Country')),
            'phone' => $row['Phone'] ?: null,
            'mobile' => $row['Mobile'] ?: null,
        ];
    }

    public static function fill(Address $address, array $values): void
    {
        $fields = ['phone', 'mobile'];
        Craft::configure($address, array_diff_key($values, array_flip($fields)));
        $address->setFieldValues(array_intersect_key($values, array_flip($fields)));
    }

    /**
     * Dutch names can have a prefix: `van` + `Berge` → `van Berge`.
     */
    public static function lastName(array $row): string
    {
        return trim("{$row['LastnamePreposition']} {$row['Lastname']}");
    }

    /**
     * `29` + `b` → `29b`; `29` + `bus 3` → `29 bus 3`.
     */
    private static function houseNumber(string $number, string $extension): string
    {
        $separator = preg_match('/^[a-z]{1,2}$/i', $extension) ? '' : ' ';
        return trim($number . $separator . $extension);
    }

    /**
     * The customer export has the country name (`Netherlands, The`), the order export the ISO code.
     */
    private static function countryCode(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        $countries = self::countries();
        if (in_array($value, $countries['en'], true)) {
            return $value;
        }

        $name = mb_strtolower(preg_replace('/, The$/i', '', $value));
        foreach ($countries as $list) {
            if (isset($list[$name])) {
                return $list[$name];
            }
        }

        return self::COUNTRY_ALIASES[$value] ?? null;
    }

    private static function countries(): array
    {
        if (!self::$countries) {
            $repository = Craft::$app->getAddresses()->getCountryRepository();
            foreach (['en', 'nl', 'fr'] as $locale) {
                self::$countries[$locale] = array_flip(array_map('mb_strtolower', $repository->getList($locale)));
            }
        }

        return self::$countries;
    }
}
