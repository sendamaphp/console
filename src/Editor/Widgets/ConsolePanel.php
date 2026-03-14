<?php

namespace Sendama\Console\Editor\Widgets;

use Atatusoft\Termutil\IO\Enumerations\Color;
use Sendama\Console\Editor\IO\Enumerations\KeyCode;
use Sendama\Console\Editor\IO\Input;

class ConsolePanel extends Widget
{
    private const int INITIAL_TAIL_LINE_COUNT = 3;
    private const float DEFAULT_REFRESH_INTERVAL_SECONDS = 5.0;
    private const string DIVIDER_LINE_CHARACTER = '─';
    private const string TAB_DIVIDER_LINE_CHARACTER = '■';
    private const array TAB_TITLES = ['Debug', 'Error'];
    private const array FILTER_OPTIONS_BY_TAB = [
        'Debug' => ['ALL', 'DEBUG', 'INFO', 'WARN', 'ERROR'],
        'Error' => ['ALL', 'ERROR', 'CRITICAL', 'FATAL'],
    ];

    protected array $logMessagesByTab = [
        'Debug' => [],
        'Error' => [],
    ];
    protected array $sessionMessagesByTab = [
        'Debug' => [],
        'Error' => [],
    ];
    protected array $messages = [];
    protected array $scrollOffsetsByTab = [
        'Debug' => 0,
        'Error' => 0,
    ];
    protected int $scrollOffset = 0;
    protected bool $isPlayModeActive = false;
    protected float $refreshIntervalSeconds;
    protected float $lastLogRefreshAt;
    protected int $activeTabIndex = 0;
    protected int $activeTabOffset = 0;
    protected int $activeTabLength = 0;
    protected Color $activeIndicatorColor = Color::LIGHT_CYAN;
    protected array $activeFiltersByTab = [
        'Debug' => 'DEBUG',
        'Error' => 'ALL',
    ];
    protected OptionListModal $filterModal;
    protected OptionListModal $clearConfirmModal;

    public function __construct(
        array $position = ['x' => 37, 'y' => 22],
        int $width = 96,
        int $height = 8,
        protected ?string $logFilePath = null,
        protected ?string $errorLogFilePath = null,
        float $refreshIntervalSeconds = self::DEFAULT_REFRESH_INTERVAL_SECONDS,
    )
    {
        parent::__construct('Console', '', $position, $width, $height);
        $this->filterModal = new OptionListModal('Filter Logs');
        $this->clearConfirmModal = new OptionListModal('Clear Log');
        $this->refreshIntervalSeconds = $refreshIntervalSeconds > 0
            ? $refreshIntervalSeconds
            : self::DEFAULT_REFRESH_INTERVAL_SECONDS;
        $this->lastLogRefreshAt = microtime(true);
        $this->loadInitialLogTail();
        $this->refreshVisibleContent();
    }

    public function hasActiveModal(): bool
    {
        return $this->filterModal->isVisible()
            || $this->clearConfirmModal->isVisible();
    }

    public function isModalDirty(): bool
    {
        return $this->filterModal->isDirty()
            || $this->clearConfirmModal->isDirty();
    }

    public function markModalClean(): void
    {
        $this->filterModal->markClean();
        $this->clearConfirmModal->markClean();
    }

    public function syncModalLayout(int $terminalWidth, int $terminalHeight): void
    {
        $this->filterModal->syncLayout($terminalWidth, $terminalHeight);
        $this->clearConfirmModal->syncLayout($terminalWidth, $terminalHeight);
    }

    public function renderActiveModal(): void
    {
        if ($this->filterModal->isVisible()) {
            $this->filterModal->render();
        }

        if ($this->clearConfirmModal->isVisible()) {
            $this->clearConfirmModal->render();
        }
    }

    public function getActiveTab(): string
    {
        return self::TAB_TITLES[$this->activeTabIndex];
    }

    public function getActiveFilter(): string
    {
        return $this->activeFiltersByTab[$this->getActiveTab()] ?? 'ALL';
    }

    public function cycleFocusForward(): bool
    {
        $this->activateNextTab();

        return true;
    }

    public function cycleFocusBackward(): bool
    {
        $this->activatePreviousTab();

        return true;
    }

