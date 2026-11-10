<?php

namespace modules\lightspeed\services;

use Craft;
use craft\elements\Address;

/**
 * Turns the address fields of an API customer or order into Craft address values.
 * Both have a billing (`addressBilling…`) and a shipping (`addressShipping…`) address.
 */
class AddressMapper
{
    public const BILLING = 'addressBilling';
    public const SHIPPING = 'addressShipping';

    /**
     * @return array|null null when the address is empty; `countryCode` is null when the country is missing
     */
    public static function values(array $data, string $prefix): ?array
    {
        $field = fn(string $name) => trim((string)($data[$prefix . $name] ?? ''));

        if ($field('Street') === '' && $field('City') === '') {
            return null;
        }

        return [
            'fullName' => $field('Name') ?: self::fullName($data),
            'organization' => ($prefix === self::SHIPPING ? $field('Company') : trim((string)$data['companyName'])) ?: null,
            'organizationTaxId' => $data['companyVatNumber'] ?: null,
            'addressLine1' => trim($field('Street') . ' ' . self::houseNumber($field('Number'), $field('Extension'))),
            'addressLine2' => $field('Street2') ?: null,
            'postalCode' => $field('Zipcode') ?: null,
            'locality' => $field('City') ?: null,
            'administrativeArea' => $field('Region') ?: null,
            'countryCode' => ($data[$prefix . 'Country'] ?? false) ? strtoupper($data[$prefix . 'Country']['code']) : null,
            'phoneField' => self::phone($data['phone']),
            'mobileField' => self::phone($data['mobile']),
        ];
    }

    public static function fill(Address $address, array $values): void
    {
        $fields = ['phoneField', 'mobileField'];
        Craft::configure($address, array_diff_key($values, array_flip($fields)));
        $address->setFieldValues(array_intersect_key($values, array_flip($fields)));
    }

    /**
     * Dutch names can have a prefix (`middlename`): `van` + `Berge` → `van Berge`.
     */
    public static function lastName(array $data): string
    {
        return trim("{$data['middlename']} {$data['lastname']}");
    }

    public static function fullName(array $data): string
    {
        return trim("{$data['firstname']} " . self::lastName($data));
    }

    /**
     * Lightspeed fills empty phone numbers with `+`.
     */
    private static function phone(?string $value): ?string
    {
        $value = trim((string)$value);
        return $value === '' || $value === '+' ? null : $value;
    }

    /**
     * `29` + `b` → `29b`; `29` + `bus 3` → `29 bus 3`.
     */
    private static function houseNumber(string $number, string $extension): string
    {
        $separator = preg_match('/^[a-z]{1,2}$/i', $extension) ? '' : ' ';
        return trim($number . $separator . $extension);
    }
}
