# Secrets Management for o2switch Deployments

This project should never store production secrets in git, screenshots, or chat logs.

## Rules

- Never commit `.env`.
- Never commit DB passwords, API keys, OAuth secrets, mail credentials, or private keys.
- Keep production secrets only in:
  - GitHub Actions Secrets, or
  - o2switch server (`.env` with strict permissions).

## Most secure model (recommended)

- Keep **application secrets only on o2switch** in server-side `.env`.
- Use GitHub Secrets only for deployment transport/authentication.
- Protect deployment with GitHub Environment `production` (required reviewers).

## GitHub Secrets (for CI/CD deploy transport)

Create these repository secrets:

- `O2S_HOST` (example: `109.234.165.114`)
- `O2S_USER` (example: `lhmi8653`)
- `O2S_PORT` (example: `22`)
- `O2S_TARGET_DIR` (example: `/home/lhmi8653/api.taskor.mickaellherminez.net`)
- `O2S_SSH_PRIVATE_KEY` (private key with deploy access)
- `O2S_KNOWN_HOSTS` (required; output of `ssh-keyscan -p 22 109.234.165.114`)

Then run workflow:

- `.github/workflows/deploy-o2switch-api.yml` via `workflow_dispatch`

## o2switch local secret storage (manual deploy path)

If deploying manually on server:

1. Keep secrets in `/home/<user>/<app>/.env` only.
2. Enforce strict permissions:

```bash
chmod 600 /home/<user>/<app>/.env
```

3. Never place secrets in world-readable files.
4. Ensure placeholders are replaced (`DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`) before CI deploy.

## Validation checklist

- `.env` is ignored by git.
- Server `.env` permissions are `600`.
- CI pipeline does not upload `.env` from GitHub; it uses server-side `.env`.
- No secret appears in markdown evidence files.
