<?php

namespace App\Entity;

use App\Repository\DataFactoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A workspace-managed, named data generator invoked as {{$name}} in any
 * request/query/assertion. Fresh value per occurrence — like the built-in
 * dynamic variables, but user-defined (e.g. a test-card factory, a provider
 * picker, a templated email).
 */
#[ORM\Entity(repositoryClass: DataFactoryRepository::class)]
#[ORM\Table(name: 'data_factory')]
#[ORM\UniqueConstraint(name: 'uniq_factory_ws_name', columns: ['workspace_id', 'name'])]
class DataFactory
{
    public const KIND_ONE_OF = 'oneOf';
    public const KIND_TEMPLATE = 'template';
    public const KIND_INT_RANGE = 'intRange';
    public const KIND_PATTERN = 'pattern';

    public const KINDS = [self::KIND_ONE_OF, self::KIND_TEMPLATE, self::KIND_INT_RANGE, self::KIND_PATTERN];

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Workspace::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Workspace $workspace;

    /** The token name; used as {{$name}}. Letters, digits, _, . only. */
    #[ORM\Column(length: 80)]
    private string $name;

    #[ORM\Column(length: 20)]
    private string $kind = self::KIND_ONE_OF;

    /** Kind-specific config: oneOf{values[]}, template{template}, intRange{min,max}, pattern{pattern}. */
    #[ORM\Column(type: Types::JSON)]
    private array $config = [];

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

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

    public function getKind(): string
    {
        return $this->kind;
    }

    public function setKind(string $kind): static
    {
        $this->kind = $kind;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getConfig(): array
    {
        return $this->config;
    }

    /** @param array<string, mixed> $config */
    public function setConfig(array $config): static
    {
        $this->config = $config;

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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
