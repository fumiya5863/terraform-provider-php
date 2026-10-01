<?php
declare(strict_types=1);

namespace PhpTf\MsgPack;

/** str ではなく bin として出すことを強制する印（dynamic 型の第1要素で必要）。 */
final readonly class RawBin
{
    public function __construct(public string $bytes) {}
}
