-- Make dashboard auth sessions persistent.
-- PGPASSWORD='...' psql -h 127.0.0.1 -U fb_ads_user -d fb_ads -f /var/www/html/sql/sessions_never_expire.sql

ALTER TABLE public.sessions
    ALTER COLUMN expires_at SET DEFAULT 'infinity'::timestamptz;

UPDATE public.sessions
SET expires_at = 'infinity'::timestamptz
WHERE expires_at IS DISTINCT FROM 'infinity'::timestamptz;
