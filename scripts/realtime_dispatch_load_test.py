#!/usr/bin/env python3
"""Deterministic capacity guard for RADO shared-hosting realtime operations.

CI cannot reproduce the production cPanel/MySQL host, so this test protects the
source-level capacity invariants that are easy to regress:
- one useful driver GPS event per sample;
- worst pre-pickup passenger-presence cadence;
- synchronized GPS bursts;
- admin SSE drain batch/interval;
- snapshot payload and browser feature budgets for 50/100/200 drivers.
"""
from __future__ import annotations

import json
from dataclasses import dataclass

DRIVER_INTERVAL_S = 2.0
PASSENGER_INTERVAL_S = 1.8
DRAIN_INTERVAL_S = 0.35
DRAIN_BATCH = 300
SIM_SECONDS = 120.0


@dataclass(frozen=True)
class Result:
    drivers: int
    avg_events_per_s: float
    max_backlog: int
    worst_drain_delay_s: float
    snapshot_bytes: int
    geojson_features: int
    dom_driver_markers: int


def fake_driver(i: int) -> dict:
    return {
        "user_id": f"driver-{i:04d}",
        "full_name": f"Driver {i}",
        "phone": f"0918000{i:04d}",
        "plate_number": f"12-{i:04d}",
        "vehicle_make": "Saipa",
        "vehicle_model": "Quick",
        "vehicle_color": "white",
        "latitude": 35.99 + (i % 20) * 0.0004,
        "longitude": 45.88 + (i % 25) * 0.0004,
        "heading": i % 360,
        "speed_kph": 31.5,
        "last_seen_at_jalali": "1405/06/17 15:40:00",
    }


def fake_trip(i: int) -> dict:
    return {
        "id": f"trip-{i:04d}",
        "status": "driver_arriving",
        "pickup_lat": 35.995,
        "pickup_lng": 45.885,
        "destination_lat": 36.005,
        "destination_lng": 45.895,
        "pickup_label": "مبدا",
        "destination_label": "مقصد",
        "driver_id": f"driver-{i:04d}",
        "passenger_id": f"passenger-{i:04d}",
        "estimated_fare": 120000,
        "final_fare": None,
        "wait_seconds": 180,
        "passenger_name": f"Passenger {i}",
        "passenger_phone": f"0919000{i:04d}",
        "driver_name": f"Driver {i}",
        "passenger_lat": 35.9952,
        "passenger_lng": 45.8851,
        "passenger_accuracy_m": 8.0,
        "passenger_last_seen_at_jalali": "1405/06/17 15:40:00",
        "requested_at_jalali": "1405/06/17 15:37:00",
        "status_fa": "راننده در مسیر",
    }


def snapshot_size(drivers: int) -> tuple[int, int]:
    payload = {
        "ok": True,
        "drivers": [fake_driver(i) for i in range(drivers)],
        "trips": [fake_trip(i) for i in range(drivers)],
        "time": "1405/06/17 15:40:00",
    }
    encoded = json.dumps(payload, ensure_ascii=False, separators=(",", ":")).encode()
    # per trip: pickup + destination + route line + live passenger.
    return len(encoded), drivers * 4


def simulate(drivers: int) -> Result:
    events: dict[int, int] = {}
    end_ms = int(SIM_SECONDS * 1000)
    driver_step = int(DRIVER_INTERVAL_S * 1000)
    passenger_step = int(PASSENGER_INTERVAL_S * 1000)

    # Deliberately synchronize every device to exercise burst behavior.
    for t in range(0, end_ms + 1, driver_step):
        events[t] = events.get(t, 0) + drivers
    for t in range(0, end_ms + 1, passenger_step):
        events[t] = events.get(t, 0) + drivers

    drain_step = int(DRAIN_INTERVAL_S * 1000)
    backlog = 0
    max_backlog = 0
    max_age_ms = 0
    queue: list[tuple[int, int]] = []
    total_events = sum(events.values())

    for now in range(0, end_ms + drain_step, drain_step):
        for born in sorted(t for t in events if now - drain_step < t <= now):
            count = events[born]
            queue.append((born, count))
            backlog += count
        max_backlog = max(max_backlog, backlog)

        budget = DRAIN_BATCH
        while budget > 0 and queue:
            born, count = queue[0]
            take = min(count, budget)
            count -= take
            budget -= take
            backlog -= take
            max_age_ms = max(max_age_ms, now - born)
            if count:
                queue[0] = (born, count)
            else:
                queue.pop(0)

    now = end_ms + drain_step
    while queue:
        budget = DRAIN_BATCH
        while budget > 0 and queue:
            born, count = queue[0]
            take = min(count, budget)
            count -= take
            budget -= take
            backlog -= take
            max_age_ms = max(max_age_ms, now - born)
            if count:
                queue[0] = (born, count)
            else:
                queue.pop(0)
        now += drain_step

    size, features = snapshot_size(drivers)
    result = Result(
        drivers=drivers,
        avg_events_per_s=total_events / SIM_SECONDS,
        max_backlog=max_backlog,
        worst_drain_delay_s=max_age_ms / 1000,
        snapshot_bytes=size,
        geojson_features=features,
        dom_driver_markers=drivers,
    )

    # Release guards with headroom for non-location trip events and host jitter.
    assert result.avg_events_per_s < 240, (
        f"{drivers}: average event rate too high: {result.avg_events_per_s:.1f}/s"
    )
    assert result.max_backlog <= 600, (
        f"{drivers}: burst backlog too high: {result.max_backlog}"
    )
    assert result.worst_drain_delay_s <= 1.05, (
        f"{drivers}: live-control delay too high: {result.worst_drain_delay_s:.2f}s"
    )
    assert result.snapshot_bytes < 700_000, (
        f"{drivers}: snapshot too large: {result.snapshot_bytes} bytes"
    )
    assert result.geojson_features <= 800, (
        f"{drivers}: map feature budget exceeded: {result.geojson_features}"
    )
    assert result.dom_driver_markers <= 200
    return result


def main() -> None:
    print("RADO realtime capacity guard")
    print(
        f"drain_capacity={DRAIN_BATCH / DRAIN_INTERVAL_S:.1f} events/s "
        f"batch={DRAIN_BATCH} interval={DRAIN_INTERVAL_S:.2f}s"
    )
    for count in (50, 100, 200):
        r = simulate(count)
        print(
            f"{count:3d} drivers | avg={r.avg_events_per_s:6.1f}/s "
            f"| burst_backlog={r.max_backlog:3d} "
            f"| worst_delay={r.worst_drain_delay_s:.2f}s "
            f"| snapshot={r.snapshot_bytes/1024:.1f}KiB "
            f"| geojson={r.geojson_features} "
            f"| DOM={r.dom_driver_markers}"
        )
    print("PASS: 50/100/200 driver capacity profile")


if __name__ == "__main__":
    main()
