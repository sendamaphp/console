<?php

namespace Sendama\Console\Editor\Widgets;

use Atatusoft\Termutil\IO\Enumerations\Color;

final class Snackbar extends Widget
{
    private const float DEFAULT_DURATION_SECONDS = 2.5;
    private const int MAX_WIDTH = 56;
    private const int SLIDE_STEP = 1;

    /** @var list<array{message: string, status: string, duration: float}> */
    private array $queue = [];
    private ?array $currentNotice = null;
    private string $phase = 'hidden';
    private float $visibleUntil = 0.0;
    private int $terminalWidth = DEFAULT_TERMINAL_WIDTH;
    private int $terminalHeight = DEFAULT_TERMINAL_HEIGHT;
    private float $defaultDurationSeconds;
    private bool $isDirty = false;

    public function __construct(float $defaultDurationSeconds = self::DEFAULT_DURATION_SECONDS)
    {
        parent::__construct(
            title: '',
            help: '',
            position: ['x' => 1, 'y' => -2],
            width: 24,
            height: 3,
        );
        $this->defaultDurationSeconds = max(0.5, $defaultDurationSeconds);
    }

    public function enqueue(string $message, string $status = 'info', ?float $durationSeconds = null): void
    {
        $normalizedMessage = trim(preg_replace('/\s+/', ' ', $message) ?? $message);

        if ($normalizedMessage === '') {
            return;
        }

        $resolvedDurationSeconds = is_numeric($durationSeconds)
            ? (float) $durationSeconds
            : $this->defaultDurationSeconds;

        $this->queue[] = [
            'message' => $normalizedMessage,
            'status' => $this->normalizeStatus($status),
            'duration' => max(0.5, $resolvedDurationSeconds),
        ];

        if ($this->currentNotice === null) {
            $this->activateNextNotice();
        }
    }

    public function hasActiveNotice(): bool
    {
        return $this->currentNotice !== null;
    }

    public function isDirty(): bool
    {
        return $this->isDirty;
    }

    public function markClean(): void
    {
        $this->isDirty = false;
    }

    public function renderAt(?int $x = null, ?int $y = null): void
    {
        if ($this->currentNotice === null) {
            return;
        }

        $position = $this->position;
        $positionX = $position['x'] ?? $position[0] ?? 0;
        $positionY = $position['y'] ?? $position[1] ?? 0;
        $leftMargin = $positionX + ($x ?? 0);
        $topMargin = $positionY + ($y ?? 0);
        $rightMargin = $leftMargin + $this->width - 1;
        $bottomMargin = $topMargin + $this->height - 1;

        if ($leftMargin > $this->terminalWidth || $rightMargin < 1 || $topMargin < 1 || $topMargin > $this->terminalHeight || $bottomMargin < 1) {
            return;
        }

        $visibleStartColumn = max(1, $leftMargin);
        $clipOffset = max(0, 1 - $leftMargin);
        $visibleWidth = max(0, min($this->terminalWidth, $rightMargin) - $visibleStartColumn + 1);

        if ($visibleWidth <= 0) {
            return;
        }

        $contentColor = $this->foregroundColor;
        $this->foregroundColor = null;
        $linesOfContent = $this->buildRenderedContentLines();
        $this->foregroundColor = $contentColor;

        if ($linesOfContent === []) {
            $linesOfContent = [''];
        }

        $renderedLines = [
            ['kind' => 'border', 'line' => $this->buildBorderLine($this->title, true)],
        ];

        foreach ($linesOfContent as $index => $line) {
            $renderedLines[] = [
                'kind' => 'content',
                'line' => $line,
                'index' => $index,
            ];
        }

        $renderedLines[] = ['kind' => 'border', 'line' => $this->buildBorderLine($this->help, false)];

        $visibleStartRow = max(1, $topMargin);
        $verticalClipOffset = max(0, 1 - $topMargin);
        $visibleHeight = max(0, min($this->terminalHeight, $bottomMargin) - $visibleStartRow + 1);

        if ($visibleHeight <= 0) {
            return;
        }

        $visibleLines = array_slice($renderedLines, $verticalClipOffset, $visibleHeight);

        foreach ($visibleLines as $visibleIndex => $lineData) {
            $this->cursor->moveTo($visibleStartColumn, $visibleStartRow + $visibleIndex);

            $clippedLine = $this->clipRenderedLine((string) ($lineData['line'] ?? ''), $clipOffset, $visibleWidth);

            if (($lineData['kind'] ?? '') === 'content') {
                echo $this->decorateContentLine(
                    $clippedLine,
                    $contentColor,
                    (int) ($lineData['index'] ?? 0),
                );
                continue;
            }

            echo $this->decorateBorderLine($clippedLine, $contentColor);
        }
    }

