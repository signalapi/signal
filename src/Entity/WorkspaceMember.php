<?php

namespace App\Entity;

use App\Repository\WorkspaceMemberRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A user's explicit access to a single workspace. Merchant owners/admins have
 * implicit admin access to every workspace and need no row here; merchant
 * members only see the workspaces they are listed in.
 */
#[ORM\Entity(repositoryClass: WorkspaceMemberRepository::class)]
#[ORM\Table(name: 'workspace_member')]
#[ORM\UniqueConstraint(name: 'uniq_workspace_member', columns: ['workspace_id', 'user_id'])]
class WorkspaceMember
{
    public const ROLE_ADMIN = 'admin';
    public const ROLE_EDITOR = 'editor';
    public const ROLE_VIEWER = 'viewer';

    public const ROLES = [self::ROLE_ADMIN, self::ROLE_EDITOR, self::ROLE_VIEWER];

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Workspace::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Workspace $workspace;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 20)]
    private string $role = self::ROLE_EDITOR;

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

    public function getWorkspace(): Workspace
    {
        return $this->workspace;
    }

    public function setWorkspace(Workspace $workspace): static
    {
        $this->workspace = $workspace;

        return $this;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function setRole(string $role): static
    {
        if (!\in_array($role, self::ROLES, true)) {
            throw new \InvalidArgumentException(sprintf('Invalid workspace role: "%s"', $role));
        }
        $this->role = $role;

        return $this;
    }

    public function canEdit(): bool
    {
        return \in_array($this->role, [self::ROLE_ADMIN, self::ROLE_EDITOR], true);
    }

    public function isAdmin(): bool
    {
        return self::ROLE_ADMIN === $this->role;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
