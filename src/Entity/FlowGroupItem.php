<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Membership of a flow in a suite, with an order. A flow can belong to many
 * suites (each membership is one row), and its position is per-suite.
 */
#[ORM\Entity]
#[ORM\Table(name: 'flow_group_item')]
#[ORM\UniqueConstraint(name: 'uniq_group_flow', columns: ['flow_group_id', 'flow_id'])]
class FlowGroupItem
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: FlowGroup::class, inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private FlowGroup $flowGroup;

    #[ORM\ManyToOne(targetEntity: TestFlow::class, inversedBy: 'groupItems')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private TestFlow $flow;

    #[ORM\Column]
    private int $position = 0;

    public function __construct(FlowGroup $flowGroup, TestFlow $flow, int $position = 0)
    {
        $this->flowGroup = $flowGroup;
        $this->flow = $flow;
        $this->position = $position;
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getFlowGroup(): FlowGroup
    {
        return $this->flowGroup;
    }

    public function getFlow(): TestFlow
    {
        return $this->flow;
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
}
