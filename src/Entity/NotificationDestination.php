<?php

namespace App\Entity;

use App\Repository\NotificationDestinationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Where a run result can be delivered: a Slack incoming webhook, or any HTTP
 * endpoint (n8n, Zapier, an internal service). The URL is the credential, so it
 * is stored encrypted and never rendered back into the form.
 */
#[ORM\Entity(repositoryClass: NotificationDestinationRepository::class)]
#[ORM\Table(name: 'notification_destination')]
class NotificationDestination
{
    /** Slack incoming webhook: the channel is fixed on Slack's side by the URL. */
    public const TYPE_SLACK = 'slack_webhook';
    /** Plain HTTP POST of the run payload, optionally HMAC-signed. */
    public const TYPE_WEBHOOK = 'webhook';

    public const TYPES = [self::TYPE_SLACK, self::TYPE_WEBHOOK];

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Workspace::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Workspace $workspace;

    #[ORM\Column(length: 120)]
    private string $name = '';

    #[ORM\Column(length: 30)]
    private string $type = self::TYPE_SLACK;

    /** The webhook URL, encrypted at rest (see SecretCipher). */
    #[ORM\Column(type: Types::TEXT)]
    private string $urlEncrypted = '';

    /** Host of the URL, kept in clear so the UI can show where it points. */
    #[ORM\Column(length: 190)]
    private string $urlHost = '';

    /** HMAC secret for generic webhooks (X-Signal-Signature), encrypted. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $secretEncrypted = null;

    /** Free-text hint shown in lists: "#api-alerts", "n8n prod" … */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $label = null;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

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

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        if (!\in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown destination type: "%s"', $type));
        }
        $this->type = $type;

        return $this;
    }

    public function isSlack(): bool
    {
        return self::TYPE_SLACK === $this->type;
    }

    public function getUrlEncrypted(): string
    {
        return $this->urlEncrypted;
    }

    public function setUrlEncrypted(string $urlEncrypted): static
    {
        $this->urlEncrypted = $urlEncrypted;

        return $this;
    }

    public function getUrlHost(): string
    {
        return $this->urlHost;
    }

    public function setUrlHost(string $urlHost): static
    {
        $this->urlHost = $urlHost;

        return $this;
    }

    public function getSecretEncrypted(): ?string
    {
        return $this->secretEncrypted;
    }

    public function setSecretEncrypted(?string $secretEncrypted): static
    {
        $this->secretEncrypted = $secretEncrypted;

        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
