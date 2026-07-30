<?php

namespace App\Entity;

use App\Repository\InvitationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A pending invite to join a merchant. The plaintext token lives only in the
 * invite link; like ApiToken, only its SHA-256 hash is stored.
 */
#[ORM\Entity(repositoryClass: InvitationRepository::class)]
#[ORM\Table(name: 'invitation')]
#[ORM\UniqueConstraint(name: 'uniq_invitation_token', columns: ['token_hash'])]
#[ORM\Index(name: 'idx_invitation_merchant', columns: ['merchant_id'])]
class Invitation
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Merchant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Merchant $merchant;

    #[ORM\Column(length: 180)]
    private string $email;

    #[ORM\Column(length: 20)]
    private string $merchantRole = MerchantMember::ROLE_MEMBER;

    /**
     * Workspace access granted on accept: [{"workspace_id": "...", "role": "editor"}, ...].
     *
     * @var list<array{workspace_id: string, role: string}>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $workspaceGrants = [];

    #[ORM\Column(length: 64)]
    private string $tokenHash;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $invitedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $acceptedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->expiresAt = new \DateTimeImmutable('+7 days');
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getMerchant(): Merchant
    {
        return $this->merchant;
    }

    public function setMerchant(Merchant $merchant): static
    {
        $this->merchant = $merchant;

        return $this;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = mb_strtolower(trim($email));

        return $this;
    }

    public function getMerchantRole(): string
    {
        return $this->merchantRole;
    }

    public function setMerchantRole(string $merchantRole): static
    {
        if (!\in_array($merchantRole, [MerchantMember::ROLE_ADMIN, MerchantMember::ROLE_MEMBER], true)) {
            throw new \InvalidArgumentException(sprintf('Davetle verilemeyecek merchant rolü: "%s"', $merchantRole));
        }
        $this->merchantRole = $merchantRole;

        return $this;
    }

    /** @return list<array{workspace_id: string, role: string}> */
    public function getWorkspaceGrants(): array
    {
        return $this->workspaceGrants;
    }

    /** @param list<array{workspace_id: string, role: string}> $workspaceGrants */
    public function setWorkspaceGrants(array $workspaceGrants): static
    {
        $this->workspaceGrants = $workspaceGrants;

        return $this;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function setTokenHash(string $tokenHash): static
    {
        $this->tokenHash = $tokenHash;

        return $this;
    }

    public function getInvitedBy(): ?User
    {
        return $this->invitedBy;
    }

    public function setInvitedBy(?User $invitedBy): static
    {
        $this->invitedBy = $invitedBy;

        return $this;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getAcceptedAt(): ?\DateTimeImmutable
    {
        return $this->acceptedAt;
    }

    public function markAccepted(): static
    {
        $this->acceptedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isExpired(\DateTimeImmutable $now = new \DateTimeImmutable()): bool
    {
        return $this->expiresAt <= $now;
    }

    public function isPending(): bool
    {
        return null === $this->acceptedAt && !$this->isExpired();
    }
}
