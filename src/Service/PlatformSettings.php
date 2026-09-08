<?php

namespace App\Service;

use App\Entity\PlatformSetting;
use App\Repository\PlatformSettingRepository;

/**
 * Read/write access to platform-wide settings with transparent sealing.
 * get() returns the effective value (decrypted when sealed); hint() returns
 * only the clear column, which for sealed settings is a safe display hint.
 */
final class PlatformSettings
{
    public const AI_API_KEY = 'ai.anthropic_api_key';
    public const AI_MODEL = 'ai.model';

    public function __construct(
        private readonly PlatformSettingRepository $repository,
        private readonly SecretCipher $cipher,
    ) {
    }

    public function get(string $name): ?string
    {
        $row = $this->repository->findOneByName($name);
        if (null === $row) {
            return null;
        }
        if ($row->isSealed()) {
            $clear = $this->cipher->decrypt((string) $row->getValueEncrypted());

            return '' !== $clear ? $clear : null;
        }

        return $row->getValue();
    }

    /** The clear column only — for sealed settings, the display hint. */
    public function hint(string $name): ?string
    {
        return $this->repository->findOneByName($name)?->getValue();
    }

    public function has(string $name): bool
    {
        return null !== $this->repository->findOneByName($name);
    }

    /** Stores a plain value; null or '' removes the row entirely. */
    public function set(string $name, ?string $value): void
    {
        if (null === $value || '' === trim($value)) {
            $this->remove($name);

            return;
        }
        $row = $this->repository->findOneByName($name) ?? (new PlatformSetting())->setName($name);
        $row->setValue(trim($value));
        $row->setValueEncrypted(null);
        $this->repository->save($row);
    }

    /** Seals the value; the clear column keeps only the display hint. */
    public function setSecret(string $name, string $plaintext, ?string $hint = null): void
    {
        $row = $this->repository->findOneByName($name) ?? (new PlatformSetting())->setName($name);
        $row->setValueEncrypted($this->cipher->encrypt($plaintext));
        $row->setValue($hint);
        $this->repository->save($row);
    }

    public function remove(string $name): void
    {
        $row = $this->repository->findOneByName($name);
        if (null !== $row) {
            $this->repository->remove($row);
        }
    }
}
