variable "aws_region" {
  type    = string
  default = "us-east-1"
}

variable "tf_state_bucket" {
  description = "Bucket S3 com o state do infra-kubernetes-fiap"
  type        = string
}

variable "environment" {
  description = "production | homolog — prefixo das filas SQS e sufixo do RDS"
  type        = string
  default     = "production"

  validation {
    condition     = contains(["production", "homolog"], var.environment)
    error_message = "environment precisa ser \"production\" ou \"homolog\"."
  }
}

variable "namespace" {
  type    = string
  default = "billing"
}

variable "image_repository" {
  type    = string
  default = "ghcr.io/vinnytechdevelopment/billing-service-fiap"
}

variable "image_tag" {
  type    = string
  default = "latest"
}

variable "rollout_id" {
  type    = string
  default = ""
}

variable "app_key" {
  type      = string
  sensitive = true
}

variable "db_password" {
  description = "Senha do RDS próprio do billing-service"
  type        = string
  sensitive   = true
}

variable "db_instance_class" {
  type    = string
  default = "db.t3.micro"
}

variable "jwt_secret" {
  description = "Mesmo JWT_SECRET do os-service (valida o token de funcionário)"
  type        = string
  sensitive   = true
}

variable "public_url" {
  description = "URL pública do API Gateway (links de aprovação e notification_url do Mercado Pago)"
  type        = string
}

variable "payment_gateway" {
  description = "mercadopago | fake"
  type        = string
  default     = "mercadopago"
}

variable "mercadopago_access_token" {
  type      = string
  sensitive = true
  default   = ""
}

variable "mercadopago_webhook_secret" {
  type      = string
  sensitive = true
  default   = ""
}

variable "newrelic_license_key" {
  type      = string
  sensitive = true
}

variable "ghcr_username" {
  type = string
}

variable "ghcr_token" {
  type      = string
  sensitive = true
}

check "mercadopago_configurado" {
  assert {
    condition     = var.payment_gateway != "mercadopago" || length(var.mercadopago_access_token) > 0
    error_message = "payment_gateway = mercadopago exige mercadopago_access_token (secret MERCADOPAGO_ACCESS_TOKEN)."
  }
}
