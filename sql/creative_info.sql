-- Migration: creative info metadata for creative_previews.php
-- PGPASSWORD='...' psql -h 127.0.0.1 -p 5432 -U fb_ads_user -d fb_ads -f /var/www/html/sql/creative_info.sql

CREATE TABLE IF NOT EXISTS public.creative_info (
    creative_name varchar(255) PRIMARY KEY,
    author varchar(255) NOT NULL DEFAULT '',
    launch_date date NULL,
    approach_name varchar(255) NOT NULL DEFAULT '',
    notes text NOT NULL DEFAULT '',
    updated_by int NULL REFERENCES public.users(id) ON DELETE SET NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

ALTER TABLE public.creative_info
    ALTER COLUMN launch_date SET DEFAULT CURRENT_DATE;

CREATE INDEX IF NOT EXISTS idx_creative_info_launch_date
    ON public.creative_info (launch_date);

CREATE OR REPLACE FUNCTION public.sync_creative_info_from_ads()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF COALESCE(BTRIM(NEW.name), '') <> '' AND COALESCE(NEW.status, '') <> 'DELETED' THEN
        INSERT INTO public.creative_info (creative_name, launch_date)
        VALUES (NEW.name, CURRENT_DATE)
        ON CONFLICT (creative_name) DO NOTHING;
    END IF;
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_ads_sync_creative_info ON public.ads;

CREATE TRIGGER trg_ads_sync_creative_info
AFTER INSERT OR UPDATE OF name, status ON public.ads
FOR EACH ROW
EXECUTE FUNCTION public.sync_creative_info_from_ads();

UPDATE public.creative_info
SET launch_date = DATE_TRUNC('year', CURRENT_DATE)::date
WHERE launch_date IS NULL;

INSERT INTO public.creative_info (creative_name, launch_date)
SELECT src.creative_name, DATE_TRUNC('year', CURRENT_DATE)::date
FROM (
    SELECT DISTINCT a.name AS creative_name
    FROM public.ads a
    WHERE COALESCE(BTRIM(a.name), '') <> ''
) src
LEFT JOIN public.creative_info ci ON ci.creative_name = src.creative_name
WHERE ci.creative_name IS NULL;
