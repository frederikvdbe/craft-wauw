<?php

namespace modules\lightspeed\services;

use Craft;
use craft\helpers\FileHelper;
use craft\helpers\Json;
use DateTime;
use yii\base\Component;

/**
 * Local JSON copy of the Lightspeed API data: `{dir}/{name}.json`, or `{dir}/{language}/{name}.json` for translated data.
 */
class Store extends Component
{
    public string $dir = '@storage/lightspeed/api';

    public function write(string $name, array $data, ?string $language = null): string
    {
        $path = $this->path($name, $language);
        FileHelper::writeToFile($path, Json::encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $path;
    }

    public function read(string $name, ?string $language = null): ?array
    {
        $path = $this->path($name, $language);
        return file_exists($path) ? Json::decode(file_get_contents($path)) : null;
    }

    /**
     * When a file was last downloaded; translated files: the oldest language.
     */
    public function modifiedAt(string $name): ?DateTime
    {
        $times = array_map('filemtime', glob(Craft::getAlias($this->dir) . "/{,*/}$name.json", GLOB_BRACE) ?: []);
        return $times ? (new DateTime())->setTimestamp(min($times)) : null;
    }

    /**
     * Downloaded, but the API returned no records (in every language).
     */
    public function isEmpty(string $name): bool
    {
        $paths = glob(Craft::getAlias($this->dir) . "/{,*/}$name.json", GLOB_BRACE) ?: [];
        foreach ($paths as $path) {
            if (filesize($path) > 2 || trim(file_get_contents($path)) !== '[]') {
                return false;
            }
        }

        return $paths !== [];
    }

    public function delete(string $name, ?string $language = null): void
    {
        FileHelper::unlink($this->path($name, $language));
    }

    private function path(string $name, ?string $language): string
    {
        return Craft::getAlias($this->dir . ($language ? "/$language" : '') . "/$name.json");
    }
}
