<?php
declare(strict_types=1);

namespace PhpTf\Provider;

use PhpTf\Cty\DynamicValue as Codec;
use PhpTf\Cty\Type;
use PhpTf\Cty\Value;

/**
 * cty の全型を持つ検証専用リソース。
 *
 * 自作の msgpack / cty 実装が「自分同士で往復できる」ことは、
 * 仕様に従っている証明にならない（トートロジー）。
 * そこで Terraform 本体の cty 実装に実際に食わせ、
 * 設定した値がそのまま返ってくるかで相互運用性を検証する。
 */
final class EchoProvider
{
    public const TYPE_NAME = 'php_echo';

    private Codec $codec;

    public function __construct()
    {
        $this->codec = new Codec();
    }

    public static function resourceType(): Type
    {
        return Type::object([
            'a_string' => Type::string(),
            'a_number' => Type::number(),
            'a_bool'   => Type::bool(),
            'a_list'   => Type::list(Type::string()),
            'a_set'    => Type::set(Type::string()),
            'a_map'    => Type::map(Type::number()),
            'a_object' => Type::object(['x' => Type::string(), 'y' => Type::number()]),
            'a_nested' => Type::list(Type::map(Type::bool())),
            'id'       => Type::string(),
        ]);
    }

    /** @return array{version: int, attributes: list<array<string,mixed>>} */
    public static function schemaDefinition(): array
    {
        $t = self::resourceType();
        $attrs = [];
        foreach ($t->attributeTypes() as $name => $at) {
            $attrs[] = [
                'name' => $name,
                'type' => $at->toJson(),
                'required' => $name !== 'id',
                'computed' => $name === 'id',
                'description' => "cty 型 {$at->kind()} の検証用",
            ];
        }
        return ['version' => 0, 'attributes' => $attrs];
    }

    /** 受け取った値をそのまま返す。id だけ計算して埋める。 */
    public function plan(string $proposedMsgpack): string
    {
        $t = self::resourceType();
        $v = $this->codec->decode($proposedMsgpack, $t);
        if ($v->isNull()) {
            return $this->codec->encode(Value::null($t));
        }

        $attrs = $v->raw();
        $attrs['id'] = Value::known(Type::string(), 'echo');

        return $this->codec->encode(Value::known($t, $attrs));
    }

    public function apply(string $plannedMsgpack): string
    {
        return $this->plan($plannedMsgpack);
    }

    public function read(string $stateMsgpack): string
    {
        $t = self::resourceType();
        $v = $this->codec->decode($stateMsgpack, $t);
        return $this->codec->encode($v);
    }
}