    public function append(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $timestampedMessage = "[$timestamp] $message";
        $tabTitle = $this->resolveSessionTabTitle($timestampedMessage);
        $this->sessionMessagesByTab[$tabTitle][] = $timestampedMessage;

        if ($tabTitle !== $this->getActiveTab()) {
            return;
        }

        $this->rebuildMessages();
        $this->scrollToRecentLines();
        $this->refreshVisibleContent();
    }

    public function clear(): void
    {
        foreach (self::TAB_TITLES as $tabTitle) {
            $this->logMessagesByTab[$tabTitle] = [];
            $this->sessionMessagesByTab[$tabTitle] = [];
            $this->scrollOffsetsByTab[$tabTitle] = 0;
        }

        $this->messages = [];
        $this->scrollOffset = 0;
        $this->refreshVisibleContent();
    }

    public function setPlayModeActive(bool $isPlayModeActive): void
    {
        if ($this->isPlayModeActive === $isPlayModeActive) {
            return;
        }

        $this->isPlayModeActive = $isPlayModeActive;

        if ($isPlayModeActive) {
            $this->refreshAllTabsFromLogFiles();
        }

        $this->refreshVisibleContent();
    }

    public function refreshFromLogFile(): void
    {
        $this->refreshTabFromLogFile($this->getActiveTab(), true);
    }

    public function scrollUp(): void
    {
        if ($this->messages === []) {
            return;
        }

        $this->scrollOffset = max(0, $this->scrollOffset - 1);
        $this->persistScrollOffset();
        $this->refreshVisibleContent();
    }

    public function scrollDown(): void
    {
        if ($this->messages === []) {
            return;
        }

        $this->scrollOffset = min(count($this->messages) - 1, $this->scrollOffset + 1);
        $this->persistScrollOffset();
        $this->refreshVisibleContent();
    }

    public function update(): void
    {
        if ($this->clearConfirmModal->isVisible()) {
            $this->handleClearConfirmModalInput();
            return;
        }

        if ($this->filterModal->isVisible()) {
            $this->handleFilterModalInput();
            return;
        }

        if ($this->shouldRefreshFromLogFile()) {
            $this->refreshAllTabsFromLogFiles();
        }

        if ($this->hasFocus()) {
            if (Input::isKeyDown(KeyCode::F)) {
                $this->openFilterModal();
                return;
            }

            if (Input::isKeyDown(KeyCode::C)) {
                $this->openClearConfirmModal();
                return;
            }

            if (!$this->isPlayModeActive && Input::isKeyDown(KeyCode::R)) {
                $this->refreshFromLogFile();
                return;
            }

            if (!$this->isPlayModeActive && Input::isKeyDown(KeyCode::UP)) {
                $this->scrollUp();
                return;
            }

            if (!$this->isPlayModeActive && Input::isKeyDown(KeyCode::DOWN)) {
                $this->scrollDown();
                return;
            }
        }

        $this->refreshVisibleContent();
    }

    protected function decorateContentLine(string $line, ?Color $contentColor, int $lineIndex): string
    {
        if ($lineIndex === 1) {
            return $this->decorateDividerLine($line, $contentColor, $lineIndex);
        }

        if ($lineIndex === 0) {
            return $this->decorateTabLine($line, $contentColor, $lineIndex);
        }

        $visibleLine = mb_substr($line, 0, $this->width);
        $visibleLength = mb_strlen($visibleLine);

        if ($visibleLength <= 1) {
            return parent::decorateContentLine($line, $contentColor, $lineIndex);
        }

        $leftBorder = mb_substr($visibleLine, 0, 1);
        $middle = $visibleLength > 2 ? mb_substr($visibleLine, 1, $visibleLength - 2) : '';
        $rightBorder = mb_substr($visibleLine, -1);
        $borderColor = $this->hasFocus() ? $this->focusBorderColor : $contentColor;

        return $this->wrapWithColor($leftBorder, $borderColor)
            . $this->colorizeLogTag($middle)
            . $this->wrapWithColor($rightBorder, $borderColor);
    }

