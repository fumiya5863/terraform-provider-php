<?php
declare(strict_types=1);

namespace PhpTf\MsgPack;

/**
 * MessagePack の拡張型（extension type）。
 *
 * Terraform はこれを「unknown value（apply 時まで決まらない値）」の表現に使う。
 *   code 0  : 素の unknown。payload は完全に無視される
 *   code 12 : refined unknown。payload は整数キーのマップで追加制約を運ぶ
 *
 * object-wire-format.md は「どんな拡張コードも unknown とみなせ」と明記している。
 */
final readonly class Ext
{
    public function __construct(
        public int $code,
        public string $payload,
    ) {}
}
