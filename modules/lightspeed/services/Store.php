<?php

namespace modules\lightspeed\services;

use Craft;
use craft\helpers\FileHelper;
use craft\helpers\Json;
use yii\base\Component;

/**
 * Local JSON copy of the Lightspeed data: storage/lightspeed/{language}/{name}.json
 */
class Store extends Component
{
    public function write(string $language, string $name, array $data): string
    {
        $path = $this->path($language, $name);
        FileHelper::writeToFile($path, Json::encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $path;
    }

    public function read(string $language, string $name): array
    {
        $path = $this->path($language, $name);
        return file_exists($path) ? Json::decode(file_get_contents($path)) : [];
    }

    private function path(string $language, string $name): string
    {
        return Craft::getAlias("@storage/lightspeed/$language/$name.json");
    }
}
