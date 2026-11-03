<?php

namespace modules\lightspeed\services;

use Craft;
use craft\helpers\App;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\ClientException;
use yii\base\Component;
use yii\base\InvalidConfigException;

class Client extends Component
{
    public const PAGE_LIMIT = 250;

    private const WINDOW_SECONDS = 300;
    private const WINDOW_MAX_REQUESTS = 290;
    private const MAX_RETRIES = 3;

    /** @var float[] */
    private array $requestTimes = [];
    private ?HttpClient $http = null;

    /**
     * @return string[]
     */
    public function getLanguages(): array
    {
        return array_filter(array_map('trim', explode(',', (string)App::env('LIGHTSPEED_LANGUAGES'))));
    }

    public function count(string $language, string $resource): int
    {
        return (int)($this->get($language, "$resource/count")['count'] ?? 0);
    }

    public function get(string $language, string $path, array $query = []): array
    {
        for ($attempt = 1; ; $attempt++) {
            $this->throttle();

            try {
                $response = $this->http()->get("$language/$path.json", ['query' => $query]);
                return json_decode((string)$response->getBody(), true, flags: JSON_THROW_ON_ERROR);
            } catch (ClientException $e) {
                if ($e->getResponse()->getStatusCode() !== 429 || $attempt >= self::MAX_RETRIES) {
                    throw $e;
                }
                sleep(60);
            }
        }
    }

    /**
     * Fetches every page of a list endpoint.
     */
    public function all(string $language, string $path, array $query = []): array
    {
        $items = [];

        for ($page = 1; ; $page++) {
            $response = $this->get($language, $path, $query + ['page' => $page, 'limit' => self::PAGE_LIMIT]);
            $pageItems = $this->extractList($response);
            array_push($items, ...$pageItems);

            if (count($pageItems) < self::PAGE_LIMIT) {
                return $items;
            }
        }
    }

    /**
     * List responses wrap the items in a single key, e.g. {"products": [...]}.
     * An empty list comes back as false instead of [].
     */
    private function extractList(array $response): array
    {
        $list = reset($response);
        return is_array($list) ? array_values($list) : [];
    }

    private function throttle(): void
    {
        $now = microtime(true);
        $this->requestTimes = array_values(array_filter(
            $this->requestTimes,
            fn(float $time) => $time > $now - self::WINDOW_SECONDS,
        ));

        if (count($this->requestTimes) >= self::WINDOW_MAX_REQUESTS) {
            $wait = $this->requestTimes[0] + self::WINDOW_SECONDS - $now;
            Craft::info("Rate limit reached, waiting {$wait}s", __METHOD__);
            usleep((int)ceil($wait * 1_000_000));
        }

        $this->requestTimes[] = microtime(true);
    }

    private function http(): HttpClient
    {
        if ($this->http) {
            return $this->http;
        }

        $key = App::env('LIGHTSPEED_API_KEY');
        $secret = App::env('LIGHTSPEED_API_SECRET');
        $cluster = App::env('LIGHTSPEED_CLUSTER');

        if (!$key || !$secret || !$cluster) {
            throw new InvalidConfigException('Set LIGHTSPEED_API_KEY, LIGHTSPEED_API_SECRET and LIGHTSPEED_CLUSTER in .env');
        }

        return $this->http = Craft::createGuzzleClient([
            'base_uri' => "https://$cluster/",
            'auth' => [$key, $secret],
        ]);
    }
}
