<?php

namespace Sendtrap\Core\Storage;

/**
 * The result of a StorageQuota::reserve()/beginRemoval() call: an admission
 * decision plus, when the implementation tracks per-operation state, an
 * opaque reservation token scoped to the workspace's counter.
 *
 * $scope is implementation-defined (Cloud stores the team id so commit()/
 * release() can address the right Redis keys without re-resolving the
 * workspace); callers must treat it as opaque.
 */
final class StorageReservation
{
    private function __construct(
        public readonly StorageAdmission $status,
        public readonly int|string|null $scope = null,
        public readonly ?string $token = null,
        public readonly int $reservedBytes = 0,
    ) {}

    public static function allowed(int|string|null $scope = null, ?string $token = null, int $reservedBytes = 0): self
    {
        return new self(StorageAdmission::Allowed, $scope, $token, $reservedBytes);
    }

    public static function blocked(): self
    {
        return new self(StorageAdmission::Blocked);
    }

    public static function unlimited(): self
    {
        return new self(StorageAdmission::Unlimited);
    }

    public static function retry(): self
    {
        return new self(StorageAdmission::Retry);
    }

    /** The caller may proceed (allowed or unlimited). */
    public function isAllowed(): bool
    {
        return $this->status === StorageAdmission::Allowed
            || $this->status === StorageAdmission::Unlimited;
    }

    public function isBlocked(): bool
    {
        return $this->status === StorageAdmission::Blocked;
    }

    public function isUnlimited(): bool
    {
        return $this->status === StorageAdmission::Unlimited;
    }

    public function shouldRetry(): bool
    {
        return $this->status === StorageAdmission::Retry;
    }

    /**
     * Whether this reservation is backed by a tracked operation that
     * commit()/release() must finalize.
     */
    public function accountable(): bool
    {
        return $this->token !== null;
    }
}
