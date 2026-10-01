<?php
declare(strict_types=1);

namespace PhpTf\Grpc;

/**
 * gRPC のステータスコードと、trailer へ載せるメッセージの符号化。
 *
 * grpc-message は生の文字列ではなく Percent-Encoded でなければならない
 * （PROTOCOL-HTTP2: Status-Message → "grpc-message" Percent-Encoded）。
 * 日本語や制御文字をそのまま載せると、クライアント側で文字化けするか、
 * 実装によっては trailer ごと破棄される。
 */
final class Status
{
    public const OK = '0';
    public const UNKNOWN = '2';
    public const UNIMPLEMENTED = '12';

    /**
     * そのまま載せてよいのは %x20-%x24 と %x26-%x7E のみ。
     * それ以外（制御文字・マルチバイト・'%' 自身）は %XX にする。
     */
    public static function encodeMessage(string $message): string
    {
        $out = '';
        foreach (str_split($message) as $char) {
            $b = ord($char);
            if (($b >= 0x20 && $b <= 0x24) || ($b >= 0x26 && $b <= 0x7E)) {
                $out .= $char;
            } else {
                $out .= sprintf('%%%02X', $b);
            }
        }
        return $out;
    }
}
