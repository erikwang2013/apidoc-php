<?php
declare(strict_types = 1);

namespace erikwang2013\apidoc\protocol\Support;

use erikwang2013\apidoc\protocol\ProtocolParseException;

/**
 * 轻量词法流:空白/注释跳过、标识符/数字/字符串、行列跟踪
 */
class TokenStream
{
    protected $source;
    protected $length;
    protected $pos = 0;
    protected $line = 1;
    protected $column = 1;

    public function __construct(string $source)
    {
        $this->source = str_replace(["\r\n", "\r"], "\n", $source);
        $this->length = strlen($this->source);
    }

    public function eof(): bool
    {
        $this->skipTrivia();
        return $this->pos >= $this->length;
    }

    public function next(): string
    {
        if ($this->pos >= $this->length) {
            return '';
        }
        $char = $this->source[$this->pos];
        $this->advance();
        return $char;
    }

    public function peek(int $offset = 0): string
    {
        $savePos = $this->pos;
        $saveLine = $this->line;
        $saveColumn = $this->column;
        $this->skipTrivia();
        for ($i = 0; $i < $offset; $i++) {
            $this->advance();
        }
        $char = $this->pos < $this->length ? $this->source[$this->pos] : '';
        $this->pos = $savePos;
        $this->line = $saveLine;
        $this->column = $saveColumn;
        return $char;
    }

    public function consume(string $chars): ?string
    {
        $this->skipTrivia();
        if ($this->pos >= $this->length) {
            return null;
        }
        $char = $this->source[$this->pos];
        if (strpos($chars, $char) === false) {
            return null;
        }
        $this->advance();
        return $char;
    }

    public function expect(string $char): void
    {
        $this->skipTrivia();
        if ($this->pos >= $this->length || $this->source[$this->pos] !== $char) {
            $this->error('expected "' . $char . '"');
        }
        $this->advance();
    }

    public function identifier(): string
    {
        $this->skipTrivia();
        if (preg_match('/\G[A-Za-z_][A-Za-z0-9_.]*/', $this->source, $matches, 0, $this->pos) !== 1) {
            $this->error('expected identifier');
        }
        $value = $matches[0];
        for ($i = 0; $i < strlen($value); $i++) {
            $this->advance();
        }
        return $value;
    }

    public function number(): string
    {
        $this->skipTrivia();
        if (preg_match('/\G-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?/', $this->source, $matches, 0, $this->pos) !== 1) {
            $this->error('expected number');
        }
        $value = $matches[0];
        for ($i = 0; $i < strlen($value); $i++) {
            $this->advance();
        }
        return $value;
    }

    public function string(): string
    {
        $this->skipTrivia();
        if ($this->pos >= $this->length || ($this->source[$this->pos] !== '"' && $this->source[$this->pos] !== "'")) {
            $this->error('expected string');
        }
        $quote = $this->source[$this->pos];
        $this->advance();
        $value = '';
        while ($this->pos < $this->length) {
            $char = $this->source[$this->pos];
            if ($char === '\\') {
                $this->advance();
                if ($this->pos >= $this->length) {
                    $this->error('unterminated string escape');
                }
                $value .= stripcslashes('\\' . $this->source[$this->pos]);
                $this->advance();
                continue;
            }
            if ($char === $quote) {
                $this->advance();
                return $value;
            }
            $value .= $char;
            $this->advance();
        }
        $this->error('unterminated string');
        return '';
    }

    public function skipTrivia(): void
    {
        while ($this->pos < $this->length) {
            $char = $this->source[$this->pos];
            if ($char === ' ' || $char === "\t" || $char === "\n") {
                $this->advance();
                continue;
            }
            if ($char === '#' || ($char === '/' && $this->peekRaw(1) === '/')) {
                while ($this->pos < $this->length && $this->source[$this->pos] !== "\n") {
                    $this->advance();
                }
                continue;
            }
            if ($char === '/' && $this->peekRaw(1) === '*') {
                $this->advance();
                $this->advance();
                while ($this->pos < $this->length && !($this->source[$this->pos] === '*' && $this->peekRaw(1) === '/')) {
                    $this->advance();
                }
                if ($this->pos >= $this->length) {
                    $this->error('unterminated block comment');
                }
                $this->advance();
                $this->advance();
                continue;
            }
            break;
        }
    }

    public function line(): int
    {
        return $this->line;
    }

    public function column(): int
    {
        return $this->column;
    }

    public function error(string $message): void
    {
        throw new ProtocolParseException($message, $this->line, $this->column);
    }

    protected function peekRaw(int $offset): string
    {
        $index = $this->pos + $offset;
        return $index < $this->length ? $this->source[$index] : '';
    }

    protected function advance(): void
    {
        if ($this->pos >= $this->length) {
            return;
        }
        if ($this->source[$this->pos] === "\n") {
            $this->line++;
            $this->column = 1;
        } else {
            $this->column++;
        }
        $this->pos++;
    }
}
