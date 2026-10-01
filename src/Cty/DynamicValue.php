<?php
declare(strict_types=1);

namespace PhpTf\Cty;

use PhpTf\MsgPack\Decoder;
use PhpTf\MsgPack\Encoder;
use PhpTf\MsgPack\Ext;
use PhpTf\MsgPack\MapOf;
use PhpTf\MsgPack\RawBin;

/**
 * protobuf の DynamicValue（= ただの bytes）と cty の値を相互変換する。
 *
 * ここが Go SDK が裏で担っていた仕事の本体であり、
 * .proto からスタブを生成しただけでは絶対に埋まらない部分。
 * 規則はすべて docs/object-wire-format.md に従う。
 */
final class DynamicValue
{
    /** refinement のキー（拡張コード12のペイロード内で使う整数キー）。 */
    public const REFINE_NULLNESS = 1;
    public const REFINE_STRING_PREFIX = 2;
    public const REFINE_NUMBER_LOWER = 3;
    public const REFINE_NUMBER_UPPER = 4;
    public const REFINE_LENGTH_LOWER = 5;
    public const REFINE_LENGTH_UPPER = 6;

    public function __construct(
        private readonly Encoder $enc = new Encoder(),
    ) {}

    /** cty の値を msgpack バイト列へ。 */
    public function encode(Value $v): string
    {
        return $this->enc->encode($this->toNative($v));
    }

    /** msgpack バイト列を、スキーマで宣言された型に従って cty の値へ。 */
    public function decode(string $bytes, Type $type): Value
    {
        // Terraform は「値が無い」ことを DynamicValue 自体の省略で表すことがある。
        // protobuf 上は bytes のデフォルト値（空）として届くので、null と解釈する。
        if ($bytes === '') {
            return Value::null($type);
        }
        return $this->fromNative(Decoder::decode($bytes), $type);
    }

