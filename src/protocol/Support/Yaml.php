<?php
declare(strict_types = 1);

namespace erikwang2013\apidoc\protocol\Support;

use erikwang2013\apidoc\protocol\ProtocolParseException;

/**
 * YAML 子集解析器
 *
 * 支持: mapping/sequence/标量/行内 list/map/注释/块标量 | 与 >
 * 不支持: 锚点、别名、自定义 tag、多文档、tab 缩进、复杂 flow 语法
 */
class Yaml
{
    /**
     * @param string $source
     * @return array
     * @throws ProtocolParseException
     */
    public static function parse(string $source): array
    {
        $lines = self::lines($source);
        $index = 0;
        if ($lines === []) {
            return [];
        }
        $result = self::parseBlock($lines, $index, $lines[0]['indent']);
        if ($index < count($lines)) {
            throw self::error('unexpected content', $lines[$index]['line']);
        }
        return is_array($result) ? $result : [];
    }

    /**
     * @return array<int,array{indent:int,content:string,line:int}>
     */
    protected static function lines(string $source): array
    {
        $source = str_replace(["\r\n", "\r"], "\n", $source);
        $raw = explode("\n", $source);
        $lines = [];
        foreach ($raw as $number => $line) {
            if (preg_match('/^[ ]*\t/', $line) === 1) {
                throw self::error('tab indentation is not supported', $number + 1);
            }
            $trimmed = trim($line);
            if ($trimmed === '' || strpos($trimmed, '#') === 0) {
                continue;
            }
            if ($trimmed === '---' || $trimmed === '...') {
                throw self::error('multi-document YAML is not supported', $number + 1);
            }
            $indent = strlen($line) - strlen(ltrim($line, ' '));
            $lines[] = [
                'indent' => $indent,
                'content' => rtrim(substr($line, $indent)),
                'line' => $number + 1,
            ];
        }
        return $lines;
    }

    /**
     * @param array<int,array{indent:int,content:string,line:int}> $lines
     * @param int $index
     * @param int $indent
     * @return array|null
     */
    protected static function parseBlock(array $lines, int &$index, int $indent)
    {
        if ($index >= count($lines) || $lines[$index]['indent'] < $indent) {
            return null;
        }
        if ($lines[$index]['indent'] > $indent) {
            throw self::error('bad indentation', $lines[$index]['line']);
        }
        if (self::isSequence($lines[$index]['content'])) {
            return self::parseSequence($lines, $index, $indent);
        }
        return self::parseMapping($lines, $index, $indent);
    }

    protected static function parseMapping(array $lines, int &$index, int $indent): array
    {
        $result = [];
        $count = count($lines);
        while ($index < $count && $lines[$index]['indent'] === $indent) {
            $line = $lines[$index];
            if (self::isSequence($line['content'])) {
                throw self::error('unexpected sequence item', $line['line']);
            }
            list($key, $value) = self::splitKey($line['content'], $line['line']);
            $index++;
            if ($value === '') {
                if ($index < $count && $lines[$index]['indent'] > $indent) {
                    $result[$key] = self::parseBlock($lines, $index, $lines[$index]['indent']);
                } else {
                    $result[$key] = null;
                }
            } elseif ($value === '|' || $value === '>') {
                $result[$key] = self::parseBlockScalar($lines, $index, $indent, $value);
            } else {
                $result[$key] = self::parseScalar($value, $line['line']);
            }
        }
        if ($index < $count && $lines[$index]['indent'] > $indent) {
            throw self::error('bad indentation', $lines[$index]['line']);
        }
        return $result;
    }

