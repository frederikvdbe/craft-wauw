<?php

namespace modules\lightspeed\services;

use Craft;
use craft\commerce\Plugin as Commerce;
use craft\elements\Address;
use craft\elements\User;
use craft\helpers\Db;
use craft\models\UserGroup;
use DateTime;
use modules\lightspeed\Module;
use yii\base\Component;
use yii\base\Exception;

/**
 * Imports the Lightspeed customer CSV export as Craft users with addresses. Safe to run again:
 * users are matched on `lightspeedId`, then on email.
 */
class CustomerImporter extends Component
{
    use SavesElements;

    /**
     * Lightspeed type => Craft option
     */
    private const CUSTOMER_TYPES = ['default' => 'private', 'company' => 'company'];
    private const GENDERS = ['male', 'female'];
    private const BILLING_TITLE = 'Billing address';
    private const SHIPPING_TITLE = 'Shipping address';
    public const GROUPS = [
        'vipMembers' => 'VIP Members',
        'customers' => 'Customers',
        'guests' => 'Guests',
    ];

    /**
     * @var string[] problems that did not stop the import
     */
    public array $warnings = [];

    /**
     * @param array<int, array<string, string>> $rows
     * @param string[] $guestIds Lightspeed IDs of guest checkouts (no account)
     * @param bool $onlyNew skip customers that already exist (for customer data from orders, which can be older)
     */
    public function __construct(
        private readonly array $rows,
        private readonly array $guestIds,
        private readonly bool $onlyNew = false,
        array $config = [],
    ) {
        parent::__construct($config);
    }

    public function import(): int
    {
        $count = 0;
        foreach ($this->rows as $row) {
            $user = $this->findUser($row);
            if ($user && $this->onlyNew) {
                continue;
            }

            $user = $this->saveUser($row, $user);
            $this->saveGroups($user, $row);
            $this->saveLanguage($user, $row);
            $this->saveAddresses($user, $row);
            $count++;
        }

        return $count;
    }

    private function findUser(array $row): ?User
    {
        return User::find()->lightspeedId($row['Internal_ID'])->status(null)->one()
            ?? User::find()->email(Db::escapeParam($row['E-mail']))->status(null)->one();
    }

    private function saveUser(array $row, ?User $user): User
    {
        if (!$user) {
            // Accounts: pending, without password (Lightspeed passwords cannot be exported).
            // At go-live, every account gets a "set your password" email.
            // Guests: inactive, like the guest customers Commerce creates.
            $user = new User([
                'username' => $row['E-mail'],
                'pending' => !$this->isGuest($row),
                // Craft only stores dateCreated for new elements.
                'dateCreated' => $this->date($row['Registered']),
            ]);
        }

        $user->email = $row['E-mail'];
        $user->firstName = $row['Firstname'] ?: null;
        $user->lastName = AddressMapper::lastName($row) ?: null;
        $user->setFieldValues([
            'lightspeedId' => $row['Internal_ID'],
            'customerType' => self::CUSTOMER_TYPES[strtolower($row['Customer type'])] ?? null,
            'cocNumber' => $row['Coc number'] ?: null,
            'birthdate' => $this->date($row['Birthdate']),
            'gender' => $this->option($row['Gender'], self::GENDERS),
            'emailConfirmed' => $row['Confirmed'] === 'Yes',
        ]);
        $this->save($user);

        return $user;
    }

    /**
     * Adds a group; groups the user already has are kept.
     */
    public static function addToGroup(User $user, UserGroup $group): void
    {
        $groups = $user->getGroups();
        if (!in_array($group->id, array_map(fn(UserGroup $group) => $group->id, $groups))) {
            Craft::$app->getUsers()->assignUserToGroups($user->id, [...array_map(fn(UserGroup $group) => $group->id, $groups), $group->id]);
            $user->setGroups([...$groups, $group]);
        }
    }

    public static function group(string $handle): UserGroup
    {
        return Craft::$app->getUserGroups()->getGroupByHandle($handle) ?? throw new Exception("User group $handle missing: run lightspeed/setup.");
    }

    /**
     * VIP Members (account) or Guests, plus the Lightspeed groups.
     */
    private function saveGroups(User $user, array $row): void
    {
        self::addToGroup($user, self::group($this->isGuest($row) ? 'guests' : 'vipMembers'));
        foreach (array_filter(array_map('trim', explode(',', $row['Groups']))) as $name) {
            self::addToGroup($user, $this->userGroup($name));
        }
    }

    private function isGuest(array $row): bool
    {
        return in_array($row['Internal_ID'], $this->guestIds, true);
    }

    private function saveLanguage(User $user, array $row): void
    {
        $language = Module::SITES[strtolower($row['Language'])]['language'] ?? null;
        if ($language) {
            Craft::$app->getUsers()->saveUserPreferences($user, ['language' => $language]);
        }
    }

    /**
     * Lightspeed has 1 billing and 1 shipping address per customer. When both are the same, only 1 address is saved.
     */
    private function saveAddresses(User $user, array $row): void
    {
        $billing = AddressMapper::values($row, '');
        $shipping = AddressMapper::values($row, 'Shipping ');
        if ($shipping === $billing) {
            $shipping = null;
        }

        $billingId = $billing ? $this->saveAddress($user, self::BILLING_TITLE, $billing, $row)?->id : null;
        $shippingId = $shipping ? $this->saveAddress($user, self::SHIPPING_TITLE, $shipping, $row)?->id : $billingId;

        $customers = Commerce::getInstance()->getCustomers();
        $customers->savePrimaryBillingAddressId($user, $billingId);
        $customers->savePrimaryShippingAddressId($user, $shippingId);
    }

    private function saveAddress(User $user, string $title, array $values, array $row): ?Address
    {
        if (!$values['countryCode']) {
            $this->warnings[] = "Customer {$row['Internal_ID']}: unknown country, $title not imported";
            return null;
        }

        $address = Address::find()->owner($user)->title(Db::escapeParam($title))->one() ?? new Address();
        $address->setOwner($user);
        $address->title = $title;
        AddressMapper::fill($address, $values);
        $this->save($address);

        return $address;
    }

    private function userGroup(string $name): UserGroup
    {
        $handle = lcfirst(str_replace(' ', '', ucwords(preg_replace('/[^a-z0-9 ]/i', ' ', $name))));
        $groups = Craft::$app->getUserGroups();
        $group = $groups->getGroupByHandle($handle);

        if (!$group) {
            $group = new UserGroup(['name' => $name, 'handle' => $handle]);
            $groups->saveGroup($group) || throw new Exception("Could not save user group $name: " . implode(', ', $group->getErrorSummary(true)));
            $this->warnings[] = "New user group: $name";
        }

        return $group;
    }

    private function option(string $value, array $options): ?string
    {
        $value = strtolower($value);
        return in_array($value, $options, true) ? $value : null;
    }

    private function date(string $value): ?DateTime
    {
        return $value !== '' && !str_starts_with($value, '0000') ? new DateTime($value) : null;
    }
}
