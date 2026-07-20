<?php

namespace Sendtrap\Core\Expect;

use InvalidArgumentException;
use Sendtrap\Core\Models\Message;
use Sendtrap\Core\Support\SafeRegex;

/**
 * One validated condition: field, operator, value. Immutable; constructed
 * through fromArray() which enforces the field/operator/value type matrix,
 * so evaluation can trust its inputs completely (no request values ever
 * reach a query or regex unchecked).
 */
final class Condition
{
    public const OPERATORS = [
        'equals', 'contains', 'starts_with', 'ends_with', 'matches',
        'exists', 'gt', 'gte', 'lt', 'lte',
    ];

    private const BY_TYPE = [
        'string' => ['equals', 'contains', 'starts_with', 'ends_with', 'matches', 'exists'],
        'list' => ['equals', 'contains', 'starts_with', 'ends_with', 'matches', 'exists'],
        'number' => ['equals', 'gt', 'gte', 'lt', 'lte', 'exists'],
        'bool' => ['equals', 'exists'],
    ];

    private const MAX_VALUE_LENGTH = 1024;

    private function __construct(
        public readonly FieldRef $field,
        public readonly string $operator,
        public readonly mixed $value,
    ) {}

    /**
     * @param  array{field?: mixed, op?: mixed, value?: mixed}  $raw
     *
     * @throws InvalidArgumentException with a user-facing message
     */
    public static function fromArray(array $raw): self
    {
        if (! is_string($raw['field'] ?? null) || ! is_string($raw['op'] ?? null)) {
            throw new InvalidArgumentException('Each condition needs a string "field" and "op".');
        }

        $field = FieldRef::parse($raw['field']);
        $op = $raw['op'];

        if (! in_array($op, self::BY_TYPE[$field->type], true)) {
            throw new InvalidArgumentException(
                "Operator \"{$op}\" does not apply to \"{$field->raw}\" — allowed: "
                .implode(', ', self::BY_TYPE[$field->type]).'.'
            );
        }

        $value = $raw['value'] ?? null;

        if ($op === 'exists') {
            $value = null;
        } elseif ($field->type === 'number') {
            if (! is_int($value) && ! is_float($value)) {
                throw new InvalidArgumentException("\"{$field->raw}\" {$op} needs a numeric value.");
            }
        } elseif ($field->type === 'bool') {
            if (! is_bool($value)) {
                throw new InvalidArgumentException("\"{$field->raw}\" equals needs a boolean value.");
            }
        } else {
            if (! is_string($value) || $value === '') {
                throw new InvalidArgumentException("\"{$field->raw}\" {$op} needs a non-empty string value.");
            }

            if (strlen($value) > self::MAX_VALUE_LENGTH) {
                throw new InvalidArgumentException('Condition values are capped at '.self::MAX_VALUE_LENGTH.' bytes.');
            }

            if ($op === 'matches') {
                SafeRegex::validate($value);
            }
        }

        return new self($field, $op, $value);
    }

    public function evaluate(Message $message): ConditionResult
    {
        $extracted = $this->field->extract($message);

        $passed = match ($this->field->type) {
            'string' => $this->evaluateScalar((string) ($extracted ?? '')) && ($this->operator !== 'exists' || $extracted !== null && $extracted !== ''),
            'list' => $this->operator === 'exists'
                ? $extracted !== []
                : collect($extracted)->contains(fn ($v) => $this->evaluateScalar((string) $v)),
            'number' => $this->evaluateNumber((int) $extracted),
            'bool' => $this->operator === 'exists' ? $extracted !== null : $extracted === $this->value,
        };

        return new ConditionResult($this, $passed, $this->safeActual($extracted));
    }

    private function evaluateScalar(string $subject): bool
    {
        return match ($this->operator) {
            'equals' => $subject === $this->value,
            'contains' => str_contains($subject, $this->value),
            'starts_with' => str_starts_with($subject, $this->value),
            'ends_with' => str_ends_with($subject, $this->value),
            'matches' => (bool) @preg_match(SafeRegex::delimit($this->value), $subject),
            'exists' => true, // presence itself is checked by the caller
            default => false,
        };
    }

    private function evaluateNumber(int $actual): bool
    {
        return match ($this->operator) {
            'equals' => $actual == $this->value,
            'gt' => $actual > $this->value,
            'gte' => $actual >= $this->value,
            'lt' => $actual < $this->value,
            'lte' => $actual <= $this->value,
            'exists' => true,
            default => false,
        };
    }

    /**
     * A diagnostics-safe representation of the extracted value: bodies are
     * never echoed back, everything else is truncated.
     */
    private function safeActual(mixed $extracted): mixed
    {
        if (in_array($this->field->base, ['text', 'html'], true)) {
            return null;
        }

        if (is_array($extracted)) {
            return array_map(
                fn ($v) => mb_strimwidth((string) $v, 0, 200, '…'),
                array_slice($extracted, 0, 10),
            );
        }

        if (is_string($extracted)) {
            return mb_strimwidth($extracted, 0, 200, '…');
        }

        return $extracted;
    }
}
