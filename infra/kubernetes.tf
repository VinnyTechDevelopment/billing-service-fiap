locals {
  labels = { app = "billing-app" }
  image  = "${var.image_repository}:${var.image_tag}"

  # "args" (não "command") preserva o ENTRYPOINT que injeta a License Key do New Relic.
  worker_processes = {
    consumer = ["php", "artisan", "messaging:consume"]
    outbox   = ["php", "artisan", "outbox:relay"]
  }
}

resource "kubernetes_namespace_v1" "billing" {
  metadata {
    name   = var.namespace
    labels = { service = "billing-service", environment = var.environment }
  }
}

resource "kubernetes_config_map_v1" "config" {
  metadata {
    name      = "billing-config"
    namespace = kubernetes_namespace_v1.billing.metadata[0].name
  }

  data = {
    APP_NAME               = "billing-service"
    APP_ENV                = var.environment
    APP_DEBUG              = "false"
    APP_URL                = var.public_url
    LOG_CHANNEL            = "stack"
    LOG_STACK              = "stderr"
    DB_CONNECTION          = "mysql"
    DB_HOST                = aws_db_instance.billing.address
    DB_PORT                = tostring(aws_db_instance.billing.port)
    DB_DATABASE            = aws_db_instance.billing.db_name
    DB_USERNAME            = aws_db_instance.billing.username
    CACHE_STORE            = "file"
    SESSION_DRIVER         = "array"
    QUEUE_CONNECTION       = "sync"
    MESSAGING_DRIVER       = "sqs"
    MESSAGING_QUEUE_PREFIX = var.environment
    AWS_DEFAULT_REGION     = var.aws_region
    BILLING_PUBLIC_URL     = var.public_url
    PAYMENT_GATEWAY        = var.payment_gateway
    MERCADOPAGO_SANDBOX    = "true"
    NEWRELIC_APPNAME       = "billing-service"
  }
}

resource "kubernetes_secret_v1" "secret" {
  metadata {
    name      = "billing-secret"
    namespace = kubernetes_namespace_v1.billing.metadata[0].name
  }

  data = {
    APP_KEY                    = var.app_key
    DB_PASSWORD                = var.db_password
    JWT_SECRET                 = var.jwt_secret
    MERCADOPAGO_ACCESS_TOKEN   = var.mercadopago_access_token
    MERCADOPAGO_WEBHOOK_SECRET = var.mercadopago_webhook_secret
    NEWRELIC_LICENSE_KEY       = var.newrelic_license_key
  }
}

resource "kubernetes_secret_v1" "ghcr" {
  metadata {
    name      = "ghcr-secret"
    namespace = kubernetes_namespace_v1.billing.metadata[0].name
  }

  type = "kubernetes.io/dockerconfigjson"

  data = {
    ".dockerconfigjson" = jsonencode({
      auths = {
        "ghcr.io" = {
          username = var.ghcr_username
          password = var.ghcr_token
          auth     = base64encode("${var.ghcr_username}:${var.ghcr_token}")
        }
      }
    })
  }
}

resource "kubernetes_job_v1" "migrate" {
  metadata {
    name      = "billing-migrate-${var.image_tag}${var.rollout_id != "" ? "-${var.rollout_id}" : ""}"
    namespace = kubernetes_namespace_v1.billing.metadata[0].name
  }

  spec {
    backoff_limit              = 3
    ttl_seconds_after_finished = 300

    template {
      metadata {
        labels = { app = "billing-migrate" }
      }

      spec {
        restart_policy = "OnFailure"

        image_pull_secrets {
          name = kubernetes_secret_v1.ghcr.metadata[0].name
        }

        init_container {
          name    = "wait-for-db"
          image   = "busybox:1.36"
          command = ["sh", "-c", "until nc -z ${aws_db_instance.billing.address} 3306; do echo 'aguardando RDS...'; sleep 3; done"]
        }

        container {
          name  = "migrate"
          image = local.image
          args  = ["php", "artisan", "migrate", "--force"]

          env_from {
            config_map_ref {
              name = kubernetes_config_map_v1.config.metadata[0].name
            }
          }
          env_from {
            secret_ref {
              name = kubernetes_secret_v1.secret.metadata[0].name
            }
          }
        }
      }
    }
  }

  wait_for_completion = true

  timeouts {
    create = "6m"
  }
}

