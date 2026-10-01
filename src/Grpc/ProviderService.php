<?php
declare(strict_types=1);

namespace PhpTf\Grpc;

use Grpc\Health\V1\HealthCheckResponse;
use Grpc\Health\V1\HealthCheckResponse\ServingStatus;
use PhpTf\Cty\Type;
use PhpTf\Provider\EchoProvider;
use PhpTf\Provider\FileProvider;
use Tfplugin6\ApplyResourceChange;
use Tfplugin6\AttributePath;
use Tfplugin6\ConfigureProvider;
use Tfplugin6\DynamicValue;
use Tfplugin6\GetMetadata;
use Tfplugin6\GetProviderSchema;
use Tfplugin6\PlanResourceChange;
use Tfplugin6\ReadResource;
use Tfplugin6\Schema;
use Tfplugin6\ServerCapabilities;
use Tfplugin6\StopProvider;
use Tfplugin6\UpgradeResourceState;
use Tfplugin6\ValidateProviderConfig;
use Tfplugin6\ValidateResourceConfig;

/**
 * gRPC のメソッド名を provider の処理へ振り分ける。
 *
 * Terraform が最小限必要とするのはここに並ぶ RPC だけで、
 * tfplugin6.proto に定義された36個すべてを実装する必要はない。
 */
final class ProviderService
{
    public function __construct(
        private readonly FileProvider $provider = new FileProvider(),
        private readonly EchoProvider $echo = new EchoProvider(),
    ) {}

    /**
     * @param string $path "/tfplugin6.Provider/GetProviderSchema" 形式
     * @param string $request protobuf バイト列（gRPC のフレームを外したもの）
     * @return string protobuf バイト列
     */
    public function handle(string $path, string $request): string
    {
        return match ($path) {
            '/grpc.health.v1.Health/Check'                 => $this->health(),
            '/tfplugin6.Provider/GetMetadata'              => $this->getMetadata(),
            '/tfplugin6.Provider/GetProviderSchema'        => $this->getProviderSchema(),
            '/tfplugin6.Provider/ValidateProviderConfig'   => (new ValidateProviderConfig\Response())->serializeToString(),
            '/tfplugin6.Provider/ValidateResourceConfig'   => (new ValidateResourceConfig\Response())->serializeToString(),
            '/tfplugin6.Provider/ConfigureProvider'        => (new ConfigureProvider\Response())->serializeToString(),
            '/tfplugin6.Provider/StopProvider'             => (new StopProvider\Response())->serializeToString(),
            '/tfplugin6.Provider/UpgradeResourceState'     => $this->upgradeResourceState($request),
            '/tfplugin6.Provider/ReadResource'             => $this->readResource($request),
            '/tfplugin6.Provider/PlanResourceChange'       => $this->planResourceChange($request),
            '/tfplugin6.Provider/ApplyResourceChange'      => $this->applyResourceChange($request),
            default => throw new UnimplementedMethod($path),
        };
    }

    /**
     * go-plugin は gRPC Health Checking Service の登録を必須とし、
     * サービス名 "plugin" が SERVING であることを要求する。
     * 実装しないとプロセスが突然再起動させられる。
     */
    private function health(): string
    {
        $res = new HealthCheckResponse();
        $res->setStatus(ServingStatus::SERVING);
        return $res->serializeToString();
    }

    private function getMetadata(): string
    {
        $res = new GetMetadata\Response();
        $res->setServerCapabilities($this->capabilities());
        return $res->serializeToString();
    }

    private function capabilities(): ServerCapabilities
    {
        $cap = new ServerCapabilities();
        // 空の状態に対して PlanResourceChange を呼ばなくてよい、とは言わない（既定のまま）
        $cap->setPlanDestroy(false);
        $cap->setGetProviderSchemaOptional(false);
        return $cap;
    }

    /** スキーマ。Terraform はこれを見て設定を検証し、値の符号化方法を決める。 */
    private function getProviderSchema(): string
    {
        $def = FileProvider::schemaDefinition();

        $providerBlock = new Schema\Block();
        $providerBlock->setVersion(0);
        $providerSchema = new Schema();
        $providerSchema->setVersion($def['provider']['version']);
        $providerSchema->setBlock($providerBlock);

        $resources = $def['resources'];
        // cty 全型の相互運用を Terraform 本体と突き合わせるための検証用リソース
        $resources[EchoProvider::TYPE_NAME] = EchoProvider::schemaDefinition();

        $resourceSchemas = [];
        foreach ($resources as $typeName => $r) {
            $attrs = [];
            foreach ($r['attributes'] as $a) {
                $attr = new Schema\Attribute();
                $attr->setName($a['name']);
                // type は「コンパクトJSONのバイト列」。protobuf 上はただの bytes。
                $attr->setType($a['type']);
                $attr->setRequired($a['required'] ?? false);
                $attr->setOptional($a['optional'] ?? false);
                $attr->setComputed($a['computed'] ?? false);
                $attr->setDescription($a['description'] ?? '');
                $attrs[] = $attr;
            }
            $block = new Schema\Block();
            $block->setVersion(0);
            $block->setAttributes($attrs);

            $schema = new Schema();
            $schema->setVersion($r['version']);
            $schema->setBlock($block);
            $resourceSchemas[$typeName] = $schema;
        }

        $res = new GetProviderSchema\Response();
        $res->setProvider($providerSchema);
        $res->setResourceSchemas($resourceSchemas);
        $res->setServerCapabilities($this->capabilities());
        return $res->serializeToString();
    }

