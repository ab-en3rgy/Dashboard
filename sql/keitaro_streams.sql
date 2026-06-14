CREATE TABLE IF NOT EXISTS public.keitaro_streams (
    id               TEXT        PRIMARY KEY,
    name             TEXT        NOT NULL,
    state            TEXT        NOT NULL DEFAULT '',
    kt_campaign_id   TEXT        NOT NULL DEFAULT '',
    kt_campaign_name TEXT        NOT NULL DEFAULT '',
    synced_at        TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS public.keitaro_stream_offers (
    stream_id         TEXT        NOT NULL REFERENCES public.keitaro_streams(id) ON DELETE CASCADE,
    offer_id          TEXT        NOT NULL,
    offer_name        TEXT        NOT NULL DEFAULT '',
    affiliate_network TEXT        NOT NULL DEFAULT '',
    share             INT         NOT NULL DEFAULT 0,
    state             TEXT        NOT NULL DEFAULT '',
    synced_at         TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (stream_id, offer_id)
);

CREATE INDEX IF NOT EXISTS idx_keitaro_streams_name
    ON public.keitaro_streams(name);

CREATE INDEX IF NOT EXISTS idx_keitaro_streams_campaign
    ON public.keitaro_streams(kt_campaign_id);

CREATE INDEX IF NOT EXISTS idx_keitaro_stream_offers_offer
    ON public.keitaro_stream_offers(offer_id);
