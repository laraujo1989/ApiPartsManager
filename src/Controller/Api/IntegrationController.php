<?php

namespace App\Controller\Api;

use App\Enum\Permission;
use App\Message\WebSearchMessage;
use App\Service\SettingsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Búsqueda de partes en internet y enlace con n8n.
 *
 * El backend no conoce SearXNG ni el LLM: solo llama a un webhook de n8n (configurable en Settings → Integrations),
 * que se encarga de buscar, leer páginas y estructurar los datos con el modelo (Ollama/Olares). Así se puede
 * cambiar de modelo o de buscador exportando/importando el workflow, sin tocar esta aplicación.
 */
class IntegrationController extends ApiController
{
    public function __construct(
        private SettingsService $settings,
        private EntityManagerInterface $em,
        private HttpClientInterface $http,
        private CacheItemPoolInterface $cache,
        private MessageBusInterface $bus,
    ) {
    }

    private function config(): array
    {
        return ($this->settings->get('integrations') ?: []) + ['searchWebhookUrl' => '', 'webhookSecret' => '', 'enabled' => false];
    }

    #[Route('/api/admin/integrations', methods: ['GET'])]
    public function get(): JsonResponse
    {
        $this->need(Permission::ManageUsers);
        $c = $this->config();

        return $this->json([
            'enabled' => (bool) $c['enabled'], 'searchWebhookUrl' => $c['searchWebhookUrl'], 'hasSecret' => $c['webhookSecret'] !== '',
            'hasApiKey' => (bool) $this->settings->get('integration_api_key_hash'),
        ]);
    }

    #[Route('/api/admin/integrations', methods: ['PUT'])]
    public function put(Request $request): JsonResponse
    {
        $this->need(Permission::ManageUsers);
        $d = $this->body($request);
        $c = $this->config();
        $url = trim((string) ($d['searchWebhookUrl'] ?? ''));
        if ($url !== '' && !preg_match('#^https?://#i', $url)) {
            return $this->json(['error' => 'The webhook URL must start with http:// or https://'], 422);
        }
        $c['searchWebhookUrl'] = $url;
        $c['enabled'] = (bool) ($d['enabled'] ?? false) && $url !== '';
        if (isset($d['webhookSecret']) && $d['webhookSecret'] !== '') {
            $c['webhookSecret'] = (string) $d['webhookSecret'];
        }
        $this->settings->set('integrations', $c);
        $this->em->flush();

        return $this->get();
    }

    /** Estado público para el formulario: ¿hay búsqueda web disponible? */
    #[Route('/api/search/status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        return $this->json(['webSearch' => (bool) $this->config()['enabled']]);
    }

    
    /**
     * Inicia una búsqueda web. Con un modelo local puede tardar minutos, así que se encola (Messenger) y el
     * navegador consulta el resultado con GET /api/search/web/{jobId}. Si ya se buscó antes, responde al instante.
     */
    #[Route('/api/search/web', methods: ['POST'])]
    public function startWeb(Request $request): JsonResponse
    {
        $this->needAny([Permission::CreateRequests, Permission::EditInventory]);
        if (!$this->config()['enabled']) {
            return $this->json(['error' => 'Web search is not configured. Ask an administrator to set it up in Settings → Integrations.'], 503);
        }
        $d = $this->body($request);
        $q = trim((string) ($d['q'] ?? ''));
        if (mb_strlen($q) < 3) {
            return $this->json(['error' => 'Type at least 3 characters.'], 422);
        }
        $manufacturer = trim((string) ($d['manufacturer'] ?? ''));

        $hit = $this->cache->getItem('websearch_'.sha1(mb_strtolower($q.'|'.$manufacturer)));
        if ($hit->isHit()) {
            return $this->json($hit->get() + ['cached' => true]);
        }
        $jobId = bin2hex(random_bytes(8));
        $job = $this->cache->getItem('websearch_job_'.$jobId);
        $job->set(['status' => 'pending'])->expiresAfter(3600);
        $this->cache->save($job);
        $this->bus->dispatch(new WebSearchMessage($jobId, $q, $manufacturer, $this->me()->username));

        return $this->json(['status' => 'pending', 'jobId' => $jobId], 202);
    }

    #[Route('/api/search/web/{jobId}', methods: ['GET'], requirements: ['jobId' => '[a-f0-9]{16}'])]
    public function webResult(string $jobId): JsonResponse
    {
        $this->needAny([Permission::CreateRequests, Permission::EditInventory]);
        $item = $this->cache->getItem('websearch_job_'.$jobId);
        if (!$item->isHit()) {
            return $this->json(['error' => 'That search expired. Start it again.'], 404);
        }

        return $this->json($item->get());
    }

    /** Prueba rápida desde Settings: llama al webhook con una consulta de ejemplo. */
    #[Route('/api/admin/integrations/test', methods: ['POST'])]
    public function test(): JsonResponse
    {
        $this->need(Permission::ManageUsers);
        $c = $this->config();
        if ($c['searchWebhookUrl'] === '') {
            return $this->json(['error' => 'Enter the webhook URL first.'], 422);
        }
        $started = microtime(true);
        try {
            $res = $this->http->request('POST', $c['searchWebhookUrl'], [
                'headers' => ['X-Webhook-Secret' => $c['webhookSecret']], 'json' => ['query' => '6205-2RS bearing', 'manufacturer' => 'SKF', 'test' => true], 'timeout' => 600,
            ]);
            $data = $res->toArray();
            $n = \count($data['items'] ?? $data);

            return $this->json(['ok' => true, 'results' => $n, 'seconds' => round(microtime(true) - $started, 1)]);
        } catch (\Throwable $e) {
            return $this->json(['ok' => false, 'error' => $e->getMessage()], 200);
        }
    }
}
