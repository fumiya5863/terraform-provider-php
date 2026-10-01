<?php
declare(strict_types=1);

namespace PhpTf\MsgPack;

/**
 * MessagePack デコーダ（Terraform が送ってくる範囲の実装）。
 *
 * Terraform 側は「どの表現形式を選ぶかは Terraform のバージョンによって変わりうるが、
 * 型は contractual である」と明言しているため、同じ型の全フォーマットを受理する必要がある。
 */
final class Decoder
{
    private int $pos = 0;

    public function __construct(private readonly string $buf) {}

    public static function decode(string $bytes): mixed
    {
        return (new self($bytes))->read();
    }

    public function read(): mixed
    {
        $b = $this->u8();

        // 単バイトで型と値が決まるもの
        if ($b <= 0x7f) return $b;                               // positive fixint
        if ($b >= 0xe0) return $b - 0x100;                       // negative fixint
        if (($b & 0xf0) === 0x80) return $this->map($b & 0x0f);  // fixmap
        if (($b & 0xf0) === 0x90) return $this->arr($b & 0x0f);  // fixarray
        if (($b & 0xe0) === 0xa0) return $this->take($b & 0x1f); // fixstr

        return match ($b) {
            0xc0 => null,
            0xc2 => false,
            0xc3 => true,

            // bin は str と別の型。RawBin で包んで区別を保つ。
            // dynamic の第1要素は bin でなければならないため、ここを潰すと再符号化で壊れる。
            0xc4 => new RawBin($this->take($this->u8())),
            0xc5 => new RawBin($this->take($this->u16())),
            0xc6 => new RawBin($this->take($this->u32())),

            0xc7 => $this->ext($this->u8()),
            0xc8 => $this->ext($this->u16()),
            0xc9 => $this->ext($this->u32()),

            0xca => $this->f32(),
            0xcb => $this->f64(),

            0xcc => $this->u8(),
            0xcd => $this->u16(),
            0xce => $this->u32(),
            0xcf => $this->u64(),

            0xd0 => $this->i8(),
            0xd1 => $this->i16(),
            0xd2 => $this->i32(),
            0xd3 => $this->i64(),

            0xd4 => $this->ext(1),
            0xd5 => $this->ext(2),
            0xd6 => $this->ext(4),
            0xd7 => $this->ext(8),
            0xd8 => $this->ext(16),

            0xd9 => $this->take($this->u8()),
            0xda => $this->take($this->u16()),
            0xdb => $this->take($this->u32()),

            0xdc => $this->arr($this->u16()),
            0xdd => $this->arr($this->u32()),
            0xde => $this->map($this->u16()),
            0xdf => $this->map($this->u32()),

            default => throw new \RuntimeException(
                sprintf('未知の msgpack ヘッダバイト 0x%02x (offset %d)', $b, $this->pos - 1)
            ),
        };
    }

    private function take(int $n): string
    {
        if ($this->pos + $n > strlen($this->buf)) {
            throw new \RuntimeException('msgpack が途中で終わっている');
        }
        $s = substr($this->buf, $this->pos, $n);
        $this->pos += $n;
        return $s;
    }

    private function u8(): int  { return ord($this->take(1)); }
    private function u16(): int { return unpack('n', $this->take(2))[1]; }
    private function u32(): int { return unpack('N', $this->take(4))[1]; }
    /**
     * uint64。PHP の int は 64bit 符号付きなので 2^63 以上を表現できない。
     * 黙って負数に化けさせると気づけないため、範囲外は10進文字列で返す。
     * Terraform の number も任意精度のときは文字列で来るので、扱いとして整合する。
     */
    private function u64(): int|string
    {
        $v = unpack('J', $this->take(8))[1];
        if ($v < 0) {
            // 符号付きとして負に見える = 2^63 以上。文字列へ逃がす。
            return sprintf('%u', $v);
        }
        return $v;
    }
    private function i8(): int  { return unpack('c', $this->take(1))[1]; }

    // 符号付き整数は、PHP にビッグエンディアン指定の書式が無い。
    // strrev + マシンエンディアンに頼るとビッグエンディアン環境で壊れるため、
    // 符号なしで読んでから2の補数を自前で戻す（どの環境でも同じ結果になる）。
    private function i16(): int
    {
        $v = $this->u16();
        return $v >= 0x8000 ? $v - 0x10000 : $v;
    }

    private function i32(): int
    {
        $v = $this->u32();
        return $v >= 0x80000000 ? $v - 0x100000000 : $v;
    }

    /** PHP の int は 64bit 符号付きなので、'J' の結果がそのまま符号付きの解釈になる。 */
    private function i64(): int { return unpack('J', $this->take(8))[1]; }
    private function f32(): float { return unpack('G', $this->take(4))[1]; }
    private function f64(): float { return unpack('E', $this->take(8))[1]; }

    private function arr(int $n): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = $this->read();
        }
        return $out;
    }

    /**
     * マップは MapOf で包んで返す。
     *
     * PHP の配列は「空マップ」と「空配列」を区別できず、"0","1",... という
     * 文字列キーも勝手に int 化されるため、素の配列で返すと再符号化で
     * マップが配列に化ける。キーが文字列だったかどうかも併せて保持する。
     */
    private function map(int $n): MapOf
    {
        $out = [];
        $stringKeys = true;
        for ($i = 0; $i < $n; $i++) {
            $k = $this->read();
            if ($k instanceof RawBin) {
                $k = $k->bytes;
            }
            if (is_int($k)) {
                // refinement マップだけが整数キーを使う
                $stringKeys = false;
                $out[$k] = $this->read();
            } else {
                $out[(string) $k] = $this->read();
            }
        }
        return new MapOf($out, $stringKeys);
    }

    private function ext(int $len): Ext
    {
        $code = $this->u8();
        return new Ext($code, $this->take($len));
    }
}
