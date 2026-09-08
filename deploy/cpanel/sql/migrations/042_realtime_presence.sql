CREATE TABLE IF NOT EXISTS passenger_presence (
  passenger_id CHAR(36) PRIMARY KEY,
  trip_id CHAR(36) NULL,
  latitude DECIMAL(10,7) NOT NULL,
  longitude DECIMAL(10,7) NOT NULL,
  accuracy_m DECIMAL(8,2) NULL,
  heading SMALLINT NULL,
  speed_kph DECIMAL(7,2) NULL,
  last_seen_at DATETIME NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_passenger_presence_user FOREIGN KEY(passenger_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_passenger_presence_trip FOREIGN KEY(trip_id) REFERENCES trips(id) ON DELETE SET NULL,
  INDEX idx_passenger_presence_trip(trip_id,last_seen_at),
  INDEX idx_passenger_presence_geo(latitude,longitude)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migrations(version) VALUES ('cpanel-mysql-0.5.4-realtime-presence')
ON DUPLICATE KEY UPDATE version=VALUES(version);
