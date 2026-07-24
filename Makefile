# Makefile — convenience wrapper around Docker Compose commands.
# Requires: Docker Engine with Compose v2 plugin.
# On Windows: run inside Git Bash or WSL.

LOCAL_COMPOSE = docker compose \
	-f docker-compose.yml \
	-f docker-compose.local.yml \
	--env-file compose.local.env

.PHONY: local-setup local-up local-down local-build local-logs local-ps \
        local-shell local-migrate release deploy

## ── Local development ────────────────────────────────────────────────────────

local-setup: ## Create local env files from examples (run once)
	@[ -f compose.local.env ]          || cp compose.local.env.example compose.local.env
	@[ -f env/backend.docker.env ]     || cp env/backend.docker.example.env env/backend.docker.env
	@echo ""
	@echo "Next steps:"
	@echo "  1. Generate an APP_KEY and paste it into env/backend.docker.env:"
	@echo "     docker run --rm php:8.2-cli php -r \"echo 'base64:'.base64_encode(random_bytes(32));\""
	@echo "  2. Set DASHBOARD_API_USERNAME and DASHBOARD_API_PASSWORD in env/backend.docker.env"
	@echo "  3. Run: make local-up"

local-up: ## Start the full local stack (builds images if needed)
	$(LOCAL_COMPOSE) up -d --build --remove-orphans

local-down: ## Stop and remove local containers (data volume is preserved)
	$(LOCAL_COMPOSE) down

local-build: ## Rebuild images without starting
	$(LOCAL_COMPOSE) build

local-logs: ## Tail logs from all services
	$(LOCAL_COMPOSE) logs -f --tail=200

local-ps: ## Show running local containers
	$(LOCAL_COMPOSE) ps

local-shell: ## Open a shell in the backend container
	$(LOCAL_COMPOSE) exec dashboard_backend sh

local-migrate: ## Run Laravel migrations inside the running backend container
	$(LOCAL_COMPOSE) exec dashboard_backend php artisan migrate

## ── Production (uses deploy.sh) ──────────────────────────────────────────────

release: ## Build images locally and push to Docker Hub (uses IMAGE_TAG=latest)
	./deploy.sh release

deploy: ## Pull latest images on server and restart the stack
	./deploy.sh deploy
