<?php

namespace App\Entity;

use App\Repository\ApiRequestRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ApiRequestRepository::class)]
#[ORM\Table(name: 'api_request')]
class ApiRequest
{
    public const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: ApiCollection::class, inversedBy: 'requests')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ApiCollection $collection;

    #[ORM\ManyToOne(targetEntity: Folder::class, inversedBy: 'requests')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Folder $folder = null;

    #[ORM\Column(length: 200)]
    private string $name;

    #[ORM\Column(length: 10)]
    private string $method = 'GET';

    #[ORM\Column(type: Types::TEXT)]
    private string $url = '';

    /**
     * List of {name, value} header pairs.
     *
     * @var array<int, array{name: string, value: string}>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $headers = [];

    /**
     * List of {name, value} query parameter pairs.
     *
     * @var array<int, array{name: string, value: string}>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $queryParams = [];

    /** none | raw | json | form */
    #[ORM\Column(length: 10)]
    private string $bodyMode = 'none';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $body = null;

    /**
     * Auth config: {type: none|bearer|basic|apikey, token?, username?, password?, key?, value?, addTo?: header|query}.
     *
     * @var array<string, string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $auth = [];

    #[ORM\Column]
    private int $position = 0;

    /**
     * Upstream identity of an imported request (OpenAPI operationId or
     * "method path"). The anchor for three-way diffs when the source spec
     * publishes a new version; null for hand-made requests.
     */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $originKey = null;

    /**
     * SHA-256 of the request state as it was imported. Comparing it against
     * the current state answers "did we change it locally?" and against a new
     * spec's hash "did upstream change it?".
     */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $originHash = null;

    /**
     * Saved example responses (Postman-style), ordered by position.
     *
     * @var Collection<int, ResponseExample>
     */
    #[ORM\OneToMany(mappedBy: 'apiRequest', targetEntity: ResponseExample::class, cascade: ['remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $examples;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->examples = new ArrayCollection();
    }

    /** @return Collection<int, ResponseExample> */
    public function getExamples(): Collection
    {
        return $this->examples;
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getCollection(): ApiCollection
    {
        return $this->collection;
    }

    public function setCollection(ApiCollection $collection): static
    {
        $this->collection = $collection;

        return $this;
    }

    public function getFolder(): ?Folder
    {
        return $this->folder;
    }

    public function setFolder(?Folder $folder): static
    {
        $this->folder = $folder;

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

    public function getMethod(): string
    {
        return $this->method;
    }

    public function setMethod(string $method): static
    {
        $this->method = strtoupper($method);

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

    /** @return array<int, array{name: string, value: string}> */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /** @param array<int, array{name: string, value: string}> $headers */
    public function setHeaders(array $headers): static
    {
        $this->headers = array_values($headers);

        return $this;
    }

    /** @return array<int, array{name: string, value: string}> */
    public function getQueryParams(): array
    {
        return $this->queryParams;
    }

    /** @param array<int, array{name: string, value: string}> $queryParams */
    public function setQueryParams(array $queryParams): static
    {
        $this->queryParams = array_values($queryParams);

        return $this;
    }

    public function getBodyMode(): string
    {
        return $this->bodyMode;
    }

    public function setBodyMode(string $bodyMode): static
    {
        $this->bodyMode = $bodyMode;

        return $this;
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function setBody(?string $body): static
    {
        $this->body = $body;

        return $this;
    }

    /** @return array<string, string> */
    public function getAuth(): array
    {
        return $this->auth;
    }

    /** @param array<string, string> $auth */
    public function setAuth(array $auth): static
    {
        $this->auth = $auth;

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

    public function getOriginKey(): ?string
    {
        return $this->originKey;
    }

    public function setOriginKey(?string $originKey): static
    {
        $this->originKey = $originKey;

        return $this;
    }

    public function getOriginHash(): ?string
    {
        return $this->originHash;
    }

    public function setOriginHash(?string $originHash): static
    {
        $this->originHash = $originHash;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
