<?php

namespace App\Entity;

use App\Repository\ApiCollectionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ApiCollectionRepository::class)]
#[ORM\Table(name: 'api_collection')]
class ApiCollection
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Workspace::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Workspace $workspace;

    #[ORM\Column(length: 200)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** Where this collection came from: 'postman' | 'openapi' | 'catalog' | null (hand-made). */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $sourceType = null;

    /** The exact catalog snapshot this was imported from — enables "update available". */
    #[ORM\ManyToOne(targetEntity: CatalogApiVersion::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CatalogApiVersion $sourceVersion = null;

    /** @var Collection<int, Folder> */
    #[ORM\OneToMany(mappedBy: 'collection', targetEntity: Folder::class, cascade: ['remove'])]
    private Collection $folders;

    /** @var Collection<int, ApiRequest> */
    #[ORM\OneToMany(mappedBy: 'collection', targetEntity: ApiRequest::class, cascade: ['remove'])]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $requests;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->folders = new ArrayCollection();
        $this->requests = new ArrayCollection();
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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

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

    public function getSourceType(): ?string
    {
        return $this->sourceType;
    }

    public function setSourceType(?string $sourceType): static
    {
        $this->sourceType = $sourceType;

        return $this;
    }

    public function getSourceVersion(): ?CatalogApiVersion
    {
        return $this->sourceVersion;
    }

    public function setSourceVersion(?CatalogApiVersion $sourceVersion): static
    {
        $this->sourceVersion = $sourceVersion;

        return $this;
    }

    /** @return Collection<int, Folder> */
    public function getFolders(): Collection
    {
        return $this->folders;
    }

    /** @return Collection<int, ApiRequest> */
    public function getRequests(): Collection
    {
        return $this->requests;
    }

    /**
     * Root-level folders (no parent), for tree rendering.
     *
     * @return Folder[]
     */
    public function getRootFolders(): array
    {
        return $this->folders->filter(fn (Folder $f) => null === $f->getParent())->getValues();
    }

    /**
     * Root-level requests (not inside any folder).
     *
     * @return ApiRequest[]
     */
    public function getRootRequests(): array
    {
        return $this->requests->filter(fn (ApiRequest $r) => null === $r->getFolder())->getValues();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
