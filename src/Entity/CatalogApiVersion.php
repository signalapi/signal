<?php

namespace App\Entity;

use App\Repository\CatalogApiVersionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One immutable published snapshot of a catalog API's spec. Never edited after
 * creation — a new publish is a new row. This immutability is what makes
 * "imported from version X, is there something newer?" answerable forever.
 */
#[ORM\Entity(repositoryClass: CatalogApiVersionRepository::class)]
#[ORM\Table(name: 'catalog_api_version')]
#[ORM\Index(name: 'idx_catalog_version_api', columns: ['catalog_api_id', 'created_at'])]
class CatalogApiVersion
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: CatalogApi::class, inversedBy: 'versions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private CatalogApi $catalogApi;

    /** Human label: "2026-08", "v3.1" — whatever the publisher uses. */
    #[ORM\Column(length: 50)]
    private string $label;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $changelog = null;

    /** @var array<string, mixed> The full decoded OpenAPI document. */
    #[ORM\Column(type: Types::JSON)]
    private array $spec = [];

    /** SHA-256 of the canonical spec JSON — quick "did anything change?" check. */
    #[ORM\Column(length: 64)]
    private string $specHash;

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

    public function getCatalogApi(): CatalogApi
    {
        return $this->catalogApi;
    }

    public function setCatalogApi(CatalogApi $catalogApi): static
    {
        $this->catalogApi = $catalogApi;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getChangelog(): ?string
    {
        return $this->changelog;
    }

    public function setChangelog(?string $changelog): static
    {
        $this->changelog = $changelog;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getSpec(): array
    {
        return $this->spec;
    }

    /** @param array<string, mixed> $spec */
    public function setSpec(array $spec): static
    {
        $this->spec = $spec;
        $this->specHash = hash('sha256', (string) json_encode($spec));

        return $this;
    }

    public function getSpecHash(): string
    {
        return $this->specHash;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
