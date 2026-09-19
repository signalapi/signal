<?php

namespace App\Entity;

use App\Repository\EvaluationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A saved measurement of a flow whose result is not the same every time: the
 * flow, the rows to feed it, and how many times to repeat each one.
 *
 * A suite answers "does this still pass?". An evaluation answers "how often?" —
 * so it has to be a stored thing rather than a one-off run, or the number can
 * never be compared with last week's. Every run of it keeps its report
 * (EvaluationRun), and the sequence of those reports is the trend.
 */
#[ORM\Entity(repositoryClass: EvaluationRepository::class)]
#[ORM\Table(name: 'evaluation')]
#[ORM\Index(name: 'idx_evaluation_workspace', columns: ['workspace_id'])]
#[ORM\Index(name: 'idx_evaluation_flow', columns: ['flow_id'])]
class Evaluation
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Workspace::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Workspace $workspace;

    /** Deleting the flow takes its evaluations with it: they have nothing to run. */
    #[ORM\ManyToOne(targetEntity: TestFlow::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private TestFlow $flow;

    #[ORM\Column(length: 150)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /**
     * The rows fed to the flow, one run per row per repeat. Each row is a map of
     * {{variable}} names to values, exactly as a data-driven run takes them.
     *
     * @var array<int, array<string, mixed>>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $dataset = [];

    /** How many times each row runs. 1 is a plain data-driven run, not a measurement. */
    #[ORM\Column(options: ['default' => 1])]
    private int $repeats = 1;

    #[ORM\ManyToOne(targetEntity: Environment::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Environment $environment = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    /** Runs this evaluation will schedule: every row, every repeat. */
    public function plannedRuns(): int
    {
        return \count($this->dataset) * max(1, $this->repeats);
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

    public function getFlow(): TestFlow
    {
        return $this->flow;
    }

    public function setFlow(TestFlow $flow): static
    {
        $this->flow = $flow;

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

    /** @return array<int, array<string, mixed>> */
    public function getDataset(): array
    {
        return $this->dataset;
    }

    /** @param array<int, array<string, mixed>> $dataset */
    public function setDataset(array $dataset): static
    {
        $this->dataset = array_values($dataset);

        return $this;
    }

    public function getRepeats(): int
    {
        return $this->repeats;
    }

    public function setRepeats(int $repeats): static
    {
        $this->repeats = $repeats;

        return $this;
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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
