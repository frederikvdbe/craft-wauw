<?php

namespace modules\lightspeed\services;

use Craft;
use craft\commerce\Plugin as Commerce;
use craft\elements\Address;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\models\UserGroup;
use DateTime;
use modules\lightspeed\Module;
use yii\base\Component;
use yii\base\Exception;

/**
 * Imports the API customers as Craft users with addresses. Safe to run again:
 * users are matched on `lightspeedId`, then on email.
 */
class CustomerImporter extends Component
{
    use SavesElements;

    public const GROUPS = [
        'vipMembers' => 'VIP Members',
        'customers' => 'Customers',
        'guests' => 'Guests',
    ];

    private const BILLING_TITLE = 'Billing address';
    private const SHIPPING_TITLE = 'Shipping address';
    private const GENDERS = ['male', 'female'];

    /**
     * @var string[] problems that did not stop the import
     */
    public array $warnings = [];

    private ?array $buyerIds = null;

    public function __construct(
        private readonly ApiData $data,
        array $config = [],
    ) {
        parent::__construct($config);
    }

    /**
     * @param callable(int $done, int $total): void|null $onProgress
     */
    public function import(?callable $onProgress = null): int
    {
        $ids = $this->ids();
        foreach ($ids as $i => $id) {
            $this->importCustomer($id);
            $onProgress && $onProgress($i + 1, count($ids));
        }

        return count($ids);
    }

    /**
     * @return int[]
     */
    public function ids(): array
    {
        return array_keys($this->data->customers);
    }

    public function importCustomer(int $id): void
    {
        $customer = $this->data->customers[$id];
        $user = $this->saveUser($customer, $this->findUser($customer));

        self::addToGroup($user, self::group($this->data->isGuest($id) ? 'guests' : 'vipMembers'));
        if (isset($this->buyerIds()[$id])) {
            self::addToGroup($user, self::group('customers'));
        }
        foreach ($this->data->customerGroups[$id] ?? [] as $name) {
            self::addToGroup($user, $this->userGroup($name));
        }

        $this->saveLanguage($user, $customer);
        $this->saveAddresses($user, $customer);
    }

    public static function addToGroup(User $user, UserGroup $group): void
    {
        $groups = $user->getGroups();
        $groupIds = array_map(fn(UserGroup $group) => $group->id, $groups);
        if (!in_array($group->id, $groupIds)) {
            Craft::$app->getUsers()->assignUserToGroups($user->id, [...$groupIds, $group->id]);
            $user->setGroups([...$groups, $group]);
        }
    }

    public static function group(string $handle): UserGroup
    {
        return Craft::$app->getUserGroups()->getGroupByHandle($handle) ?? throw new Exception("User group $handle missing: run lightspeed/setup.");
    }

    /**
     * @return array<int, int> customer ID => index, for customers with orders
     */
    private function buyerIds(): array
    {
        return $this->buyerIds ??= array_flip(array_filter(array_map(fn(array $order) => ApiData::id($order['customer']), $this->data->orders)));
    }

    private function findUser(array $customer): ?User
    {
        return User::find()->lightspeedIdField($customer['id'])->status(null)->one()
            ?? User::find()->email(Db::escapeParam($customer['email']))->status(null)->one();
    }

    private function saveUser(array $customer, ?User $user): User
    {
        if (!$user) {
            // Accounts: pending, without password (Lightspeed passwords cannot be exported).
            // At go-live, every account gets a "set your password" email.
            // Guests: inactive, like the guest customers Commerce creates.
            $user = new User([
                'username' => $customer['email'],
                'pending' => !$this->data->isGuest($customer['id']),
                // Craft only stores dateCreated for new elements.
                'dateCreated' => new DateTime($customer['createdAt']),
            ]);
        }

        $user->email = $customer['email'];
        $user->firstName = $customer['firstname'] ?: null;
        $user->lastName = AddressMapper::lastName($customer) ?: null;
        $user->setFieldValues([
            'lightspeedIdField' => (string)$customer['id'],
            'customerTypeField' => $customer['isCompany'] ? 'company' : 'private',
            'cocNumberField' => $customer['companyCoCNumber'] ?: null,
            'birthdateField' => $customer['birthDate'] ? new DateTime($customer['birthDate']) : null,
            'genderField' => in_array($customer['gender'], self::GENDERS, true) ? $customer['gender'] : null,
            'emailConfirmedField' => $customer['isConfirmed'],
        ]);
        $this->save($user);

        return $user;
    }

    private function saveLanguage(User $user, array $customer): void
    {
        $language = Module::SITES[$customer['language']['code'] ?? '']['language'] ?? null;
        if ($language) {
            Craft::$app->getUsers()->saveUserPreferences($user, ['language' => $language]);
        }
    }

    /**
     * Lightspeed has 1 billing and 1 shipping address per customer. When both are the same, only 1 address is saved.
     */
    private function saveAddresses(User $user, array $customer): void
    {
        $billing = AddressMapper::values($customer, AddressMapper::BILLING);
        $shipping = AddressMapper::values($customer, AddressMapper::SHIPPING);
        if ($shipping === $billing) {
            $shipping = null;
        }

        $billingId = $billing ? $this->saveAddress($user, self::BILLING_TITLE, $billing, $customer)?->id : null;
        $shippingId = $shipping ? $this->saveAddress($user, self::SHIPPING_TITLE, $shipping, $customer)?->id : $billingId;

        $customers = Commerce::getInstance()->getCustomers();
        $customers->savePrimaryBillingAddressId($user, $billingId);
        $customers->savePrimaryShippingAddressId($user, $shippingId);
    }

    private function saveAddress(User $user, string $title, array $values, array $customer): ?Address
    {
        if (!$values['countryCode']) {
            $this->warnings[] = "Customer {$customer['id']}: no country, $title not imported";
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
        $handle = StringHelper::toHandle($name);
        $groups = Craft::$app->getUserGroups();
        $group = $groups->getGroupByHandle($handle);

        if (!$group) {
            $group = new UserGroup(['name' => $name, 'handle' => $handle]);
            $groups->saveGroup($group) || throw new Exception("Could not save user group $name: " . implode(', ', $group->getErrorSummary(true)));
            $this->warnings[] = "New user group: $name";
        }

        return $group;
    }
}