    protected static function parseSequence(array $lines, int &$index, int $indent): array
    {
        $result = [];
        $count = count($lines);
        while ($index < $count && $lines[$index]['indent'] === $indent && self::isSequence($lines[$index]['content'])) {
            $line = $lines[$index];
            $rest = ltrim(substr($line['content'], 1));
            $index++;
            if ($rest === '') {
                if ($index < $count && $lines[$index]['indent'] > $indent) {
                    $result[] = self::parseBlock($lines, $index, $lines[$index]['indent']);
                } else {
                    $result[] = null;
                }
                continue;
            }
            if (self::looksLikeKey($rest)) {
                list($key, $value) = self::splitKey($rest, $line['line']);
                $item = [];
                if ($value === '') {
                    if ($index < $count && $lines[$index]['indent'] > $indent) {
                        $item[$key] = self::parseBlock($lines, $index, $lines[$index]['indent']);
                    } else {
                        $item[$key] = null;
                    }
                } elseif ($value === '|' || $value === '>') {
                    $item[$key] = self::parseBlockScalar($lines, $index, $indent, $value);
                } else {
                    $item[$key] = self::parseScalar($value, $line['line']);
                }
                if ($index < $count && $lines[$index]['indent'] > $indent) {
                    $extra = self::parseMapping($lines, $index, $lines[$index]['indent']);
                    $item = array_merge($item, $extra);
                }
                $result[] = $item;
            } else {
                $result[] = self::parseScalar($rest, $line['line']);
                if ($index < $count && $lines[$index]['indent'] > $indent) {
                    throw self::error('bad indentation', $lines[$index]['line']);
                }
            }
        }
        return $result;
    }

    protected static function parseBlockScalar(array $lines, int &$index, int $parentIndent, string $style): string
    {
        $parts = [];
        $count = count($lines);
        $minIndent = null;
        $start = $index;
        while ($index < $count && $lines[$index]['indent'] > $parentIndent) {
            $line = $lines[$index];
            if ($minIndent === null) {
                $minIndent = $line['indent'];
            }
            $parts[] = str_repeat(' ', max(0, $line['indent'] - $minIndent)) . $line['content'];
            $index++;
        }
        if ($index === $start) {
            return '';
        }
        return $style === '|' ? implode("\n", $parts) : implode(' ', $parts);
    }

    protected static function isSequence(string $content): bool
    {
        return $content === '-' || strpos($content, '- ') === 0;
    }

    protected static function looksLikeKey(string $content): bool
    {
        $pos = self::findTopLevelColon($content);
        return $pos !== false;
    }

    /**
     * @return array{0:string,1:string}
     */
    protected static function splitKey(string $content, int $line): array
    {
        $pos = self::findTopLevelColon($content);
        if ($pos === false) {
            throw self::error('expected "key: value"', $line);
        }
        $key = trim(substr($content, 0, $pos));
        if ($key === '') {
            throw self::error('empty key', $line);
        }
        if (self::isQuoted($key)) {
            $key = (string)self::parseScalar($key, $line);
        }
        if (strpos($key, '&') === 0 || strpos($key, '*') === 0 || strpos($key, '!') === 0) {
            throw self::error('anchors, aliases and tags are not supported', $line);
        }
        return [$key, trim(substr($content, $pos + 1))];
    }

    protected static function findTopLevelColon(string $content): ?int
    {
        $quote = '';
        $depth = 0;
        $length = strlen($content);
        for ($i = 0; $i < $length; $i++) {
            $ch = $content[$i];
            if ($quote !== '') {
                if ($ch === '\\') {
                    $i++;
                } elseif ($ch === $quote) {
                    $quote = '';
                }
                continue;
            }
            if ($ch === '"' || $ch === "'") {
                $quote = $ch;
            } elseif ($ch === '[' || $ch === '{') {
                $depth++;
            } elseif ($ch === ']' || $ch === '}') {
                $depth = max(0, $depth - 1);
            } elseif ($ch === ':' && $depth === 0) {
                if ($i + 1 >= $length || $content[$i + 1] === ' ' || $content[$i + 1] === "\t") {
                    return $i;
                }
            }
        }
        return null;
    }

