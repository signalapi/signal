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
 * An API offered in the marketplace. The marketplace itself is a platform-level
 * catalog that lives above companies: an entry is either curated by the platform
 * (no owner) or published by a company, and its reach is set by $visibility.
 * The spec lives in immutable CatalogApiVersion snapshots.
 */
#[ORM\Entity(repositoryClass: CatalogApiRepository::class)]
#[ORM\Table(name: 'catalog_api')]
#[ORM\UniqueConstraint(name: 'uniq_catalog_api_slug', columns: ['slug'])]
#[ORM\Index(name: 'idx_catalog_visibility', columns: ['visibility'])]
class CatalogApi
{
    /** Everyone on the platform can see and import it. */
    public const VISIBILITY_PUBLIC = 'public';
    /** Only members of the owning company. */
    public const VISIBILITY_MERCHANT = 'merchant';
    /** Only people with access to the owning workspace. */
    public const VISIBILITY_WORKSPACE = 'workspace';

    public const VISIBILITIES = [self::VISIBILITY_PUBLIC, self::VISIBILITY_MERCHANT, self::VISIBILITY_WORKSPACE];

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

    #[ORM\Column(length: 20, options: ['default' => self::VISIBILITY_PUBLIC])]
    private string $visibility = self::VISIBILITY_PUBLIC;

    /**
     * Platform-curated entries are verified by definition; company-published
     * public entries start unverified and a super admin promotes them.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $verified = false;

    /** Null for platform-curated entries. */
    #[ORM\ManyToOne(targetEntity: Merchant::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Merchant $ownerMerchant = null;

    /** Set only when $visibility is workspace-scoped. */
    #[ORM\ManyToOne(targetEntity: Workspace::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Workspace $ownerWorkspace = null;

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

    public function getVisibility(): string
    {
        return $this->visibility;
    }

    public function setVisibility(string $visibility): static
    {
        if (!\in_array($visibility, self::VISIBILITIES, true)) {
            throw new \InvalidArgumentException(sprintf('Invalid catalog visibility: "%s"', $visibility));
        }
        $this->visibility = $visibility;

        return $this;
    }

    public function isVerified(): bool
    {
        return $this->verified;
    }

    public function setVerified(bool $verified): static
    {
        $this->verified = $verified;

        return $this;
    }

    public function getOwnerMerchant(): ?Merchant
    {
        return $this->ownerMerchant;
    }

    public function setOwnerMerchant(?Merchant $ownerMerchant): static
    {
        $this->ownerMerchant = $ownerMerchant;

        return $this;
    }

    public function getOwnerWorkspace(): ?Workspace
    {
        return $this->ownerWorkspace;
    }

    public function setOwnerWorkspace(?Workspace $ownerWorkspace): static
    {
        $this->ownerWorkspace = $ownerWorkspace;

        return $this;
    }

    /** Curated by the platform rather than published by a company. */
    public function isPlatformCurated(): bool
    {
        return null === $this->ownerMerchant;
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