    /**
     * JSON 表現の state を cty の値として読む。
     *
     * UpgradeResourceState は msgpack ではなく JSON で過去の state を渡してくる。
     * 規則の大半は msgpack と共通だが、2点だけ違う（object-wire-format.md 後半）:
     *   - unknown が存在しない
     *   - dynamic は2要素配列ではなく {"type":…, "value":…} のオブジェクト
     * 任意精度の数値が float に落ちないよう、大きな整数は文字列のまま受け取る。
     */
    public function decodeJson(string $json, Type $type): Value
    {
        if ($json === '') {
            return Value::null($type);
        }
        $native = json_decode($json, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        return $this->fromJsonNative($native, $type);
    }

    /** JSON 規則で PHP ネイティブ → cty の値。 */
    private function fromJsonNative(mixed $n, Type $type): Value
    {
        if ($n === null) {
            return Value::null($type);
        }

        return match ($type->kind()) {
            'string' => Value::known($type, (string) $n),
            'bool'   => Value::known($type, (bool) $n),
            'number' => Value::known($type, $n),

            'list', 'set' => Value::known($type, array_map(
                fn ($el) => $this->fromJsonNative($el, $type->elementType()),
                $n,
            )),

            'map' => Value::known($type, array_combine(
                array_map(strval(...), array_keys($n)),
                array_map(fn ($el) => $this->fromJsonNative($el, $type->elementType()), $n),
            )),

            'object' => Value::known($type, $this->objectFromJson($n, $type)),

            'tuple' => Value::known($type, array_map(
                fn (Type $et, int $i) => $this->fromJsonNative($n[$i] ?? null, $et),
                $type->tupleTypes(),
                array_keys($type->tupleTypes()),
            )),

            // JSON の dynamic は {"type": …, "value": …}
            'dynamic' => Value::known(
                Type::dynamic(),
                $this->fromJsonNative(
                    $n['value'] ?? null,
                    Type::fromJson(json_encode($n['type'] ?? 'dynamic', JSON_THROW_ON_ERROR)),
                ),
            ),

            default => throw new \RuntimeException('未知の型: ' . $type->kind()),
        };
    }

    /** @return array<string,Value> */
    private function objectFromJson(array $n, Type $type): array
    {
        $out = [];
        foreach ($type->attributeTypes() as $name => $at) {
            $out[$name] = array_key_exists($name, $n)
                ? $this->fromJsonNative($n[$name], $at)
                : Value::null($at);
        }
        return $out;
    }

    /** cty の値 → PHP ネイティブ（Encoder が食える形）。 */
    private function toNative(Value $v): mixed
    {
        // 型によらない特別規則が2つある。これが最優先。
        if ($v->isNull()) {
            return null;
        }
        if ($v->isUnknown()) {
            return $this->unknownToExt($v);
        }

        $t = $v->type;
        $raw = $v->raw();

        return match ($t->kind()) {
            'string', 'number', 'bool' => $raw,

            'list', 'set' => array_values(array_map(
                fn (Value $el) => $this->toNative($el),
                $raw,
            )),

            'map' => $this->mapToNative($raw),

            'object' => $this->objectToNative($raw, $t),

            'tuple' => array_values(array_map(
                fn (Value $el) => $this->toNative($el),
                $raw,
            )),

            // dynamic は [型制約JSONのバイナリ, 値] の2要素配列
            'dynamic' => [
                new RawBin($raw->type->toJson()),
                $this->toNative($raw),
            ],

            default => throw new \RuntimeException('未知の型: ' . $t->kind()),
        };
    }

    /** @param array<string,Value> $raw */
    private function mapToNative(array $raw): MapOf
    {
        $out = [];
        foreach ($raw as $k => $el) {
            $out[(string) $k] = $this->toNative($el);
        }
        // 空でも「配列」ではなく「マップ」として出す必要がある
        return new MapOf($out);
    }

    /** @param array<string,Value> $raw */
    private function objectToNative(array $raw, Type $t): MapOf
    {
        $out = [];
        // 属性の順序はスキーマ側の宣言順に揃える（Terraform は順序を要求しないが決定性のため）
        foreach ($t->attributeTypes() as $name => $_) {
            if (!array_key_exists($name, $raw)) {
                throw new \RuntimeException("object に属性 {$name} が無い");
            }
            $out[$name] = $this->toNative($raw[$name]);
        }
        return new MapOf($out);
    }

    private function unknownToExt(Value $v): Ext
    {
        // 絞り込みが無いときは必ず旧形式（コード0）を使うこと、と仕様が明記している
        if ($v->refinements === []) {
            return new Ext(0, '');
        }
        return new Ext(12, $this->enc->encode(new MapOf($v->refinements, stringKeys: false)));
    }

    /** PHP ネイティブ → cty の値。 */
    private function fromNative(mixed $n, Type $type): Value
    {
        // Decoder は型情報を保つため map を MapOf、bin を RawBin で返す。
        // cty 側では中身だけ使えばよいのでここでほどく。
        if ($n instanceof MapOf) {
            $n = $n->entries;
        }
        if ($n instanceof RawBin) {
            $n = $n->bytes;
        }

        if ($n === null) {
            return Value::null($type);
        }
        // 拡張型はすべて unknown とみなす。コード12のときだけ中身を読む。
        if ($n instanceof Ext) {
            // 仕様は「どんな拡張コードも unknown とみなせ」と指示している。
            // 中身を読むのはコード12のときだけ。ただし go-cty は長さ1以下の
            // ペイロードを「絞り込み無し」として受理するので、こちらも
            // 空ペイロードで落ちてはいけない。
            if ($n->code === 12 && strlen($n->payload) > 1) {
                $refine = Decoder::decode($n->payload);
                if ($refine instanceof MapOf) {
                    $refine = $refine->entries;
                }
                return Value::unknown($type, is_array($refine) ? $refine : []);
            }
            return Value::unknown($type);
        }

        return match ($type->kind()) {
            'string' => Value::known($type, (string) $n),
            'bool'   => Value::known($type, (bool) $n),
            // number は int / float / 文字列（任意精度）のいずれでも来る
            'number' => Value::known($type, $n),

            'list', 'set' => Value::known($type, array_map(
                fn ($el) => $this->fromNative($el, $type->elementType()),
                $n,
            )),

            'map' => Value::known($type, $this->mapFromNative($n, $type)),

            'object' => Value::known($type, $this->objectFromNative($n, $type)),

            'tuple' => Value::known($type, $this->tupleFromNative($n, $type)),

            'dynamic' => $this->dynamicFromNative($n),

            default => throw new \RuntimeException('未知の型: ' . $type->kind()),
        };
    }

    private function mapFromNative(array $n, Type $type): array
    {
        $out = [];
        foreach ($n as $k => $el) {
            $out[(string) $k] = $this->fromNative($el, $type->elementType());
        }
        return $out;
    }

    private function objectFromNative(array $n, Type $type): array
    {
        $out = [];
        foreach ($type->attributeTypes() as $name => $at) {
            // スキーマにあって届いていない属性は null 扱い
            $out[$name] = array_key_exists($name, $n)
                ? $this->fromNative($n[$name], $at)
                : Value::null($at);
        }
        return $out;
    }

    private function tupleFromNative(array $n, Type $type): array
    {
        $types = $type->tupleTypes();
        $out = [];
        foreach ($types as $i => $et) {
            $out[$i] = $this->fromNative($n[$i] ?? null, $et);
        }
        return $out;
    }

    private function dynamicFromNative(array $n): Value
    {
        // [型制約JSON(バイナリ), 値]
        // 実行時の具体型でほどいたうえで、dynamic 型の値として包み直す。
        // 包まないと encode の逆関数にならず、受け取った値をそのまま返す
        // provider を書いたときに型情報が落ちる。
        $typeJson = $n[0] ?? null;
        if ($typeJson instanceof RawBin) {
            $typeJson = $typeJson->bytes;
        }
        if (!is_string($typeJson)) {
            throw new \RuntimeException('dynamic の第1要素が型制約になっていない');
        }
        $inner = Type::fromJson($typeJson);
        return Value::known(Type::dynamic(), $this->fromNative($n[1] ?? null, $inner));
    }
}
