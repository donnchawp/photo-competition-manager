SHELL := /bin/bash
MAKEFLAGS += --no-builtin-rules
.DEFAULT_GOAL := help

ASSETS_DIR := assets
MAILPIT_SCRIPT := ./start-mailpit.sh
MAILPIT_CONTAINER := photo-competition-manager-mailpit
PLUGIN_NAME := photo-competition-manager
WP_ENV := COMPOSE_PROJECT_NAME=$(PLUGIN_NAME) npx @wordpress/env
RELEASE_DIR := release

.PHONY: logs help install up down destroy dev build lint fix test test-js check mailpit-start mailpit-stop release clean-release seed-competition

help: ## Show available targets
	@echo "Photo Competition Manager Make targets:"
	@grep -E '^[a-zA-Z0-9_-]+:.*?##' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?##"}; {printf "  %-18s %s\n", $$1, $$2}'

install: ## Install PHP and JS dependencies
	composer install
	npm --prefix $(ASSETS_DIR) install

logs: ## Show WordPress environment logs
	$(WP_ENV) logs

up: ## Boot the local WordPress environment
	$(WP_ENV) start
	bash $(MAILPIT_SCRIPT)

down: ## Stop the local WordPress environment
	$(WP_ENV) stop
	$(MAKE) mailpit-stop

destroy: ## Destroy the local WordPress environment
	$(WP_ENV) destroy
	$(MAKE) mailpit-stop

dev: ## Watch JS/CSS bundles with @wordpress/scripts
	npm --prefix $(ASSETS_DIR) run dev

build: ## Build production JS/CSS bundles
	npm --prefix $(ASSETS_DIR) run build

lint: ## Run PHP_CodeSniffer against the plugin
	composer lint

fix: ## Auto-fix PHP coding standard violations
	vendor/bin/phpcbf --standard=WordPress --extensions=php src

test: ## Execute the PHPUnit test suite (inside the wp-env tests container, same as CI)
	$(WP_ENV) start
	$(WP_ENV) run tests-cli --env-cwd=wp-content/pcm \
		bash -c 'WP_CORE_DIR=/var/www/html WP_ENV_TEST_DB_HOST=tests-mysql ./vendor/bin/phpunit -c phpunit.xml.dist $(ARGS)'

test-js: ## Execute the JavaScript test suite
	npm --prefix $(ASSETS_DIR) run test:js

check: lint test test-js ## Run all linting and tests

mailpit-start: ## Manually start Mailpit SMTP relay
	bash $(MAILPIT_SCRIPT)

mailpit-stop: ## Stop and remove the Mailpit container
	@if docker ps -a --format '{{.Names}}' | grep -q '^$(MAILPIT_CONTAINER)$$'; then \
		echo "Stopping Mailpit container $(MAILPIT_CONTAINER)..."; \
		docker stop $(MAILPIT_CONTAINER) >/dev/null 2>&1 || true; \
		docker rm $(MAILPIT_CONTAINER) >/dev/null 2>&1 || true; \
	else \
		echo "Mailpit container $(MAILPIT_CONTAINER) is not running."; \
	fi

release: clean-release build ## Build production release zip file
	@echo "Creating production release..."
	@mkdir -p $(RELEASE_DIR)/$(PLUGIN_NAME)
	@echo "Copying plugin files to release directory..."
	@rsync -a --exclude-from=.releaseignore src/ $(RELEASE_DIR)/$(PLUGIN_NAME)/
	@echo "Copying built assets..."
	@mkdir -p $(RELEASE_DIR)/$(PLUGIN_NAME)/assets/build/
	@cp -r $(ASSETS_DIR)/build/* $(RELEASE_DIR)/$(PLUGIN_NAME)/assets/build/
	@echo "Creating zip file..."
	@cd $(RELEASE_DIR) && zip -qr $(PLUGIN_NAME).zip $(PLUGIN_NAME)
	@mv $(RELEASE_DIR)/$(PLUGIN_NAME).zip ./$(PLUGIN_NAME).zip
	@echo "✓ Release zip created: $(PLUGIN_NAME).zip"
	@echo ""
	@echo "Release package info:"
	@unzip -l $(PLUGIN_NAME).zip | head -20
	@rm -fr $(RELEASE_DIR)/$(PLUGIN_NAME)

seed-competition: ## Seed 12 members, a competition, and test images for voting
	$(WP_ENV) run cli -- wp eval-file /var/www/html/wp-content/plugins/src/scripts/seed-voting-data.php

clean-release: ## Remove release build artifacts
	@echo "Cleaning release artifacts..."
	@rm -rf $(RELEASE_DIR)
	@rm -f $(PLUGIN_NAME).zip
	@echo "✓ Release artifacts cleaned"
