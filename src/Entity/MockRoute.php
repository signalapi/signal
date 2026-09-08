<?php

namespace App\Entity;

use App\Repository\MockRouteRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One stubbed endpoint of the workspace mock server: "{method} {path} answers
 * {status} with {body}". Served publicly under /mock/{workspace mock token}/…
 * so flows (and the systems under test) can call it like any real API — which
 * is the point: cut the external dependency, keep the test deterministic.
 */
#[ORM\Entity(repositoryClass: MockRouteRepository::class)]
#[ORM\Table(name: 'mock_route')]
class MockRoute
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Workspace::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Workspace $workspace;

    #[ORM\Column(length: 10)]
    private string $method = 'GET';

    /** Normalised with a leading slash; a trailing "/*" matches any deeper path. */
    #[ORM\Column(length: 500)]
    private string $path = '/';

    #[ORM\Column]
    private int $responseStatus = 200;

    #[ORM\Column(length: 100)]
    private string $contentType = 'application/json';

    /** May contain {{$guid}}-style generators, resolved per hit. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $responseBody = null;

    /** Simulated latency, capped at 30s at serve time. */
    #[ORM\Column]
    private int $delayMs = 0;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column]
    private int $hits = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastHitAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getWorkspace(): Workspace
    {
        return $this->workspace;
    }

    public function setWorkspace(Workspace $workspace): static
    {
        $this->workspace = $workspace;

        return $this;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function setMethod(string $method): static
    {
        $this->method = strtoupper($method);

        return $this;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function setPath(string $path): static
    {
        $this->path = '/' . ltrim(trim($path), '/');

        return $this;
    }

    public function getResponseStatus(): int
    {
        return $this->responseStatus;
    }

    public function setResponseStatus(int $responseStatus): static
    {
        $this->responseStatus = max(100, min(599, $responseStatus));

        return $this;
    }

    public function getContentType(): string
    {
        return $this->contentType;
    }

    public function setContentType(string $contentType): static
    {
        $this->contentType = $contentType;

        return $this;
    }

    public function getResponseBody(): ?string
    {
        return $this->responseBody;
    }

    public function setResponseBody(?string $responseBody): static
    {
        $this->responseBody = $responseBody;

        return $this;
    }

    public function getDelayMs(): int
    {
        return $this->delayMs;
    }

    public function setDelayMs(int $delayMs): static
    {
        $this->delayMs = max(0, min(30000, $delayMs));

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function getHits(): int
    {
        return $this->hits;
    }

    public function recordHit(): static
    {
        ++$this->hits;
        $this->lastHitAt = new \DateTimeImmutable();

        return $this;
    }

    public function getLastHitAt(): ?\DateTimeImmutable
    {
        return $this->lastHitAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Exact match, or a trailing "/*" prefix match. */
    public function matches(string $method, string $path): bool
    {
        if ($this->method !== strtoupper($method)) {
            return false;
        }
        if (str_ends_with($this->path, '/*')) {
            $prefix = substr($this->path, 0, -1); // keep the slash: "/users/"

            return str_starts_with($path, $prefix) || $path === rtrim($prefix, '/');
        }

        return $this->path === $path;
    }
}
