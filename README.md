# terraform-provider-php

Terraform のプラグインプロトコルを PHP で実装したものです。`terraform plan`、`apply`、`destroy` が動作します。

実験的な実装であり、本番環境での利用は想定していません。詳しくは[制限事項](#制限事項)を参照してください。

## 背景

Terraform の provider は Go で書くのが一般的で、HashiCorp が提供する SDK も Go 向けのみです。一方で、公式ドキュメントには次の記述があります。

> Go is currently the only programming language supported by HashiCorp for building Terraform providers.

> while it is technically possible to write providers in languages other than Go, our tooling, documentation, and ecosystem will all assume your provider is being written in and distributed as Go code for the time being.

出典: [Provider Code Best Practices](https://developer.hashicorp.com/terraform/plugin/best-practices/provider-code)

つまり、サポート対象が Go のみであるだけで、他の言語での実装自体は技術的に可能だと明記されています。これを PHP で確かめたものがこのリポジトリです。

## 実装の内容

Terraform と provider は gRPC で通信します。プロトコル定義 (`.proto`) は公開されていますが、そこからスタブを生成するだけでは動作しません。Go SDK が担っていた処理を自前で実装する必要があります。

| ディレクトリ | 内容 |
| --- | --- |
| `src/MsgPack/` | MessagePack の符号化と復号。拡張型、空マップと空配列の区別、bin と str の区別を扱います |
| `src/Cty/` | Terraform の型システム。known、null、unknown の 3 状態と、MessagePack / JSON との相互変換を実装しています |
| `src/Grpc/` | gRPC のメッセージフレーミング、trailer による `grpc-status` の返却、Swoole の HTTP/2 サーバ上での処理 |
| `src/Plugin/` | go-plugin のハンドシェイク |
| `src/Provider/` | provider 本体。ローカルファイルを管理するリソースと、cty の全型を検証するリソースを含みます |

実装にあたって対応が必要だった主な箇所は次のとおりです。

- PHP を gRPC サーバとして動作させること。gRPC 公式は PHP をクライアント専用としているため、HTTP/2 の終端には Swoole 拡張を使っています
- ハンドシェイク。標準出力の 1 行目に所定の形式で接続先を出力します
- gRPC Health Checking Service の登録。go-plugin がサービス名 `plugin` の `SERVING` を要求します
- cty 型システムと MessagePack の相互変換

### PHP 固有の注意点

実装中に対応が必要だった、PHP の挙動に起因する箇所です。

- PHP は `"123"` のような文字列の配列キーを整数に変換します。Terraform のマップキーは常に文字列であるため、符号化時に文字列へ戻す必要があります
- ただし unknown 値の refinement マップのみ整数キーが仕様です。前項の対応を一律に適用すると壊れます
- MessagePack では空マップ (`0x80`) と空配列 (`0x90`) が別の型ですが、PHP の `[]` はどちらとも解釈できます (`array_is_list([])` は `true` を返します)
- `pack('d')` はマシンのエンディアンに依存します。MessagePack はビッグエンディアン固定のため `pack('E')` を使います
- `unpack('J')` は 2 の 63 乗以上の値を負数として返します

## 必要な環境

| 項目 | 備考 |
| --- | --- |
| PHP 8.4 系 | Swoole 6.1.x が PHP 8.5 に未対応のため |
| Swoole 拡張 (HTTP/2 有効) | `php --ri swoole` に `http2 => enabled` と表示されること |
| Composer | |
| protoc | protobuf スタブの生成に使用します |
| Terraform または OpenTofu | Terraform 1.5.1 と 1.16.4、OpenTofu 1.13.0 で動作を確認しています |

macOS で Swoole 6.1 系をビルドする場合、`sw_usleep` が未定義でコンパイルに失敗します ([swoole-src#6243](https://github.com/swoole/swoole-src/issues/6243))。次のように回避できます。

```bash
CFLAGS="-I$(brew --prefix)/include -Dsw_usleep=usleep" \
CXXFLAGS="-I$(brew --prefix)/include -Dsw_usleep=usleep" \
pecl install --configureoptions 'enable-openssl="no" enable-http2="yes" enable-sockets="yes"' \
  swoole-6.1.10
```

## セットアップ

```bash
make setup        # composer install と protobuf スタブの生成
make dev-config   # dev/terraformrc を生成します
```

`dev/terraformrc` は絶対パスを含むため、リポジトリでは管理していません。

## 使い方

```bash
cd examples/file
export TF_CLI_CONFIG_FILE=$(git rev-parse --show-toplevel)/dev/terraformrc
export TF_DISABLE_PLUGIN_TLS=1
terraform apply -auto-approve
cat out/hello.txt
```

実行すると次のように表示されます。

```
  # php_file.greeting will be created
  + resource "php_file" "greeting" {
      + content  = <<-EOT
            PHP で書いた Terraform provider が作りました
        EOT
      + filename = "./out/hello.txt"
      + id       = "78eee3a7dfb719e3113e08335020ee952a8bbe85eb8ac8642665aaf3f64766e3"
    }

Apply complete! Resources: 1 added, 0 changed, 0 destroyed.
```

`id` はファイル内容の SHA256 です。provider 側で計算して返しています。

`TF_DISABLE_PLUGIN_TLS` は AutoMTLS を無効にする環境変数です。本実装では AutoMTLS を実装していないため指定が必要です。この変数は Terraform のソース上で「エンドユーザーが設定することは想定していない」とされています。

## テスト

```bash
make test
```

| 対象 | 件数 |
| --- | --- |
| cty と MessagePack の単体 | 35 |
| 境界値と異常系 | 60 |
| provider のロジック | 19 |
| 参照実装との相互検証 | 45 / 44 |

自作実装同士での往復確認は仕様への準拠を示せないため、独立した実装との照合を行っています。

- MessagePack 層は Python の `msgpack` パッケージと双方向で照合しています (`tests/crossval.py`)
- cty 層は Terraform 本体の cty 実装と照合しています (`examples/types/`)。apply 後の plan が `No changes` になることで、値が正確に往復していることを確認しています

動作を確認した組み合わせは次のとおりです。

| CLI | apply | destroy |
| --- | --- | --- |
| Terraform 1.5.1 | 動作 | 動作 |
| Terraform 1.16.4 | 動作 | 動作 |
| OpenTofu 1.13.0 | 動作 | 動作 |

## 制限事項

- AutoMTLS を実装していません。`TF_DISABLE_PLUGIN_TLS=1` の指定が必要です
- Terraform Registry への公開ができません。Registry は単一の実行ファイルを起動する前提のため、処理系と vendor ディレクトリを必要とする PHP では配布形式を満たせません
- nested block (`block_types`) を実装していません
- `Diagnostic` を使用していないため、エラーが `rpc error: code = Unknown` としか表示されません
- data source、provider functions、`terraform import` を実装していません
- 性能を計測していません
- macOS / arm64 でのみ動作を確認しています

## ライセンス

MIT License ([LICENSE](LICENSE))

`proto/` 以下のファイルの出典については [NOTICE](NOTICE) を参照してください。
