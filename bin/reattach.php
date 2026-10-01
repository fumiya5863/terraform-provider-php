<?php
declare(strict_types=1);

/**
 * TF_REATTACH_PROVIDERS 方式で provider を起動する（開発用）。
 *
 * Terraform にプラグインを起動させるのではなく、こちらが先に起動しておき、
 * Terraform には「そこへ繋げ」と教える方式。公式のデバッグ用機能で、
 * go-plugin のハンドシェイクと AutoMTLS を丸ごと迂回できるため、
 * gRPC と値型の検証に集中できる。
 */

require __DIR__ . '/../vendor/autoload.php';

use PhpTf\Grpc\SwooleServer;

if (!extension_loaded('swoole')) {
    fwrite(STDERR, "swoole 拡張が読み込まれていません\n");
    exit(1);
}

$host = '127.0.0.1';
$port = (int) (getenv('TFPHP_PORT') ?: 0);

$server = new SwooleServer()->create($host, $port);
$actualPort = $server->port;

$reattach = [
    'registry.terraform.io/fumiya5863/php' => [
        'Protocol' => 'grpc',
        'ProtocolVersion' => 6,
        'Pid' => getmypid(),
        'Test' => true,
        'Addr' => ['Network' => 'tcp', 'String' => "{$host}:{$actualPort}"],
    ],
];

fwrite(STDERR, "provider を起動しました: {$host}:{$actualPort}\n\n");
fwrite(STDERR, "別のターミナルで:\n");
fwrite(STDERR, "  export TF_REATTACH_PROVIDERS='" . json_encode($reattach, JSON_UNESCAPED_SLASHES) . "'\n");
fwrite(STDERR, "  terraform plan\n\n");

file_put_contents(
    __DIR__ . '/../.reattach.json',
    json_encode($reattach, JSON_UNESCAPED_SLASHES) . "\n"
);

$server->start();
