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

    /**
     * A one-person account created by self-registration. Structurally identical
     * to a company — the whole model still hangs off a merchant — but the UI
     * hides the company vocabulary until the owner invites someone, at which
     * point the account is promoted to a team.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $personal = false;

    /** @var Collection<int, MerchantMember> */
    #[ORM\OneToMany(mappedBy: 'merchant', targetEntity: MerchantMember::class)]
    private Collection $members;

    /** @var Collection<int, Workspace> */
    #[ORM\OneToMany(mappedBy: 'merchant', targetEntity: Workspace::class, cascade: ['remove'])]
    private Collection $workspaces;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->members = new ArrayCollection();
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

    public function isPersonal(): bool
    {
        return $this->personal;
    }

    public function setPersonal(bool $personal): static
    {
        $this->personal = $personal;

        return $this;
    }

    /** Inviting anyone turns a personal account into a team account. */
    public function promoteToTeam(): static
    {
        $this->personal = false;

        return $this;
    }

    /** @return Collection<int, MerchantMember> */
    public function getMembers(): Collection
    {
        return $this->members;
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
