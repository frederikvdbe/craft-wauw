<?php

namespace modules\lightspeed\services;

use Closure;
use Craft;
use craft\helpers\App;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use LogicException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use yii\base\Component;
use yii\base\InvalidConfigException;

/**
 * Read-only client for the Lightspeed eCom API. The API key is for the live shop: only GET requests are allowed.
 */
class Client extends Component
{
    public const PAGE_LIMIT = 250;

    private const MAX_RETRIES = 5;

    /**
     * @var Closure(int $seconds): void|null called before waiting for the rate limit
     */
    public ?Closure $onWait = null;

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
            try {
                $response = $this->http()->request('GET', "$language/$path.json", ['query' => $query]);
                $this->throttle($response);
                return json_decode((string)$response->getBody(), true, flags: JSON_THROW_ON_ERROR);
            } catch (ClientException $e) {
                if ($e->getResponse()->getStatusCode() !== 429 || $attempt >= self::MAX_RETRIES) {
                    throw $e;
                }
                $this->wait($this->resets($e->getResponse())[0] ?? 60);
            }
        }
    }

    /**
     * Fetches every page of a list endpoint, or only the first `$max` items.
     */
    public function all(string $language, string $path, ?int $max = null): array
    {
        $limit = min($max ?? self::PAGE_LIMIT, self::PAGE_LIMIT);
        $items = [];

        for ($page = 1; ; $page++) {
            $pageItems = $this->extractList($this->get($language, $path, ['page' => $page, 'limit' => $limit]));
            array_push($items, ...$pageItems);

            if (count($pageItems) < $limit || ($max !== null && count($items) >= $max)) {
                return $max === null ? $items : array_slice($items, 0, $max);
            }
        }
    }

    /**
     * List responses wrap the items in a single key, e.g. {"products": [...]}.
     * An empty list comes back as false instead of []. A single object (e.g. `shop`) becomes a list of 1.
     */
    private function extractList(array $response): array
    {
        $list = reset($response);
        if (!is_array($list)) {
            return [];
        }

        return array_is_list($list) ? $list : [$list];
    }

    /**
     * Waits when a rate limit window (5 min / 1 hour / 1 day) is almost used up.
     */
    private function throttle(ResponseInterface $response): void
    {
        $remaining = explode('/', $response->getHeaderLine('X-RateLimit-Remaining'));
        $resets = $this->resets($response);

        foreach ($remaining as $i => $left) {
            if ($left !== '' && (int)$left <= 1) {
                $this->wait($resets[$i] ?? 60);
            }
        }
    }

    /**
     * @return int[] seconds until each window resets
     */
    private function resets(ResponseInterface $response): array
    {
        $header = $response->getHeaderLine('X-RateLimit-Reset');
        return $header === '' ? [] : array_map('intval', explode('/', $header));
    }

    private function wait(int $seconds): void
    {
        $seconds = max($seconds, 1) + 1;
        $this->onWait && ($this->onWait)($seconds);
        sleep($seconds);
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

        $handler = HandlerStack::create();
        $handler->push(Middleware::mapRequest(function(RequestInterface $request) {
            if ($request->getMethod() !== 'GET') {
                throw new LogicException("Lightspeed API is read-only (live shop): {$request->getMethod()} is not allowed.");
            }
            return $request;
        }));

        return $this->http = Craft::createGuzzleClient([
            'base_uri' => "https://$cluster/",
            'auth' => [$key, $secret],
            'handler' => $handler,
        ]);
    }
}
