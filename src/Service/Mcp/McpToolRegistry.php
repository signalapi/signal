<?php

namespace App\Service\Mcp;

use App\Entity\Environment;
use App\Entity\FlowRun;
use App\Entity\FlowStep;
use App\Entity\TestFlow;
use App\Entity\Workspace;
use App\Message\RunFlowMessage;
use App\Repository\ApiRequestRepository;
use App\Repository\DbConnectionRepository;
use App\Repository\EnvironmentRepository;
use App\Repository\FlowRunRepository;
use App\Repository\FlowStepRepository;
use App\Repository\TestFlowRepository;
use App\Service\FlowExpressionParser;
use App\Service\FlowRunner;
use App\Service\FlowRunReporter;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Defines and executes the MCP tools exposed to Claude. Everything is scoped to
 * the workspace of the authenticating API token, so Claude can only ever read or
 * change that workspace's data.
 */
class McpToolRegistry
{
    public function __construct(
        private readonly \App\Repository\ApiCollectionRepository $collections,
        private readonly ApiRequestRepository $requests,
        private readonly EnvironmentRepository $environments,
        private readonly DbConnectionRepository $dbConnections,
        private readonly TestFlowRepository $flows,
        private readonly FlowStepRepository $steps,
        private readonly FlowRunRepository $runs,
        private readonly FlowRunner $runner,
        private readonly FlowExpressionParser $parser,
        private readonly FlowRunReporter $reporter,
        private readonly MessageBusInterface $bus,
    ) {
    }

