CREATE TABLE IF NOT EXISTS public.offer_insights_daily (
    date                  DATE          NOT NULL,
    ad_id                 TEXT          NOT NULL,
    offer_id              TEXT          NOT NULL,
    offer_name            TEXT          NOT NULL DEFAULT '',
    affiliate_network     TEXT          NOT NULL DEFAULT '',
    geo                   TEXT          NOT NULL DEFAULT '',
    stream_id             TEXT          NOT NULL DEFAULT '',
    kt_campaign_id        TEXT          NOT NULL DEFAULT '',
    kt_campaign_name      TEXT          NOT NULL DEFAULT '',
    fb_campaign_id        TEXT          NOT NULL DEFAULT '',
    fb_campaign_name      TEXT          NOT NULL DEFAULT '',
    fb_adset_id           TEXT          NOT NULL DEFAULT '',
    fb_adset_name         TEXT          NOT NULL DEFAULT '',
    fb_ad_name            TEXT          NOT NULL DEFAULT '',
    clicks                INT           NOT NULL DEFAULT 0,
    regs                  INT           NOT NULL DEFAULT 0,
    deps                  INT           NOT NULL DEFAULT 0,
    conversions           INT           NOT NULL DEFAULT 0,
    revenue               NUMERIC(12,4) NOT NULL DEFAULT 0,
    allocated_spend       NUMERIC(12,4) NOT NULL DEFAULT 0,
    source_ad_spend       NUMERIC(12,4) NOT NULL DEFAULT 0,
    matched_ad            BOOLEAN       NOT NULL DEFAULT FALSE,
    spend_allocation_basis TEXT         NOT NULL DEFAULT 'clicks',
    synced_at             TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
    PRIMARY KEY (date, ad_id, offer_id, geo, kt_campaign_id, stream_id)
);

ALTER TABLE public.offer_insights_daily
    ADD COLUMN IF NOT EXISTS stream_id TEXT NOT NULL DEFAULT '';

DO $$
DECLARE
    pk_def TEXT;
BEGIN
    SELECT pg_get_constraintdef(oid)
    INTO pk_def
    FROM pg_constraint
    WHERE conrelid = 'public.offer_insights_daily'::regclass
      AND conname = 'offer_insights_daily_pkey';

    IF pk_def IS NULL OR pk_def NOT ILIKE '%stream_id%' THEN
        IF pk_def IS NOT NULL THEN
            ALTER TABLE public.offer_insights_daily
                DROP CONSTRAINT offer_insights_daily_pkey;
        END IF;

        ALTER TABLE public.offer_insights_daily
            ADD CONSTRAINT offer_insights_daily_pkey
            PRIMARY KEY (date, ad_id, offer_id, geo, kt_campaign_id, stream_id);
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_offer_insights_date
    ON public.offer_insights_daily(date DESC);

CREATE INDEX IF NOT EXISTS idx_offer_insights_offer_date
    ON public.offer_insights_daily(offer_id, date DESC);

CREATE INDEX IF NOT EXISTS idx_offer_insights_geo_date
    ON public.offer_insights_daily(geo, date DESC);

CREATE INDEX IF NOT EXISTS idx_offer_insights_ad_date
    ON public.offer_insights_daily(ad_id, date DESC);

CREATE INDEX IF NOT EXISTS idx_offer_insights_stream_date
    ON public.offer_insights_daily(stream_id, date DESC);
