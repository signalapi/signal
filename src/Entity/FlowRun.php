<?php

namespace App\Entity;

use App\Repository\FlowRunRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: FlowRunRepository::class)]
#[ORM\Table(name: 'flow_run')]
class FlowRun
{
    public const STATUS_RUNNING = 'running';
    public const STATUS_PASSED = 'passed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_ERROR = 'error';
    public const STATUS_CANCELLED = 'cancelled';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: TestFlow::class, inversedBy: 'runs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private TestFlow $flow;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $environmentName = null;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_RUNNING;

    #[ORM\Column(length: 20)]
    private string $trigger = 'manual';

    /**
     * Who set this run off. Null for scheduled runs, which have no actor.
     * Also decides whose personal cookie jar and environment values apply.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $triggeredBy = null;

    /** Groups iterations of one data-driven run; null for single runs. */
    #[ORM\Column(length: 36, nullable: true)]
    private ?string $batchId = null;

    #[ORM\Column]
    private int $iteration = 0;

    /** The dataset row (variables) used for this iteration. */
    #[ORM\Column(type: Types::JSON)]
    private array $iterationData = [];

    #[ORM\Column]
    private bool $cancelRequested = false;

    #[ORM\Column]
    private int $totalSteps = 0;

    #[ORM\Column]
    private int $passedSteps = 0;

    /** @var Collection<int, StepResult> */
    #[ORM\OneToMany(mappedBy: 'run', targetEntity: StepResult::class, cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $stepResults;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    /** Random token for a public, login-less report link (null = not shared). */
    #[ORM\Column(length: 64, unique: true, nullable: true)]
    private ?string $shareToken = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $shareExpiresAt = null;

    /**
     * Notification choice made when this run was started, overriding the
     * standing rules: {"mute": true} sends nothing, {"destinations": [...]} adds
     * those destinations on top of the rules.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $notifyOverride = null;

    public function __construct()
    {
        $this->stepResults = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getShareToken(): ?string
    {
        return $this->shareToken;
    }

    public function setShareToken(?string $shareToken): static
    {
        $this->shareToken = $shareToken;

        return $this;
    }

    public function getShareExpiresAt(): ?\DateTimeImmutable
    {
        return $this->shareExpiresAt;
    }

    public function setShareExpiresAt(?\DateTimeImmutable $shareExpiresAt): static
    {
        $this->shareExpiresAt = $shareExpiresAt;

        return $this;
    }

    public function isShareValid(): bool
    {
        return null !== $this->shareToken
            && (null === $this->shareExpiresAt || $this->shareExpiresAt > new \DateTimeImmutable());
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getFlow(): TestFlow
    {
        return $this->flow;
    }

    public function setFlow(TestFlow $flow): static
    {
        $this->flow = $flow;

        return $this;
    }

    public function getEnvironmentName(): ?string
    {
        return $this->environmentName;
    }

    public function setEnvironmentName(?string $environmentName): static
    {
        $this->environmentName = $environmentName;

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

    public function getTriggeredBy(): ?User
    {
        return $this->triggeredBy;
    }

    public function setTriggeredBy(?User $triggeredBy): static
    {
        $this->triggeredBy = $triggeredBy;

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

    public function getBatchId(): ?string
    {
        return $this->batchId;
    }

    public function setBatchId(?string $batchId): static
    {
        $this->batchId = $batchId;

        return $this;
    }

    public function getIteration(): int
    {
        return $this->iteration;
    }

    public function setIteration(int $iteration): static
    {
        $this->iteration = $iteration;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getIterationData(): array
    {
        return $this->iterationData;
    }

    /** @param array<string, mixed> $iterationData */
    public function setIterationData(array $iterationData): static
    {
        $this->iterationData = $iterationData;

        return $this;
    }

    public function isCancelRequested(): bool
    {
        return $this->cancelRequested;
    }

    public function setCancelRequested(bool $cancelRequested): static
    {
        $this->cancelRequested = $cancelRequested;

        return $this;
    }

    public function getTotalSteps(): int
    {
        return $this->totalSteps;
    }

    public function setTotalSteps(int $totalSteps): static
    {
        $this->totalSteps = $totalSteps;

        return $this;
    }

    public function getPassedSteps(): int
    {
        return $this->passedSteps;
    }

    public function setPassedSteps(int $passedSteps): static
    {
        $this->passedSteps = $passedSteps;

        return $this;
    }

    /** @return Collection<int, StepResult> */
    public function getStepResults(): Collection
    {
        return $this->stepResults;
    }

    public function addStepResult(StepResult $result): static
    {
        if (!$this->stepResults->contains($result)) {
            $this->stepResults->add($result);
            $result->setRun($this);
        }

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

    public function getDurationMs(): ?int
    {
        if (null === $this->finishedAt) {
            return null;
        }

        return (int) (($this->finishedAt->format('U.u') - $this->createdAt->format('U.u')) * 1000);
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