    private function planResourceChange(string $request): string
    {
        $req = new PlanResourceChange\Request();
        $req->mergeFromString($request);

        $proposedBytes = $req->getProposedNewState()?->getMsgpack() ?? '';
        $priorBytes = $req->getPriorState()?->getMsgpack() ?? '';

        $planned = $req->getTypeName() === EchoProvider::TYPE_NAME
            ? $this->echo->plan($proposedBytes)
            : $this->provider->plan($proposedBytes);

        $res = new PlanResourceChange\Response();
        $res->setPlannedState($this->dynamic($planned));

        // 置換が要る属性を Terraform へ伝える。これが無いと
        // filename を変えても in-place 更新になり、古いファイルが残る。
        $replace = [];
        $names = $req->getTypeName() === EchoProvider::TYPE_NAME
            ? []
            : $this->provider->requiresReplace($priorBytes, $proposedBytes);
        foreach ($names as $name) {
            $step = new AttributePath\Step();
            $step->setAttributeName($name);
            $path = new AttributePath();
            $path->setSteps([$step]);
            $replace[] = $path;
        }
        if ($replace !== []) {
            $res->setRequiresReplace($replace);
        }

        return $res->serializeToString();
    }

    private function applyResourceChange(string $request): string
    {
        $req = new ApplyResourceChange\Request();
        $req->mergeFromString($request);

        $plannedBytes = $req->getPlannedState()?->getMsgpack() ?? '';
        $priorBytes = $req->getPriorState()?->getMsgpack() ?? '';

        // planned が null = 破棄。prior を見て実体を消す。
        $isEcho = $req->getTypeName() === EchoProvider::TYPE_NAME;
        $type = $isEcho ? EchoProvider::resourceType() : FileProvider::resourceType();

        $codec = new \PhpTf\Cty\DynamicValue();
        $plannedValue = $codec->decode($plannedBytes, $type);

        if ($plannedValue->isNull()) {
            if (!$isEcho) {
                $this->provider->destroy($priorBytes);
            }
            $newState = $plannedBytes;
        } elseif ($isEcho) {
            $newState = $this->echo->apply($plannedBytes);
        } else {
            $newState = $this->provider->apply($plannedBytes);
        }

        $res = new ApplyResourceChange\Response();
        $res->setNewState($this->dynamic($newState));
        return $res->serializeToString();
    }

    /**
     * 過去のスキーマバージョンで保存された state を、現在のスキーマへ読み替える。
     * 今回はバージョン0しか無いので、JSON を読んで msgpack で返すだけでよい。
     * 実装しないと destroy すら通らない（Terraform が必ず呼ぶ）。
     */
    private function upgradeResourceState(string $request): string
    {
        $req = new UpgradeResourceState\Request();
        $req->mergeFromString($request);

        $codec = new \PhpTf\Cty\DynamicValue();
        $value = $codec->decodeJson(
            $req->getRawState()?->getJson() ?? '',
            $req->getTypeName() === EchoProvider::TYPE_NAME
                ? EchoProvider::resourceType()
                : FileProvider::resourceType(),
        );

        $res = new UpgradeResourceState\Response();
        $res->setUpgradedState($this->dynamic($codec->encode($value)));
        return $res->serializeToString();
    }

    private function readResource(string $request): string
    {
        $req = new ReadResource\Request();
        $req->mergeFromString($request);

        $current = $req->getCurrentState()?->getMsgpack() ?? '';
        $newState = $req->getTypeName() === EchoProvider::TYPE_NAME
            ? $this->echo->read($current)
            : $this->provider->read($current);

        $res = new ReadResource\Response();
        $res->setNewState($this->dynamic($newState));
        return $res->serializeToString();
    }

    private function dynamic(string $msgpack): DynamicValue
    {
        $dv = new DynamicValue();
        // 応答は常に msgpack で返すこと、と仕様が指示している
        $dv->setMsgpack($msgpack);
        return $dv;
    }
}

/** 未実装の RPC。gRPC の UNIMPLEMENTED(12) として返すために使う。 */
final class UnimplementedMethod extends \RuntimeException
{
    public function __construct(public readonly string $path)
    {
        parent::__construct("未実装の RPC: {$path}");
    }
}
