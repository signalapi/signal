<?php

namespace App\Entity;

use App\Repository\EvaluationRunRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One execution of an Evaluation: the batch of flow runs it produced, and the
 * report computed from them.
 *
 * The report is stored rather than recomputed. Recomputing means loading every
 * step result of every run in the batch, which is fine once and ruinous on a
 * trend page showing ninety days of them. The headline numbers are also kept as
 * columns so the trend is a single query, with the full report (per-row detail
 * and the checks that did not always pass) left in JSON for the detail view.
 */
#[ORM\Entity(repositoryClass: EvaluationRunRepository::class)]
#[ORM\Table(name: 'evaluation_run')]
#[ORM\Index(name: 'idx_evaluation_run_eval', columns: ['evaluation_id', 'created_at'])]
class EvaluationRun
{
    public const STATUS_RUNNING = 'running';
    public const STATUS_DONE = 'done';
    public const STATUS_ERROR = 'error';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Evaluation::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Evaluation $evaluation;

    /** Ties this back to the FlowRuns it produced. */
    #[ORM\Column(length: 64)]
    private string $batchId;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_RUNNING;

    #[ORM\Column(length: 20)]
    private string $trigger = 'manual';

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $environmentName = null;

    /* --- the headline numbers, denormalised so a trend is one query --- */

    /** How often a SINGLE attempt passed: the number to quote about this flow. */
    #[ORM\Column(type: Types::FLOAT, options: ['default' => 0])]
    private float $passRate = 0.0;

    /** `rows` is a reserved word in more than one engine; the column spells it out. */
    #[ORM\Column(name: 'row_count', options: ['default' => 0])]
    private int $rows = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $repeats = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $runs = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $runsPassed = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $stablePassRows = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $flakyRows = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $stableFailRows = 0;

    /** The full EvalReport: per-row detail and the checks that did not always pass. */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $report = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    /** @var array<string, mixed>|null chosen notification targets for this run */
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

    /**
     * Copies the headline numbers out of a computed report and keeps the rest.
     *
     * @param array<string, mixed> $report
     */
    public function applyReport(array $report): static
    {
        $this->passRate = (float) ($report['passRate'] ?? 0);
        $this->rows = (int) ($report['rows'] ?? 0);
        $this->repeats = (int) ($report['repeats'] ?? 0);
        $this->runs = (int) ($report['runs'] ?? 0);
        $this->runsPassed = (int) ($report['runsPassed'] ?? 0);
        $this->stablePassRows = (int) ($report['stablePassRows'] ?? 0);
        $this->flakyRows = (int) ($report['flakyRows'] ?? 0);
        $this->stableFailRows = (int) ($report['stableFailRows'] ?? 0);
        $this->report = $report;

        return $this;
    }

    /** Rows that are neither reliably right nor reliably wrong plus those that are wrong. */
    public function isClean(): bool
    {
        return 0 === $this->flakyRows && 0 === $this->stableFailRows;
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getEvaluation(): Evaluation
    {
        return $this->evaluation;
    }

    public function setEvaluation(Evaluation $evaluation): static
    {
        $this->evaluation = $evaluation;

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

    public function getTrigger(): string
    {
        return $this->trigger;
    }

    public function setTrigger(string $trigger): static
    {
        $this->trigger = $trigger;

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

    public function getPassRate(): float
    {
        return $this->passRate;
    }

    public function getRows(): int
    {
        return $this->rows;
    }

    public function getRepeats(): int
    {
        return $this->repeats;
    }

    public function getRuns(): int
    {
        return $this->runs;
    }

    public function getRunsPassed(): int
    {
        return $this->runsPassed;
    }

    public function getStablePassRows(): int
    {
        return $this->stablePassRows;
    }

    public function getFlakyRows(): int
    {
        return $this->flakyRows;
    }

    public function getStableFailRows(): int
    {
        return $this->stableFailRows;
    }

    /** @return array<string, mixed>|null */
    public function getReport(): ?array
    {
        return $this->report;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function setError(?string $error): static
    {
        $this->error = $error;

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

        return (int) round(($this->finishedAt->format('U.u') - $this->createdAt->format('U.u')) * 1000);
    }
}
