# terraform-provider-php

**Terraform の provider を PHP で書けるのかを確かめた実験。**

答えは **書ける**。`terraform plan` / `apply` / `destroy` がすべて通る。

ただし**本番で使えるものではない**。理由は[限界](#限界)に書いた。

---

## なぜ作ったか

Terraform provider といえば Go。公式SDKもGo向けしかない。
しかし HashiCorp 自身が、同じページでこう書いている。

> "Go is currently the only programming language supported by HashiCorp for building Terraform providers."

> "**while it is technically possible to write providers in languages other than Go**, our tooling, documentation, and ecosystem will all assume your provider is being written in and distributed as Go code for the time being."

— [Provider Code Best Practices](https://developer.hashicorp.com/terraform/plugin/best-practices/provider-code)

つまり**サポートしないだけで、技術的には可能**と明言されている。ではPHPでは？を確かめた。

## 何を自前で書いたか

Terraform と provider は gRPC で話す。`.proto` は公開されているが、**スタブを生成しただけでは1ミリも動かない**。Go SDK が裏で担っていた仕事を全部書く必要がある。

| 壁 | 実際の難易度 |
|---|---|
| **PHPをgRPCサーバにすること** | **最大の壁**（公式はPHPをクライアント専用と明記） |
| ハンドシェイク | printf 1行。最も簡単 |
| gRPC Health Service | 10行程度 |
| AutoMTLS | 高い（**未実装**） |
| cty + msgpack | 重いが着実。最も学びがあった |

自作コード 1,765行、依存は `google/protobuf` ひとつ。

| ディレクトリ | 中身 |
|---|---|
| `src/MsgPack/` | msgpack の符号化・復号（拡張型、空マップと空配列の区別、bin と str の区別） |
| `src/Cty/` | Terraform の型システム。**known / null / unknown の3状態**と msgpack / JSON の相互変換 |
| `src/Grpc/` | gRPC の框化、trailer の `grpc-status`、Swoole の HTTP/2 上のサーバ |
| `src/Plugin/` | go-plugin のハンドシェイク |
| `src/Provider/` | provider 本体（ファイル管理と、全cty型の検証用リソース） |

実装中に踏んだ PHP 固有の罠:

- PHP は `"123"` という配列キーを勝手に `int` にする。Terraform のマップキーは常に文字列なので、
  戻さないとプロトコル準拠が壊れる
- ところが unknown の refinement マップ**だけ**は整数キーが仕様。一律に対策すると壊れる
- msgpack は空マップ (`0x80`) と空配列 (`0x90`) が別型だが、PHP の `[]` はどちらにも見える
  （`array_is_list([])` は `true`）
- `pack('d')` はマシンエンディアン依存。msgpack はビッグエンディアン固定なので `pack('E')` を使う
- `unpack('J')` は 2^63 以上を黙って負数に化けさせる

## 動かす

### 必要なもの

| | 備考 |
|---|---|
| PHP **8.4**系 | Swoole 6.1.x が 8.5 に未対応のため |
| **Swoole** 拡張（HTTP/2有効） | `php --ri swoole` に `http2 => enabled` が出ること |
| Composer / protoc | |
| Terraform または OpenTofu | 1.5 以降で確認済み |

macOS で Swoole を入れる場合、6.1系は `sw_usleep` 未定義でビルドが通らない（[swoole-src#6243](https://github.com/swoole/swoole-src/issues/6243)）。回避策:

```bash
CFLAGS="-I$(brew --prefix)/include -Dsw_usleep=usleep" \
CXXFLAGS="-I$(brew --prefix)/include -Dsw_usleep=usleep" \
pecl install --configureoptions 'enable-openssl="no" enable-http2="yes" enable-sockets="yes"' \
  swoole-6.1.10
```

### 手順

```bash
make setup        # composer install + protobuf スタブ生成
make test         # 検証をすべて走らせる
make dev-config   # dev/terraformrc を生成（絶対パスを含むため追跡していない）

cd examples/file
export TF_CLI_CONFIG_FILE=$(git rev-parse --show-toplevel)/dev/terraformrc
export TF_DISABLE_PLUGIN_TLS=1    # AutoMTLS 未実装のため必要
terraform apply -auto-approve
cat out/hello.txt
```

`TF_DISABLE_PLUGIN_TLS` は公式が「エンドユーザーは使うな」と書いている環境変数。
AutoMTLS を実装していないので、現状これが必須になっている。

## 検証

```
cty / msgpack 単体            35 passed
境界値・異常系                 60 passed
provider ロジック              19 passed
リファレンス実装との相互検証    45 + 44 passed
```

自作実装同士の往復は仕様準拠の証明にならないので、**独立した実装と突き合わせている**。

- Python の `msgpack` パッケージと双方向で照合（`tests/crossval.py`）
- cty 層は **Terraform 本体の cty 実装**と照合（`examples/types/`。apply 後の plan が `No changes` になることを確認）

| CLI | apply | destroy |
|---|---|---|
| Terraform 1.5.1 | ✅ | ✅ |
| Terraform 1.16.4 | ✅ | ✅ |
| OpenTofu 1.13.0 | ✅ | ✅ |

## 限界

本番で使えない理由。

- **AutoMTLS 未実装。** `TF_DISABLE_PLUGIN_TLS=1` に依存している
- **配布できない。** Registry は `terraform-provider-{NAME}_v{VERSION}` という実行ファイルを起動するだけなので、処理系と vendor を必要とする PHP はそのままでは配れない
- **nested block 未実装。** `block_types` を扱っていない
- `Diagnostic` 未使用のため、エラーが `rpc error: code = Unknown` としか出ない
- data source / provider functions / `terraform import` 未実装
- 性能を測っていない
- macOS / arm64 でしか動かしていない

## ライセンス

MIT（[LICENSE](LICENSE)）。
`proto/` 以下のサードパーティファイルについては [NOTICE](NOTICE) を参照。
