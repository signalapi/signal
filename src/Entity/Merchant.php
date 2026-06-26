<?php

namespace App\Entity;

use App\Repository\MerchantRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: MerchantRepository::class)]
#[ORM\Table(name: 'merchant')]
#[ORM\UniqueConstraint(name: 'uniq_merchant_slug', columns: ['slug'])]
class Merchant
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

    #[ORM\Column]
    private bool $active = true;

    /** @var Collection<int, User> */
    #[ORM\OneToMany(mappedBy: 'merchant', targetEntity: User::class)]
    private Collection $users;

    /** @var Collection<int, Workspace> */
    #[ORM\OneToMany(mappedBy: 'merchant', targetEntity: Workspace::class, cascade: ['remove'])]
    private Collection $workspaces;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->users = new ArrayCollection();
        $this->workspaces = new ArrayCollection();
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

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    /** @return Collection<int, User> */
    public function getUsers(): Collection
    {
        return $this->users;
    }

    /** @return Collection<int, Workspace> */
    public function getWorkspaces(): Collection
    {
        return $this->workspaces;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
