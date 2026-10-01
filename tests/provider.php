<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PhpTf\Cty\DynamicValue;
use PhpTf\Cty\Type;
use PhpTf\Cty\Value;
use PhpTf\Grpc\Frame;
use PhpTf\Grpc\Status;
use PhpTf\Provider\FileProvider;

$pass = 0; $fail = 0;
function ok(string $n, bool $c, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  \033[32mPASS\033[0m {$n}\n"; }
    else { $fail++; echo "  \033[31mFAIL\033[0m {$n}" . ($d ? "\n       {$d}" : '') . "\n"; }
}

$codec = new DynamicValue();
$t = FileProvider::resourceType();
$p = new FileProvider();

/** @param array<string,Value> $attrs */
function res(array $attrs): string {
    global $codec, $t;
    $full = [
        'filename' => Value::null(Type::string()),
        'content'  => Value::null(Type::string()),
        'id'       => Value::null(Type::string()),
    ];
    return $codec->encode(Value::known($t, array_merge($full, $attrs)));
}

$tmp = sys_get_temp_dir() . '/php-tf-test-' . getmypid();
@mkdir($tmp, 0o755, true);

echo "\n[1] PlanResourceChange\n";
$planned = $codec->decode($p->plan(res([
    'filename' => Value::known(Type::string(), "{$tmp}/a.txt"),
    'content'  => Value::known(Type::string(), 'hello'),
])), $t);
ok('content が確定なら id も確定',
   $planned->raw()['id']->isKnown()
   && $planned->raw()['id']->raw() === hash('sha256', 'hello'));

$planned = $codec->decode($p->plan(res([
    'filename' => Value::known(Type::string(), "{$tmp}/a.txt"),
    'content'  => Value::unknown(Type::string()),
])), $t);
ok('content が unknown なら id も unknown（null にしない）',
   $planned->raw()['id']->isUnknown(), 'got: ' . $planned->raw()['id']->describe());

ok('破棄計画（null）はそのまま null',
   $codec->decode($p->plan($codec->encode(Value::null($t))), $t)->isNull());

echo "\n[2] ApplyResourceChange\n";
$state = $codec->decode($p->apply(res([
    'filename' => Value::known(Type::string(), "{$tmp}/b.txt"),
    'content'  => Value::known(Type::string(), 'written'),
])), $t);
ok('ファイルが作られる', is_file("{$tmp}/b.txt"));
ok('内容が一致', file_get_contents("{$tmp}/b.txt") === 'written');
ok('id が確定値になる（unknown を残さない）',
   $state->raw()['id']->isKnown() && $state->raw()['id']->raw() === hash('sha256', 'written'));

echo "\n[3] ReadResource（ドリフト検知）\n";
file_put_contents("{$tmp}/b.txt", 'changed-outside');
$read = $codec->decode($p->read(res([
    'filename' => Value::known(Type::string(), "{$tmp}/b.txt"),
    'content'  => Value::known(Type::string(), 'written'),
])), $t);
ok('外部変更を検知する', $read->raw()['content']->raw() === 'changed-outside');

unlink("{$tmp}/b.txt");
$read = $codec->decode($p->read(res([
    'filename' => Value::known(Type::string(), "{$tmp}/b.txt"),
])), $t);
ok('実体が消えていれば null を返す', $read->isNull());

echo "\n[4] requiresReplace\n";
$prior = res(['filename' => Value::known(Type::string(), '/x/a.txt')]);
ok('filename が同じなら置換しない',
   $p->requiresReplace($prior, res(['filename' => Value::known(Type::string(), '/x/a.txt')])) === []);
ok('filename が違えば置換する',
   $p->requiresReplace($prior, res(['filename' => Value::known(Type::string(), '/x/b.txt')])) === ['filename']);
ok('filename が unknown なら置換する（孤児ファイルを防ぐ）',
   $p->requiresReplace($prior, res(['filename' => Value::unknown(Type::string())])) === ['filename'],
   '未確定値を「変わっていない」と見なすと古いファイルが残る');
ok('新規作成時は置換判定しない',
   $p->requiresReplace($codec->encode(Value::null($t)), $prior) === []);

echo "\n[5] destroy\n";
file_put_contents("{$tmp}/c.txt", 'x');
$p->destroy(res(['filename' => Value::known(Type::string(), "{$tmp}/c.txt")]));
ok('ファイルを削除する', !is_file("{$tmp}/c.txt"));

$locked = "{$tmp}/locked";
@mkdir($locked, 0o755, true);
file_put_contents("{$locked}/d.txt", 'x');
chmod($locked, 0o555);
$threw = false;
try { $p->destroy(res(['filename' => Value::known(Type::string(), "{$locked}/d.txt")])); }
catch (\RuntimeException $e) { $threw = true; }
chmod($locked, 0o755);
ok('削除に失敗したら例外にする（state だけ消えるのを防ぐ）', $threw,
   '握りつぶすと Terraform は state からリソースを消すのに実体が残る');

echo "\n[6] gRPC 框化の回帰\n";
$threw = false;
try { Frame::unwrap(Frame::wrap('abc') . "\x00\x00\x00"); }
catch (\RuntimeException $e) { $threw = true; }
ok('末尾の不完全なヘッダは例外', $threw);

echo "\n[7] grpc-message のパーセントエンコード\n";
ok('日本語がエンコードされる', Status::encodeMessage('あ') === '%E3%81%82');
ok('制御文字がエンコードされる', Status::encodeMessage("a\rb") === 'a%0Db');
ok("'%' 自身がエンコードされる", Status::encodeMessage('100%') === '100%25');
ok('許可範囲はそのまま', Status::encodeMessage('ok /x-y_z') === 'ok /x-y_z');

exec('rm -rf ' . escapeshellarg($tmp));
printf("\n%s\n結果: %d passed, %d failed\n", str_repeat('─', 50), $pass, $fail);
exit($fail === 0 ? 0 : 1);
