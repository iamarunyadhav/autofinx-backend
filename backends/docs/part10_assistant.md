## Assistant (Part 10)

Tables:
- `assistant_actions` (propose/approve/execute flow).

API:
- GET `/api/v1/assistant/actions?workspace_id=...`
- POST `/api/v1/assistant/actions/propose`
- POST `/api/v1/assistant/actions/{id}/approve` (2FA)
- POST `/api/v1/assistant/actions/{id}/execute` (2FA)

Rules:
- Tools must be allow-listed.
- Dangerous actions require approval + 2FA.
- Audit trail is expected (future expansion).
