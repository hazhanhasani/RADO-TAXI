CREATE EXTENSION IF NOT EXISTS postgis;
CREATE EXTENSION IF NOT EXISTS pgcrypto;

CREATE TYPE user_role AS ENUM ('passenger','driver','admin');
CREATE TYPE driver_status AS ENUM ('pending','approved','suspended','rejected');
CREATE TYPE trip_status AS ENUM ('requested','searching','driver_assigned','driver_arriving','arrived','in_progress','completed','cancelled_by_passenger','cancelled_by_driver','cancelled_by_admin','expired');

CREATE TABLE users (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  phone varchar(20) UNIQUE NOT NULL,
  role user_role NOT NULL,
  full_name varchar(120),
  is_active boolean NOT NULL DEFAULT true,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE drivers (
  user_id uuid PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
  status driver_status NOT NULL DEFAULT 'pending',
  national_id varchar(20),
  license_number varchar(50),
  plate_number varchar(32),
  vehicle_make varchar(60),
  vehicle_model varchar(60),
  vehicle_color varchar(40),
  commission_rate numeric(5,2) NOT NULL DEFAULT 10.00,
  approved_at timestamptz
);

CREATE TABLE driver_presence (
  driver_id uuid PRIMARY KEY REFERENCES drivers(user_id) ON DELETE CASCADE,
  is_online boolean NOT NULL DEFAULT false,
  location geography(Point,4326),
  heading numeric(6,2),
  speed_kph numeric(7,2),
  last_seen_at timestamptz
);
CREATE INDEX driver_presence_location_gix ON driver_presence USING GIST(location);

CREATE TABLE trips (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  passenger_id uuid NOT NULL REFERENCES users(id),
  driver_id uuid REFERENCES drivers(user_id),
  status trip_status NOT NULL DEFAULT 'requested',
  pickup geography(Point,4326) NOT NULL,
  pickup_label varchar(255),
  destination geography(Point,4326) NOT NULL,
  destination_label varchar(255),
  estimated_distance_m integer,
  estimated_duration_s integer,
  estimated_fare bigint,
  final_fare bigint,
  requested_at timestamptz NOT NULL DEFAULT now(),
  accepted_at timestamptz,
  started_at timestamptz,
  completed_at timestamptz,
  cancelled_at timestamptz,
  cancellation_reason varchar(255),
  version integer NOT NULL DEFAULT 1
);
CREATE INDEX trips_passenger_idx ON trips(passenger_id, requested_at DESC);
CREATE INDEX trips_driver_idx ON trips(driver_id, requested_at DESC);
CREATE INDEX trips_status_idx ON trips(status, requested_at DESC);

CREATE TABLE trip_offers (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  trip_id uuid NOT NULL REFERENCES trips(id) ON DELETE CASCADE,
  driver_id uuid NOT NULL REFERENCES drivers(user_id) ON DELETE CASCADE,
  offered_at timestamptz NOT NULL DEFAULT now(),
  expires_at timestamptz NOT NULL,
  responded_at timestamptz,
  accepted boolean,
  UNIQUE(trip_id, driver_id)
);

CREATE TABLE pricing_rules (
  id bigserial PRIMARY KEY,
  name varchar(100) NOT NULL,
  base_fare bigint NOT NULL DEFAULT 0,
  per_km bigint NOT NULL DEFAULT 0,
  per_minute bigint NOT NULL DEFAULT 0,
  waiting_per_minute bigint NOT NULL DEFAULT 0,
  minimum_fare bigint NOT NULL DEFAULT 0,
  surge_multiplier numeric(6,3) NOT NULL DEFAULT 1.000,
  active boolean NOT NULL DEFAULT true,
  effective_from timestamptz NOT NULL DEFAULT now(),
  effective_to timestamptz
);

CREATE TABLE ledger_entries (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id uuid NOT NULL REFERENCES users(id),
  trip_id uuid REFERENCES trips(id),
  entry_type varchar(40) NOT NULL,
  amount bigint NOT NULL,
  balance_after bigint,
  idempotency_key varchar(100) UNIQUE,
  metadata jsonb NOT NULL DEFAULT '{}'::jsonb,
  created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX ledger_user_idx ON ledger_entries(user_id, created_at DESC);

CREATE TABLE ratings (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  trip_id uuid NOT NULL REFERENCES trips(id) ON DELETE CASCADE,
  from_user_id uuid NOT NULL REFERENCES users(id),
  to_user_id uuid NOT NULL REFERENCES users(id),
  score smallint NOT NULL CHECK(score BETWEEN 1 AND 5),
  comment text,
  created_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE(trip_id, from_user_id)
);
