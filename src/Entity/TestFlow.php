<?php

namespace App\Entity;

use App\Repository\TestFlowRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: TestFlowRepository::class)]
#[ORM\Table(name: 'test_flow')]
class TestFlow
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Workspace::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Workspace $workspace;

    #[ORM\Column(length: 200)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\ManyToOne(targetEntity: Environment::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Environment $defaultEnvironment = null;

    #[ORM\Column]
    private bool $stopOnFailure = true;

    #[ORM\Column]
    private bool $scheduleEnabled = false;

    /** Standard 5-field cron expression, evaluated by app:run-due-flows. */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $cronExpression = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastScheduledRunAt = null;

    /** Optional suite this flow belongs to (for running a group of flows together). */
    #[ORM\ManyToOne(targetEntity: FlowGroup::class, inversedBy: 'flows')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?FlowGroup $flowGroup = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $groupPosition = 0;

    /**
     * Visual builder connections: list of [fromStepId, toStepId] pairs. Execution
     * order (FlowStep.position) is derived from this chain on save.
     *
     * @var array<int, array{0: string, 1: string}>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $canvasEdges = [];

    /** @var Collection<int, FlowStep> */
    #[ORM\OneToMany(mappedBy: 'flow', targetEntity: FlowStep::class, cascade: ['remove'])]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $steps;

    /** @var Collection<int, FlowRun> */
    #[ORM\OneToMany(mappedBy: 'flow', targetEntity: FlowRun::class, cascade: ['remove'])]
    #[ORM\OrderBy(['createdAt' => 'DESC'])]
    private Collection $runs;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->steps = new ArrayCollection();
        $this->runs = new ArrayCollection();
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getDefaultEnvironment(): ?Environment
    {
        return $this->defaultEnvironment;
    }

    public function setDefaultEnvironment(?Environment $environment): static
    {
        $this->defaultEnvironment = $environment;

        return $this;
    }

    public function isStopOnFailure(): bool
    {
        return $this->stopOnFailure;
    }

    public function setStopOnFailure(bool $stopOnFailure): static
    {
        $this->stopOnFailure = $stopOnFailure;

        return $this;
    }

    public function isScheduleEnabled(): bool
    {
        return $this->scheduleEnabled;
    }

    public function setScheduleEnabled(bool $scheduleEnabled): static
    {
        $this->scheduleEnabled = $scheduleEnabled;

        return $this;
    }

    public function getCronExpression(): ?string
    {
        return $this->cronExpression;
    }

    public function setCronExpression(?string $cronExpression): static
    {
        $this->cronExpression = $cronExpression;

        return $this;
    }

    public function getLastScheduledRunAt(): ?\DateTimeImmutable
    {
        return $this->lastScheduledRunAt;
    }

    public function setLastScheduledRunAt(?\DateTimeImmutable $lastScheduledRunAt): static
    {
        $this->lastScheduledRunAt = $lastScheduledRunAt;

        return $this;
    }

    /** @return Collection<int, FlowStep> */
    public function getSteps(): Collection
    {
        return $this->steps;
    }

    public function getFlowGroup(): ?FlowGroup
    {
        return $this->flowGroup;
    }

    public function setFlowGroup(?FlowGroup $flowGroup): static
    {
        $this->flowGroup = $flowGroup;

        return $this;
    }

    public function getGroupPosition(): int
    {
        return $this->groupPosition;
    }

    public function setGroupPosition(int $groupPosition): static
    {
        $this->groupPosition = $groupPosition;

        return $this;
    }

    /** @return array<int, array{0: string, 1: string}> */
    public function getCanvasEdges(): array
    {
        return $this->canvasEdges;
    }

    /** @param array<int, array{0: string, 1: string}> $canvasEdges */
    public function setCanvasEdges(array $canvasEdges): static
    {
        $this->canvasEdges = $canvasEdges;

        return $this;
    }

    /** @return Collection<int, FlowRun> */
    public function getRuns(): Collection
    {
        return $this->runs;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
