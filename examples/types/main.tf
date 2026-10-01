# cty の全型が PHP 実装を通して往復することを確かめる例。
#
# 自作の msgpack / cty 実装が「自分同士で往復できる」ことは仕様準拠の証明に
# ならないため、Terraform 本体の cty 実装に実際に食わせて確認する。
# apply したあと plan が "No changes" になれば、往復が正確だと言える。

terraform {
  required_providers {
    php = {
      source = "fumiya5863/php"
    }
  }
}

resource "php_echo" "all_types" {
  a_string = "テスト"
  a_number = 42
  a_bool   = true
  a_list   = ["alpha", "bravo", "charlie"]
  a_set    = ["x", "y"]

  a_map = {
    one = 1
    two = 2.5
  }

  a_object = {
    x = "sample"
    y = 1889
  }

  a_nested = [
    { enabled = true, debug = false },
    { enabled = false },
  ]
}

output "echoed" {
  value = php_echo.all_types
}
