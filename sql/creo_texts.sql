-- Migration: creo_headlines + creo_bodies
-- PGPASSWORD='...' psql -h 127.0.0.1 -U fb_ads_user -d fb_ads -f /var/www/html/sql/creo_texts.sql

DROP TABLE IF EXISTS public.creo_texts;

-- ── Table 1: Headlines (geo + approach + headline) ─────────────
CREATE TABLE IF NOT EXISTS public.creo_headlines (
    id          bigserial       PRIMARY KEY,
    geo         char(2)         NOT NULL,
    approach    varchar(100)    NOT NULL DEFAULT '',
    title       varchar(250)    NOT NULL DEFAULT '',
    created_at  timestamptz     NOT NULL DEFAULT now(),
    updated_at  timestamptz     NOT NULL DEFAULT now()
);

ALTER TABLE public.creo_headlines OWNER TO fb_ads_user;

CREATE INDEX IF NOT EXISTS idx_ch_geo     ON public.creo_headlines (geo);
CREATE INDEX IF NOT EXISTS idx_ch_approach ON public.creo_headlines (approach);
CREATE INDEX IF NOT EXISTS idx_ch_geo_app  ON public.creo_headlines (geo, approach);

-- ── Table 2: Bodies (geo + approach + body1 + body2) ────
CREATE TABLE IF NOT EXISTS public.creo_bodies (
    id          bigserial       PRIMARY KEY,
    geo         char(2)         NOT NULL,
    approach    varchar(100)    NOT NULL DEFAULT '',
    desc1       varchar(250)    NOT NULL DEFAULT '',
    desc2       text            NOT NULL DEFAULT '',
    created_at  timestamptz     NOT NULL DEFAULT now(),
    updated_at  timestamptz     NOT NULL DEFAULT now()
);

ALTER TABLE public.creo_bodies OWNER TO fb_ads_user;

CREATE INDEX IF NOT EXISTS idx_cb_geo      ON public.creo_bodies (geo);
CREATE INDEX IF NOT EXISTS idx_cb_approach ON public.creo_bodies (approach);
CREATE INDEX IF NOT EXISTS idx_cb_geo_app  ON public.creo_bodies (geo, approach);

-- ── updated_at trigger ─────────────────────────────────────────
CREATE OR REPLACE FUNCTION public.set_updated_at()
RETURNS TRIGGER LANGUAGE plpgsql AS $$
BEGIN NEW.updated_at = now(); RETURN NEW; END;
$$;

DROP TRIGGER IF EXISTS trg_ch_updated_at ON public.creo_headlines;
CREATE TRIGGER trg_ch_updated_at
    BEFORE UPDATE ON public.creo_headlines
    FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

DROP TRIGGER IF EXISTS trg_cb_updated_at ON public.creo_bodies;
CREATE TRIGGER trg_cb_updated_at
    BEFORE UPDATE ON public.creo_bodies
    FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();