    protected static function parseScalar(string $value, int $line)
    {
        $value = self::stripComment($value);
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (self::isQuoted($value)) {
            return self::unquote($value, $line);
        }
        $first = $value[0];
        if ($first === '&' || $first === '*' || $first === '!') {
            throw self::error('anchors, aliases and tags are not supported', $line);
        }
        if ($first === '[') {
            return self::parseInlineList($value, $line);
        }
        if ($first === '{') {
            return self::parseInlineMap($value, $line);
        }
        $lower = strtolower($value);
        if ($lower === 'null' || $value === '~') {
            return null;
        }
        if ($lower === 'true' || $lower === 'false') {
            return $lower === 'true';
        }
        if (preg_match('/^-?\d+$/', $value) === 1) {
            return (int)$value;
        }
        if (preg_match('/^-?\d+\.\d+([eE][+-]?\d+)?$/', $value) === 1 || preg_match('/^-?\d+[eE][+-]?\d+$/', $value) === 1) {
            return (float)$value;
        }
        return $value;
    }

    protected static function parseInlineList(string $value, int $line): array
    {
        $inner = trim(substr($value, 1, -1));
        if ($inner === '') {
            return [];
        }
        $items = self::splitInline($inner);
        $result = [];
        foreach ($items as $item) {
            $result[] = self::parseScalar($item, $line);
        }
        return $result;
    }

    protected static function parseInlineMap(string $value, int $line): array
    {
        $inner = trim(substr($value, 1, -1));
        if ($inner === '') {
            return [];
        }
        $result = [];
        foreach (self::splitInline($inner) as $pair) {
            list($key, $itemValue) = self::splitKey($pair, $line);
            $result[$key] = $itemValue === '' ? null : self::parseScalar($itemValue, $line);
        }
        return $result;
    }

    /**
     * 按顶层逗号切分 flow 内容,尊重引号与嵌套括号
     * @return array<int,string>
     */
    protected static function splitInline(string $value): array
    {
        $parts = [];
        $buffer = '';
        $quote = '';
        $depth = 0;
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $ch = $value[$i];
            if ($quote !== '') {
                $buffer .= $ch;
                if ($ch === '\\') {
                    if ($i + 1 < $length) {
                        $buffer .= $value[++$i];
                    }
                } elseif ($ch === $quote) {
                    $quote = '';
                }
                continue;
            }
            if ($ch === '"' || $ch === "'") {
                $quote = $ch;
                $buffer .= $ch;
            } elseif ($ch === '[' || $ch === '{') {
                $depth++;
                $buffer .= $ch;
            } elseif ($ch === ']' || $ch === '}') {
                $depth = max(0, $depth - 1);
                $buffer .= $ch;
            } elseif ($ch === ',' && $depth === 0) {
                $parts[] = trim($buffer);
                $buffer = '';
            } else {
                $buffer .= $ch;
            }
        }
        if (trim($buffer) !== '') {
            $parts[] = trim($buffer);
        }
        return $parts;
    }

    protected static function isQuoted(string $value): bool
    {
        $length = strlen($value);
        return $length >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[$length - 1] === $value[0];
    }

    protected static function unquote(string $value, int $line): string
    {
        $quote = $value[0];
        $inner = substr($value, 1, -1);
        if ($quote === "'") {
            return str_replace("''", "'", $inner);
        }
        return stripcslashes($inner);
    }

    protected static function stripComment(string $value): string
    {
        $quote = '';
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $ch = $value[$i];
            if ($quote !== '') {
                if ($ch === '\\') {
                    $i++;
                } elseif ($ch === $quote) {
                    $quote = '';
                }
                continue;
            }
            if ($ch === '"' || $ch === "'") {
                $quote = $ch;
            } elseif ($ch === '#' && ($i === 0 || $value[$i - 1] === ' ' || $value[$i - 1] === "\t")) {
                return rtrim(substr($value, 0, $i));
            }
        }
        return $value;
    }

    protected static function error(string $message, int $line): ProtocolParseException
    {
        return new ProtocolParseException('YAML: ' . $message, $line, 0);
    }
}
