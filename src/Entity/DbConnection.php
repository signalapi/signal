<?php

namespace App\Entity;

use App\Repository\DbConnectionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: DbConnectionRepository::class)]
#[ORM\Table(name: 'db_connection')]
class DbConnection
{
    public const TYPE_POSTGRES = 'postgres';
    public const TYPE_MYSQL = 'mysql';
    public const TYPE_REDIS = 'redis';
    public const TYPE_MONGO = 'mongo';

    public const TYPES = [self::TYPE_POSTGRES, self::TYPE_MYSQL, self::TYPE_REDIS, self::TYPE_MONGO];

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Workspace::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Workspace $workspace;

    #[ORM\Column(length: 150)]
    private string $name;

    #[ORM\Column(length: 20)]
    private string $type = self::TYPE_POSTGRES;

    #[ORM\Column(length: 255)]
    private string $host = '';

    #[ORM\Column]
    private int $port = 5432;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $databaseName = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $username = null;

    /** Base64 libsodium ciphertext; never exposed in plaintext to the UI. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $passwordEncrypted = null;

    /** Extra options, e.g. {"authSource":"admin","ssl":false}. */
    #[ORM\Column(type: Types::JSON)]
    private array $options = [];

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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function setHost(string $host): static
    {
        $this->host = $host;

        return $this;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function setPort(int $port): static
    {
        $this->port = $port;

        return $this;
    }

    public function getDatabaseName(): ?string
    {
        return $this->databaseName;
    }

    public function setDatabaseName(?string $databaseName): static
    {
        $this->databaseName = $databaseName;

        return $this;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(?string $username): static
    {
        $this->username = $username;

        return $this;
    }

    public function getPasswordEncrypted(): ?string
    {
        return $this->passwordEncrypted;
    }

    public function setPasswordEncrypted(?string $passwordEncrypted): static
    {
        $this->passwordEncrypted = $passwordEncrypted;

        return $this;
    }

    public function hasPassword(): bool
    {
        return null !== $this->passwordEncrypted && '' !== $this->passwordEncrypted;
    }

    /** @return array<string, mixed> */
    public function getOptions(): array
    {
        return $this->options;
    }

    /** @param array<string, mixed> $options */
    public function setOptions(array $options): static
    {
        $this->options = $options;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
