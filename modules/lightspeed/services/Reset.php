<?php

namespace modules\lightspeed\services;

use Craft;
use craft\base\ElementInterface;
use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\elements\Asset;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use craft\elements\User;
use craft\models\VolumeFolder;
use yii\base\Exception;

/**
 * Deletes all imported Lightspeed data, for a fresh import. The content model (`lightspeed/setup`) stays.
 */
class Reset
{
    public const VOLUMES = ['productImages', 'brandImages'];

    /**
     * @return array<string, ElementQueryInterface> label => query, in delete order (orders before the users and variants they use)
     */
    public function queries(): array
    {
        $imported = fn(ElementQueryInterface $query) => $query->lightspeedIdField(':notempty:')->status(null);

        return [
            'orders' => $imported(Order::find()->isCompleted(null)),
            'users' => $imported(User::find()->admin(false)),
            'products' => $imported(Product::find()),
            'brands' => $imported(Entry::find()->section('brands')),
            'categories' => $imported(Entry::find()->section('productCategories')),
            'images' => Asset::find()->volume(self::VOLUMES),
        ];
    }

    /**
     * @return array<string, int> label => count
     */
    public function counts(): array
    {
        return array_map(fn(ElementQueryInterface $query) => (int)$query->count(), $this->queries());
    }

    /**
     * @param callable(string $label, int $done, int $total): void|null $progress
     * @return array<string, int> label => deleted
     */
    public function run(?callable $progress = null): array
    {
        $elements = Craft::$app->getElements();
        $deleted = [];

        foreach ($this->queries() as $label => $query) {
            // Ids first: deleting while paging through a query skips elements.
            $ids = $query->ids();
            $deleted[$label] = 0;

            foreach ($ids as $id) {
                $element = (clone $query)->id($id)->one();
                if ($element && !$elements->deleteElement($element, true)) {
                    throw new Exception("Could not delete $label #$id");
                }
                $deleted[$label]++;
                if ($progress) {
                    $progress($label, $deleted[$label], count($ids));
                }
            }
        }

        $this->deleteFolders();

        return $deleted;
    }

    /**
     * The folders per product (`{id}-{slug}`) in the import volumes.
     */
    private function deleteFolders(): void
    {
        $assets = Craft::$app->getAssets();

        foreach (self::VOLUMES as $handle) {
            $volume = Craft::$app->getVolumes()->getVolumeByHandle($handle);
            $root = $volume ? $assets->getRootFolderByVolumeId($volume->id) : null;
            if ($root) {
                $ids = array_map(fn(VolumeFolder $folder) => $folder->id, $assets->findFolders(['parentId' => $root->id]));
                $assets->deleteFoldersByIds($ids);
            }
        }
    }
}
