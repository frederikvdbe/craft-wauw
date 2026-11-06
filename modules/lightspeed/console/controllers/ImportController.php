<?php

namespace modules\lightspeed\console\controllers;

use Craft;
use craft\console\Controller;
use modules\lightspeed\services\CsvExport;
use modules\lightspeed\services\CustomerImporter;
use modules\lightspeed\services\OrderImporter;
use modules\lightspeed\services\ProductImporter;
use modules\lightspeed\services\ProductMatcher;
use modules\lightspeed\services\Sample;
use modules\lightspeed\services\Store;
use yii\base\Exception;
use yii\console\ExitCode;

/**
 * Imports the Lightspeed CSV exports in storage/lightspeed into Craft. Run `lightspeed/setup` first.
 * Safe to run again.
 */
class ImportController extends Controller
{
    private const DIR = '@storage/lightspeed';

    /**
     * @var bool only import a small, fixed sample (for development)
     */
    public bool $sample = false;

    private ?array $products = null;
    private ?array $orders = null;
    private ?array $customers = null;

    public function options($actionID): array
    {
        return [...parent::options($actionID), 'sample'];
    }

    public function beforeAction($action): bool
    {
        if (!$this->sample && !$this->confirm('Full import: everything in the CSV exports. Continue? (Use --sample for a small test set.)')) {
            return false;
        }

        return parent::beforeAction($action);
    }

    /**
     * Imports everything in dependency order.
     */
    public function actionIndex(): int
    {
        return $this->actionCategories()
            ?: $this->actionBrands()
            ?: $this->actionProducts()
            ?: $this->actionCustomers()
            ?: $this->actionOrders();
    }

    public function actionCategories(): int
    {
        $this->stdout('Categories: ' . $this->productImporter()->importCategories() . "\n");
        return ExitCode::OK;
    }

    public function actionBrands(): int
    {
        $this->stdout('Brands: ' . $this->productImporter()->importBrands() . "\n");
        return ExitCode::OK;
    }

    public function actionProducts(): int
    {
        $importer = $this->productImporter();
        $count = $importer->importProducts($this->progress(...));

        $this->stdout("Products: $count\n");
        foreach ($importer->failedImages as $url) {
            $this->stderr("Image not downloaded: $url\n");
        }

        return ExitCode::OK;
    }

    /**
     * Customers from the customer export, then the customers of orders that are not in it.
     */
    public function actionCustomers(): int
    {
        $importer = new CustomerImporter($this->data()['customers'], $this->guestIds());
        $this->stdout('Customers: ' . $importer->import() . "\n");
        $this->warnings($importer->warnings);

        $orderImporter = $this->orderImporter();
        $this->stdout('Customers from orders: ' . $orderImporter->importCustomers() . "\n");
        $this->warnings($orderImporter->warnings);

        return ExitCode::OK;
    }

    public function actionOrders(): int
    {
        $importer = $this->orderImporter();
        $this->stdout('New orders: ' . $importer->importOrders($this->progress(...)) . "\n");
        $this->warnings($importer->warnings);

        return ExitCode::OK;
    }

    private function productImporter(): ProductImporter
    {
        return new ProductImporter($this->data()['products']);
    }

    private function orderImporter(): OrderImporter
    {
        // The matcher always uses all products: an order line can link to any imported product.
        $allProducts = CsvExport::readProducts(Craft::getAlias(self::DIR . '/products.csv'));
        return new OrderImporter($this->data()['orders'], new ProductMatcher($allProducts), $this->guestIds());
    }

    /**
     * Only the API knows which customers are guests: `lightspeed/extract customers`.
     *
     * @return string[]
     */
    private function guestIds(): array
    {
        $customers = (new Store())->read('customers') ?? throw new Exception('Run lightspeed/extract customers first.');
        $guests = array_filter($customers, fn(array $customer) => $customer['type'] === 'guest');
        return array_map(fn(array $customer) => (string)$customer['id'], array_values($guests));
    }

    /**
     * @return array{products: array, orders: array, customers: array}
     */
    private function data(): array
    {
        if ($this->products === null) {
            $dir = Craft::getAlias(self::DIR);
            $this->products = CsvExport::readProducts("$dir/products.csv");
            $this->orders = CsvExport::readOrders("$dir/orders.csv");
            $this->customers = CsvExport::read("$dir/customers.csv");

            if ($this->sample) {
                $sample = new Sample($this->products, $this->orders, $this->customers, $this->guestIds());
                [$this->products, $this->orders, $this->customers] = [$sample->products, $sample->orders, $sample->customers];
            }
        }

        return ['products' => $this->products, 'orders' => $this->orders, 'customers' => $this->customers];
    }

    private function progress(int $done, int $total): void
    {
        if ($done % 50 === 0) {
            $this->stdout("$done / $total\n");
        }
    }

    private function warnings(array $warnings): void
    {
        foreach ($warnings as $warning) {
            $this->stderr("$warning\n");
        }
    }
}
