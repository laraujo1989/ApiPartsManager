<?php

namespace App\Message;

use App\Service\SettingsService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsMessageHandler]
class WebSearchHandler
{
    public function __construct(private SettingsService $settings, private HttpClientInterface $http, private CacheItemPoolInterface $cache)
    {
    }

    public function __invoke(WebSearchMessage $m): void
    {
        $c = ($this->settings->get('integrations') ?: []) + ['searchWebhookUrl' => '', 'webhookSecret' => ''];
        try {
            $res = $this->http->request('POST', $c['searchWebhookUrl'], [
                'headers' => ['X-Webhook-Secret' => $c['webhookSecret']],
                'json' => ['query' => $m->query, 'manufacturer' => $m->manufacturer, 'requestedBy' => $m->requestedBy],
                'timeout' => 600,
                'max_duration' => 600,
            ]);
            $data = $res->toArray();
            $list = $data['items'] ?? $data;
            if (!\is_array($list)) {
                throw new \RuntimeException('Unexpected response from the automation.');
            }
            // Se normaliza para que el frontend no dependa del modelo que haya contestado.
            $items = array_values(array_map(fn ($r) => [
                'title' => (string) ($r['title'] ?? $r['description'] ?? ''),
                'url' => (string) ($r['url'] ?? ''),
                'description' => (string) ($r['description'] ?? ''),
                'manufacturer' => (string) ($r['manufacturer'] ?? ''),
                'partNumber' => (string) ($r['partNumber'] ?? $r['mpn'] ?? ''),
                'image' => (string) ($r['image'] ?? ''),
                'datasheet' => (string) ($r['datasheet'] ?? ''),
                'specs' => \is_array($r['specs'] ?? null) ? array_filter(array_map(fn ($v) => \is_scalar($v) ? trim((string) $v) : '', $r['specs']), fn ($v) => !\in_array(strtolower($v), ['', 'unknown', 'n/a', 'null', 'none', 'not specified'], true)) : [],
                'price' => isset($r['price']) && is_numeric($r['price']) ? (float) $r['price'] : null,
            ], array_filter($list, 'is_array')));
            $result = ['status' => 'done', 'items' => $items];
            // Los resultados buenos se reutilizan 3 días (la búsqueda es lenta y consume el modelo).
            $this->store('websearch_'.sha1(mb_strtolower($m->query.'|'.$m->manufacturer)), $result, 60 * 60 * 24 * 3);
        } catch (\Throwable $e) {
            $result = ['status' => 'error', 'error' => 'The search automation did not answer: '.$e->getMessage()];
        }
        $this->store('websearch_job_'.$m->jobId, $result, 3600);
    }

    private function store(string $key, array $value, int $ttl): void
    {
        $item = $this->cache->getItem($key);
        $item->set($value)->expiresAfter($ttl);
        $this->cache->save($item);
    }
}