    private function activateNextTab(): void
    {
        $previousTabTitle = $this->getActiveTab();
        $this->scrollOffsetsByTab[$previousTabTitle] = $this->scrollOffset;
        $this->activeTabIndex = ($this->activeTabIndex + 1) % count(self::TAB_TITLES);
        $this->restoreActiveTabState();
    }

    private function activatePreviousTab(): void
    {
        $previousTabTitle = $this->getActiveTab();
        $this->scrollOffsetsByTab[$previousTabTitle] = $this->scrollOffset;
        $this->activeTabIndex = ($this->activeTabIndex - 1 + count(self::TAB_TITLES)) % count(self::TAB_TITLES);
        $this->restoreActiveTabState();
    }

    private function restoreActiveTabState(): void
    {
        $this->scrollOffset = $this->scrollOffsetsByTab[$this->getActiveTab()] ?? 0;
        $this->rebuildMessages();
        $this->refreshVisibleContent();
    }

    private function loadInitialLogTail(): void
    {
        foreach (self::TAB_TITLES as $tabTitle) {
            $this->logMessagesByTab[$tabTitle] = $this->loadLogLinesForTab($tabTitle);
            $this->rebuildMessagesForTab($tabTitle);
            $this->scrollOffsetsByTab[$tabTitle] = $this->resolveRecentScrollOffsetForTab($tabTitle);
        }

        $this->lastLogRefreshAt = microtime(true);
        $this->restoreActiveTabState();
    }

    private function refreshAllTabsFromLogFiles(): void
    {
        foreach (self::TAB_TITLES as $tabTitle) {
            $shouldJumpToLatest = $tabTitle === $this->getActiveTab();
            $this->refreshTabFromLogFile($tabTitle, $shouldJumpToLatest);
        }

        $this->lastLogRefreshAt = microtime(true);
        $this->restoreActiveTabState();
    }

    private function refreshTabFromLogFile(string $tabTitle, bool $jumpToLatestVisibleLines): void
    {
        $this->logMessagesByTab[$tabTitle] = $this->loadLogLinesForTab($tabTitle);
        $this->rebuildMessagesForTab($tabTitle);

        if ($jumpToLatestVisibleLines) {
            $this->scrollOffsetsByTab[$tabTitle] = $this->resolveLatestVisibleScrollOffsetForTab($tabTitle);
        } else {
            $this->scrollOffsetsByTab[$tabTitle] = $this->clampScrollOffsetValue(
                $this->scrollOffsetsByTab[$tabTitle] ?? 0,
                count($this->messagesForTab($tabTitle)),
            );
        }

        if ($tabTitle === $this->getActiveTab()) {
            $this->scrollOffset = $this->scrollOffsetsByTab[$tabTitle];
            $this->rebuildMessages();
            $this->refreshVisibleContent();
        }
    }

    private function decorateTabLine(string $line, ?Color $contentColor, int $lineIndex): string
    {
        $visibleLine = mb_substr($line, 0, $this->width);
        $visibleLength = mb_strlen($visibleLine);

        if ($visibleLength <= 1) {
            return parent::decorateContentLine($line, $contentColor, $lineIndex);
        }

        $leftBorder = mb_substr($visibleLine, 0, 1);
        $middle = $visibleLength > 2 ? mb_substr($visibleLine, 1, $visibleLength - 2) : '';
        $rightBorder = mb_substr($visibleLine, -1);
        $borderColor = $this->hasFocus() ? $this->focusBorderColor : $contentColor;
        $indicatorStart = $this->padding->leftPadding + $this->activeTabOffset;
        $indicatorLength = $this->activeTabLength;
        $beforeIndicator = mb_substr($middle, 0, $indicatorStart);
        $indicator = mb_substr($middle, $indicatorStart, $indicatorLength);
        $afterIndicator = mb_substr($middle, $indicatorStart + $indicatorLength);

        return $this->wrapWithColor($leftBorder, $borderColor)
            . $this->wrapWithColor($beforeIndicator, $contentColor)
            . $this->wrapWithColor($indicator, $this->activeIndicatorColor)
            . $this->wrapWithColor($afterIndicator, $contentColor)
            . $this->wrapWithColor($rightBorder, $borderColor);
    }