    /**
     * Tool definitions for tools/list (name, description, JSON Schema).
     *
     * @return array<int, array<string, mixed>>
     */
    public function definitions(): array
    {
        $strArray = ['type' => 'array', 'items' => ['type' => 'string']];

        return [
            ['name' => 'whoami', 'description' => 'Bu token\'ın bağlı olduğu merchant ve workspace\'i, içindeki kaynak sayılarıyla döner. Her işlem yalnızca bu merchant/workspace ile sınırlıdır.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()]],
            ['name' => 'list_collections', 'description' => 'Workspace içindeki collection\'ları ve istek sayılarını listeler.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()]],
            ['name' => 'search_requests', 'description' => 'İstekleri ada/URL\'ye göre arar; flow adımı eklemek için id döner.',
                'inputSchema' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string', 'description' => 'Aranacak metin (boş = hepsi)']]]],
            ['name' => 'list_environments', 'description' => 'Environment\'ları ve değişken adlarını listeler (secret değerler maskelenir).',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()]],
            ['name' => 'list_db_connections', 'description' => 'Veritabanı bağlantılarını listeler (kimlik bilgisi dönmez).',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()]],
            ['name' => 'list_flows', 'description' => 'Test akışlarını ve adım sayılarını listeler.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()]],
            ['name' => 'create_flow', 'description' => 'Yeni bir test akışı oluşturur; flowId döner.',
                'inputSchema' => ['type' => 'object', 'required' => ['name'], 'properties' => [
                    'name' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'environmentName' => ['type' => 'string', 'description' => 'Varsayılan environment adı'],
                    'stopOnFailure' => ['type' => 'boolean'],
                ]]],
            ['name' => 'add_http_step', 'description' => 'Akışa bir HTTP isteği adımı ekler. extractions: ["var = json.path"], assertions: ["status == 200", "data.id exists"].',
                'inputSchema' => ['type' => 'object', 'required' => ['flowId', 'requestId'], 'properties' => [
                    'flowId' => ['type' => 'string'],
                    'requestId' => ['type' => 'string'],
                    'name' => ['type' => 'string'],
                    'extractions' => $strArray,
                    'assertions' => $strArray,
                ]]],
            ['name' => 'add_db_step', 'description' => 'Akışa bir DB doğrulama adımı ekler. query {{değişken}} destekler. assertions örn: ["rowCount == 1", "rows.0.status == active"].',
                'inputSchema' => ['type' => 'object', 'required' => ['flowId', 'connection', 'query'], 'properties' => [
                    'flowId' => ['type' => 'string'],
                    'connection' => ['type' => 'string', 'description' => 'DB bağlantısı adı veya id'],
                    'query' => ['type' => 'string'],
                    'name' => ['type' => 'string'],
                    'extractions' => $strArray,
                    'assertions' => $strArray,
                ]]],
            ['name' => 'create_flow_from_collection', 'description' => 'Bir collection\'ın isteklerinden tek seferde sıralı HTTP adımlı akış kurar. requestIds verilmezse collection\'daki tüm istekler sırasıyla eklenir; verilirse yalnızca o istekler (verilen sırayla).',
                'inputSchema' => ['type' => 'object', 'required' => ['collectionId', 'name'], 'properties' => [
                    'collectionId' => ['type' => 'string'],
                    'name' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'requestIds' => array_merge($strArray, ['description' => 'Eklenecek istek id\'leri (sıralı). Boş = collection\'daki tüm istekler.']),
                    'environmentName' => ['type' => 'string'],
                    'stopOnFailure' => ['type' => 'boolean'],
                ]]],
            ['name' => 'add_setvar_step', 'description' => 'Akışa değişken atama adımı ekler. assignments: ["orderId = {{data.id}}", "label = sabit"]. Değerlerde {{değişken}} ve {{$randomEmail}} çözülür.',
                'inputSchema' => ['type' => 'object', 'required' => ['flowId', 'assignments'], 'properties' => [
                    'flowId' => ['type' => 'string'],
                    'assignments' => $strArray,
                    'name' => ['type' => 'string'],
                ]]],
            ['name' => 'add_delay_step', 'description' => 'Akışa bekleme adımı ekler (asenkron işlemler arası gecikme).',
                'inputSchema' => ['type' => 'object', 'required' => ['flowId', 'ms'], 'properties' => [
                    'flowId' => ['type' => 'string'],
                    'ms' => ['type' => 'integer', 'description' => 'Bekleme süresi (ms, maks 60000)'],
                    'name' => ['type' => 'string'],
                ]]],
            ['name' => 'get_flow', 'description' => 'Bir akışın detayını (tüm adımları, extraction/assertion\'ları, retry ayarlarıyla) döner.',
                'inputSchema' => ['type' => 'object', 'required' => ['flowId'], 'properties' => ['flowId' => ['type' => 'string']]]],
            ['name' => 'run_flow', 'description' => 'Bir akışı SENKRON çalıştırır, biter ve adım adım sonucu (assertion durumları dahil) döner. Kısa akışlar için.',
                'inputSchema' => ['type' => 'object', 'required' => ['flowId'], 'properties' => [
                    'flowId' => ['type' => 'string'],
                    'environmentName' => ['type' => 'string'],
                    'variables' => ['type' => 'object', 'description' => 'Tek seferlik değişkenler {ad: değer}'],
                ]]],
            ['name' => 'run_flow_async', 'description' => 'Bir akışı ARKA PLANDA başlatır ve hemen runId döner (beklemez). Uzun/poll\'lü akışlar için. İlerlemeyi list_runs veya get_run ile izleyin.',
                'inputSchema' => ['type' => 'object', 'required' => ['flowId'], 'properties' => [
                    'flowId' => ['type' => 'string'],
                    'environmentName' => ['type' => 'string'],
                    'variables' => ['type' => 'object'],
                ]]],
            ['name' => 'list_runs', 'description' => 'Son koşumları listeler (durum, geçen adım, süre, tarih). flowId verilirse o akışın, verilmezse workspace genelinin.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'flowId' => ['type' => 'string'],
                    'limit' => ['type' => 'integer', 'description' => 'Maks kayıt (varsayılan 10)'],
                ]]],
            ['name' => 'get_run', 'description' => 'Bir koşumun detayını döner.',
                'inputSchema' => ['type' => 'object', 'required' => ['runId'], 'properties' => ['runId' => ['type' => 'string']]]],
            ['name' => 'delete_flow', 'description' => 'Bir akışı ve tüm koşum geçmişini siler.',
                'inputSchema' => ['type' => 'object', 'required' => ['flowId'], 'properties' => ['flowId' => ['type' => 'string']]]],
            ['name' => 'delete_step', 'description' => 'Bir akış adımını siler.',
                'inputSchema' => ['type' => 'object', 'required' => ['stepId'], 'properties' => ['stepId' => ['type' => 'string']]]],
        ];
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed> Structured result
     */
    public function call(string $name, array $args, Workspace $ws): array
    {
        return match ($name) {
            'whoami' => $this->whoami($ws),
            'list_collections' => $this->listCollections($ws),
            'search_requests' => $this->searchRequests($ws, (string) ($args['query'] ?? '')),
            'list_environments' => $this->listEnvironments($ws),
            'list_db_connections' => $this->listDbConnections($ws),
            'list_flows' => $this->listFlows($ws),
            'create_flow' => $this->createFlow($ws, $args),
            'create_flow_from_collection' => $this->createFlowFromCollection($ws, $args),
            'add_http_step' => $this->addHttpStep($ws, $args),
            'add_db_step' => $this->addDbStep($ws, $args),
            'add_setvar_step' => $this->addSetvarStep($ws, $args),
            'add_delay_step' => $this->addDelayStep($ws, $args),
            'get_flow' => $this->getFlow($ws, $args),
            'run_flow' => $this->runFlow($ws, $args),
            'run_flow_async' => $this->runFlowAsync($ws, $args),
            'list_runs' => $this->listRuns($ws, $args),
            'get_run' => $this->getRun($ws, $args),
            'delete_flow' => $this->deleteFlow($ws, $args),
            'delete_step' => $this->deleteStep($ws, $args),
            default => throw new \InvalidArgumentException("Bilinmeyen araç: $name"),
        };
    }

    private function listCollections(Workspace $ws): array
    {
        $out = [];
        foreach ($this->collections->findByWorkspace($ws) as $c) {
            $out[] = ['id' => (string) $c->getId(), 'name' => $c->getName(), 'requests' => $c->getRequests()->count()];
        }

        return ['collections' => $out];
    }

    private function searchRequests(Workspace $ws, string $query): array
    {
        $query = mb_strtolower(trim($query));
        $out = [];
        foreach ($this->requests->findByWorkspace($ws) as $r) {
            $hay = mb_strtolower($r->getName() . ' ' . $r->getUrl() . ' ' . $r->getMethod());
            if ('' === $query || str_contains($hay, $query)) {
                $out[] = [
                    'id' => (string) $r->getId(),
                    'name' => $r->getName(),
                    'method' => $r->getMethod(),
                    'url' => $r->getUrl(),
                    'collection' => $r->getCollection()->getName(),
                ];
            }
        }

        return ['requests' => $out];
    }

    private function listEnvironments(Workspace $ws): array
    {
        $out = [];
        foreach ($this->environments->findByWorkspace($ws) as $e) {
            $vars = [];
            foreach ($e->getVariables() as $v) {
                $vars[] = ['name' => $v->getName(), 'value' => $v->isSecret() ? '••• (secret)' : $v->getValue()];
            }
            $out[] = ['id' => (string) $e->getId(), 'name' => $e->getName(), 'variables' => $vars];
        }

        return ['environments' => $out];
    }

    private function listDbConnections(Workspace $ws): array
    {
        $out = [];
        foreach ($this->dbConnections->findByWorkspace($ws) as $c) {
            $out[] = ['id' => (string) $c->getId(), 'name' => $c->getName(), 'type' => $c->getType(), 'host' => $c->getHost()];
        }

        return ['dbConnections' => $out];
    }

    private function listFlows(Workspace $ws): array
    {
        $out = [];
        foreach ($this->flows->findByWorkspace($ws) as $f) {
            $last = $f->getRuns()->first();
            $out[] = [
                'id' => (string) $f->getId(),
                'name' => $f->getName(),
                'steps' => $f->getSteps()->count(),
                'lastStatus' => $last ? $last->getStatus() : null,
            ];
        }

        return ['flows' => $out];
    }

    private function createFlow(Workspace $ws, array $args): array
    {
        if (empty($args['name'])) {
            throw new \InvalidArgumentException('name zorunlu.');
        }

        $flow = new TestFlow();
        $flow->setWorkspace($ws);
        $flow->setName((string) $args['name']);
        $flow->setDescription(isset($args['description']) ? (string) $args['description'] : null);
        $flow->setStopOnFailure((bool) ($args['stopOnFailure'] ?? true));
        if (!empty($args['environmentName'])) {
            $flow->setDefaultEnvironment($this->findEnvironmentByName($ws, (string) $args['environmentName']));
        }
        $this->flows->save($flow);

        return ['flowId' => (string) $flow->getId(), 'name' => $flow->getName()];
    }

    private function addHttpStep(Workspace $ws, array $args): array
    {
        $flow = $this->requireFlow($ws, (string) ($args['flowId'] ?? ''));
        $request = $this->requests->find((string) ($args['requestId'] ?? ''));
        if (null === $request || $request->getCollection()->getWorkspace()->getId()?->toRfc4122() !== $ws->getId()?->toRfc4122()) {
            throw new \InvalidArgumentException('İstek bulunamadı.');
        }

        $step = new FlowStep();
        $step->setFlow($flow);
        $step->setType(FlowStep::TYPE_HTTP);
        $step->setApiRequest($request);
        $step->copyRequestFrom($request);
        $step->setName((string) ($args['name'] ?? $request->getName()));
        $step->setPosition($this->nextPosition($flow));
        $step->setExtractions($this->parser->parseExtractions($this->joinLines($args['extractions'] ?? [])));
        $step->setAssertions($this->parser->parseAssertions($this->joinLines($args['assertions'] ?? [])));
        $this->steps->save($step);

        return ['stepId' => (string) $step->getId(), 'position' => $step->getPosition()];
    }

    private function addDbStep(Workspace $ws, array $args): array
    {
        $flow = $this->requireFlow($ws, (string) ($args['flowId'] ?? ''));
        $connection = $this->findConnection($ws, (string) ($args['connection'] ?? ''));

        $step = new FlowStep();
        $step->setFlow($flow);
        $step->setType(FlowStep::TYPE_DB);
        $step->setDbConnection($connection);
        $step->setQuery((string) ($args['query'] ?? ''));
        $step->setName((string) ($args['name'] ?? 'DB: ' . $connection->getName()));
        $step->setPosition($this->nextPosition($flow));
        $step->setExtractions($this->parser->parseExtractions($this->joinLines($args['extractions'] ?? [])));
        $step->setAssertions($this->parser->parseAssertions($this->joinLines($args['assertions'] ?? [])));
        $this->steps->save($step);

        return ['stepId' => (string) $step->getId(), 'position' => $step->getPosition()];
    }

    private function runFlow(Workspace $ws, array $args): array
    {
        $flow = $this->requireFlow($ws, (string) ($args['flowId'] ?? ''));
        if ($flow->getSteps()->isEmpty()) {
            throw new \InvalidArgumentException('Akışta adım yok.');
        }

        $environment = $flow->getDefaultEnvironment();
        if (!empty($args['environmentName'])) {
            $environment = $this->findEnvironmentByName($ws, (string) $args['environmentName']);
        }

        $run = $this->runner->run($flow, $environment, 'mcp', $this->scalarVars($args['variables'] ?? []));

        return $this->reporter->toArray($run);
    }

    private function runFlowAsync(Workspace $ws, array $args): array
    {
        $flow = $this->requireFlow($ws, (string) ($args['flowId'] ?? ''));
        if ($flow->getSteps()->isEmpty()) {
            throw new \InvalidArgumentException('Akışta adım yok.');
        }

        $environment = $flow->getDefaultEnvironment();
        if (!empty($args['environmentName'])) {
            $environment = $this->findEnvironmentByName($ws, (string) $args['environmentName']);
        }

        $run = $this->runner->createRun($flow, $environment, 'mcp', null, 0, []);
        $this->bus->dispatch(new RunFlowMessage(
            (string) $run->getId(),
            (string) $flow->getId(),
            $environment ? (string) $environment->getId() : null,
            $this->scalarVars($args['variables'] ?? []),
        ));

        return [
            'runId' => (string) $run->getId(),
            'status' => $run->getStatus(),
            'totalSteps' => $run->getTotalSteps(),
            'hint' => 'Arka planda başlatıldı. İlerleme için get_run veya list_runs kullanın.',
        ];
    }

    private function listRuns(Workspace $ws, array $args): array
    {
        $limit = max(1, min(50, (int) ($args['limit'] ?? 10)));
        if (!empty($args['flowId'])) {
            $flow = $this->requireFlow($ws, (string) $args['flowId']);
            $list = $this->runs->recentForFlow($flow, $limit);
        } else {
            $list = $this->runs->recentForWorkspace($ws, $limit);
        }

        $out = [];
        foreach ($list as $r) {
            $out[] = [
                'runId' => (string) $r->getId(),
                'flow' => $r->getFlow()->getName(),
                'status' => $r->getStatus(),
                'passed' => $r->getPassedSteps(),
                'total' => $r->getTotalSteps(),
                'durationMs' => $r->getDurationMs(),
                'trigger' => $r->getTrigger(),
                'createdAt' => $r->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ];
        }

        return ['runs' => $out];
    }

    private function getRun(Workspace $ws, array $args): array
    {
        $run = $this->runs->find((string) ($args['runId'] ?? ''));
        if (null === $run || $run->getFlow()->getWorkspace()->getId()?->toRfc4122() !== $ws->getId()?->toRfc4122()) {
            throw new \InvalidArgumentException('Koşum bulunamadı.');
        }

        return $this->reporter->toArray($run);
    }

    private function whoami(Workspace $ws): array
    {
        $merchant = $ws->getMerchant();

        return [
            'merchant' => ['id' => (string) $merchant->getId(), 'name' => $merchant->getName()],
            'workspace' => ['id' => (string) $ws->getId(), 'name' => $ws->getName(), 'slug' => $ws->getSlug()],
            'counts' => [
                'collections' => \count($this->collections->findByWorkspace($ws)),
                'flows' => \count($this->flows->findByWorkspace($ws)),
                'environments' => \count($this->environments->findByWorkspace($ws)),
                'dbConnections' => \count($this->dbConnections->findByWorkspace($ws)),
            ],
            'scope' => 'Tüm araçlar yalnızca bu merchant\'ın bu workspace\'i ile sınırlıdır; başka merchant/workspace verisine erişilemez.',
        ];
    }

    private function createFlowFromCollection(Workspace $ws, array $args): array
    {
        if (empty($args['collectionId']) || empty($args['name'])) {
            throw new \InvalidArgumentException('collectionId ve name zorunlu.');
        }

        $collection = $this->collections->find((string) $args['collectionId']);
        if (null === $collection || $collection->getWorkspace()->getId()?->toRfc4122() !== $ws->getId()?->toRfc4122()) {
            throw new \InvalidArgumentException('Collection bulunamadı.');
        }

        // Resolve the requests to include: explicit ordered ids, or all requests in collection order.
        $byId = [];
        foreach ($collection->getRequests() as $r) {
            $byId[(string) $r->getId()] = $r;
        }
        $requests = [];
        if (!empty($args['requestIds']) && \is_array($args['requestIds'])) {
            foreach ($args['requestIds'] as $rid) {
                $rid = (string) $rid;
                if (!isset($byId[$rid])) {
                    throw new \InvalidArgumentException("İstek bu collection'da değil: $rid");
                }
                $requests[] = $byId[$rid];
            }
        } else {
            $requests = array_values($byId);
        }
        if ([] === $requests) {
            throw new \InvalidArgumentException('Collection\'da eklenecek istek yok.');
        }

        $flow = new TestFlow();
        $flow->setWorkspace($ws);
        $flow->setName((string) $args['name']);
        $flow->setDescription(isset($args['description']) ? (string) $args['description'] : null);
        $flow->setStopOnFailure((bool) ($args['stopOnFailure'] ?? true));
        if (!empty($args['environmentName'])) {
            $flow->setDefaultEnvironment($this->findEnvironmentByName($ws, (string) $args['environmentName']));
        }
        $this->flows->save($flow);

        $pos = 0;
        $added = [];
        foreach ($requests as $request) {
            $step = new FlowStep();
            $step->setFlow($flow);
            $step->setType(FlowStep::TYPE_HTTP);
            $step->setApiRequest($request);
            $step->copyRequestFrom($request);
            $step->setName($request->getName());
            $step->setPosition($pos++);
            $this->steps->save($step, false);
            $added[] = ['name' => $request->getName(), 'method' => $request->getMethod()];
        }
        $this->flows->save($flow); // flush the queued steps in one go

        return [
            'flowId' => (string) $flow->getId(),
            'name' => $flow->getName(),
            'steps' => \count($added),
            'addedFrom' => $collection->getName(),
            'hint' => 'Adımlar HTTP isteği olarak eklendi. İstersen add_db_step ile DB doğrulaması, get_flow ile detay, run_flow ile çalıştır.',
        ];
    }

    private function addSetvarStep(Workspace $ws, array $args): array
    {
        $flow = $this->requireFlow($ws, (string) ($args['flowId'] ?? ''));
        $assignments = $this->joinLines($args['assignments'] ?? []);
        if ('' === trim($assignments)) {
            throw new \InvalidArgumentException('assignments boş olamaz.');
        }

        $step = new FlowStep();
        $step->setFlow($flow);
        $step->setType(FlowStep::TYPE_SETVAR);
        $step->setQuery($assignments);
        $step->setName((string) ($args['name'] ?? 'Değişken set'));
        $step->setPosition($this->nextPosition($flow));
        $this->steps->save($step);

        return ['stepId' => (string) $step->getId(), 'position' => $step->getPosition()];
    }

    private function addDelayStep(Workspace $ws, array $args): array
    {
        $flow = $this->requireFlow($ws, (string) ($args['flowId'] ?? ''));
        $ms = max(0, min(60000, (int) ($args['ms'] ?? 0)));

        $step = new FlowStep();
        $step->setFlow($flow);
        $step->setType(FlowStep::TYPE_DELAY);
        $step->setQuery((string) $ms);
        $step->setName((string) ($args['name'] ?? $ms . ' ms bekle'));
        $step->setPosition($this->nextPosition($flow));
        $this->steps->save($step);

        return ['stepId' => (string) $step->getId(), 'position' => $step->getPosition(), 'delayMs' => $ms];
    }

    private function getFlow(Workspace $ws, array $args): array
    {
        $flow = $this->requireFlow($ws, (string) ($args['flowId'] ?? ''));

        $steps = [];
        foreach ($flow->getSteps() as $s) {
            $entry = [
                'stepId' => (string) $s->getId(),
                'position' => $s->getPosition(),
                'type' => $s->getType(),
                'name' => $s->getName(),
            ];
            if ($s->isDb()) {
                $entry['connection'] = $s->getDbConnection()?->getName();
                $entry['query'] = $s->getQuery();
            } elseif ($s->getApiRequest()) {
                $entry['request'] = $s->getApiRequest()->getMethod() . ' ' . $s->getApiRequest()->getUrl();
            } else {
                $entry['query'] = $s->getQuery();
            }
            if (!$s->isSetvar() && !$s->isDelay()) {
                $entry['extractions'] = $s->getExtractions();
                $entry['assertions'] = $s->getAssertions();
                if ($s->isRetryEnabled()) {
                    $entry['retry'] = ['max' => $s->getRetryMax(), 'delayMs' => $s->getRetryDelayMs()];
                }
            }
            $steps[] = $entry;
        }

        return [
            'id' => (string) $flow->getId(),
            'name' => $flow->getName(),
            'description' => $flow->getDescription(),
            'defaultEnvironment' => $flow->getDefaultEnvironment()?->getName(),
            'stopOnFailure' => $flow->isStopOnFailure(),
            'steps' => $steps,
        ];
    }

    private function deleteFlow(Workspace $ws, array $args): array
    {
        $flow = $this->requireFlow($ws, (string) ($args['flowId'] ?? ''));
        $name = $flow->getName();
        $this->flows->remove($flow);

        return ['deleted' => true, 'flow' => $name];
    }

    private function deleteStep(Workspace $ws, array $args): array
    {
        $step = $this->steps->find((string) ($args['stepId'] ?? ''));
        if (null === $step || $step->getFlow()->getWorkspace()->getId()?->toRfc4122() !== $ws->getId()?->toRfc4122()) {
            throw new \InvalidArgumentException('Adım bulunamadı.');
        }
        $this->steps->remove($step);

        return ['deleted' => true];
    }

    // ---- helpers ----

    /**
     * Normalises a {name: value} arg into a flat array<string,string> for FlowRunner.
     *
     * @return array<string, string>
     */
    private function scalarVars(mixed $vars): array
    {
        if (!\is_array($vars)) {
            return [];
        }
        $out = [];
        foreach ($vars as $k => $v) {
            $out[(string) $k] = \is_scalar($v) ? (string) $v : (string) json_encode($v);
        }

        return $out;
    }

    private function requireFlow(Workspace $ws, string $id): TestFlow
    {
        $flow = $this->flows->find($id);
        if (null === $flow || $flow->getWorkspace()->getId()?->toRfc4122() !== $ws->getId()?->toRfc4122()) {
            throw new \InvalidArgumentException('Akış bulunamadı.');
        }

        return $flow;
    }

    private function findEnvironmentByName(Workspace $ws, string $name): ?Environment
    {
        foreach ($this->environments->findByWorkspace($ws) as $e) {
            if ($e->getName() === $name) {
                return $e;
            }
        }

        return null;
    }

    private function findConnection(Workspace $ws, string $ref): \App\Entity\DbConnection
    {
        // $ref may be a UUID or a connection name; only look up by id when it is a valid UUID.
        $conn = \Symfony\Component\Uid\Uuid::isValid($ref) ? $this->dbConnections->find($ref) : null;
        if (null === $conn) {
            foreach ($this->dbConnections->findByWorkspace($ws) as $c) {
                if ($c->getName() === $ref) {
                    $conn = $c;
                    break;
                }
            }
        }
        if (null === $conn || $conn->getWorkspace()->getId()?->toRfc4122() !== $ws->getId()?->toRfc4122()) {
            throw new \InvalidArgumentException('DB bağlantısı bulunamadı: ' . $ref);
        }

        return $conn;
    }

    private function nextPosition(TestFlow $flow): int
    {
        $max = -1;
        foreach ($flow->getSteps() as $s) {
            $max = max($max, $s->getPosition());
        }

        return $max + 1;
    }

    /**
     * @param mixed $lines string[] or string
     */
    private function joinLines(mixed $lines): string
    {
        if (\is_array($lines)) {
            return implode("\n", array_map('strval', $lines));
        }

        return (string) $lines;
    }
}
