# PHP で書いた Terraform provider

PHP     ?= php
PROTOC  ?= protoc
ROOT    := $(shell pwd)

.PHONY: help setup proto docs test demo dev-config clean

help:
	@echo "make setup       依存を入れて protobuf スタブを生成する"
	@echo "make proto       protobuf スタブだけ生成する"
	@echo "make docs        参照した一次資料を取得する（再配布しないため追跡していない）"
	@echo "make test        すべての検証を走らせる"
	@echo "make dev-config  dev/terraformrc を生成する"
	@echo "make demo        デモGIFを録り直す（要 vhs）"
	@echo "make clean       生成物を消す"

setup: proto
	composer install

proto:
	@mkdir -p gen
	$(PROTOC) --proto_path=proto --php_out=gen proto/tfplugin6.proto
	$(PROTOC) --proto_path=proto --php_out=gen proto/health.proto
	@echo "生成: $$(find gen -type f | wc -l | tr -d ' ') ファイル"

docs:
	@mkdir -p docs
	curl -fsSL https://raw.githubusercontent.com/hashicorp/terraform/main/docs/plugin-protocol/object-wire-format.md -o docs/object-wire-format.md
	curl -fsSL https://raw.githubusercontent.com/hashicorp/terraform/main/docs/plugin-protocol/README.md -o docs/plugin-protocol-README.md
	@echo "取得完了。cty/msgpack の規定は object-wire-format.md にある。"

test:
	@PHP=$(PHP) ./tests/all.sh

# Terraform にこのディレクトリの provider を起動させるための設定。
# 絶対パスを含むので追跡していない。
dev-config:
	@printf 'provider_installation {\n  dev_overrides {\n    "fumiya5863/php" = "$(ROOT)/dev"\n  }\n  direct {}\n}\n' > dev/terraformrc
	@echo "生成: dev/terraformrc"
	@echo
	@echo "使い方:"
	@echo "  cd examples/file"
	@echo "  export TF_CLI_CONFIG_FILE=$(ROOT)/dev/terraformrc"
	@echo "  export TF_DISABLE_PLUGIN_TLS=1   # AutoMTLS 未実装のため必要"
	@echo "  terraform apply -auto-approve"

demo:
	vhs demo/apply.tape

clean:
	rm -rf gen docs vendor .reattach.json dev/terraformrc
	rm -rf examples/*/out examples/*/.terraform examples/*/.terraform.lock.hcl
	rm -f examples/*/terraform.tfstate examples/*/terraform.tfstate.backup