    private function decorateDividerLine(string $line, ?Color $contentColor, int $lineIndex): string
    {
        $visibleLine = mb_substr($line, 0, $this->width);
        $visibleLength = mb_strlen($visibleLine);

        if ($visibleLength <= 1) {
            return parent::decorateContentLine($line, $contentColor, $lineIndex);
        }

        $leftBorder = mb_substr($visibleLine, 0, 1);
        $middle = $visibleLength > 2 ? mb_substr($visibleLine, 1, $visibleLength - 2) : '';
        $rightBorder = mb_substr($visibleLine, -1);
        $borderColor = $this->hasFocus() ? $this->focusBorderColor : $contentColor;
        $indicatorStart = $this->padding->leftPadding + $this->activeTabOffset;
        $indicatorLength = $this->activeTabLength;
        $beforeIndicator = mb_substr($middle, 0, $indicatorStart);
        $indicator = mb_substr($middle, $indicatorStart, $indicatorLength);
        $afterIndicator = mb_substr($middle, $indicatorStart + $indicatorLength);

        return $this->wrapWithColor($leftBorder, $borderColor)
            . $this->wrapWithColor($beforeIndicator, $contentColor)
            . $this->wrapWithColor($indicator, $this->activeIndicatorColor)
            . $this->wrapWithColor($afterIndicator, $contentColor)
            . $this->wrapWithColor($rightBorder, $borderColor);
    }

