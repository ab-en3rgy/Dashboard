-- Global audit log for campaign/adset/ad/task business actions.
-- Server:
-- PGPASSWORD='...' psql -h 127.0.0.1 -U fb_ads_user -d fb_ads -f /var/www/html/sql/global_log.sql

CREATE TABLE IF NOT EXISTS public.global_log (
    id BIGSERIAL PRIMARY KEY,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    source TEXT NOT NULL DEFAULT 'system',
    actor TEXT NOT NULL DEFAULT '',
    event_type TEXT NOT NULL,
    entity_type TEXT NOT NULL DEFAULT 'task',
    entity_id TEXT,
    bm_id TEXT NOT NULL DEFAULT '',
    account_id TEXT NOT NULL DEFAULT '',
    campaign_id TEXT,
    adset_id TEXT,
    ad_id TEXT,
    task_id BIGINT,
    status TEXT NOT NULL DEFAULT 'info',
    action TEXT NOT NULL DEFAULT '',
    reason TEXT NOT NULL DEFAULT '',
    before_state JSONB,
    desired_state JSONB,
    after_state JSONB,
    payload JSONB NOT NULL DEFAULT '{}'::jsonb,
    result JSONB,
    error TEXT,
    correlation_id TEXT
);

CREATE INDEX IF NOT EXISTS idx_global_log_created
    ON public.global_log (created_at DESC, id DESC);

CREATE INDEX IF NOT EXISTS idx_global_log_task
    ON public.global_log (task_id)
    WHERE task_id IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_global_log_campaign
    ON public.global_log (campaign_id, created_at DESC)
    WHERE campaign_id IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_global_log_adset
    ON public.global_log (adset_id, created_at DESC)
    WHERE adset_id IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_global_log_bm_account
    ON public.global_log (bm_id, account_id, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_global_log_event_status
    ON public.global_log (event_type, status, created_at DESC);
