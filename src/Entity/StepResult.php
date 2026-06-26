<?php

namespace App\Entity;

use App\Repository\StepResultRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: StepResultRepository::class)]
#[ORM\Table(name: 'step_result')]
class StepResult
{
    public const STATUS_PASSED = 'passed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_ERROR = 'error';
    public const STATUS_SKIPPED = 'skipped';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: FlowRun::class, inversedBy: 'stepResults')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private FlowRun $run;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column]
    private int $attempts = 1;

    #[ORM\Column(length: 200)]
    private string $label;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_PASSED;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $requestMethod = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $requestUrl = null;

    #[ORM\Column(nullable: true)]
    private ?int $responseStatus = null;

    #[ORM\Column(nullable: true)]
    private ?int $durationMs = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $responseBody = null;

    /** @var array<int, array<string, mixed>> */
    #[ORM\Column(type: Types::JSON)]
    private array $assertionResults = [];

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $extractedVars = [];

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getRun(): FlowRun
    {
        return $this->run;
    }

    public function setRun(FlowRun $run): static
    {
        $this->run = $run;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function setAttempts(int $attempts): static
    {
        $this->attempts = $attempts;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

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

    public function getRequestMethod(): ?string
    {
        return $this->requestMethod;
    }

    public function setRequestMethod(?string $requestMethod): static
    {
        $this->requestMethod = $requestMethod;

        return $this;
    }

    public function getRequestUrl(): ?string
    {
        return $this->requestUrl;
    }

    public function setRequestUrl(?string $requestUrl): static
    {
        $this->requestUrl = $requestUrl;

        return $this;
    }

    public function getResponseStatus(): ?int
    {
        return $this->responseStatus;
    }

    public function setResponseStatus(?int $responseStatus): static
    {
        $this->responseStatus = $responseStatus;

        return $this;
    }

    public function getDurationMs(): ?int
    {
        return $this->durationMs;
    }

    public function setDurationMs(?int $durationMs): static
    {
        $this->durationMs = $durationMs;

        return $this;
    }

    public function getResponseBody(): ?string
    {
        return $this->responseBody;
    }

    public function setResponseBody(?string $responseBody): static
    {
        $this->responseBody = $responseBody;

        return $this;
    }

    /** @return array<int, array<string, mixed>> */
    public function getAssertionResults(): array
    {
        return $this->assertionResults;
    }

    /** @param array<int, array<string, mixed>> $assertionResults */
    public function setAssertionResults(array $assertionResults): static
    {
        $this->assertionResults = $assertionResults;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getExtractedVars(): array
    {
        return $this->extractedVars;
    }

    /** @param array<string, mixed> $extractedVars */
    public function setExtractedVars(array $extractedVars): static
    {
        $this->extractedVars = $extractedVars;

        return $this;
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
}
