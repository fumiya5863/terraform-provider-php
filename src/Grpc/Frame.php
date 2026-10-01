<?php
declare(strict_types=1);

namespace PhpTf\Grpc;

/**
 * gRPC の「長さ前置きメッセージ」のフレーミング。
 *
 * HTTP/2 の DATA フレームの中身は、protobuf をそのまま流すのではなく
 * 1バイトの圧縮フラグ + 4バイトのビッグエンディアン長 + 本体 という形を取る。
 * ここを知らないと、protobuf のパースが必ず失敗する。
 */
final class Frame
{
    /** protobuf バイト列を gRPC メッセージ1件に包む。 */
    public static function wrap(string $message, bool $compressed = false): string
    {
        return chr($compressed ? 1 : 0) . pack('N', strlen($message)) . $message;
    }

    /**
     * gRPC メッセージ列をほどく。1リクエストに複数載りうる（ストリーミング）。
     *
     * @return list<string> protobuf バイト列の並び
     */
    public static function unwrap(string $body): array
    {
        $out = [];
        $off = 0;
        $len = strlen($body);

        while ($off < $len) {
            if ($off + 5 > $len) {
                throw new \RuntimeException(
                    'gRPC メッセージのヘッダが途中で切れている (残り ' . ($len - $off) . ' バイト)'
                );
            }
            $compressed = ord($body[$off]);
            $size = unpack('N', substr($body, $off + 1, 4))[1];
            $off += 5;

            if ($off + $size > $len) {
                throw new \RuntimeException(
                    "gRPC メッセージが途中で切れている (宣言長 {$size}, 残り " . ($len - $off) . ')'
                );
            }
            if ($compressed !== 0) {
                // Terraform は圧縮を使わない。0 以外は実装漏れとして明示的に落とす。
                throw new \RuntimeException(
                    "圧縮された gRPC メッセージは未対応 (compressed-flag={$compressed})"
                );
            }

            $out[] = substr($body, $off, $size);
            $off += $size;
        }

        return $out;
    }
}
