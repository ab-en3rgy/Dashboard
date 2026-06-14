-- External Meta task queue
-- Server:
-- PGPASSWORD='...' psql -h 127.0.0.1 -U fb_ads_user -d fb_ads -f /var/www/html/sql/tasks.sql

CREATE TABLE IF NOT EXISTS public.tasks (
    id              BIGSERIAL PRIMARY KEY,
    task_type       TEXT NOT NULL,
    status          TEXT NOT NULL DEFAULT 'pending',
    priority        INTEGER NOT NULL DEFAULT 100,

    bm_id           TEXT NOT NULL DEFAULT '',
    account_id      TEXT NOT NULL DEFAULT '',
    campaign_id     TEXT,
    adset_id        TEXT,

    payload         JSONB NOT NULL DEFAULT '{}'::jsonb,
    result          JSONB,
    error           TEXT,

    idempotency_key TEXT,
    created_by      TEXT NOT NULL DEFAULT 'system',
    locked_by       TEXT,
    locked_at       TIMESTAMPTZ,
    run_after       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    attempts        INTEGER NOT NULL DEFAULT 0,
    max_attempts    INTEGER NOT NULL DEFAULT 3,

    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    started_at      TIMESTAMPTZ,
    finished_at     TIMESTAMPTZ,

    CONSTRAINT tasks_type_chk CHECK (task_type IN (
        'set_campaign_status',
        'set_adset_status',
        'set_ad_status',
        'delete_campaign',
        'update_campaign_budget',
        'update_adset_budget',
        'update_adset_bid',
        'create_campaign'
    )),
    CONSTRAINT tasks_status_chk CHECK (status IN (
        'pending',
        'running',
        'done',
        'failed',
        'cancelled'
    ))
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_tasks_idempotency_key
    ON public.tasks (idempotency_key)
    WHERE idempotency_key IS NOT NULL AND idempotency_key <> '';

CREATE INDEX IF NOT EXISTS idx_tasks_poll
    ON public.tasks (status, run_after, priority DESC, created_at);

CREATE INDEX IF NOT EXISTS idx_tasks_targets
    ON public.tasks (bm_id, account_id, campaign_id, adset_id);

CREATE INDEX IF NOT EXISTS idx_tasks_type_status
    ON public.tasks (task_type, status, created_at DESC);

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
