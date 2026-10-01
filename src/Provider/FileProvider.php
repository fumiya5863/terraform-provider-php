<?php
declare(strict_types=1);

namespace PhpTf\Provider;

use PhpTf\Cty\DynamicValue as Codec;
use PhpTf\Cty\Type;
use PhpTf\Cty\Value;

/**
 * PHP で書いた Terraform provider の本体。
 *
 * 管理対象はローカルファイル1種類（php_file）。
 * 「PHP のプロセスが terraform apply でファイルを作る」ことを示すのが目的なので、
 * リソースは意図的に最小に留めている。
 */
final class FileProvider
{
    public const TYPE_NAME = 'php_file';

    private Codec $codec;

    public function __construct()
    {
        $this->codec = new Codec();
    }

    /** リソース1件ぶんの object 型。config / plan / state の DynamicValue はすべてこの型で読む。 */
    public static function resourceType(): Type
    {
        return Type::object([
            'filename' => Type::string(),
            'content'  => Type::string(),
            'id'       => Type::string(),
        ]);
    }

    /** provider ブロック自体の型（設定項目なし）。 */
    public static function providerType(): Type
    {
        return Type::object([]);
    }

    /**
     * スキーマ定義。Terraform はこれを見て設定を検証し、値の符号化方法を決める。
     * attribute の type は「コンパクトJSONのバイト列」で渡す必要がある。
     *
     * @return array{provider: array, resources: array<string, array>}
     */
    public static function schemaDefinition(): array
    {
        return [
            'provider' => [
                'version' => 0,
                'attributes' => [],
            ],
            'resources' => [
                self::TYPE_NAME => [
                    'version' => 0,
                    'attributes' => [
                        [
                            'name' => 'filename',
                            'type' => Type::string()->toJson(),
                            'required' => true,
                            'description' => '作成するファイルのパス',
                        ],
                        [
                            'name' => 'content',
                            'type' => Type::string()->toJson(),
                            'required' => true,
                            'description' => 'ファイルへ書き込む内容',
                        ],
                        [
                            'name' => 'id',
                            'type' => Type::string()->toJson(),
                            'computed' => true,
                            'description' => 'ファイル内容のSHA256（Terraform が差分判定に使う）',
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * PlanResourceChange: 「apply したら何が起きるか」を Terraform へ返す。
     *
     * ここが値の3状態を最も必要とする場面で、
     * computed な属性はまだ決まらないので unknown を返さなければならない。
     * null を返すと「値は空になる」という別の意味になってしまう。
     */
    public function plan(string $proposedMsgpack): string
    {
        $t = self::resourceType();
        $proposed = $this->codec->decode($proposedMsgpack, $t);

        // 削除計画（proposed が null）はそのまま返す
        if ($proposed->isNull()) {
            return $this->codec->encode(Value::null($t));
        }

        $attrs = $proposed->raw();

        // id は content から導出するが、content が unknown なら id も unknown。
        $content = $attrs['content'];
        $attrs['id'] = $content->isKnown()
            ? Value::known(Type::string(), hash('sha256', (string) $content->raw()))
            : Value::unknown(Type::string());

        return $this->codec->encode(Value::known($t, $attrs));
    }

    /**
     * 置換（destroy → create）が必要な属性名を返す。
     *
     * filename が変わったのに in-place で更新してしまうと、
     * 古いファイルが消されずに残ってしまう。Terraform に
     * 「この属性が変わったら作り直せ」と伝える必要がある。
     *
     * @return list<string>
     */
    public function requiresReplace(string $priorMsgpack, string $proposedMsgpack): array
    {
        $t = self::resourceType();
        $prior = $this->codec->decode($priorMsgpack, $t);
        $proposed = $this->codec->decode($proposedMsgpack, $t);

        // 新規作成または破棄なら置換判定は不要
        if ($prior->isNull() || !$prior->isKnown() || $proposed->isNull() || !$proposed->isKnown()) {
            return [];
        }

        $before = $prior->raw()['filename'];
        $after = $proposed->raw()['filename'];

        // 両方が確定していて同じ値のときだけ「変わっていない」と言える。
        // 片方でも unknown なら、違う値になる可能性が残るので置換を主張する。
        // terraform-plugin-framework の RequiresReplaceIf も、離脱するのは
        // 作成時・破棄時・plan と state が等しいときの3つだけで、
        // unknown は known と等しくないため置換扱いになる。
        // ここで「比較できないから置換しない」と判断すると、
        // filename が other_resource.id のような未確定値のときに
        // in-place 更新となり、古いファイルが孤児として残る。
        if ($before->isKnown() && $after->isKnown()) {
            return $before->raw() === $after->raw() ? [] : ['filename'];
        }

        return ['filename'];
    }

    /**
     * ApplyResourceChange: 実際に副作用を起こし、確定した状態を返す。
     * 返す値に unknown が残っていると Terraform はエラーにする。
     */
    public function apply(string $plannedMsgpack): string
    {
        $t = self::resourceType();
        $planned = $this->codec->decode($plannedMsgpack, $t);

        // 削除
        if ($planned->isNull()) {
            return $this->codec->encode(Value::null($t));
        }

        $attrs = $planned->raw();
        $filename = (string) $attrs['filename']->raw();
        $content  = (string) $attrs['content']->raw();

        $dir = dirname($filename);
        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new \RuntimeException("ディレクトリを作成できない: {$dir}");
        }
        if (file_put_contents($filename, $content) === false) {
            throw new \RuntimeException("ファイルを書き込めない: {$filename}");
        }

        $attrs['id'] = Value::known(Type::string(), hash('sha256', $content));

        return $this->codec->encode(Value::known($t, $attrs));
    }

    /**
     * ReadResource: 実体を見て現在の状態を返す。
     * 実体が消えていれば null を返し、Terraform に「作り直しが要る」と伝える。
     */
    public function read(string $stateMsgpack): string
    {
        $t = self::resourceType();
        $state = $this->codec->decode($stateMsgpack, $t);

        if ($state->isNull()) {
            return $this->codec->encode(Value::null($t));
        }

        $attrs = $state->raw();
        $filename = (string) $attrs['filename']->raw();

        if (!is_file($filename)) {
            // 実体が消えた → リソースごと消えたと報告する
            return $this->codec->encode(Value::null($t));
        }

        $content = @file_get_contents($filename);
        if ($content === false) {
            // 空文字として扱うと、Terraform が差分と見なして
            // apply で中身を空に上書きしてしまう。
            throw new \RuntimeException("ファイルを読み込めない: {$filename}");
        }
        $attrs['content'] = Value::known(Type::string(), $content);
        $attrs['id'] = Value::known(Type::string(), hash('sha256', $content));

        return $this->codec->encode(Value::known($t, $attrs));
    }

    /** apply 時に実体を消す。 */
    public function destroy(string $priorMsgpack): void
    {
        $t = self::resourceType();
        $prior = $this->codec->decode($priorMsgpack, $t);
        if ($prior->isNull()) {
            return;
        }
        $filename = (string) $prior->raw()['filename']->raw();
        if (!is_file($filename)) {
            return;
        }
        // 失敗を握りつぶすと、Terraform は state からリソースを消すのに
        // 実体が残り、以後 Terraform から手が届かなくなる。
        if (!@unlink($filename)) {
            throw new \RuntimeException("ファイルを削除できない: {$filename}");
        }
    }
}
