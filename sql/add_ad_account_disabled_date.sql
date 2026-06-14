ALTER TABLE IF EXISTS public.ad_accounts
    ADD COLUMN IF NOT EXISTS disabled_date DATE;

WITH insight_dates AS (
    SELECT
        aa.id AS ad_account_id,
        MAX(id.date) FILTER (
            WHERE COALESCE(id.spend, 0) > 0
               OR COALESCE(id.impressions, 0) > 0
               OR COALESCE(id.clicks, 0) > 0
        )::date AS last_traffic_date,
        MAX(id.date)::date AS last_any_insight_date
    FROM public.ad_accounts aa
    LEFT JOIN public.ads a ON a.ad_account_id = aa.id
    LEFT JOIN public.insights_daily id ON id.ad_id = a.id
    WHERE aa.status <> 1
    GROUP BY aa.id
)
UPDATE public.ad_accounts aa
SET disabled_date = COALESCE(d.last_traffic_date, d.last_any_insight_date, aa.synced_at::date, CURRENT_DATE)
FROM insight_dates d
WHERE aa.id = d.ad_account_id
  AND aa.status <> 1
  AND aa.disabled_date IS NULL;

UPDATE public.ad_accounts
SET disabled_date = COALESCE(disabled_date, synced_at::date, CURRENT_DATE)
WHERE status <> 1
  AND disabled_date IS NULL;
