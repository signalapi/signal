<?php

namespace App\Entity;

use App\Repository\McpServerRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * An MCP server the workspace tests against, and the catalogue of tools it
 * publishes.
 *
 * An MCP server is a catalogue in the same sense a collection is — better, in
 * fact, because tools/list returns every tool's input schema, so the arguments
 * of a step can be offered rather than typed from memory. Without this a step
 * carried the URL, the auth header and the tool name inline, repeated for every
 * step, with nothing anywhere to say which servers a workspace even tests.
 *
 * url and headers may contain {{variables}} on purpose, and no secret is sealed
 * on the row: one definition then works across environments, and the bearer
 * token stays in the environment variable where per-environment secrets already
 * live and are already masked. The alternative — a token on the server — would
 * force a separate server row per environment.
 */
#[ORM\Entity(repositoryClass: McpServerRepository::class)]
#[ORM\Table(name: 'mcp_server')]
#[ORM\Index(name: 'idx_mcp_server_workspace', columns: ['workspace_id'])]
class McpServer
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
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** Endpoint, e.g. https://host/mcp — {{variables}} resolve at run time. */
    #[ORM\Column(type: Types::TEXT)]
    private string $url = '';

    /**
     * Sent with every request; values resolve {{variables}}, which is how the
     * bearer token gets in without being stored here.
     *
     * @var array<string, string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $headers = [];

    /**
     * The last tools/list result, verbatim — name, description and inputSchema
     * per tool. Cached rather than fetched on demand because the step editor
     * needs it while the server may be unreachable from the browser's side of
     * the network, and because a catalogue that changed is itself worth seeing.
     *
     * @var array<int, array<string, mixed>>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $tools = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $toolsRefreshedAt = null;

    /** Why the last refresh failed; null when the last one worked. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $refreshError = null;

    /** Reported by the server's own handshake, for the listing. */
    #[ORM\Column(length: 150, nullable: true)]
    private ?string $serverName = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $protocolVersion = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    /** @return array<string, mixed>|null the catalogued tool, by name */
    public function tool(string $name): ?array
    {
        foreach ($this->tools as $tool) {
            if (($tool['name'] ?? null) === $name) {
                return $tool;
            }
        }

        return null;
    }

    /** @return string[] */
    public function toolNames(): array
    {
        return array_values(array_filter(array_map(
            static fn (array $t): string => (string) ($t['name'] ?? ''),
            $this->tools,
        )));
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

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): static
    {
        $this->url = $url;

        return $this;
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /** @param array<string, string> $headers */
    public function setHeaders(array $headers): static
    {
        $this->headers = $headers;

        return $this;
    }

    /** @return array<int, array<string, mixed>> */
    public function getTools(): array
    {
        return $this->tools;
    }

    /** @param array<int, array<string, mixed>> $tools */
    public function setTools(array $tools): static
    {
        $this->tools = array_values($tools);

        return $this;
    }

    public function getToolsRefreshedAt(): ?\DateTimeImmutable
    {
        return $this->toolsRefreshedAt;
    }

    public function setToolsRefreshedAt(?\DateTimeImmutable $at): static
    {
        $this->toolsRefreshedAt = $at;

        return $this;
    }

    public function getRefreshError(): ?string
    {
        return $this->refreshError;
    }

    public function setRefreshError(?string $error): static
    {
        $this->refreshError = $error;

        return $this;
    }

    public function getServerName(): ?string
    {
        return $this->serverName;
    }

    public function setServerName(?string $serverName): static
    {
        $this->serverName = $serverName;

        return $this;
    }

    public function getProtocolVersion(): ?string
    {
        return $this->protocolVersion;
    }

    public function setProtocolVersion(?string $v): static
    {
        $this->protocolVersion = $v;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
