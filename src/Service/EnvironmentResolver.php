<?php

namespace App\Service;

use App\Entity\Environment;
use App\Entity\EnvUserValue;
use App\Entity\User;
use App\Repository\EnvUserValueRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Resolves an environment to a flat variable map, applying the acting user's
 * personal overrides on top of the shared values.
 *
 * A null user means "no personal layer": automated flow runs and API/MCP
 * traffic see the shared values only.
 */
class EnvironmentResolver
{
    public function __construct(
        private readonly EnvUserValueRepository $userValues,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function map(?Environment $environment, ?User $user = null): array
    {
        if (null === $environment) {
            return [];
        }

        $map = $environment->toMap();
        if (null === $user) {
            return $map;
        }

        foreach ($this->userValues->mapFor($environment, $user) as $name => $value) {
            // Only override variables the environment actually declares, and
            // only when the user filled something in.
            if (\array_key_exists($name, $map) && '' !== $value) {
                $map[$name] = $value;
            }
        }

        return $map;
    }

    /**
     * Just the user's overrides for variables the environment declares — meant
     * to be merged into a flow run's extra vars (which win over the shared
     * environment), so a run carries the acting user's own credentials.
     *
     * @return array<string, string>
     */
    public function overridesFor(?Environment $environment, ?User $user): array
    {
        if (null === $environment || null === $user) {
            return [];
        }

        $declared = $environment->toMap();
        $out = [];
        foreach ($this->userValues->mapFor($environment, $user) as $name => $value) {
            if (\array_key_exists($name, $declared) && '' !== $value) {
                $out[$name] = $value;
            }
        }

        return $out;
    }

    /**
     * Writes the user's personal values for the given environment.
     * An empty value clears the override (falling back to the shared value).
     *
     * @param array<string, string> $values name => value
     */
    public function saveUserValues(Environment $environment, User $user, array $values): void
    {
        /** @var array<string, EnvUserValue> $existing */
        $existing = [];
        foreach ($this->userValues->findFor($environment, $user) as $row) {
            $existing[$row->getName()] = $row;
        }

        $declared = array_keys($environment->toMap());

        foreach ($values as $name => $value) {
            if (!\in_array($name, $declared, true)) {
                continue;
            }
            $value = trim($value);
            $row = $existing[$name] ?? null;

            if ('' === $value) {
                if (null !== $row) {
                    $this->em->remove($row);
                }
                continue;
            }

            if (null === $row) {
                $row = new EnvUserValue();
                $row->setEnvironment($environment);
                $row->setUser($user);
                $row->setName($name);
                $this->em->persist($row);
            }
            $row->setValue($value);
        }

        $this->em->flush();
    }
}
