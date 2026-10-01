# PHP で書いた Terraform provider でローカルファイルを管理する例。
#
#   export TF_CLI_CONFIG_FILE=$(pwd)/../../dev/terraformrc
#   export TF_DISABLE_PLUGIN_TLS=1
#   terraform apply -auto-approve

terraform {
  required_providers {
    php = {
      source = "fumiya5863/php"
    }
  }
}

provider "php" {}

resource "php_file" "greeting" {
  filename = "${path.module}/out/hello.txt"
  content  = "PHP で書いた Terraform provider が作りました\n"
}

output "sha256" {
  # id は content の SHA256。provider が計算して返している。
  value = php_file.greeting.id
}
