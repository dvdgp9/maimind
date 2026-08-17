<?php

declare(strict_types=1);

namespace MaiMind\Domain;

use DateTimeImmutable;

final readonly class User
{
    public function __construct(
        public int $id,
        public string $uid,
        public string $email,
        public ?string $displayName,
        public string $timezone,
        public string $locale,
        public string $status,
        public ?DateTimeImmutable $onboardedAt = null,
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id:          (int) $row['id'],
            uid:         (string) $row['uid'],
            email:       (string) $row['email'],
            displayName: $row['display_name'] !== null ? (string) $row['display_name'] : null,
            timezone:    (string) $row['timezone'],
            locale:      (string) $row['locale'],
            status:      (string) $row['status'],
            onboardedAt: $row['onboarded_at'] !== null
                ? new DateTimeImmutable((string) $row['onboarded_at'])
                : null,
        );
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function name(): string
    {
        return $this->displayName ?? strstr($this->email, '@', true) ?: $this->email;
    }
}
