# RADO Architecture

## System boundaries

Passenger and Driver clients talk only to the RADO API. The API owns identity, trip state, pricing, dispatch, finance and permissions. Realtime delivery is a projection of server state, never the source of truth.

## Core services

1. Auth — OTP challenge/session lifecycle.
2. Driver Availability — online/offline, current position and eligibility.
3. Pricing — fare estimate and final fare calculation.
4. Dispatch — nearby driver search and offer sequencing.
5. Trips — authoritative state machine.
6. Finance — immutable ledger for fare, commission and wallet movements.
7. Realtime — trip/driver events via WebSocket.
8. Admin — operations, driver approval, pricing, support, reporting.

## Trip state machine

`requested -> searching -> driver_assigned -> driver_arriving -> arrived -> in_progress -> completed`

Terminal alternatives: `cancelled_by_passenger`, `cancelled_by_driver`, `cancelled_by_admin`, `expired`.

All transitions are validated server-side and written transactionally.

## Dispatch v1 — Baneh

- Search eligible online drivers within 1.5 km.
- Rank by ETA/distance, idle time and recent rejection penalty.
- Offer to a small batch.
- First valid acceptance wins via database/Redis lock.
- Expand radius to 3 km and then 5 km when needed.
- Never let two drivers own the same trip.

## Scale strategy

The MVP is a modular monolith. Domain boundaries are explicit so dispatch/realtime can be extracted later if demand grows.
