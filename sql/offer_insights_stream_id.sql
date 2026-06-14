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

CREATE INDEX IF NOT EXISTS idx_offer_insights_stream_date
    ON public.offer_insights_daily(stream_id, date DESC);
