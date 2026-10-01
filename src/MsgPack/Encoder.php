<?php
declare(strict_types=1);

namespace PhpTf\MsgPack;

/**
 * MessagePack エンコーダ（Terraform が必要とする範囲の実装）。
 *
 * 仕様は https://github.com/msgpack/msgpack/blob/master/spec.md
 * object-wire-format.md は「値を表現できる最もコンパクトな形式を選べ」と推奨しているので、
 * 各型で最小幅のフォーマットを選択する。
 */
final class Encoder
{
    public function encode(mixed $v): string
    {
        return match (true) {
            $v === null       => "\xc0",
            $v === true       => "\xc3",
            $v === false      => "\xc2",
            $v instanceof Ext    => $this->ext($v),
            $v instanceof MapOf  => $this->map($v->entries, $v->stringKeys),
            $v instanceof RawBin => $this->bin($v->bytes),
            is_int($v)        => $this->int($v),
            is_float($v)      => $this->float($v),
            is_string($v)     => $this->str($v),
            is_array($v)      => array_is_list($v) ? $this->arr($v) : $this->map($v, true),
            default           => throw new \InvalidArgumentException(
                'encode できない型: ' . get_debug_type($v)
            ),
        };
    }

    /** 整数。正負それぞれ最小幅を選ぶ。 */
    private function int(int $n): string
    {
        if ($n >= 0) {
            if ($n < 0x80)        return chr($n);                        // positive fixint
            if ($n <= 0xff)       return "\xcc" . chr($n);               // uint8
            if ($n <= 0xffff)     return "\xcd" . pack('n', $n);         // uint16
            if ($n <= 0xffffffff) return "\xce" . pack('N', $n);         // uint32
            return "\xcf" . pack('J', $n);                               // uint64
        }
        if ($n >= -0x20)       return chr(0xe0 | ($n + 0x20));           // negative fixint
        if ($n >= -0x80)       return "\xd0" . pack('c', $n);            // int8
        if ($n >= -0x8000)     return "\xd1" . pack('n', $n & 0xffff);   // int16
        if ($n >= -0x80000000) return "\xd2" . pack('N', $n & 0xffffffff); // int32
        return "\xd3" . pack('J', $n);                                   // int64
    }

    /** 浮動小数点は常に float64。Terraform の number は任意精度なので縮めない。 */
    private function float(float $f): string
    {
        return "\xcb" . pack('E', $f);
    }

    private function str(string $s): string
    {
        $len = strlen($s);
        if ($len < 0x20)   return chr(0xa0 | $len) . $s;        // fixstr
        if ($len <= 0xff)  return "\xd9" . chr($len) . $s;      // str8
        if ($len <= 0xffff) return "\xda" . pack('n', $len) . $s; // str16
        return "\xdb" . pack('N', $len) . $s;                   // str32
    }

    /** バイナリ。dynamic 型の第1要素（型制約JSON）で使う。 */
    public function bin(string $s): string
    {
        $len = strlen($s);
        if ($len <= 0xff)   return "\xc4" . chr($len) . $s;
        if ($len <= 0xffff) return "\xc5" . pack('n', $len) . $s;
        return "\xc6" . pack('N', $len) . $s;
    }

    private function arr(array $a): string
    {
        $len = count($a);
        $head = match (true) {
            $len < 0x10     => chr(0x90 | $len),
            $len <= 0xffff  => "\xdc" . pack('n', $len),
            default         => "\xdd" . pack('N', $len),
        };
        $out = $head;
        foreach ($a as $item) {
            $out .= $this->encode($item);
        }
        return $out;
    }

    private function map(array $m, bool $stringKeys): string
    {
        $len = count($m);
        $head = match (true) {
            $len < 0x10     => chr(0x80 | $len),
            $len <= 0xffff  => "\xde" . pack('n', $len),
            default         => "\xdf" . pack('N', $len),
        };
        $out = $head;
        foreach ($m as $k => $v) {
            // Terraform のマップ/オブジェクトのキーは常に文字列。PHP は "123" のような
            // 数値文字列キーを勝手に int 化するため、ここで明示的に文字列へ戻す。
            // 一方 refinement マップだけは整数キーが正なので、その場合はそのまま符号化する。
            $out .= ($stringKeys ? $this->str((string) $k) : $this->encode($k))
                  . $this->encode($v);
        }
        return $out;
    }

    private function ext(Ext $e): string
    {
        $len = strlen($e->payload);
        $head = match (true) {
            $len === 1  => "\xd4",
            $len === 2  => "\xd5",
            $len === 4  => "\xd6",
            $len === 8  => "\xd7",
            $len === 16 => "\xd8",
            $len <= 0xff   => "\xc7" . chr($len),
            $len <= 0xffff => "\xc8" . pack('n', $len),
            default        => "\xc9" . pack('N', $len),
        };
        return $head . chr($e->code) . $e->payload;
    }
}
