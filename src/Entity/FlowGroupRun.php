<?php

namespace App\Entity;

use App\Repository\FlowGroupRunRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One execution of a flow group (suite). Created the moment the user hits "run"
 * (status=running) so the run is immediately visible and revisitable; the worker
 * sets the final status. Child FlowRuns are tagged with the same batchId.
 */
#[ORM\Entity(repositoryClass: FlowGroupRunRepository::class)]
#[ORM\Table(name: 'flow_group_run')]
#[ORM\Index(name: 'idx_group_run_group', columns: ['flow_group_id', 'created_at'])]
class FlowGroupRun
{
    public const STATUS_RUNNING = 'running';
    public const STATUS_PASSED = 'passed';
    public const STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: FlowGroup::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private FlowGroup $flowGroup;

    #[ORM\Column(length: 64)]
    private string $batchId;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_RUNNING;

    #[ORM\Column]
    private int $total = 0;

    /** How this suite run was started: manual | schedule | mcp | api. */
    #[ORM\Column(length: 20)]
    private string $trigger = 'manual';

    /**
     * Notification choice made at start time; same shape as FlowRun's.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $notifyOverride = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getFlowGroup(): FlowGroup
    {
        return $this->flowGroup;
    }

    public function setFlowGroup(FlowGroup $flowGroup): static
    {
        $this->flowGroup = $flowGroup;

        return $this;
    }

    public function getBatchId(): string
    {
        return $this->batchId;
    }

    public function setBatchId(string $batchId): static
    {
        $this->batchId = $batchId;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    public function setTotal(int $total): static
    {
        $this->total = $total;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function setFinishedAt(?\DateTimeImmutable $finishedAt): static
    {
        $this->finishedAt = $finishedAt;

        return $this;
    }

    public function getTrigger(): string
    {
        return $this->trigger;
    }

    public function setTrigger(string $trigger): static
    {
        $this->trigger = $trigger;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getNotifyOverride(): ?array
    {
        return $this->notifyOverride;
    }

    /** @param array<string, mixed>|null $notifyOverride */
    public function setNotifyOverride(?array $notifyOverride): static
    {
        $this->notifyOverride = $notifyOverride;

        return $this;
    }
}
