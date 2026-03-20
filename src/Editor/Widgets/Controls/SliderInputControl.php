<?php

namespace Sendama\Console\Editor\Widgets\Controls;

class SliderInputControl extends InputControl
{
    private const int DEFAULT_TRACK_LENGTH = 12;
    private const int MINIMUM_TRACK_LENGTH = 4;

    protected int|float $minimum;
    protected int|float $maximum;
    protected int|float $step;
    protected bool $prefersFloat = false;
    protected int|float $editingValue;

    public function __construct(
        string $label,
        mixed $value,
        int|float $minimum,
        int|float $maximum,
        int|float $step = 1,
        int $indentLevel = 1,
        bool $isReadOnly = false,
    )
    {
        parent::__construct($label, $value, $indentLevel, $isReadOnly);

        $resolvedMinimum = (float) $minimum;
        $resolvedMaximum = (float) $maximum;

        if ($resolvedMinimum > $resolvedMaximum) {
            [$resolvedMinimum, $resolvedMaximum] = [$resolvedMaximum, $resolvedMinimum];
        }

        $resolvedStep = abs((float) $step);
        $this->prefersFloat = $this->shouldPreferFloat($value, $minimum, $maximum, $step);

        $this->minimum = $this->normalizeCommittedValue($resolvedMinimum);
        $this->maximum = $this->normalizeCommittedValue($resolvedMaximum);
        $this->step = $resolvedStep > 0
            ? $this->normalizeCommittedValue($resolvedStep)
            : $this->normalizeCommittedValue(1);
        $this->value = $this->clampCommittedValue($value);
        $this->editingValue = $this->value;
    }

    public function setValue(mixed $value): void
    {
        $this->value = $this->clampCommittedValue($value);
        $this->editingValue = $this->value;
    }

    public function enterEditMode(): bool
    {
        if (!parent::enterEditMode()) {
            return false;
        }

        $this->editingValue = $this->value;

        return true;
    }

    public function commitEdit(): bool
    {
        if ($this->isEditing) {
            $this->value = $this->clampCommittedValue($this->editingValue);
        }

        return parent::commitEdit();
    }

    public function cancelEdit(): void
    {
        $this->editingValue = $this->value;
        parent::cancelEdit();
    }

    public function increment(): bool
    {
        if (!$this->isEditing) {
            return false;
        }

        $this->editingValue = $this->clampCommittedValue(
            $this->toNumeric($this->editingValue) + $this->toNumeric($this->step),
        );

        return true;
    }

    public function decrement(): bool
    {
        if (!$this->isEditing) {
            return false;
        }

        $this->editingValue = $this->clampCommittedValue(
            $this->toNumeric($this->editingValue) - $this->toNumeric($this->step),
        );

        return true;
    }

    public function moveCursorLeft(): bool
    {
        return $this->decrement();
    }

    public function moveCursorRight(): bool
    {
        return $this->increment();
    }

    public function renderLines(): array
    {
        $currentValue = $this->isEditing ? $this->editingValue : $this->value;
        $prefix = sprintf('%s%s: ', $this->indentation(), $this->label);
        $valueLabel = $this->formatCommittedValue($currentValue);
        $availableWidth = $this->getAvailableWidth();

        if ($availableWidth !== null) {
            $singleLineFixedWidth = $this->getDisplayWidth($prefix) + $this->getDisplayWidth($valueLabel) + 3;
            $singleLineTrackLength = min(
                self::DEFAULT_TRACK_LENGTH,
                max(0, $availableWidth - $singleLineFixedWidth),
            );

            if ($singleLineTrackLength >= self::MINIMUM_TRACK_LENGTH) {
                return [
                    sprintf(
                        '%s[%s] %s',
                        $prefix,
                        $this->buildTrack($currentValue, $singleLineTrackLength),
                        $valueLabel,
                    ),
                ];
            }

            $trackPrefix = $this->indentation(1);
            $trackWidth = min(
                self::DEFAULT_TRACK_LENGTH,
                max(
                    self::MINIMUM_TRACK_LENGTH,
                    $availableWidth - $this->getDisplayWidth($trackPrefix) - 2,
                ),
            );

            return [
                $prefix . $valueLabel,
                sprintf('%s[%s]', $trackPrefix, $this->buildTrack($currentValue, $trackWidth)),
            ];
        }

        return [
            sprintf(
                '%s%s: [%s] %s',
                $this->indentation(),
                $this->label,
                $this->buildTrack($currentValue, self::DEFAULT_TRACK_LENGTH),
                $valueLabel,
            ),
        ];
    }

    private function buildTrack(mixed $currentValue, int $trackLength): string
    {
        $trackLength = max(1, $trackLength);
        $minimum = $this->toNumeric($this->minimum);
        $maximum = $this->toNumeric($this->maximum);
        $range = max(0.0, $maximum - $minimum);
        $normalizedValue = $this->toNumeric($currentValue);
        $ratio = $range <= 0
            ? 1.0
            : (($normalizedValue - $minimum) / $range);
        $ratio = max(0.0, min(1.0, $ratio));
        $filledSegments = (int) round($ratio * $trackLength);

        return str_repeat('#', $filledSegments) . str_repeat('-', $trackLength - $filledSegments);
    }

    private function clampCommittedValue(mixed $value): int|float
    {
        $numericValue = $this->toNumeric($value);
        $minimum = $this->toNumeric($this->minimum);
        $maximum = $this->toNumeric($this->maximum);

        if ($numericValue < $minimum) {
            $numericValue = $minimum;
        }

        if ($numericValue > $maximum) {
            $numericValue = $maximum;
        }

        return $this->normalizeCommittedValue($numericValue);
    }

    private function normalizeCommittedValue(mixed $value): int|float
    {
        $numericValue = $this->toNumeric($value);

        if ($this->prefersFloat) {
            return (float) $numericValue;
        }

        return (int) round($numericValue);
    }

    private function formatCommittedValue(mixed $value): string
    {
        $numericValue = $this->normalizeCommittedValue($value);

        if (!$this->prefersFloat) {
            return (string) $numericValue;
        }

        $formatted = rtrim(rtrim(number_format((float) $numericValue, 6, '.', ''), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }

    private function toNumeric(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function shouldPreferFloat(mixed ...$candidates): bool
    {
        foreach ($candidates as $candidate) {
            if (is_float($candidate)) {
                return true;
            }

            if (is_string($candidate) && is_numeric($candidate) && str_contains($candidate, '.')) {
                return true;
            }
        }

        return false;
    }
}