resource "kubernetes_deployment_v1" "app" {
  metadata {
    name      = "billing-app"
    namespace = kubernetes_namespace_v1.billing.metadata[0].name
    labels    = local.labels
  }

  spec {
    replicas = 2

    selector {
      match_labels = local.labels
    }

    template {
      metadata {
        labels      = local.labels
        annotations = { "postech.dev/rollout-id" = var.rollout_id }
      }

      spec {
        image_pull_secrets {
          name = kubernetes_secret_v1.ghcr.metadata[0].name
        }

        container {
          name              = "app"
          image             = local.image
          image_pull_policy = "Always"

          port {
            container_port = 8000
          }

          env_from {
            config_map_ref {
              name = kubernetes_config_map_v1.config.metadata[0].name
            }
          }
          env_from {
            secret_ref {
              name = kubernetes_secret_v1.secret.metadata[0].name
            }
          }

          readiness_probe {
            http_get {
              path = "/up"
              port = 8000
            }
            initial_delay_seconds = 10
            period_seconds        = 10
          }

          liveness_probe {
            http_get {
              path = "/up"
              port = 8000
            }
            initial_delay_seconds = 30
            period_seconds        = 15
          }

          resources {
            requests = { cpu = "100m", memory = "192Mi" }
            limits   = { cpu = "500m", memory = "384Mi" }
          }
        }
      }
    }
  }

  depends_on = [kubernetes_job_v1.migrate]
}

resource "kubernetes_deployment_v1" "worker" {
  metadata {
    name      = "billing-worker"
    namespace = kubernetes_namespace_v1.billing.metadata[0].name
    labels    = { app = "billing-worker" }
  }

  spec {
    replicas = 1

    strategy {
      type = "Recreate"
    }

    selector {
      match_labels = { app = "billing-worker" }
    }

    template {
      metadata {
        labels      = { app = "billing-worker" }
        annotations = { "postech.dev/rollout-id" = var.rollout_id }
      }

      spec {
        image_pull_secrets {
          name = kubernetes_secret_v1.ghcr.metadata[0].name
        }

        dynamic "container" {
          for_each = local.worker_processes

          content {
            name              = container.key
            image             = local.image
            image_pull_policy = "Always"
            args              = container.value

            env {
              name  = "NEWRELIC_APPNAME"
              value = "billing-service (worker)"
            }

            env_from {
              config_map_ref {
                name = kubernetes_config_map_v1.config.metadata[0].name
              }
            }
            env_from {
              secret_ref {
                name = kubernetes_secret_v1.secret.metadata[0].name
              }
            }

            resources {
              requests = { cpu = "50m", memory = "128Mi" }
              limits   = { cpu = "250m", memory = "256Mi" }
            }
          }
        }
      }
    }
  }

  depends_on = [kubernetes_job_v1.migrate, aws_sqs_queue.commands]
}

resource "kubernetes_service_v1" "app" {
  metadata {
    name      = "billing-app"
    namespace = kubernetes_namespace_v1.billing.metadata[0].name
    annotations = {
      "service.beta.kubernetes.io/aws-load-balancer-type" = "nlb"
    }
  }

  spec {
    type     = "LoadBalancer"
    selector = local.labels

    port {
      name        = "http"
      port        = 8080
      target_port = 8000
    }
  }
}

resource "kubernetes_horizontal_pod_autoscaler_v2" "app" {
  metadata {
    name      = "billing-app-hpa"
    namespace = kubernetes_namespace_v1.billing.metadata[0].name
  }

  spec {
    min_replicas = 2
    max_replicas = 6

    scale_target_ref {
      api_version = "apps/v1"
      kind        = "Deployment"
      name        = kubernetes_deployment_v1.app.metadata[0].name
    }

    metric {
      type = "Resource"
      resource {
        name = "cpu"
        target {
          type                = "Utilization"
          average_utilization = 70
        }
      }
    }
  }
}
