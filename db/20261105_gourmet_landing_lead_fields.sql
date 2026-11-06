-- The short VNV Gourmet quote form intentionally does not ask for a start time.
-- Keep the existing event request compatible with that one-step conversion flow.
ALTER TABLE event_requests
  MODIFY COLUMN event_time TIME NULL;
