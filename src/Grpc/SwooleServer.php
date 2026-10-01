<?php
declare(strict_types=1);

namespace PhpTf\Grpc;

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;

/**
 * Swoole の HTTP/2 サーバの上に gRPC を自前で載せる。
 *
 * 公式は PHP を gRPC クライアント専用としており、サーバは提供されていない。
 * そこで HTTP/2 の終端だけ Swoole に任せ、gRPC の約束事はここで実装する:
 *
 *   - パスは /パッケージ.サービス/メソッド
 *   - 本体は 1バイトの圧縮フラグ + 4バイト長 + protobuf
 *   - 成否は HTTP ステータスではなく trailer の grpc-status で返す
 *     （gRPC 仕様: "Status must be sent in Trailers even if the status code is OK."）
 */
final class SwooleServer
{
    public function __construct(
        private readonly ProviderService $service = new ProviderService(),
    ) {}

    /**
     * サーバを構築して返す。start() はここでは呼ばない。
     *
     * ハンドシェイク行には実際に待ち受けたポート番号が要るが、
     * それが確定するのは bind 後・start() 前なので、
     * 「構築」と「開始」を分けて呼び出し側に制御を渡している。
     */
    public function create(string $host = '127.0.0.1', int $port = 0): Server
    {
        $server = new Server($host, $port, SWOOLE_BASE);

        $server->set([
            'open_http2_protocol' => true,
            // 標準出力はハンドシェイク専用。ログを混ぜると認識に失敗する。
            'log_level' => SWOOLE_LOG_ERROR,
            'log_file'  => getenv('TFPHP_LOG_FILE') ?: '/dev/null',
            'worker_num' => 1,
            'enable_coroutine' => true,
        ]);

        $server->on('request', function (Request $req, Response $res): void {
            $this->dispatch($req, $res);
        });

        return $server;
    }

    private function dispatch(Request $req, Response $res): void
    {
        $path = $req->server['request_uri'] ?? '';

        $res->status(200);
        $res->header('content-type', 'application/grpc');
        // どの trailer を後送するかを先に宣言しておく
        $res->header('trailer', 'grpc-status, grpc-message');

        $status = Status::OK;
        $message = '';
        $body = '';

        try {
            $messages = Frame::unwrap((string) $req->getContent());
            // Terraform が使う RPC はすべて単発リクエスト
            $body = Frame::wrap($this->service->handle($path, $messages[0] ?? ''));
        } catch (UnimplementedMethod $e) {
            $status = Status::UNIMPLEMENTED;
            $message = $e->getMessage();
        } catch (\Throwable $e) {
            $status = Status::UNKNOWN;
            $message = $e->getMessage();
            fwrite(STDERR, "[error] {$path}: {$message}\n");
        }

        // trailer は end() より前に積む。write() を使うと Swoole は
        // ストリーミングモードに入り、trailer を送らずにストリームを閉じる。
        // その場合 Terraform は
        //   "server closed the stream without sending trailers"
        // で失敗する。
        $res->trailer('grpc-status', $status);
        $res->trailer('grpc-message', Status::encodeMessage($message));
        $res->end($body);
    }
}
