-- Migration for sites that already ran the programme-pricing migration.
-- This keeps programme prices only for the three bookable programmes.
-- Existing enquiries/bookings are not deleted. Existing sessions under other names are deactivated so they cannot be booked.

USE summitcraft_leadership;

INSERT INTO programme_prices (programme, price_per_participant)
VALUES
  ('The Ascent', 95.00),
  ('The Ridgeline', 295.00),
  ('The Summit', 495.00)
ON DUPLICATE KEY UPDATE price_per_participant = programme_prices.price_per_participant;

DELETE FROM programme_prices
WHERE programme NOT IN ('The Ascent', 'The Ridgeline', 'The Summit');

UPDATE sessions
SET active = 0, updated_at = CURRENT_TIMESTAMP
WHERE programme NOT IN ('The Ascent', 'The Ridgeline', 'The Summit');
