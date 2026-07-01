<?php

namespace App\Entity;

use App\Repository\FlowStepRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: FlowStepRepository::class)]
#[ORM\Table(name: 'flow_step')]
class FlowStep
{
    public const TYPE_HTTP = 'http';
    public const TYPE_DB = 'db';
    public const TYPE_SETVAR = 'setvar';
    public const TYPE_DELAY = 'delay';
    public const TYPE_CALL = 'call';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: TestFlow::class, inversedBy: 'steps')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private TestFlow $flow;

    #[ORM\Column(length: 10)]
    private string $type = self::TYPE_HTTP;

    /**
     * The saved request this step executes (http steps). Nullable so deleting a
     * request leaves the step visible (and clearly broken) instead of vanishing.
     */
    #[ORM\ManyToOne(targetEntity: ApiRequest::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ApiRequest $apiRequest = null;

    /** The database this step queries (db steps). */
    #[ORM\ManyToOne(targetEntity: DbConnection::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?DbConnection $dbConnection = null;

    /**
     * The flow this step calls inline (call steps). Referenced, not copied:
     * the sub-flow's current steps run in the parent's run and share its
     * variable context. SET NULL so deleting the sub-flow leaves a broken
     * (but visible) call step rather than cascading.
     */
    #[ORM\ManyToOne(targetEntity: TestFlow::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?TestFlow $calledFlow = null;

    /** SQL / Redis command / Mongo JSON spec (db steps), with {{var}} support. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $query = null;

    #[ORM\Column(length: 200)]
    private string $name;

    #[ORM\Column]
    private int $position = 0;

    /** Retry the step until its assertions pass (or attempts run out). */
    #[ORM\Column]
    private bool $retryEnabled = false;

    #[ORM\Column]
    private int $retryMax = 5;

    #[ORM\Column]
    private int $retryDelayMs = 1000;

    /**
     * Each entry: {var: string, path: string} — extracts a value from the JSON
     * response body and stores it under {{var}} in the run context.
     *
     * @var array<int, array{var: string, path: string}>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $extractions = [];

    /**
     * Each entry: {kind: 'status'|'jsonpath'|'body', path?: string, op: string, expected?: string}.
     *
     * @var array<int, array<string, string>>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $assertions = [];

    /**
     * Optional run-if guard: {left, op, right}. Evaluated against the run
     * context before the step executes; if it fails, the step is skipped
     * (not failed). null = always run. Enables branching, e.g. call a
     * provider-specific sub-flow only when {{provider}} == Yuno.
     *
     * @var array{left: string, op: string, right: string}|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $condition = null;

    /**
     * Optional forEach loop: {over, as}. `over` resolves to a JSON array; the
     * step (or sub-flow) runs once per element with {{as}} bound to it
     * ({{as.field}} for objects, {{as_index}} for the index). null = run once.
     *
     * @var array{over: string, as: string}|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $loop = null;

    /**
     * Baseline response shape (keys + types) captured on the first successful
     * run; later runs diff against it to flag contract drift. null = not yet
     * captured / not tracked.
     *
     * @var array<string, mixed>|string|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private array|string|null $responseShape = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $contractBaselineAt = null;

    /** Node position on the visual flow canvas. */
    #[ORM\Column(options: ['default' => 0])]
    private int $canvasX = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $canvasY = 0;

    /*
     * Per-step HTTP request — a FLOW-OWNED copy taken when the step is created from a
     * collection request. Editing it changes only this flow's step, never the shared
     * collection request or other flows. The apiRequest link above is kept only as the
     * origin (for its saved example responses). Execution uses these fields.
     */
    #[ORM\Column(length: 10, options: ['default' => 'GET'])]
    private string $reqMethod = 'GET';

    #[ORM\Column(type: Types::TEXT, options: ['default' => ''])]
    private string $reqUrl = '';

    /** @var array<int, array{name: string, value: string}> */
    #[ORM\Column(type: Types::JSON)]
    private array $reqHeaders = [];

    /** @var array<int, array{name: string, value: string}> */
    #[ORM\Column(type: Types::JSON)]
    private array $reqParams = [];

    #[ORM\Column(length: 10, options: ['default' => 'none'])]
    private string $reqBodyMode = 'none';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $reqBody = null;

    /** @var array<string, string> */
    #[ORM\Column(type: Types::JSON)]
    private array $reqAuth = [];

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getReqMethod(): string { return $this->reqMethod; }
    public function setReqMethod(string $v): static { $this->reqMethod = $v; return $this; }
    public function getReqUrl(): string { return $this->reqUrl; }
    public function setReqUrl(string $v): static { $this->reqUrl = $v; return $this; }
    /** @return array<int, array{name: string, value: string}> */
    public function getReqHeaders(): array { return $this->reqHeaders; }
    /** @param array<int, array{name: string, value: string}> $v */
    public function setReqHeaders(array $v): static { $this->reqHeaders = $v; return $this; }
    /** @return array<int, array{name: string, value: string}> */
    public function getReqParams(): array { return $this->reqParams; }
    /** @param array<int, array{name: string, value: string}> $v */
    public function setReqParams(array $v): static { $this->reqParams = $v; return $this; }
    public function getReqBodyMode(): string { return $this->reqBodyMode; }
    public function setReqBodyMode(string $v): static { $this->reqBodyMode = $v; return $this; }
    public function getReqBody(): ?string { return $this->reqBody; }
    public function setReqBody(?string $v): static { $this->reqBody = $v; return $this; }
    /** @return array<string, string> */
    public function getReqAuth(): array { return $this->reqAuth; }
    /** @param array<string, string> $v */
    public function setReqAuth(array $v): static { $this->reqAuth = $v; return $this; }

    /**
     * Copies an origin request's fields into this step (flow-owned snapshot).
     */
    public function copyRequestFrom(ApiRequest $r): static
    {
        $this->reqMethod = $r->getMethod();
        $this->reqUrl = $r->getUrl();
        $this->reqHeaders = $r->getHeaders();
        $this->reqParams = $r->getQueryParams();
        $this->reqBodyMode = $r->getBodyMode();
        $this->reqBody = $r->getBody();
        $this->reqAuth = $r->getAuth();

        return $this;
    }

    /**
     * Builds a transient (unpersisted) ApiRequest from this step's own fields,
     * for the request runner to execute.
     */
    public function toTransientRequest(): ApiRequest
    {
        $r = new ApiRequest();
        $r->setName($this->name);
        $r->setMethod($this->reqMethod);
        $r->setUrl($this->reqUrl);
        $r->setHeaders($this->reqHeaders);
        $r->setQueryParams($this->reqParams);
        $r->setBodyMode($this->reqBodyMode);
        $r->setBody($this->reqBody);
        $r->setAuth($this->reqAuth);

        return $r;
    }

    public function getCanvasX(): int
    {
        return $this->canvasX;
    }

    public function setCanvasX(int $canvasX): static
    {
        $this->canvasX = $canvasX;

        return $this;
    }

    public function getCanvasY(): int
    {
        return $this->canvasY;
    }

    public function setCanvasY(int $canvasY): static
    {
        $this->canvasY = $canvasY;

        return $this;
    }

    public function getFlow(): TestFlow
    {
        return $this->flow;
    }

    public function setFlow(TestFlow $flow): static
    {
        $this->flow = $flow;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function isDb(): bool
    {
        return self::TYPE_DB === $this->type;
    }

    public function isSetvar(): bool
    {
        return self::TYPE_SETVAR === $this->type;
    }

    public function isDelay(): bool
    {
        return self::TYPE_DELAY === $this->type;
    }

    public function isCall(): bool
    {
        return self::TYPE_CALL === $this->type;
    }

    /** @return array{left: string, op: string, right: string}|null */
    public function getCondition(): ?array
    {
        return $this->condition;
    }

    /** @param array{left: string, op: string, right: string}|null $condition */
    public function setCondition(?array $condition): static
    {
        $this->condition = $condition;

        return $this;
    }

    public function hasCondition(): bool
    {
        return null !== $this->condition && '' !== trim((string) ($this->condition['left'] ?? ''));
    }

    /** @return array{over: string, as: string}|null */
    public function getLoop(): ?array
    {
        return $this->loop;
    }

    /** @param array{over: string, as: string}|null $loop */
    public function setLoop(?array $loop): static
    {
        $this->loop = $loop;

        return $this;
    }

    public function hasLoop(): bool
    {
        return null !== $this->loop && '' !== trim((string) ($this->loop['over'] ?? ''));
    }

    public function getResponseShape(): array|string|null
    {
        return $this->responseShape;
    }

    public function setResponseShape(array|string|null $shape): static
    {
        $this->responseShape = $shape;

        return $this;
    }

    public function getContractBaselineAt(): ?\DateTimeImmutable
    {
        return $this->contractBaselineAt;
    }

    public function setContractBaselineAt(?\DateTimeImmutable $at): static
    {
        $this->contractBaselineAt = $at;

        return $this;
    }

    public function getCalledFlow(): ?TestFlow
    {
        return $this->calledFlow;
    }

    public function setCalledFlow(?TestFlow $calledFlow): static
    {
        $this->calledFlow = $calledFlow;

        return $this;
    }

    public function getApiRequest(): ?ApiRequest
    {
        return $this->apiRequest;
    }

    public function setApiRequest(?ApiRequest $apiRequest): static
    {
        $this->apiRequest = $apiRequest;

        return $this;
    }

    public function getDbConnection(): ?DbConnection
    {
        return $this->dbConnection;
    }

    public function setDbConnection(?DbConnection $dbConnection): static
    {
        $this->dbConnection = $dbConnection;

        return $this;
    }

    public function getQuery(): ?string
    {
        return $this->query;
    }

    public function setQuery(?string $query): static
    {
        $this->query = $query;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function isRetryEnabled(): bool
    {
        return $this->retryEnabled;
    }

    public function setRetryEnabled(bool $retryEnabled): static
    {
        $this->retryEnabled = $retryEnabled;

        return $this;
    }

    public function getRetryMax(): int
    {
        return $this->retryMax;
    }

    public function setRetryMax(int $retryMax): static
    {
        $this->retryMax = $retryMax;

        return $this;
    }

    public function getRetryDelayMs(): int
    {
        return $this->retryDelayMs;
    }

    public function setRetryDelayMs(int $retryDelayMs): static
    {
        $this->retryDelayMs = $retryDelayMs;

        return $this;
    }

    /** @return array<int, array{var: string, path: string}> */
    public function getExtractions(): array
    {
        return $this->extractions;
    }

    /** @param array<int, array{var: string, path: string}> $extractions */
    public function setExtractions(array $extractions): static
    {
        $this->extractions = array_values($extractions);

        return $this;
    }

    /** @return array<int, array<string, string>> */
    public function getAssertions(): array
    {
        return $this->assertions;
    }

    /** @param array<int, array<string, string>> $assertions */
    public function setAssertions(array $assertions): static
    {
        $this->assertions = array_values($assertions);

        return $this;
    }
}
