<?php

namespace App\Entity;

use App\Repository\EnvVariableRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: EnvVariableRepository::class)]
#[ORM\Table(name: 'env_variable')]
class EnvVariable
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Environment::class, inversedBy: 'variables')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Environment $environment = null;

    #[ORM\Column(length: 200)]
    private string $name = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $value = null;

    #[ORM\Column]
    private bool $secret = false;

    public function getId(): ?Uuid
    {
        return $this->id;
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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getValue(): ?string
    {
        return $this->value;
    }

    public function setValue(?string $value): static
    {
        $this->value = $value;

        return $this;
    }

    public function isSecret(): bool
    {
        return $this->secret;
    }

    public function setSecret(bool $secret): static
    {
        $this->secret = $secret;

        return $this;
    }
}