    public function syncLayout(int $terminalWidth, int $terminalHeight): void
    {
        $this->terminalWidth = max(10, $terminalWidth);
        $this->terminalHeight = max(3, $terminalHeight);
        $targetX = $this->resolveTargetX($this->width);

        if ($this->currentNotice === null) {
            $this->setPosition($targetX, $this->resolveHiddenY());
            return;
        }

        $desiredWidth = min(
            self::MAX_WIDTH,
            max(
                24,
                $this->getDisplayWidth((string) ($this->currentNotice['message'] ?? '')) + 4,
                $this->getDisplayWidth($this->title) + 4,
            )
        );
        $width = min($desiredWidth, max(10, $this->terminalWidth - 2));
        $targetX = $this->resolveTargetX($width);
        $targetY = $this->resolveTargetY();
        $hiddenY = $this->resolveHiddenY();

        $previousX = $this->x;
        $this->setDimensions($width, 3);
        $this->setPosition($targetX, $this->y);
        $this->markDirtyIfChanged($previousX !== $this->x);

        if ($this->phase === 'visible') {
            $this->moveToY($targetY);
            return;
        }

        if ($this->phase === 'hidden') {
            $this->moveToY($hiddenY);
            return;
        }

        $this->moveToY(max($hiddenY, min($this->y, $targetY)));
    }

    public function update(): void
    {
        if ($this->currentNotice === null) {
            if ($this->queue !== []) {
                $this->activateNextNotice();
            }

            return;
        }

        $targetY = $this->resolveTargetY();
        $hiddenY = $this->resolveHiddenY();

        switch ($this->phase) {
            case 'entering':
                $this->moveToY(min($targetY, $this->y + self::SLIDE_STEP));

                if ($this->y >= $targetY) {
                    $this->moveToY($targetY);
                    $this->phase = 'visible';
                    $this->isDirty = true;
                    $this->visibleUntil = microtime(true) + (float) ($this->currentNotice['duration'] ?? self::DEFAULT_DURATION_SECONDS);
                }
                break;

            case 'visible':
                if (microtime(true) >= $this->visibleUntil) {
                    $this->phase = 'exiting';
                    $this->isDirty = true;
                }
                break;

            case 'exiting':
                $this->moveToY(max($hiddenY, $this->y - self::SLIDE_STEP));

                if ($this->y <= $hiddenY) {
                    $this->currentNotice = null;
                    $this->content = [];
                    $this->title = '';
                    $this->phase = 'hidden';
                    $this->isDirty = true;

                    if ($this->queue !== []) {
                        $this->activateNextNotice();
                    }
                }
                break;
        }
    }

    protected function decorateBorderLine(string $line, ?Color $contentColor): string
    {
        return $this->wrapWithColor(mb_substr($line, 0, $this->width), $this->resolveStatusColor());
    }

    protected function decorateContentLine(string $line, ?Color $contentColor, int $lineIndex): string
    {
        $visibleLine = mb_substr($line, 0, $this->width);
        $visibleLength = mb_strlen($visibleLine);

        if ($visibleLength <= 1) {
            return parent::decorateContentLine($line, $contentColor, $lineIndex);
        }

        $leftBorder = mb_substr($visibleLine, 0, 1);
        $middle = $visibleLength > 2 ? mb_substr($visibleLine, 1, $visibleLength - 2) : '';
        $rightBorder = mb_substr($visibleLine, -1);
        $borderColor = $this->resolveStatusColor();

        return $this->wrapWithColor($leftBorder, $borderColor)
            . $this->wrapWithSequence($middle, $this->resolveStatusSequence())
            . $this->wrapWithColor($rightBorder, $borderColor);
    }

    private function activateNextNotice(): void
    {
        $nextNotice = array_shift($this->queue);

        if (!is_array($nextNotice)) {
            return;
        }

        $this->currentNotice = $nextNotice;
        $this->title = ucfirst((string) $nextNotice['status']);
        $this->help = '';
        $this->content = [(string) $nextNotice['message']];
        $this->phase = 'entering';
        $this->isDirty = true;
        $this->syncLayout($this->terminalWidth, $this->terminalHeight);
        $this->moveToY($this->resolveHiddenY());
    }

    private function clipRenderedLine(string $line, int $offset, int $visibleWidth): string
    {
        if ($visibleWidth <= 0) {
            return '';
        }

        if ($offset <= 0 && $this->getDisplayWidth($line) <= $visibleWidth) {
            return $line;
        }

        return mb_strimwidth($line, $offset, $visibleWidth, '', 'UTF-8');
    }

    private function normalizeStatus(string $status): string
    {
        return match (strtolower(trim($status))) {
            'success' => 'success',
            'error' => 'error',
            'warning', 'warn' => 'warn',
            default => 'info',
        };
    }

    private function resolveStatusColor(): Color
    {
        return match ($this->currentNotice['status'] ?? 'info') {
            'success' => Color::LIGHT_GREEN,
            'error' => Color::LIGHT_RED,
            'warn' => Color::YELLOW,
            default => Color::LIGHT_BLUE,
        };
    }

    private function resolveStatusSequence(): string
    {
        return match ($this->currentNotice['status'] ?? 'info') {
            'success' => "\033[30;42m",
            'error' => "\033[30;41m",
            'warn' => "\033[30;43m",
            default => "\033[30;44m",
        };
    }

    private function resolveTargetX(int $width): int
    {
        return max(1, intdiv(max(0, $this->terminalWidth - $width), 2) + 1);
    }

    private function resolveTargetY(): int
    {
        return 1;
    }

    private function resolveHiddenY(): int
    {
        return 0;
    }

    private function moveToY(int $y): void
    {
        $didChange = $this->y !== $y;
        $this->setPosition($this->x, $y);
        $this->markDirtyIfChanged($didChange);
    }

    private function markDirtyIfChanged(bool $didChange): void
    {
        if ($didChange) {
            $this->isDirty = true;
        }
    }
}
