ALTER TABLE IF EXISTS public.insights_daily
    ADD COLUMN IF NOT EXISTS delta NUMERIC(12,4) NOT NULL DEFAULT 0;

UPDATE public.insights_daily
SET delta = spend
WHERE delta = 0
  AND spend <> 0;
