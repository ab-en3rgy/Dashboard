-- Migration: domains_fp
-- PGPASSWORD='...' psql -h 127.0.0.1 -U fb_ads_user -d fb_ads -f /var/www/html/sql/domains.sql

CREATE TABLE IF NOT EXISTS public.domains_fp (
    id          bigserial       PRIMARY KEY,
    user_id     int             REFERENCES public.users(id) ON DELETE SET NULL,
    bm          varchar(20)     NOT NULL,
    geo         char(2)         NOT NULL,
    domain      varchar(255)    NOT NULL DEFAULT '',
    fp_name     varchar(255)    NOT NULL DEFAULT '',
    page_id     varchar(255)    NOT NULL DEFAULT '',
    pixel_id    varchar(255)    NOT NULL DEFAULT '',
    used_geos   jsonb           NOT NULL DEFAULT '[]'::jsonb,
    fp_url      varchar(2048)   NOT NULL DEFAULT '',
    status      varchar(10)     NOT NULL DEFAULT 'active'
        CONSTRAINT domains_fp_status_chk CHECK (status IN ('active', 'banned')),
    created_at  timestamptz     NOT NULL DEFAULT now(),
    updated_at  timestamptz     NOT NULL DEFAULT now()
);

ALTER TABLE public.domains_fp
    ADD COLUMN IF NOT EXISTS status varchar(10) NOT NULL DEFAULT 'active';

ALTER TABLE public.domains_fp
    ADD COLUMN IF NOT EXISTS user_id int REFERENCES public.users(id) ON DELETE SET NULL;

UPDATE public.domains_fp
SET user_id = (
    SELECT id
    FROM public.users
    WHERE role = 'admin'
    ORDER BY id
    LIMIT 1
)
WHERE user_id IS NULL
  AND EXISTS (SELECT 1 FROM public.users WHERE role = 'admin');

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

ALTER TABLE public.domains_fp OWNER TO fb_ads_user;

CREATE INDEX IF NOT EXISTS idx_dfp_bm     ON public.domains_fp (bm);
CREATE INDEX IF NOT EXISTS idx_dfp_geo    ON public.domains_fp (geo);
CREATE INDEX IF NOT EXISTS idx_dfp_bm_geo ON public.domains_fp (bm, geo);
CREATE INDEX IF NOT EXISTS idx_dfp_status ON public.domains_fp (status);
CREATE INDEX IF NOT EXISTS idx_dfp_user   ON public.domains_fp (user_id);
CREATE INDEX IF NOT EXISTS idx_dfp_used_geos ON public.domains_fp USING GIN (used_geos);

CREATE OR REPLACE FUNCTION public.set_updated_at()
RETURNS TRIGGER LANGUAGE plpgsql AS $$
BEGIN NEW.updated_at = now(); RETURN NEW; END;
$$;

DROP TRIGGER IF EXISTS trg_dfp_updated_at ON public.domains_fp;
CREATE TRIGGER trg_dfp_updated_at
    BEFORE UPDATE ON public.domains_fp
    FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();
