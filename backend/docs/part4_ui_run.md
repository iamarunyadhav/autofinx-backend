# AutoFinX Frontend (Vite/React) — Part 4 Notes

This project uses Vite + React (not Next.js). The steps below map the requested Part 4 features to the current stack and give you the run / env info.

## Env
Copy `frontend/sentinel-ai-trader/.env.example` to `.env` and adjust if needed:
```
VITE_API_BASE_URL=http://localhost:8000/api/v1
VITE_WS_MARKET_URL=ws://localhost:8000/ws/market
VITE_REVERB_HOST=127.0.0.1
VITE_REVERB_PORT=8080
VITE_REVERB_SCHEME=http
VITE_REVERB_APP_KEY=local
VITE_DEMO_MODE=true
VITE_ASSISTANT_VOICE_ENABLED=false
VITE_DEFAULT_EMAIL=admin@autofinx.local
VITE_DEFAULT_PASSWORD=password
```

## Run frontend
```bash
cd frontend/sentinel-ai-trader
npm install   # or pnpm install
npm run dev
```
Backend should already be running at `http://localhost:8000`.

## Realtime
- Default WS: `VITE_WS_MARKET_URL=ws://localhost:8000/ws/market`
- If using Reverb/Laravel websockets, set the VITE_REVERB_* keys; UI falls back to REST polling if WS is down.

## Critical flows (mapping to existing pages)
- Auth: `/auth`
- Dashboard: `/` (Index)
- Strategies: `/strategies`
- Scheduler: `/scheduler`
- Risk: `/risk`
- Wallet/Billing: `/wallet` and `/billing`
- Connections: `/connections`
- Assistant: `/assistant`
- Ops: `/ops`

## Tests (when added)
- Unit (Vitest): `npm run test:unit` (add script + config if you enable Vitest)
- E2E (Playwright): `npm run test:e2e` (after adding Playwright config)

## Demo walkthrough
1) Start backend + frontend.
2) Login with seeded admin (see env defaults).
3) Start demo data: `php artisan market:demo-start` and `php artisan trading:loop-start`.
4) Open dashboard to see prices/orders update; websocket indicator shows if WS is connected.
5) Billing demo: initiate topup then simulate webhook against `/api/v1/billing/webhook/payhere`; wallet balance updates.
6) Assistant: open `/assistant` to chat; tool calls are gated by backend allowlist.
7) Ops: kill switch and health info are under `/ops` (2FA required).
