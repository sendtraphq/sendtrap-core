<?php

namespace Sendtrap\Core\Expect;

final class ConditionResult
{
    public function __construct(
        public readonly Condition $condition,
        public readonly bool $passed,
        public readonly mixed $actual,
    ) {}

    /**
     * @return array{field: string, op: string, value: mixed, passed: bool, actual: mixed}
     */
    public function toArray(): array
    {
        return [
            'field' => $this->condition->field->raw,
            'op' => $this->condition->operator,
            'value' => $this->condition->value,
            'passed' => $this->passed,
            'actual' => $this->actual,
        ];
    }
}
