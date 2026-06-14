-- Migration: fanpage setup data for api/ext/fp_setup.php
-- PGPASSWORD='...' psql -h 127.0.0.1 -U fb_ads_user -d fb_ads -f /var/www/html/sql/fanpage_data.sql

CREATE TABLE IF NOT EXISTS public.fanpage_urls (
    id          bigserial       PRIMARY KEY,
    fp_url      varchar(2048)   NOT NULL,
    status      varchar(10)     NOT NULL DEFAULT 'active'
        CONSTRAINT fanpage_urls_status_chk CHECK (status IN ('active', 'banned')),
    created_at  timestamptz     NOT NULL DEFAULT now(),
    updated_at  timestamptz     NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS public.fanpage_stop_words (
    id          bigserial       PRIMARY KEY,
    geo         char(2)         NOT NULL UNIQUE,
    stop_words  text            NOT NULL DEFAULT '',
    created_at  timestamptz     NOT NULL DEFAULT now(),
    updated_at  timestamptz     NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_fanpage_urls_status
    ON public.fanpage_urls (status);

DROP INDEX IF EXISTS public.idx_fanpage_urls_geo;
DROP INDEX IF EXISTS public.idx_fanpage_urls_geo_status;

ALTER TABLE public.fanpage_urls DROP COLUMN IF EXISTS geo;
ALTER TABLE public.fanpage_urls DROP COLUMN IF EXISTS fp_name;
ALTER TABLE public.fanpage_urls DROP COLUMN IF EXISTS note;

CREATE OR REPLACE FUNCTION public.set_updated_at()
RETURNS TRIGGER LANGUAGE plpgsql AS $$
BEGIN
    NEW.updated_at = now();
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_fanpage_urls_updated_at ON public.fanpage_urls;
CREATE TRIGGER trg_fanpage_urls_updated_at
    BEFORE UPDATE ON public.fanpage_urls
    FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

DROP TRIGGER IF EXISTS trg_fanpage_stop_words_updated_at ON public.fanpage_stop_words;
CREATE TRIGGER trg_fanpage_stop_words_updated_at
    BEFORE UPDATE ON public.fanpage_stop_words
    FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();
