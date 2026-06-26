<?php

namespace App\Entity;

use App\Repository\EnvironmentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: EnvironmentRepository::class)]
#[ORM\Table(name: 'environment')]
class Environment
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

    /** @var Collection<int, EnvVariable> */
    #[ORM\OneToMany(mappedBy: 'environment', targetEntity: EnvVariable::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $variables;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->variables = new ArrayCollection();
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

    /** @return Collection<int, EnvVariable> */
    public function getVariables(): Collection
    {
        return $this->variables;
    }

    public function addVariable(EnvVariable $variable): static
    {
        if (!$this->variables->contains($variable)) {
            $this->variables->add($variable);
            $variable->setEnvironment($this);
        }

        return $this;
    }

    public function removeVariable(EnvVariable $variable): static
    {
        $this->variables->removeElement($variable);

        return $this;
    }

    /**
     * Flat key => value map for variable resolution.
     *
     * @return array<string, string>
     */
    public function toMap(): array
    {
        $map = [];
        foreach ($this->variables as $variable) {
            $map[$variable->getName()] = $variable->getValue() ?? '';
        }

        return $map;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
