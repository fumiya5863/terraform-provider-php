<?php
declare(strict_types=1);

namespace PhpTf\Cty;

/**
 * Terraform の型制約（cty type constraint）。
 *
 * ワイヤ上は Schema.Attribute.type に「コンパクトJSON」のバイト列として載る。
 * protobuf 定義上はただの bytes で、構造の規定は object-wire-format.md にしかない。
 *
 *   プリミティブ : "string" / "number" / "bool" / "dynamic"
 *   コレクション : ["list",T] / ["set",T] / ["map",T]
 *   構造型       : ["object",{名前:T,...}] / ["tuple",[T,...]]
 */
final readonly class Type implements \JsonSerializable
{
    /** @param string|array $spec コンパクトJSONをデコードした形 */
    private function __construct(public string|array $spec) {}

    public static function string(): self  { return new self('string'); }
    public static function number(): self  { return new self('number'); }
    public static function bool(): self    { return new self('bool'); }
    /** 実行時まで型が決まらない値（DynamicPseudoType）。 */
    public static function dynamic(): self { return new self('dynamic'); }

    public static function list(self $el): self { return new self(['list', $el->spec]); }
    public static function set(self $el): self  { return new self(['set', $el->spec]); }
    public static function map(self $el): self  { return new self(['map', $el->spec]); }

    /** @param array<string,self> $attrs */
    public static function object(array $attrs): self
    {
        $spec = [];
        foreach ($attrs as $name => $t) {
            $spec[$name] = $t->spec;
        }
        // 属性が空でも JSON オブジェクトとして出す必要があるため stdClass 相当に寄せる
        return new self(['object', $spec]);
    }

    /** @param list<self> $types */
    public static function tuple(array $types): self
    {
        return new self(['tuple', array_map(static fn (self $t) => $t->spec, $types)]);
    }

    public static function fromJson(string $json): self
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_string($decoded) && !is_array($decoded)) {
            throw new \InvalidArgumentException('型制約として不正: ' . $json);
        }
        return new self($decoded);
    }

    /** 'string' | 'number' | 'bool' | 'dynamic' | 'list' | 'set' | 'map' | 'object' | 'tuple' */
    public function kind(): string
    {
        return is_string($this->spec) ? $this->spec : (string) $this->spec[0];
    }

    public function isPrimitive(): bool
    {
        return is_string($this->spec);
    }

    /** list/set/map の要素型。 */
    public function elementType(): self
    {
        return new self($this->spec[1]);
    }

    /** @return array<string,self> object の属性型。 */
    public function attributeTypes(): array
    {
        $out = [];
        foreach ((array) $this->spec[1] as $name => $s) {
            $out[(string) $name] = new self($s);
        }
        return $out;
    }

    /** @return list<self> tuple の要素型。 */
    public function tupleTypes(): array
    {
        return array_map(static fn ($s) => new self($s), $this->spec[1]);
    }

    public function jsonSerialize(): string|array
    {
        return $this->spec;
    }

    /**
     * スキーマへ載せるコンパクトJSON。
     * object の属性マップは空でも {} でなければならないので、空配列を補正する。
     */
    public function toJson(): string
    {
        return json_encode($this->normalize($this->spec), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function normalize(string|array $spec): mixed
    {
        if (is_string($spec)) {
            return $spec;
        }
        if ($spec[0] === 'object') {
            $attrs = (array) $spec[1];
            $norm = new \stdClass();
            foreach ($attrs as $k => $v) {
                $norm->{(string) $k} = $this->normalize($v);
            }
            return ['object', $norm];
        }
        if ($spec[0] === 'tuple') {
            return ['tuple', array_map(fn ($v) => $this->normalize($v), $spec[1])];
        }
        return [$spec[0], $this->normalize($spec[1])];
    }
}
