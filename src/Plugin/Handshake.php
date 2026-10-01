<?php
declare(strict_types=1);

namespace PhpTf\Plugin;

/**
 * go-plugin のハンドシェイク。
 *
 * Terraform はプラグインを子プロセスとして起動し、その「標準出力の最初の1行」だけを読む。
 * 仕様は Terraform 側では文書化されておらず（README に "not currently documented" と明記）、
 * hashicorp/go-plugin の server.go が事実上の仕様になっている。
 *
 *   CORE | APP | NETWORK | ADDR | PROTOCOL | 証明書(base64)
 *   例:  1|6|unix|/tmp/plugin123|grpc|
 *
 * 落とし穴:
 *   - CORE は 1 固定。他の値を出すとロードに失敗する
 *   - 証明書の base64 は「パディング禁止」。Rust/Python/C# の実装が全て独立に踏んでいる
 *   - この1行より前に何か出力すると、Terraform がプラグインを認識できなくなる
 */
final class Handshake
{
    /** go-plugin 自体のプロトコルバージョン。server.go の CoreProtocolVersion と一致させる。 */
    public const CORE_PROTOCOL_VERSION = 1;

    /** Terraform プラグインプロトコル。6 は Terraform 1.0 以降。 */
    public const APP_PROTOCOL_VERSION = 6;

    public static function line(
        string $network,
        string $address,
        ?string $certificateDer = null,
    ): string {
        $cert = '';
        if ($certificateDer !== null) {
            // 「パディング無し」が必須。改行を含められないので PEM は不可。
            $cert = rtrim(base64_encode($certificateDer), '=');
        }

        return implode('|', [
            self::CORE_PROTOCOL_VERSION,
            self::APP_PROTOCOL_VERSION,
            $network,
            $address,
            'grpc',
            $cert,
        ]);
    }

    /**
     * ハンドシェイク行を標準出力へ出し、即座に流し切る。
     * Terraform はこの1行を読むまでブロックするため、バッファに溜めてはいけない。
     */
    public static function announce(string $network, string $address, ?string $certDer = null): void
    {
        fwrite(STDOUT, self::line($network, $address, $certDer) . "\n");
        fflush(STDOUT);
    }

    /**
     * Terraform が渡してくる magic cookie の検証。
     *
     * go-plugin 曰く "This is not a security measure, just a UX feature." で、
     * 「直接実行するな」と人間に伝えるためだけのもの。実際、Rust版・Python版の
     * 非Go実装はどちらも検証コードを持っていない。
     */
    public const MAGIC_COOKIE_KEY = 'TF_PLUGIN_MAGIC_COOKIE';
    public const MAGIC_COOKIE_VALUE = 'd602bf8f470bc67ca7faa0386276bbdd4330efaf76d1a219cb4d6991ca9872b2';

    public static function looksLikeDirectExecution(): bool
    {
        return (getenv(self::MAGIC_COOKIE_KEY) ?: '') !== self::MAGIC_COOKIE_VALUE;
    }
}
