<?php

namespace App\Entity;

use App\Repository\FolderRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: FolderRepository::class)]
#[ORM\Table(name: 'folder')]
class Folder
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: ApiCollection::class, inversedBy: 'folders')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ApiCollection $collection;

    #[ORM\ManyToOne(targetEntity: Folder::class, inversedBy: 'children')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Folder $parent = null;

    #[ORM\Column(length: 200)]
    private string $name;

    #[ORM\Column]
    private int $position = 0;

    /** @var Collection<int, Folder> */
    #[ORM\OneToMany(mappedBy: 'parent', targetEntity: Folder::class)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $children;

    /** @var Collection<int, ApiRequest> */
    #[ORM\OneToMany(mappedBy: 'folder', targetEntity: ApiRequest::class)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $requests;

    public function __construct()
    {
        $this->children = new ArrayCollection();
        $this->requests = new ArrayCollection();
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

    public function getParent(): ?Folder
    {
        return $this->parent;
    }

    public function setParent(?Folder $parent): static
    {
        $this->parent = $parent;

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

    /** @return Collection<int, Folder> */
    public function getChildren(): Collection
    {
        return $this->children;
    }

    /** @return Collection<int, ApiRequest> */
    public function getRequests(): Collection
    {
        return $this->requests;
    }
}
