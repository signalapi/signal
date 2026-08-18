<?php

namespace App\Entity;

use App\Repository\NotificationSubscriptionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A standing rule: "when a run in this scope finishes, tell that destination".
 * Scheduled runs carry their own destination list on the Schedule, and a single
 * run can override everything at start time — see FlowRun::$notifyOverride.
 */
#[ORM\Entity(repositoryClass: NotificationSubscriptionRepository::class)]
#[ORM\Table(name: 'notification_subscription')]
class NotificationSubscription
{
    /** Every automated run of the workspace. */
    public const SCOPE_WORKSPACE = 'workspace';
    /** One flow. */
    public const SCOPE_FLOW = 'flow';
    /** One suite (FlowGroup). */
    public const SCOPE_SUITE = 'suite';

    public const SCOPES = [self::SCOPE_WORKSPACE, self::SCOPE_FLOW, self::SCOPE_SUITE];

    public const WHEN_ALWAYS = 'always';
    public const WHEN_FAILURE = 'on_failure';

    public const CONDITIONS = [self::WHEN_ALWAYS, self::WHEN_FAILURE];

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Workspace::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Workspace $workspace;

    #[ORM\ManyToOne(targetEntity: NotificationDestination::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private NotificationDestination $destination;

    #[ORM\Column(length: 20)]
    private string $scopeType = self::SCOPE_WORKSPACE;

    /** Flow or suite id for the narrower scopes; null for workspace scope. */
    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    private ?Uuid $scopeId = null;

    #[ORM\Column(length: 20)]
    private string $condition = self::WHEN_FAILURE;

    #[ORM\Column]
    private bool $enabled = true;

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

    public function getDestination(): NotificationDestination
    {
        return $this->destination;
    }

    public function setDestination(NotificationDestination $destination): static
    {
        $this->destination = $destination;

        return $this;
    }

    public function getScopeType(): string
    {
        return $this->scopeType;
    }

    public function setScopeType(string $scopeType): static
    {
        if (!\in_array($scopeType, self::SCOPES, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown subscription scope: "%s"', $scopeType));
        }
        $this->scopeType = $scopeType;

        return $this;
    }

    public function getScopeId(): ?Uuid
    {
        return $this->scopeId;
    }

    public function setScopeId(?Uuid $scopeId): static
    {
        $this->scopeId = $scopeId;

        return $this;
    }

    public function getCondition(): string
    {
        return $this->condition;
    }

    public function setCondition(string $condition): static
    {
        if (!\in_array($condition, self::CONDITIONS, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown notification condition: "%s"', $condition));
        }
        $this->condition = $condition;

        return $this;
    }

    /** Does this rule want to hear about a run that ended with $status? */
    public function wants(string $status): bool
    {
        return self::WHEN_ALWAYS === $this->condition || FlowRun::STATUS_PASSED !== $status;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): static
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
