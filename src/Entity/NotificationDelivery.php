<?php

namespace App\Entity;

use App\Repository\NotificationDeliveryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One attempt to deliver one run result to one destination. Kept as a row (with
 * the rendered payload) so the send happens outside the run, survives a retry,
 * and "why did Slack not get it?" is answerable from the UI.
 */
#[ORM\Entity(repositoryClass: NotificationDeliveryRepository::class)]
#[ORM\Table(name: 'notification_delivery')]
#[ORM\Index(name: 'idx_delivery_workspace_created', columns: ['workspace_id', 'created_at'])]
class NotificationDelivery
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    public const EVENT_FLOW_RUN = 'flow_run.finished';
    public const EVENT_SUITE_RUN = 'suite_run.finished';
    /** Manual "send a test message" from the destination list. */
    public const EVENT_TEST = 'test';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Workspace::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Workspace $workspace;

    #[ORM\ManyToOne(targetEntity: NotificationDestination::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?NotificationDestination $destination = null;

    /** Kept for the log even if the destination is deleted later. */
    #[ORM\Column(length: 120)]
    private string $destinationName = '';

    #[ORM\Column(length: 30)]
    private string $event = self::EVENT_FLOW_RUN;

    /** One-line summary shown in the delivery log. */
    #[ORM\Column(length: 190)]
    private string $subject = '';

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column]
    private int $attempts = 0;

    #[ORM\Column(nullable: true)]
    private ?int $responseCode = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    /**
     * The rendered run summary. The channel formats it at send time, so a retry
     * never has to re-read a run that may have been pruned meanwhile.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $payload = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

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

    public function getDestination(): ?NotificationDestination
    {
        return $this->destination;
    }

    public function setDestination(?NotificationDestination $destination): static
    {
        $this->destination = $destination;
        if (null !== $destination) {
            $this->destinationName = $destination->getName();
        }

        return $this;
    }

    public function getDestinationName(): string
    {
        return $this->destinationName;
    }

    public function setDestinationName(string $destinationName): static
    {
        $this->destinationName = $destinationName;

        return $this;
    }

    public function getEvent(): string
    {
        return $this->event;
    }

    public function setEvent(string $event): static
    {
        $this->event = $event;

        return $this;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function setSubject(string $subject): static
    {
        $this->subject = mb_substr($subject, 0, 190);

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

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function setAttempts(int $attempts): static
    {
        $this->attempts = $attempts;

        return $this;
    }

    public function getResponseCode(): ?int
    {
        return $this->responseCode;
    }

    public function setResponseCode(?int $responseCode): static
    {
        $this->responseCode = $responseCode;

        return $this;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function setError(?string $error): static
    {
        $this->error = null === $error ? null : mb_substr($error, 0, 2000);

        return $this;
    }

    /** @return array<string, mixed> */
    public function getPayload(): array
    {
        return $this->payload;
    }

    /** @param array<string, mixed> $payload */
    public function setPayload(array $payload): static
    {
        $this->payload = $payload;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function setSentAt(?\DateTimeImmutable $sentAt): static
    {
        $this->sentAt = $sentAt;

        return $this;
    }
}
