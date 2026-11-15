# Banco relacional PRÓPRIO do billing-service (database per service).
# Orçamentos e pagamentos são transacionais e precisam de consistência forte
# + outbox na mesma transação — por isso SQL (MySQL), não NoSQL.

resource "aws_db_subnet_group" "billing" {
  name       = "billing-${var.environment}"
  subnet_ids = data.terraform_remote_state.cluster.outputs.private_subnet_ids
}

resource "aws_security_group" "billing_db" {
  name        = "billing-db-${var.environment}"
  description = "MySQL do billing-service: acesso apenas a partir dos nodes do EKS"
  vpc_id      = data.terraform_remote_state.cluster.outputs.vpc_id

  ingress {
    description     = "MySQL a partir do cluster EKS"
    from_port       = 3306
    to_port         = 3306
    protocol        = "tcp"
    security_groups = [data.terraform_remote_state.cluster.outputs.node_security_group_id]
  }

  egress {
    from_port   = 0
    to_port     = 0
    protocol    = "-1"
    cidr_blocks = ["0.0.0.0/0"]
  }
}

resource "aws_db_instance" "billing" {
  identifier     = "billing-${var.environment}"
  engine         = "mysql"
  engine_version = "8.0"

  instance_class    = var.db_instance_class
  allocated_storage = 20
  storage_type      = "gp2"

  db_name  = "billing"
  username = "billing"
  password = var.db_password
  port     = 3306

  db_subnet_group_name   = aws_db_subnet_group.billing.name
  vpc_security_group_ids = [aws_security_group.billing_db.id]

  multi_az                     = false
  publicly_accessible          = false
  monitoring_interval          = 0 # Academy não permite criar a role de Enhanced Monitoring
  performance_insights_enabled = false
  backup_retention_period      = 1
  skip_final_snapshot          = true
  deletion_protection          = false

  tags = {
    Service     = "billing-service"
    Environment = var.environment
  }
}
