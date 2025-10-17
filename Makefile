SHELL := /bin/bash
MAKEFLAGS += --no-builtin-rules
.DEFAULT_GOAL := help

ASSETS_DIR := assets
WP_ENV := npx @wordpress/env

.PHONY: help install up down env-destroy dev build lint phpcbf test test-js check

help: ## Show available targets
	@echo "Club Competitions Make targets:"
	@grep -E '^[a-zA-Z0-9_-]+:.*?##' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?##"}; {printf "  %-18s %s\n", $$1, $$2}'

install: ## Install PHP and JS dependencies
	composer install
	npm --prefix $(ASSETS_DIR) install

up: ## Boot the local WordPress environment
	$(WP_ENV) start

down: ## Stop the local WordPress environment
	$(WP_ENV) stop

env-destroy: ## Destroy the local WordPress environment
	$(WP_ENV) destroy

dev: ## Watch JS/CSS bundles with @wordpress/scripts
	npm --prefix $(ASSETS_DIR) run dev

build: ## Build production JS/CSS bundles
	npm --prefix $(ASSETS_DIR) run build

lint: ## Run PHP_CodeSniffer against the plugin
	composer lint

phpcbf: ## Auto-fix PHP coding standard violations
	vendor/bin/phpcbf --standard=WordPress --extensions=php club-competitions

test: ## Execute the PHPUnit test suite
	@set -e; \
	DB_HOST="$$WP_ENV_TEST_DB_HOST"; \
	if [ -z "$$DB_HOST" ]; then \
		PORT_OUTPUT="$$( $(WP_ENV) start )"; \
		printf '%s\n' "$$PORT_OUTPUT"; \
		TEST_PORT="$$( printf '%s\n' "$$PORT_OUTPUT" | awk '/MySQL for automated testing is listening on port/ {print $$NF}' )"; \
		if [ -z "$$TEST_PORT" ]; then \
			echo "Unable to determine MySQL automated test port. Run 'make up' and export WP_ENV_TEST_DB_HOST=\"127.0.0.1:<port>\"." >&2; \
			exit 1; \
		fi; \
		DB_HOST="127.0.0.1:$$TEST_PORT"; \
		echo "Using WP_ENV_TEST_DB_HOST=$$DB_HOST"; \
		echo "Tip: export WP_ENV_TEST_DB_HOST=\"$$DB_HOST\" to reuse this setting for future test runs."; \
	else \
		echo "Using existing WP_ENV_TEST_DB_HOST=$$DB_HOST"; \
	fi; \
	WP_ENV_TEST_DB_HOST="$$DB_HOST" composer test

test-js: ## Execute the JavaScript test suite
	npm --prefix $(ASSETS_DIR) run test:js

check: lint test test-js ## Run all linting and tests
