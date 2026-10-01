<?php
declare(strict_types=1);

namespace PhpTf\Cty;

/**
 * cty の値。Terraform の値は「型」だけでなく3つの状態を持つ。
 *
 *   KNOWN   : 値が確定している
 *   NULL    : 型はあるが値が無い（msgpack nil）
 *   UNKNOWN : apply 時まで決まらない（msgpack 拡張型）
 *
 * この3状態こそが Go SDK が隠していたもので、PHP の素の値では表現できない。
 */
final readonly class Value
{
    private const KNOWN = 'known';
    private const NIL = 'null';
    private const UNKNOWN = 'unknown';

    /** @param array<int,mixed> $refinements 拡張コード12で運ばれる絞り込み情報 */
    private function __construct(
        public Type $type,
        private string $state,
        private mixed $raw = null,
        public array $refinements = [],
    ) {}

    public static function known(Type $type, mixed $raw): self
    {
        return new self($type, self::KNOWN, $raw);
    }

    public static function null(Type $type): self
    {
        return new self($type, self::NIL);
    }

    /** @param array<int,mixed> $refinements */
    public static function unknown(Type $type, array $refinements = []): self
    {
        return new self($type, self::UNKNOWN, null, $refinements);
    }

    public function isKnown(): bool   { return $this->state === self::KNOWN; }
    public function isNull(): bool    { return $this->state === self::NIL; }
    public function isUnknown(): bool { return $this->state === self::UNKNOWN; }

    /** KNOWN のときだけ中身を取り出せる。NULL/UNKNOWN で呼べば例外。 */
    public function raw(): mixed
    {
        if (!$this->isKnown()) {
            throw new \LogicException(
                "値が {$this->state} なので取り出せない（型: {$this->type->kind()}）"
            );
        }
        return $this->raw;
    }

    /** 表示用。テストの失敗メッセージで使う。 */
    public function describe(): string
    {
        return match ($this->state) {
            self::NIL     => 'null',
            self::UNKNOWN => $this->refinements === []
                ? 'unknown'
                : 'unknown(refined: ' . implode(',', array_keys($this->refinements)) . ')',
            default       => json_encode($this->raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        };
    }
}
