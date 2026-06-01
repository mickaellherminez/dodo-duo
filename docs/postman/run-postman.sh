#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
COLLECTION="${COLLECTION:-$ROOT_DIR/docs/postman/SaaSForge-API-v1.postman_collection.json}"
ENV_FILE="${ENV_FILE:-$ROOT_DIR/docs/postman/SaaSForge-Local.postman_environment.json}"

BASE_URL="${POSTMAN_BASE_URL:-http://localhost:8000}"
API_TOKEN="${POSTMAN_API_TOKEN:-}"
API_TOKEN_OTHER="${POSTMAN_API_TOKEN_OTHER:-}"
API_TOKEN_SUPER_ADMIN="${POSTMAN_API_TOKEN_SUPER_ADMIN:-}"
SUPER_ADMIN_EMAIL="${POSTMAN_SUPER_ADMIN_EMAIL:-}"
SUPER_ADMIN_PASSWORD="${POSTMAN_SUPER_ADMIN_PASSWORD:-}"
OAUTH_STATE="${POSTMAN_OAUTH_STATE:-}"
OAUTH_CODE_VERIFIER="${POSTMAN_OAUTH_CODE_VERIFIER:-}"
OAUTH_GOOGLE_CODE="${POSTMAN_OAUTH_GOOGLE_CODE:-}"
OAUTH_GITHUB_CODE="${POSTMAN_OAUTH_GITHUB_CODE:-}"
EMAIL_VERIFICATION_URL="${POSTMAN_EMAIL_VERIFICATION_URL:-}"
SETUP_INVITE_ENABLED="${POSTMAN_SETUP_INVITE_ENABLED:-}"
POSTMAN_FOLDER="${POSTMAN_FOLDER:-}"
POSTMAN_FOLDERS="${POSTMAN_FOLDERS:-}"
POSTMAN_INSECURE="${POSTMAN_INSECURE:-}"
POSTMAN_DELAY_REQUEST_MS="${POSTMAN_DELAY_REQUEST_MS:-}"

if ! command -v npx >/dev/null 2>&1; then
    echo "Error: npx is required (install Node.js)."
    exit 1
fi

NEWMAN_CMD=(npx --yes newman run "$COLLECTION" -e "$ENV_FILE" --env-var "base_url=$BASE_URL")

if [[ -n "$API_TOKEN" ]]; then
    NEWMAN_CMD+=(--env-var "api_token=$API_TOKEN")
fi

if [[ -n "$API_TOKEN_OTHER" ]]; then
    NEWMAN_CMD+=(--env-var "api_token_other=$API_TOKEN_OTHER")
fi

if [[ -n "$API_TOKEN_SUPER_ADMIN" ]]; then
    NEWMAN_CMD+=(--env-var "api_token_super_admin=$API_TOKEN_SUPER_ADMIN")
fi

if [[ -n "$SUPER_ADMIN_EMAIL" ]]; then
    NEWMAN_CMD+=(--env-var "super_admin_email=$SUPER_ADMIN_EMAIL")
fi

if [[ -n "$SUPER_ADMIN_PASSWORD" ]]; then
    NEWMAN_CMD+=(--env-var "super_admin_password=$SUPER_ADMIN_PASSWORD")
fi

if [[ -n "$OAUTH_STATE" ]]; then
    NEWMAN_CMD+=(--env-var "oauth_state=$OAUTH_STATE")
fi

if [[ -n "$OAUTH_CODE_VERIFIER" ]]; then
    NEWMAN_CMD+=(--env-var "oauth_code_verifier=$OAUTH_CODE_VERIFIER")
fi

if [[ -n "$OAUTH_GOOGLE_CODE" ]]; then
    NEWMAN_CMD+=(--env-var "oauth_google_code=$OAUTH_GOOGLE_CODE")
fi

if [[ -n "$OAUTH_GITHUB_CODE" ]]; then
    NEWMAN_CMD+=(--env-var "oauth_github_code=$OAUTH_GITHUB_CODE")
fi

if [[ -n "$EMAIL_VERIFICATION_URL" ]]; then
    NEWMAN_CMD+=(--env-var "email_verification_url=$EMAIL_VERIFICATION_URL")
fi

if [[ -n "$SETUP_INVITE_ENABLED" ]]; then
    NEWMAN_CMD+=(--env-var "setup_invite_enabled=$SETUP_INVITE_ENABLED")
fi

if [[ -n "$POSTMAN_FOLDER" ]]; then
    NEWMAN_CMD+=(--folder "$POSTMAN_FOLDER")
fi

if [[ -n "$POSTMAN_FOLDERS" ]]; then
    IFS=',' read -r -a FOLDERS <<< "$POSTMAN_FOLDERS"
    for folder in "${FOLDERS[@]}"; do
        NEWMAN_CMD+=(--folder "$folder")
    done
fi

if [[ -n "$POSTMAN_INSECURE" ]]; then
    NEWMAN_CMD+=(--insecure)
fi

if [[ -n "$POSTMAN_DELAY_REQUEST_MS" ]]; then
    NEWMAN_CMD+=(--delay-request "$POSTMAN_DELAY_REQUEST_MS")
fi

echo "Running Postman collection with base_url=${BASE_URL}"
"${NEWMAN_CMD[@]}"