    private function colorizeLogTag(string $content): string
    {
        if (preg_match('/\[(ERROR|CRITICAL|FATAL|INFO|WARN|WARNING|DEBUG)\]/', $content, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return $content;
        }

        $tag = $matches[0][0];
        $tagOffset = $matches[0][1];
        $level = $matches[1][0];
        $beforeTag = substr($content, 0, $tagOffset);
        $afterTag = substr($content, $tagOffset + strlen($tag));

        return $beforeTag
            . $this->wrapWithColor($tag, $this->resolveLogLevelColor($level))
            . $afterTag;
    }

    private function resolveLogLevelColor(string $level): ?Color
    {
        return match ($level) {
            'ERROR' => Color::LIGHT_RED,
            'CRITICAL', 'FATAL' => Color::RED,
            'INFO' => Color::LIGHT_BLUE,
            'WARN', 'WARNING' => Color::YELLOW,
            'DEBUG' => Color::LIGHT_GRAY,
            default => null,
        };
    }

    private function scrollToRecentLines(): void
    {
        $messageCount = count($this->messages);

        if ($messageCount === 0) {
            $this->scrollOffset = 0;
            $this->persistScrollOffset();
            return;
        }

        $this->scrollOffset = max(0, $messageCount - self::INITIAL_TAIL_LINE_COUNT);
        $this->persistScrollOffset();
    }

    private function clampScrollOffset(): void
    {
        $this->scrollOffset = $this->clampScrollOffsetValue($this->scrollOffset, count($this->messages));
        $this->persistScrollOffset();
    }

    private function clampScrollOffsetValue(int $scrollOffset, int $messageCount): int
    {
        if ($messageCount === 0) {
            return 0;
        }

        return max(0, min($scrollOffset, $messageCount - 1));
    }

    private function refreshVisibleContent(): void
    {
        $this->updateHelpInfo();
        $this->rebuildMessages();
        $this->clampScrollOffset();
        $tabsLine = $this->buildTabsLine();
        $dividerWidth = max(0, $this->innerWidth - 2);
        $dividerLine = $this->buildDividerLine($dividerWidth);
        $visibleLineCount = $this->getVisibleLogLineCount();
        $visibleMessages = array_slice($this->messages, $this->scrollOffset, $visibleLineCount);

        $this->content = [
            $tabsLine,
            $dividerLine,
            ...$visibleMessages,
        ];
    }

    private function rebuildMessages(): void
    {
        $this->messages = $this->messagesForTab($this->getActiveTab());
    }

    private function rebuildMessagesForTab(string $tabTitle): void
    {
        if ($tabTitle !== $this->getActiveTab()) {
            return;
        }

        $this->rebuildMessages();
    }

    private function messagesForTab(string $tabTitle): array
    {
        $messages = [
            ...($this->logMessagesByTab[$tabTitle] ?? []),
            ...($this->sessionMessagesByTab[$tabTitle] ?? []),
        ];

        return $this->applyActiveFilter($tabTitle, $messages);
    }

    private function shouldRefreshFromLogFile(): bool
    {
        if (!$this->isPlayModeActive) {
            return false;
        }

        return (microtime(true) - $this->lastLogRefreshAt) >= $this->refreshIntervalSeconds;
    }

    private function loadLogLinesForTab(string $tabTitle): array
    {
        $logFilePath = $this->resolveLogFilePathForTab($tabTitle);

        if (!is_string($logFilePath) || $logFilePath === '' || !is_file($logFilePath)) {
            return [];
        }

        $lines = file($logFilePath, FILE_IGNORE_NEW_LINES);

        return $lines === false ? [] : array_values($lines);
    }

    private function resolveLogFilePathForTab(string $tabTitle): ?string
    {
        return match ($tabTitle) {
            'Debug' => $this->logFilePath,
            'Error' => $this->errorLogFilePath,
            default => null,
        };
    }

    private function resolveSessionTabTitle(string $message): string
    {
        return preg_match('/\[ERROR\]/', $message) === 1 ? 'Error' : 'Debug';
    }

    private function resolveRecentScrollOffsetForTab(string $tabTitle): int
    {
        $messageCount = count($this->messagesForTab($tabTitle));

        if ($messageCount === 0) {
            return 0;
        }

        return max(0, $messageCount - self::INITIAL_TAIL_LINE_COUNT);
    }

    private function resolveLatestVisibleScrollOffsetForTab(string $tabTitle): int
    {
        $messageCount = count($this->messagesForTab($tabTitle));

        if ($messageCount === 0) {
            return 0;
        }

        return max(0, $messageCount - $this->getVisibleLogLineCount());
    }

    private function updateHelpInfo(): void
    {
        $this->help = $this->isPlayModeActive
            ? 'Tab/Shift+Tab tabs  Shift+F filter  Shift+C clear  Auto refresh on'
            : 'Tab/Shift+Tab tabs  Up/Down scroll  Shift+R refresh  Shift+F filter  Shift+C clear';
    }

    private function buildTabsLine(): string
    {
        $tabsLine = '';
        $this->activeTabOffset = 0;

        foreach (self::TAB_TITLES as $index => $tabTitle) {
            if ($index > 0) {
                $tabsLine .= '  ';
            }

            if ($index === $this->activeTabIndex) {
                $this->activeTabOffset = mb_strlen($tabsLine);
            }

            $tabsLine .= $tabTitle;
        }

        $this->activeTabLength = mb_strlen($this->getActiveTab());

        return $tabsLine;
    }

    private function buildDividerLine(int $dividerWidth): string
    {
        if ($dividerWidth <= 0) {
            return '';
        }

        $characters = array_fill(0, $dividerWidth, self::DIVIDER_LINE_CHARACTER);

        for ($index = 0; $index < $this->activeTabLength; $index++) {
            $characterIndex = $this->activeTabOffset + $index;

            if (!isset($characters[$characterIndex])) {
                break;
            }

            $characters[$characterIndex] = self::TAB_DIVIDER_LINE_CHARACTER;
        }

        return implode('', $characters);
    }

    private function getVisibleLogLineCount(): int
    {
        return max(1, $this->innerHeight - 2);
    }

    private function persistScrollOffset(): void
    {
        $this->scrollOffsetsByTab[$this->getActiveTab()] = $this->scrollOffset;
    }

    private function openFilterModal(): void
    {
        $tabTitle = $this->getActiveTab();
        $options = self::FILTER_OPTIONS_BY_TAB[$tabTitle] ?? ['ALL'];
        $currentFilter = $this->activeFiltersByTab[$tabTitle] ?? 'ALL';
        $selectedIndex = array_search($currentFilter, $options, true);

        $this->filterModal->show(
            $options,
            is_int($selectedIndex) ? $selectedIndex : 0,
            $tabTitle . ' Filter'
        );
    }

    private function handleFilterModalInput(): void
    {
        if (Input::isKeyDown(KeyCode::ESCAPE)) {
            $this->filterModal->hide();
            return;
        }

        if (Input::isKeyDown(KeyCode::UP)) {
            $this->filterModal->moveSelection(-1);
            return;
        }

        if (Input::isKeyDown(KeyCode::DOWN)) {
            $this->filterModal->moveSelection(1);
            return;
        }

        if (!Input::isKeyDown(KeyCode::ENTER)) {
            return;
        }

        $selection = $this->filterModal->getSelectedOption();
        $this->filterModal->hide();

        if (!is_string($selection) || $selection === '') {
            return;
        }

        $tabTitle = $this->getActiveTab();
        $this->activeFiltersByTab[$tabTitle] = $selection;
        $this->scrollOffsetsByTab[$tabTitle] = $this->resolveLatestVisibleScrollOffsetForTab($tabTitle);
        $this->restoreActiveTabState();
    }

    private function openClearConfirmModal(): void
    {
        $logFilePath = $this->resolveLogFilePathForTab($this->getActiveTab());

        if (!is_string($logFilePath) || $logFilePath === '' || !is_file($logFilePath)) {
            return;
        }

        $this->clearConfirmModal->show(
            ['Cancel', 'Clear'],
            0,
            'Clear ' . basename($logFilePath) . '?'
        );
    }

    private function handleClearConfirmModalInput(): void
    {
        if (Input::isKeyDown(KeyCode::ESCAPE)) {
            $this->clearConfirmModal->hide();
            return;
        }

        if (Input::isKeyDown(KeyCode::UP)) {
            $this->clearConfirmModal->moveSelection(-1);
            return;
        }

        if (Input::isKeyDown(KeyCode::DOWN)) {
            $this->clearConfirmModal->moveSelection(1);
            return;
        }

        if (!Input::isKeyDown(KeyCode::ENTER)) {
            return;
        }

        $selection = $this->clearConfirmModal->getSelectedOption();
        $this->clearConfirmModal->hide();

        if ($selection !== 'Clear') {
            return;
        }

        $this->rotateAndClearActiveLogFile();
    }

    private function rotateAndClearActiveLogFile(): void
    {
        $tabTitle = $this->getActiveTab();
        $logFilePath = $this->resolveLogFilePathForTab($tabTitle);

        if (!is_string($logFilePath) || $logFilePath === '' || !is_file($logFilePath)) {
            return;
        }

        $contents = file_get_contents($logFilePath);

        if ($contents === false) {
            return;
        }

        $rotatedPath = $this->resolveNextRotatedLogPath($logFilePath);

        if (file_put_contents($rotatedPath, $contents) === false) {
            return;
        }

        if (file_put_contents($logFilePath, '') === false) {
            return;
        }

        $this->logMessagesByTab[$tabTitle] = [];
        $this->scrollOffsetsByTab[$tabTitle] = 0;
        $this->lastLogRefreshAt = microtime(true);
        $this->restoreActiveTabState();
    }

    private function resolveNextRotatedLogPath(string $logFilePath): string
    {
        $index = 1;

        do {
            $candidatePath = $logFilePath . '.' . $index;
            $index++;
        } while (file_exists($candidatePath));

        return $candidatePath;
    }

    private function applyActiveFilter(string $tabTitle, array $messages): array
    {
        $activeFilter = $this->activeFiltersByTab[$tabTitle] ?? 'ALL';

        if ($activeFilter === 'ALL') {
            return $messages;
        }

        return array_values(array_filter(
            $messages,
            fn(string $message): bool => $this->messageMatchesFilter($tabTitle, $message, $activeFilter)
        ));
    }

    private function messageMatchesFilter(string $tabTitle, string $message, string $filter): bool
    {
        if (preg_match('/\[(ERROR|CRITICAL|FATAL|INFO|WARN|WARNING|DEBUG)\]/', $message, $matches) !== 1) {
            return $tabTitle === 'Debug' && $filter === 'DEBUG';
        }

        $level = $matches[1];

        if ($filter === 'WARN') {
            return in_array($level, ['WARN', 'WARNING'], true);
        }

        return $level === $filter;
    }
}
