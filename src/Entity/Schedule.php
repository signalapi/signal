<?php

namespace App\Entity;

use App\Repository\ScheduleRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A recurring run of one flow or one suite.
 *
 * Scheduling used to live on TestFlow as a single cron string, which meant one
 * timing per flow and no way to schedule a suite at all. A schedule is its own
 * thing now: it points at a target, carries its own timezone, and holds a LIST
 * of rules — so "Mondays hourly, Tuesdays every two hours" is one schedule with
 * two rules rather than something you cannot express.
 *
 * Each rule compiles to a cron expression (see ScheduleCompiler); the smallest
 * unit is a minute, matching the once-a-minute scheduler tick.
 */
#[ORM\Entity(repositoryClass: ScheduleRepository::class)]
#[ORM\Table(name: 'schedule')]
#[ORM\Index(name: 'idx_schedule_workspace', columns: ['workspace_id'])]
class Schedule
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Workspace::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Workspace $workspace;

    #[ORM\Column(length: 150)]
    private string $name = '';

    #[ORM\Column]
    private bool $enabled = true;

    /** Exactly one of flow / flowGroup is set. */
    #[ORM\ManyToOne(targetEntity: TestFlow::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?TestFlow $flow = null;

    #[ORM\ManyToOne(targetEntity: FlowGroup::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?FlowGroup $flowGroup = null;

    /** Overrides the target's own environment when set. */
    #[ORM\ManyToOne(targetEntity: Environment::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Environment $environment = null;

    #[ORM\Column(length: 64)]
    private string $timezone = 'Europe/Istanbul';

    /**
     * @var list<array<string, mixed>> see ScheduleCompiler::normaliseRule()
     */
    #[ORM\Column(type: Types::JSON)]
    private array $rules = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastRunAt = null;

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

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): static
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function getFlow(): ?TestFlow
    {
        return $this->flow;
    }

    public function getFlowGroup(): ?FlowGroup
    {
        return $this->flowGroup;
    }

    /** Points the schedule at a flow, clearing any suite target. */
    public function setFlow(?TestFlow $flow): static
    {
        $this->flow = $flow;
        if (null !== $flow) {
            $this->flowGroup = null;
        }

        return $this;
    }

    /** Points the schedule at a suite, clearing any flow target. */
    public function setFlowGroup(?FlowGroup $group): static
    {
        $this->flowGroup = $group;
        if (null !== $group) {
            $this->flow = null;
        }

        return $this;
    }

    public function isSuite(): bool
    {
        return null !== $this->flowGroup;
    }

    /** The display name of whatever this schedule runs. */
    public function getTargetName(): string
    {
        return $this->flowGroup?->getName() ?? $this->flow?->getName() ?? '—';
    }

    public function hasTarget(): bool
    {
        return null !== $this->flow || null !== $this->flowGroup;
    }

    public function getEnvironment(): ?Environment
    {
        return $this->environment;
    }

    public function setEnvironment(?Environment $environment): static
    {
        $this->environment = $environment;

        return $this;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    public function setTimezone(string $timezone): static
    {
        $this->timezone = $timezone;

        return $this;
    }

    /** @return list<array<string, mixed>> */
    public function getRules(): array
    {
        return $this->rules;
    }

    /** @param list<array<string, mixed>> $rules */
    public function setRules(array $rules): static
    {
        $this->rules = array_values($rules);

        return $this;
    }

    public function getLastRunAt(): ?\DateTimeImmutable
    {
        return $this->lastRunAt;
    }

    public function setLastRunAt(?\DateTimeImmutable $at): static
    {
        $this->lastRunAt = $at;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
