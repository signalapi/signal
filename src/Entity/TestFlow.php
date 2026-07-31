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

    /** Standard 5-field cron expression, evaluated by app:run-due-flows. */

    /**
     * Suite memberships (a flow can belong to many suites, each with its own order).
     *
     * @var Collection<int, FlowGroupItem>
     */
    #[ORM\OneToMany(mappedBy: 'flow', targetEntity: FlowGroupItem::class, cascade: ['remove'])]
    private Collection $groupItems;

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
        $this->groupItems = new ArrayCollection();
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

    /** @return Collection<int, FlowStep> */
    public function getSteps(): Collection
    {
        return $this->steps;
    }

    /** @return Collection<int, FlowGroupItem> */
    public function getGroupItems(): Collection
    {
        return $this->groupItems;
    }

    /**
     * The suites this flow belongs to.
     *
     * @return FlowGroup[]
     */
    public function getGroups(): array
    {
        return array_map(static fn (FlowGroupItem $i): FlowGroup => $i->getFlowGroup(), $this->groupItems->toArray());
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
