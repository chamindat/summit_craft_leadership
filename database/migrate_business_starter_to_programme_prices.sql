-- Migration for sites that already imported the earlier business-starter database.
-- If you are starting fresh, import database/schema.sql instead of this file.

USE summitcraft_leadership;

CREATE TABLE IF NOT EXISTS programme_prices (
  programme VARCHAR(120) NOT NULL PRIMARY KEY,
  price_per_participant DECIMAL(10,2) NOT NULL DEFAULT 95.00,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO programme_prices (programme, price_per_participant)
VALUES
  ('The Ascent', 95.00),
  ('The Ridgeline', 295.00),
  ('The Summit', 495.00)
ON DUPLICATE KEY UPDATE price_per_participant = programme_prices.price_per_participant;

DELETE FROM programme_prices
WHERE programme NOT IN ('The Ascent', 'The Ridgeline', 'The Summit');
