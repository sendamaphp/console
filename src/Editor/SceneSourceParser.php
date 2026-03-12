<?php

namespace Sendama\Console\Editor;

final class SceneSourceParser
{
    public function parseFile(string $path): ?array
    {
        $source = @file_get_contents($path);

        if ($source === false) {
            return null;
        }

        return $this->parse($source);
    }

    public function parse(string $source): ?array
    {
        $tokens = token_get_all($source);
        $returnIndex = $this->findReturnIndex($tokens);

        if ($returnIndex === null) {
            return null;
        }

        $valueIndex = $returnIndex + 1;
        $this->skipTrivia($tokens, $valueIndex);

        if (!isset($tokens[$valueIndex])) {
            return null;
        }

        $prefix = $this->sliceTokens($tokens, 0, $valueIndex);
        $root = $this->parseNode($tokens, $valueIndex, [';']);

        if (($root['kind'] ?? null) !== 'array') {
            return null;
        }

        $suffix = $this->sliceTokens($tokens, $valueIndex);

        return [
            'prefix' => $prefix,
            'root' => $root,
            'suffix' => $suffix,
        ];
    }

    private function parseNode(array $tokens, int &$index, array $terminators, bool $stopAtArrow = false): array
    {
        $this->skipTrivia($tokens, $index);

        if (!isset($tokens[$index])) {
            return [
                'kind' => 'value',
                'source' => '',
            ];
        }

        if ($this->tokenText($tokens[$index]) === '[') {
            return $this->parseShortArray($tokens, $index);
        }

        if ($this->isLongArrayStart($tokens, $index)) {
            return $this->parseLongArray($tokens, $index);
        }

        return $this->parseValue($tokens, $index, $terminators, $stopAtArrow);
    }

    private function parseShortArray(array $tokens, int &$index): array
    {
        $start = $index;
        $items = [];
        $index++;

        while (isset($tokens[$index])) {
            $this->skipTrivia($tokens, $index);

            if ($this->tokenText($tokens[$index] ?? null) === ']') {
                $index++;
                break;
            }

            $items[] = $this->parseArrayItem($tokens, $index, ']');
            $this->skipTrivia($tokens, $index);

            if ($this->tokenText($tokens[$index] ?? null) === ',') {
                $index++;
            }
        }

        return [
            'kind' => 'array',
            'source' => $this->sliceTokens($tokens, $start, $index),
            'items' => $items,
        ];
    }

    private function parseLongArray(array $tokens, int &$index): array
    {
        $start = $index;
        $index++;
        $this->skipTrivia($tokens, $index);

        if ($this->tokenText($tokens[$index] ?? null) !== '(') {
            return [
                'kind' => 'value',
                'source' => $this->sliceTokens($tokens, $start, $index),
            ];
        }

        $items = [];
        $index++;

        while (isset($tokens[$index])) {
            $this->skipTrivia($tokens, $index);

            if ($this->tokenText($tokens[$index] ?? null) === ')') {
                $index++;
                break;
            }

            $items[] = $this->parseArrayItem($tokens, $index, ')');
            $this->skipTrivia($tokens, $index);

            if ($this->tokenText($tokens[$index] ?? null) === ',') {
                $index++;
            }
        }

        return [
            'kind' => 'array',
            'source' => $this->sliceTokens($tokens, $start, $index),
            'items' => $items,
        ];
    }

    private function parseArrayItem(array $tokens, int &$index, string $terminator): array
    {
        $firstNode = $this->parseNode($tokens, $index, [$terminator, ','], true);
        $lookaheadIndex = $index;
        $this->skipTrivia($tokens, $lookaheadIndex);

        if ($this->isDoubleArrow($tokens[$lookaheadIndex] ?? null)) {
            $index = $lookaheadIndex + 1;
            $valueNode = $this->parseNode($tokens, $index, [$terminator, ',']);

            return [
                'keySource' => rtrim($firstNode['source']),
                'node' => $valueNode,
            ];
        }

        return [
            'keySource' => null,
            'node' => $firstNode,
        ];
    }

    private function parseValue(array $tokens, int &$index, array $terminators, bool $stopAtArrow): array
    {
        $start = $index;
        $parenthesisDepth = 0;
        $squareDepth = 0;
        $braceDepth = 0;

        while (isset($tokens[$index])) {
            $token = $tokens[$index];
            $text = $this->tokenText($token);

            if (
                $parenthesisDepth === 0
                && $squareDepth === 0
                && $braceDepth === 0
            ) {
                if ($stopAtArrow && $this->isDoubleArrow($token)) {
                    break;
                }

                if (in_array($text, $terminators, true)) {
                    break;
                }
            }

            switch ($text) {
                case '(':
                    $parenthesisDepth++;
                    break;
                case ')':
                    $parenthesisDepth = max(0, $parenthesisDepth - 1);
                    break;
                case '[':
                    $squareDepth++;
                    break;
                case ']':
                    $squareDepth = max(0, $squareDepth - 1);
                    break;
                case '{':
                    $braceDepth++;
                    break;
                case '}':
                    $braceDepth = max(0, $braceDepth - 1);
                    break;
            }

            $index++;
        }

        return [
            'kind' => 'value',
            'source' => rtrim($this->sliceTokens($tokens, $start, $index)),
        ];
    }

    private function findReturnIndex(array $tokens): ?int
    {
        $braceDepth = 0;
        $lastTopLevelReturnIndex = null;

        foreach ($tokens as $index => $token) {
            $text = $this->tokenText($token);

            if ($text === '{') {
                $braceDepth++;
                continue;
            }

            if ($text === '}') {
                $braceDepth = max(0, $braceDepth - 1);
                continue;
            }

            if ($braceDepth === 0 && is_array($token) && $token[0] === T_RETURN) {
                $lastTopLevelReturnIndex = $index;
            }
        }

        return $lastTopLevelReturnIndex;
    }

    private function isLongArrayStart(array $tokens, int $index): bool
    {
        if (!is_array($tokens[$index] ?? null) || $tokens[$index][0] !== T_ARRAY) {
            return false;
        }

        $lookaheadIndex = $index + 1;
        $this->skipTrivia($tokens, $lookaheadIndex);

        return $this->tokenText($tokens[$lookaheadIndex] ?? null) === '(';
    }

    private function skipTrivia(array $tokens, int &$index): void
    {
        while (isset($tokens[$index])) {
            if (!is_array($tokens[$index])) {
                break;
            }

            if (!in_array($tokens[$index][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                break;
            }

            $index++;
        }
    }

    private function isDoubleArrow(mixed $token): bool
    {
        return is_array($token) && $token[0] === T_DOUBLE_ARROW;
    }

    private function tokenText(mixed $token): string
    {
        if ($token === null) {
            return '';
        }

        return is_array($token) ? $token[1] : $token;
    }

    private function sliceTokens(array $tokens, int $start, ?int $end = null): string
    {
        $end ??= count($tokens);
        $slice = array_slice($tokens, $start, $end - $start);

        return implode('', array_map(fn (mixed $token) => $this->tokenText($token), $slice));
    }
}
