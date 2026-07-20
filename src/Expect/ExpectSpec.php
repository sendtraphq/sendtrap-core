<?php

namespace Sendtrap\Core\Expect;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Sendtrap\Core\Extract\ExtractSpec;

/**
 * The fully-validated /expect request. Construction is the only place
 * request data is interpreted; everything downstream works with typed
 * values.
 */
final class ExpectSpec
{
    public const MAX_CONDITIONS = 20;

    /**
     * @param  list<Condition>  $match
     * @param  list<Condition>  $assert
     */
    private function __construct(
        public readonly array $match,
        public readonly array $assert,
        public readonly ?string $testId,
        public readonly ?CarbonImmutable $receivedAfter,
        public readonly ?CarbonImmutable $receivedBefore,
        public readonly ?int $afterMessageId,
        public readonly bool $unreadOnly,
        public readonly int $timeoutMs,
        public readonly ?int $atLeast,
        public readonly ?int $exactly,
        public readonly string $sort,
        public readonly bool $markRead,
        public readonly bool $strict,
        public readonly ?ExtractSpec $extract,
    ) {}

    /**
     * @param  array<string, mixed>  $input  the decoded request body
     *
     * @throws InvalidArgumentException with a user-facing message
     */
    public static function fromArray(array $input, int $maxTimeoutMs): self
    {
        $match = self::conditions($input['match'] ?? [], 'match');
        $assert = self::conditions($input['assert'] ?? [], 'assert');

        if ($match === [] && $assert === []) {
            throw new InvalidArgumentException('Provide at least one condition in "match" (or "assert").');
        }

        $scope = $input['scope'] ?? [];
        $wait = $input['wait'] ?? [];
        $count = $input['count'] ?? [];

        foreach ([['scope', $scope], ['wait', $wait], ['count', $count]] as [$key, $section]) {
            if (! is_array($section)) {
                throw new InvalidArgumentException("\"{$key}\" must be an object.");
            }
        }

        $timeoutMs = $wait['timeout_ms'] ?? 0;

        if (! is_int($timeoutMs) || $timeoutMs < 0) {
            throw new InvalidArgumentException('"wait.timeout_ms" must be a non-negative integer.');
        }

        $atLeast = $count['at_least'] ?? null;
        $exactly = $count['exactly'] ?? null;

        if ($atLeast !== null && $exactly !== null) {
            throw new InvalidArgumentException('"count.at_least" and "count.exactly" are mutually exclusive.');
        }

        // Counts are capped at the evaluator's candidate cap: it never loads
        // more than CANDIDATE_CAP messages, so a larger requirement could
        // never be verified (and "exactly" could falsely pass on a truncated
        // set).
        foreach ([['count.at_least', $atLeast], ['count.exactly', $exactly]] as [$key, $v]) {
            if ($v !== null && (! is_int($v) || $v < 1 || $v > ExpectEvaluator::CANDIDATE_CAP)) {
                throw new InvalidArgumentException("\"{$key}\" must be an integer between 1 and ".ExpectEvaluator::CANDIDATE_CAP.'.');
            }
        }

        $sort = $input['sort'] ?? 'newest';

        if (! in_array($sort, ['newest', 'oldest'], true)) {
            throw new InvalidArgumentException('"sort" must be "newest" or "oldest".');
        }

        $mode = $input['mode'] ?? 'report';

        if (! in_array($mode, ['report', 'strict'], true)) {
            throw new InvalidArgumentException('"mode" must be "report" or "strict".');
        }

        return new self(
            match: $match,
            assert: $assert,
            testId: self::optionalString($scope, 'test_id'),
            receivedAfter: self::optionalDate($scope, 'received_after'),
            receivedBefore: self::optionalDate($scope, 'received_before'),
            afterMessageId: self::optionalInt($scope, 'after_message_id'),
            unreadOnly: self::optionalBool($scope, 'unread_only', 'scope.unread_only'),
            timeoutMs: min($timeoutMs, $maxTimeoutMs),
            atLeast: $exactly === null ? ($atLeast ?? 1) : null,
            exactly: $exactly,
            sort: $sort,
            markRead: self::optionalBool($input, 'mark_read', 'mark_read'),
            strict: $mode === 'strict',
            extract: isset($input['extract']) ? ExtractSpec::fromArray($input['extract']) : null,
        );
    }

    public function requiredCount(): int
    {
        return $this->exactly ?? $this->atLeast;
    }

    /**
     * @return list<Condition>
     */
    private static function conditions(mixed $raw, string $key): array
    {
        if (! is_array($raw)) {
            throw new InvalidArgumentException("\"{$key}\" must be an array of conditions.");
        }

        if (count($raw) > self::MAX_CONDITIONS) {
            throw new InvalidArgumentException("\"{$key}\" is capped at ".self::MAX_CONDITIONS.' conditions.');
        }

        return array_values(array_map(function ($entry) use ($key) {
            if (! is_array($entry)) {
                throw new InvalidArgumentException("Every \"{$key}\" entry must be an object with field/op/value.");
            }

            return Condition::fromArray($entry);
        }, $raw));
    }

    private static function optionalString(array $scope, string $key): ?string
    {
        $v = $scope[$key] ?? null;

        if ($v !== null && (! is_string($v) || $v === '' || strlen($v) > 255)) {
            throw new InvalidArgumentException("\"scope.{$key}\" must be a non-empty string.");
        }

        return $v;
    }

    /**
     * A missing key is false; anything present — an explicit JSON null
     * included — must be a real boolean. Casting would turn "false" (any
     * non-empty string) into true.
     */
    private static function optionalBool(array $section, string $key, string $label): bool
    {
        if (! array_key_exists($key, $section)) {
            return false;
        }

        if (! is_bool($section[$key])) {
            throw new InvalidArgumentException("\"{$label}\" must be a boolean.");
        }

        return $section[$key];
    }

    private static function optionalInt(array $scope, string $key): ?int
    {
        $v = $scope[$key] ?? null;

        if ($v !== null && (! is_int($v) || $v < 0)) {
            throw new InvalidArgumentException("\"scope.{$key}\" must be a non-negative integer.");
        }

        return $v;
    }

    private static function optionalDate(array $scope, string $key): ?CarbonImmutable
    {
        $v = $scope[$key] ?? null;

        if ($v === null) {
            return null;
        }

        if (! is_string($v)) {
            throw new InvalidArgumentException("\"scope.{$key}\" must be an ISO 8601 datetime string.");
        }

        try {
            return CarbonImmutable::parse($v);
        } catch (\Throwable) {
            throw new InvalidArgumentException("\"scope.{$key}\" is not a parseable datetime.");
        }
    }
}
