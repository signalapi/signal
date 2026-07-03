<?php

namespace App\Entity;

use App\Repository\FlowGroupRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A named, ordered collection of test flows that can be run together, one after
 * another (a "suite").
 */
#[ORM\Entity(repositoryClass: FlowGroupRepository::class)]
#[ORM\Table(name: 'flow_group')]
class FlowGroup
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

    /** @var Collection<int, FlowGroupItem> */
    #[ORM\OneToMany(mappedBy: 'flowGroup', targetEntity: FlowGroupItem::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $items;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->items = new ArrayCollection();
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

    /** @return Collection<int, FlowGroupItem> */
    public function getItems(): Collection
    {
        return $this->items;
    }

    /**
     * The flows in this suite, in order (derived from the membership items).
     *
     * @return Collection<int, TestFlow>
     */
    public function getFlows(): Collection
    {
        return new ArrayCollection(array_map(
            static fn (FlowGroupItem $i): TestFlow => $i->getFlow(),
            $this->items->toArray(),
        ));
    }

    public function hasFlow(TestFlow $flow): bool
    {
        foreach ($this->items as $item) {
            if ($item->getFlow() === $flow) {
                return true;
            }
        }

        return false;
    }

    public function addFlow(TestFlow $flow, ?int $position = null): static
    {
        if ($this->hasFlow($flow)) {
            return $this;
        }
        $pos = $position ?? $this->items->count();
        $this->items->add(new FlowGroupItem($this, $flow, $pos));

        return $this;
    }

    public function removeFlow(TestFlow $flow): static
    {
        foreach ($this->items as $item) {
            if ($item->getFlow() === $flow) {
                $this->items->removeElement($item);
            }
        }

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
