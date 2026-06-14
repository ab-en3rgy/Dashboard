-- Migration: status for domains_fp records
-- PGPASSWORD='...' psql -h 127.0.0.1 -U fb_ads_user -d fb_ads -f /var/www/html/sql/domains_status.sql

ALTER TABLE public.domains_fp
    ADD COLUMN IF NOT EXISTS status varchar(10) NOT NULL DEFAULT 'active';

UPDATE public.domains_fp
SET status = 'active'
WHERE status IS NULL OR status = '';

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'domains_fp_status_chk'
    ) THEN
        ALTER TABLE public.domains_fp
            ADD CONSTRAINT domains_fp_status_chk
            CHECK (status IN ('active', 'banned'));
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_dfp_status ON public.domains_fp (status);
