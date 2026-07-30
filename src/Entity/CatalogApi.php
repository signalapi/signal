<?php

namespace App\Entity;

use App\Repository\CatalogApiRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A publicly offered API in the marketplace catalog. Global — belongs to no
 * merchant; curated from the super-admin panel. The spec itself lives in
 * immutable CatalogApiVersion snapshots.
 */
#[ORM\Entity(repositoryClass: CatalogApiRepository::class)]
#[ORM\Table(name: 'catalog_api')]
#[ORM\UniqueConstraint(name: 'uniq_catalog_api_slug', columns: ['slug'])]
class CatalogApi
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\Column(length: 150)]
    private string $name;

    #[ORM\Column(length: 150)]
    private string $slug;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $publisher = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $category = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** A single emoji shown on the marketplace card. */
    #[ORM\Column(length: 16, nullable: true)]
    private ?string $logo = null;

    #[ORM\Column]
    private bool $active = true;

    /** @var Collection<int, CatalogApiVersion> */
    #[ORM\OneToMany(mappedBy: 'catalogApi', targetEntity: CatalogApiVersion::class, cascade: ['remove'])]
    #[ORM\OrderBy(['createdAt' => 'DESC'])]
    private Collection $versions;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->versions = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
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

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function getPublisher(): ?string
    {
        return $this->publisher;
    }

    public function setPublisher(?string $publisher): static
    {
        $this->publisher = $publisher;

        return $this;
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(?string $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getLogo(): ?string
    {
        return $this->logo;
    }

    public function setLogo(?string $logo): static
    {
        $this->logo = $logo;

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

    /** @return Collection<int, CatalogApiVersion> */
    public function getVersions(): Collection
    {
        return $this->versions;
    }

    public function getLatestVersion(): ?CatalogApiVersion
    {
        return $this->versions->first() ?: null;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
