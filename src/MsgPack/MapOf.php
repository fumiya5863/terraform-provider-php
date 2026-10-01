<?php
declare(strict_types=1);

namespace PhpTf\MsgPack;

/**
 * msgpack は「空のマップ」と「空の配列」を別の型として区別するが、
 * PHP の [] はどちらにも見えてしまう。どちらで出すかを明示するための印。
 */
final readonly class MapOf
{
    /**
     * @param array<string|int,mixed> $entries
     * @param bool $stringKeys Terraform のマップ/オブジェクトのキーは常に文字列。
     *                         例外は unknown の refinement マップだけで、そこは整数キー。
     */
    public function __construct(
        public array $entries,
        public bool $stringKeys = true,
    ) {}
}
