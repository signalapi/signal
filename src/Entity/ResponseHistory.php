<?php

namespace App\Entity;

use App\Repository\ResponseHistoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ResponseHistoryRepository::class)]
#[ORM\Table(name: 'response_history')]
#[ORM\Index(name: 'idx_history_request', columns: ['api_request_id', 'created_at'])]
class ResponseHistory
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: ApiRequest::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ApiRequest $apiRequest;

    #[ORM\Column(length: 10)]
    private string $method = 'GET';

    #[ORM\Column(type: Types::TEXT)]
    private string $url = '';

    #[ORM\Column(nullable: true)]
    private ?int $statusCode = null;

    #[ORM\Column]
    private int $durationMs = 0;

    #[ORM\Column]
    private int $size = 0;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $environmentName = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $responseBody = null;

    /** @var array<string, string> */
    #[ORM\Column(type: Types::JSON)]
    private array $responseHeaders = [];

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

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

    public function getApiRequest(): ApiRequest
    {
        return $this->apiRequest;
    }

    public function setApiRequest(ApiRequest $apiRequest): static
    {
        $this->apiRequest = $apiRequest;

        return $this;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function setMethod(string $method): static
    {
        $this->method = $method;

        return $this;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): static
    {
        $this->url = $url;

        return $this;
    }

    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    public function setStatusCode(?int $statusCode): static
    {
        $this->statusCode = $statusCode;

        return $this;
    }

    public function getDurationMs(): int
    {
        return $this->durationMs;
    }

    public function setDurationMs(int $durationMs): static
    {
        $this->durationMs = $durationMs;

        return $this;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function setSize(int $size): static
    {
        $this->size = $size;

        return $this;
    }

    public function getEnvironmentName(): ?string
    {
        return $this->environmentName;
    }

    public function setEnvironmentName(?string $environmentName): static
    {
        $this->environmentName = $environmentName;

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

    /** @return array<string, string> */
    public function getResponseHeaders(): array
    {
        return $this->responseHeaders;
    }

    /** @param array<string, string> $responseHeaders */
    public function setResponseHeaders(array $responseHeaders): static
    {
        $this->responseHeaders = $responseHeaders;

        return $this;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function setError(?string $error): static
    {
        $this->error = $error;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
