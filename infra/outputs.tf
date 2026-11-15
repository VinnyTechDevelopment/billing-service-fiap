output "load_balancer_hostname" {
  description = "NLB do billing-service — usar como BILLING_BACKEND_DOMAIN no API Gateway (lambda-auth-cpf-fiap)"
  value       = try(kubernetes_service_v1.app.status[0].load_balancer[0].ingress[0].hostname, null)
}

output "rds_address" {
  value = aws_db_instance.billing.address
}

output "commands_queue_url" {
  value = aws_sqs_queue.commands.url
}

output "namespace" {
  value = kubernetes_namespace_v1.billing.metadata[0].name
}
