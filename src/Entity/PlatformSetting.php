<?php

namespace App\Entity;

use App\Repository\PlatformSettingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A platform-wide key/value setting, editable from the admin panel. Secrets
 * (API keys) live in valueEncrypted, sealed with SecretCipher; the clear
 * `value` column then only carries a display hint ("sk-ant-…x4Kp"). Plain
 * settings use `value` alone. Environment variables stay as a fallback so a
 * self-hosted install can still be configured entirely from .env.
 */
#[ORM\Entity(repositoryClass: PlatformSettingRepository::class)]
#[ORM\Table(name: 'platform_setting')]
class PlatformSetting
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\Column(length: 100, unique: true)]
    private string $name = '';

    /** Plain value — or, for sealed settings, a short display hint. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $value = null;

    /** Sealed value (see SecretCipher); wins over `value` when present. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $valueEncrypted = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
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

    public function getValue(): ?string
    {
        return $this->value;
    }

    public function setValue(?string $value): static
    {
        $this->value = $value;
        $this->touch();

        return $this;
    }

    public function getValueEncrypted(): ?string
    {
        return $this->valueEncrypted;
    }

    public function setValueEncrypted(?string $valueEncrypted): static
    {
        $this->valueEncrypted = $valueEncrypted;
        $this->touch();

        return $this;
    }

    public function isSealed(): bool
    {
        return null !== $this->valueEncrypted && '' !== $this->valueEncrypted;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
