<?php

namespace Sendama\Console\Editor\Widgets\Controls;

class VectorInputControl extends CompoundInputControl
{
    public function __construct(
        string $label,
        array $value,
        int $indentLevel = 1,
        bool $isReadOnly = false,
    )
    {
        $normalizedValue = $this->normalizeVector($value);
        $controls = [];

        foreach ($normalizedValue as $axis => $axisValue) {
            $controls[] = new NumberInputControl(
                strtoupper((string) $axis),
                $axisValue,
                $indentLevel + 1,
                $isReadOnly,
            );
        }

        parent::__construct($label, $normalizedValue, $controls, $indentLevel, $isReadOnly);
    }

    private function normalizeVector(array $value): array
    {
        $normalizedValue = [];

        foreach ($value as $axis => $axisValue) {
            if (!is_string($axis)) {
                continue;
            }

            $normalizedValue[$axis] = is_numeric($axisValue) ? $axisValue + 0 : 0;
        }

        if ($normalizedValue === []) {
            return ['x' => 0, 'y' => 0];
        }

        return $normalizedValue;
    }
}
