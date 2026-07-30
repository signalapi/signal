<?php

namespace App\Entity;

use App\Repository\MerchantMemberRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A user's membership in a merchant. Replaces the old single `user.merchant_id`
 * link so one account can belong to several merchants with a per-merchant role.
 */
#[ORM\Entity(repositoryClass: MerchantMemberRepository::class)]
#[ORM\Table(name: 'merchant_member')]
#[ORM\UniqueConstraint(name: 'uniq_merchant_member', columns: ['merchant_id', 'user_id'])]
class MerchantMember
{
    /** Full control incl. deleting the merchant; exactly one per merchant, transferable. */
    public const ROLE_OWNER = 'owner';
    public const ROLE_ADMIN = 'admin';
    public const ROLE_MEMBER = 'member';

    public const ROLES = [self::ROLE_OWNER, self::ROLE_ADMIN, self::ROLE_MEMBER];

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Merchant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Merchant $merchant;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 20)]
    private string $role = self::ROLE_MEMBER;

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

    public function getMerchant(): Merchant
    {
        return $this->merchant;
    }

    public function setMerchant(Merchant $merchant): static
    {
        $this->merchant = $merchant;

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
            throw new \InvalidArgumentException(sprintf('Geçersiz merchant rolü: "%s"', $role));
        }
        $this->role = $role;

        return $this;
    }

    public function isOwner(): bool
    {
        return self::ROLE_OWNER === $this->role;
    }

    /** Owners and admins manage members and see every workspace of the merchant. */
    public function canManage(): bool
    {
        return \in_array($this->role, [self::ROLE_OWNER, self::ROLE_ADMIN], true);
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
