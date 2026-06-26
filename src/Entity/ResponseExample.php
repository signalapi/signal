<?php

namespace App\Entity;

use App\Repository\ResponseExampleRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A named, saved example response for a request (Postman-style: "Success Response",
 * "Fail Response"). Unlike ResponseHistory (auto-pruned time-series), examples persist:
 * they document what a request returns and feed value-mapping in flows.
 */
#[ORM\Entity(repositoryClass: ResponseExampleRepository::class)]
#[ORM\Table(name: 'response_example')]
#[ORM\Index(name: 'idx_example_request', columns: ['api_request_id', 'position'])]
class ResponseExample
{
    public const SOURCE_AUTO = 'auto';        // captured automatically on first send per status
    public const SOURCE_MANUAL = 'manual';    // user saved a response as an example
    public const SOURCE_IMPORTED = 'imported'; // came from a Postman collection

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: ApiRequest::class, inversedBy: 'examples')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ApiRequest $apiRequest;

    #[ORM\Column(length: 150)]
    private string $name = 'Örnek';

    #[ORM\Column(length: 12)]
    private string $source = self::SOURCE_MANUAL;

    #[ORM\Column(nullable: true)]
    private ?int $statusCode = null;

    #[ORM\Column(length: 10)]
    private string $method = 'GET';

    #[ORM\Column(type: Types::TEXT)]
    private string $url = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $responseBody = null;

    /** @var array<string, string> */
    #[ORM\Column(type: Types::JSON)]
    private array $responseHeaders = [];

    #[ORM\Column]
    private int $position = 0;

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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): static
    {
        $this->source = $source;

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

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isSuccess(): bool
    {
        return null !== $this->statusCode && $this->statusCode >= 200 && $this->statusCode < 300;
    }
}
