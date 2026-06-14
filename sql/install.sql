-- ============================================================
-- FB Ads Dashboard — PostgreSQL Schema v2.0
-- Architecture: BM is the central entity
-- Each account belongs to a BM. The token is stored in the BM.
-- ============================================================

\set ON_ERROR_STOP on
BEGIN;

-- ============================================================
-- 1. Extensions
-- ============================================================
CREATE EXTENSION IF NOT EXISTS pg_trgm;

-- ============================================================
-- 2. Business Managers (central entity)
--    The token is stored here - one token per BM
-- ============================================================
CREATE TABLE IF NOT EXISTS business_managers (
    id               BIGINT       PRIMARY KEY,   -- FB BM ID
    name             VARCHAR(255) NOT NULL,
    access_token     TEXT,                        -- long-lived API token
    token_expires_at TIMESTAMPTZ,
    is_active        BOOLEAN      NOT NULL DEFAULT TRUE,
    name_locked      BOOLEAN      NOT NULL DEFAULT FALSE,
    auto_rules_cron_enabled BOOLEAN NOT NULL DEFAULT FALSE,
    created_at       TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at       TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    synced_at        TIMESTAMPTZ
);

-- ============================================================
-- 3. Dashboard users
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
    id            SERIAL       PRIMARY KEY,
    username      VARCHAR(80)  NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role          VARCHAR(10)  NOT NULL DEFAULT 'user'
                      CHECK (role IN ('admin', 'user')),
    display_name  VARCHAR(255),
    is_active     BOOLEAN      NOT NULL DEFAULT TRUE,
    created_by    INT          REFERENCES users(id) ON DELETE SET NULL,
    created_at    TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    last_login_at TIMESTAMPTZ,
    display_tz    VARCHAR(100) NOT NULL DEFAULT 'Europe/Kyiv'
);

-- ============================================================
-- 4. Buyer access to BM
--    Buyer sees only BMs from this table and all accounts inside
-- ============================================================
CREATE TABLE IF NOT EXISTS user_bm_accounts (
    user_id INT    NOT NULL REFERENCES users(id)              ON DELETE CASCADE,
    bm_id   BIGINT NOT NULL REFERENCES business_managers(id)  ON DELETE CASCADE,
    PRIMARY KEY (user_id, bm_id)
);

CREATE INDEX IF NOT EXISTS idx_uba_user ON user_bm_accounts(user_id);
CREATE INDEX IF NOT EXISTS idx_uba_bm   ON user_bm_accounts(bm_id);

-- ============================================================
-- 4b. FBTool accounts owned by dashboard users
--     Hierarchy: user -> fbtool_account -> BM -> ad account
-- ============================================================
CREATE TABLE IF NOT EXISTS fbtool_accounts (
    id         BIGSERIAL PRIMARY KEY,
    user_id    INT          NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    fbtool_id  VARCHAR(50)  NOT NULL UNIQUE,
    name       VARCHAR(255),
    is_active  BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_fbtool_accounts_user_id ON fbtool_accounts(user_id);

-- ============================================================
-- 5. Sessions
-- ============================================================
CREATE TABLE IF NOT EXISTS sessions (
    token        VARCHAR(64) PRIMARY KEY,
    user_id      INT         NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    ip           VARCHAR(45),
    user_agent   TEXT,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    expires_at   TIMESTAMPTZ NOT NULL DEFAULT 'infinity'::timestamptz,
    last_seen_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_sessions_user    ON sessions(user_id);
CREATE INDEX IF NOT EXISTS idx_sessions_expires ON sessions(expires_at);

-- ============================================================
-- 6. Ad Accounts
--    bm_id NOT NULL — each account belongs to a BM
-- ============================================================
CREATE TABLE IF NOT EXISTS ad_accounts (
    id            VARCHAR(50)  PRIMARY KEY,   -- act_XXXXXXX
    bm_id         BIGINT       NOT NULL REFERENCES business_managers(id) ON DELETE CASCADE,
    name          VARCHAR(255) NOT NULL,
    status        SMALLINT     NOT NULL DEFAULT 1,
        -- 1=ACTIVE 2=DISABLED 3=UNSETTLED 7=PENDING_RISK_REVIEW 9=IN_GRACE_PERIOD
    disabled_date DATE,
    timezone_id   INT,
    timezone_name VARCHAR(100) NOT NULL DEFAULT 'UTC',
    currency      VARCHAR(10)  NOT NULL DEFAULT 'USD',
    spend_cap     NUMERIC(15,2),
    amount_spent  NUMERIC(15,2) NOT NULL DEFAULT 0,
    balance       NUMERIC(15,2) NOT NULL DEFAULT 0,
    synced_at     TIMESTAMPTZ
);

CREATE INDEX IF NOT EXISTS idx_aa_bm ON ad_accounts(bm_id);

ALTER TABLE business_managers
    ADD COLUMN IF NOT EXISTS fbtool_account_id BIGINT REFERENCES fbtool_accounts(id) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS idx_bm_fbtool_account_id ON business_managers(fbtool_account_id);

-- ============================================================
-- 7. Sync log
-- ============================================================
CREATE TABLE IF NOT EXISTS sync_log (
    id              BIGSERIAL    PRIMARY KEY,
    started_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    finished_at     TIMESTAMPTZ,
    source          VARCHAR(20)  NOT NULL DEFAULT 'cron',
    status          VARCHAR(20)  NOT NULL DEFAULT 'running',
    accounts        INT          NOT NULL DEFAULT 0,
    campaigns       INT          NOT NULL DEFAULT 0,
    ad_sets         INT          NOT NULL DEFAULT 0,
    ads_count       INT          NOT NULL DEFAULT 0,
    insight_rows    INT          NOT NULL DEFAULT 0,
    total_requests  INT          NOT NULL DEFAULT 0,
    failed_requests INT          NOT NULL DEFAULT 0,
    rate_limit_hits INT          NOT NULL DEFAULT 0,
    total_retries   INT          NOT NULL DEFAULT 0,
    duration_sec    NUMERIC(8,1),
    error_msg       TEXT
);

-- ============================================================
-- 8. Detailed log of FB Graph API requests
-- ============================================================
CREATE TABLE IF NOT EXISTS fb_request_log (
    id            BIGSERIAL   PRIMARY KEY,
    sync_log_id   INT         REFERENCES sync_log(id) ON DELETE SET NULL,
    ts            TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    request_type  VARCHAR(30) NOT NULL,
    endpoint      TEXT        NOT NULL,
    batch_size    SMALLINT    NOT NULL DEFAULT 1,
    http_code     SMALLINT,
    duration_ms   INT,
    rows_returned INT         NOT NULL DEFAULT 0,
    attempt       SMALLINT    NOT NULL DEFAULT 1,
    status        VARCHAR(20) NOT NULL DEFAULT 'ok',
    error_code    INT,
    error_msg     TEXT,
    response_preview TEXT,
    raw_error     JSONB
);

CREATE INDEX IF NOT EXISTS idx_rlog_sync   ON fb_request_log(sync_log_id);
CREATE INDEX IF NOT EXISTS idx_rlog_ts     ON fb_request_log(ts DESC);
CREATE INDEX IF NOT EXISTS idx_rlog_status ON fb_request_log(status) WHERE status != 'ok';

-- ============================================================
-- 9. Error log
-- ============================================================
CREATE TABLE IF NOT EXISTS fb_errors (
    id            BIGSERIAL   PRIMARY KEY,
    ts            TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    sync_log_id   INT         REFERENCES sync_log(id) ON DELETE SET NULL,
    severity      VARCHAR(10) NOT NULL DEFAULT 'error',
    category      VARCHAR(30) NOT NULL,
    fb_error_code INT,
    message       TEXT        NOT NULL,
    context       JSONB,
    resolved      BOOLEAN     NOT NULL DEFAULT FALSE,
    resolved_at   TIMESTAMPTZ,
    note          TEXT
);

CREATE INDEX IF NOT EXISTS idx_errors_ts       ON fb_errors(ts DESC);
CREATE INDEX IF NOT EXISTS idx_errors_severity ON fb_errors(severity);
CREATE INDEX IF NOT EXISTS idx_errors_resolved ON fb_errors(resolved) WHERE resolved = FALSE;
CREATE INDEX IF NOT EXISTS idx_errors_category ON fb_errors(category);

-- ============================================================
-- 10. FB API quota log
-- ============================================================
CREATE TABLE IF NOT EXISTS fb_quota_log (
    id              BIGSERIAL   PRIMARY KEY,
    ts              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    sync_log_id     INT         REFERENCES sync_log(id) ON DELETE SET NULL,
    import_job_id   BIGINT,
    ad_account_id   VARCHAR(50) NOT NULL,
    buc_type        VARCHAR(30),
    call_count      SMALLINT    NOT NULL DEFAULT 0,
    total_cputime   SMALLINT    NOT NULL DEFAULT 0,
    total_time      SMALLINT    NOT NULL DEFAULT 0,
    max_pct         SMALLINT    NOT NULL DEFAULT 0,
    estimated_time_to_regain_access INT,
    throttle_status VARCHAR(20) NOT NULL DEFAULT 'ok',
    request_type    VARCHAR(30),
    raw_header      JSONB
);

CREATE INDEX IF NOT EXISTS idx_quota_account_ts ON fb_quota_log(ad_account_id, ts DESC);
CREATE INDEX IF NOT EXISTS idx_quota_throttle   ON fb_quota_log(throttle_status, ts DESC)
    WHERE throttle_status != 'ok';
CREATE INDEX IF NOT EXISTS idx_quota_sync       ON fb_quota_log(sync_log_id)
    WHERE sync_log_id IS NOT NULL;

-- ============================================================
-- 11. Global business action log
-- ============================================================
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

-- ============================================================
-- 12. Campaigns
-- ============================================================
CREATE TABLE IF NOT EXISTS campaigns (
    id               BIGINT       PRIMARY KEY,
    ad_account_id    VARCHAR(50)  NOT NULL REFERENCES ad_accounts(id) ON DELETE CASCADE,
    name             VARCHAR(512) NOT NULL,
    status           VARCHAR(20)  NOT NULL DEFAULT 'PAUSED',
    effective_status VARCHAR(20),
    objective        VARCHAR(100),
    daily_budget     NUMERIC(15,2),
    lifetime_budget  NUMERIC(15,2),
    auto_rule_verdict TEXT,
    auto_rule_payload JSONB,
    created_time     TIMESTAMPTZ,
    updated_time     TIMESTAMPTZ,
    synced_at        TIMESTAMPTZ
);

CREATE INDEX IF NOT EXISTS idx_camp_account ON campaigns(ad_account_id);

-- ============================================================
-- 12. Ad sets
-- ============================================================
CREATE TABLE IF NOT EXISTS ad_sets (
    id               BIGINT       PRIMARY KEY,
    campaign_id      BIGINT       NOT NULL REFERENCES campaigns(id) ON DELETE CASCADE,
    ad_account_id    VARCHAR(50)  NOT NULL,
    name             VARCHAR(512) NOT NULL,
    status           VARCHAR(20)  NOT NULL DEFAULT 'PAUSED',
    effective_status VARCHAR(20),
    daily_budget     NUMERIC(15,2),
    lifetime_budget  NUMERIC(15,2),
    bid_amount       NUMERIC(15,2),
    bid_strategy_mode TEXT,
    targeting        JSONB,
    created_time     TIMESTAMPTZ,
    updated_time     TIMESTAMPTZ,
    synced_at        TIMESTAMPTZ
);

CREATE INDEX IF NOT EXISTS idx_adset_campaign ON ad_sets(campaign_id);
CREATE INDEX IF NOT EXISTS idx_adset_account  ON ad_sets(ad_account_id);

-- ============================================================
-- 13. Ads
-- ============================================================
CREATE TABLE IF NOT EXISTS ads (
    id               BIGINT       PRIMARY KEY,
    ad_set_id        BIGINT       NOT NULL REFERENCES ad_sets(id) ON DELETE CASCADE,
    campaign_id      BIGINT       NOT NULL,
    ad_account_id    VARCHAR(50)  NOT NULL,
    name             VARCHAR(512) NOT NULL,
    status           VARCHAR(20)  NOT NULL DEFAULT 'PAUSED',
    effective_status VARCHAR(20),
    creative_id      BIGINT,
    created_time     TIMESTAMPTZ,
    updated_time     TIMESTAMPTZ,
    synced_at        TIMESTAMPTZ
);

CREATE INDEX IF NOT EXISTS idx_ads_adset    ON ads(ad_set_id);
CREATE INDEX IF NOT EXISTS idx_ads_campaign ON ads(campaign_id);
CREATE INDEX IF NOT EXISTS idx_ads_account  ON ads(ad_account_id);

-- ============================================================
-- 14. Insights by day
-- ============================================================
CREATE TABLE IF NOT EXISTS insights_daily (
    ad_id       BIGINT        NOT NULL,
    date        DATE          NOT NULL,
    impressions INT           NOT NULL DEFAULT 0,
    clicks      INT           NOT NULL DEFAULT 0,
    spend       NUMERIC(12,4) NOT NULL DEFAULT 0,
    delta       NUMERIC(12,4) NOT NULL DEFAULT 0,
    cpc         NUMERIC(12,4) NOT NULL DEFAULT 0,
    ctr         NUMERIC(8,4)  NOT NULL DEFAULT 0,
    cpm         NUMERIC(12,4) NOT NULL DEFAULT 0,
    frequency   NUMERIC(8,4)  NOT NULL DEFAULT 0,
    leads       INT           NOT NULL DEFAULT 0,
    regs        INT           NOT NULL DEFAULT 0,
    deps        INT           NOT NULL DEFAULT 0,
    revenue     NUMERIC(12,4) NOT NULL DEFAULT 0,
    fb_synced_at  TIMESTAMPTZ,
    kt_synced_at  TIMESTAMPTZ,
    PRIMARY KEY (ad_id, date)
);

CREATE INDEX IF NOT EXISTS idx_id_date    ON insights_daily(date DESC);
CREATE INDEX IF NOT EXISTS idx_id_ad_date ON insights_daily(ad_id, date DESC);

-- ============================================================
-- 14.1. Daily offer analytics from Keitaro
-- ============================================================
CREATE TABLE IF NOT EXISTS offer_insights_daily (
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

CREATE INDEX IF NOT EXISTS idx_offer_insights_date
    ON offer_insights_daily(date DESC);
CREATE INDEX IF NOT EXISTS idx_offer_insights_offer_date
    ON offer_insights_daily(offer_id, date DESC);
CREATE INDEX IF NOT EXISTS idx_offer_insights_geo_date
    ON offer_insights_daily(geo, date DESC);
CREATE INDEX IF NOT EXISTS idx_offer_insights_ad_date
    ON offer_insights_daily(ad_id, date DESC);
CREATE INDEX IF NOT EXISTS idx_offer_insights_stream_date
    ON offer_insights_daily(stream_id, date DESC);

-- ============================================================
-- 14.2. Keitaro stream catalog
-- ============================================================
CREATE TABLE IF NOT EXISTS keitaro_streams (
    id               TEXT        PRIMARY KEY,
    name             TEXT        NOT NULL,
    state            TEXT        NOT NULL DEFAULT '',
    kt_campaign_id   TEXT        NOT NULL DEFAULT '',
    kt_campaign_name TEXT        NOT NULL DEFAULT '',
    synced_at        TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS keitaro_stream_offers (
    stream_id         TEXT        NOT NULL REFERENCES keitaro_streams(id) ON DELETE CASCADE,
    offer_id          TEXT        NOT NULL,
    offer_name        TEXT        NOT NULL DEFAULT '',
    affiliate_network TEXT        NOT NULL DEFAULT '',
    share             INT         NOT NULL DEFAULT 0,
    state             TEXT        NOT NULL DEFAULT '',
    synced_at         TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (stream_id, offer_id)
);

CREATE INDEX IF NOT EXISTS idx_keitaro_streams_name
    ON keitaro_streams(name);
CREATE INDEX IF NOT EXISTS idx_keitaro_streams_campaign
    ON keitaro_streams(kt_campaign_id);
CREATE INDEX IF NOT EXISTS idx_keitaro_stream_offers_offer
    ON keitaro_stream_offers(offer_id);

-- ============================================================
-- 15. Historical import sessions
-- ============================================================
CREATE TABLE IF NOT EXISTS import_sessions (
    id          SERIAL      PRIMARY KEY,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_by  INT         REFERENCES users(id) ON DELETE SET NULL,
    label       VARCHAR(255),
    date_from   DATE        NOT NULL,
    date_to     DATE        NOT NULL,
    total_jobs  INT         NOT NULL DEFAULT 0,
    done_jobs   INT         NOT NULL DEFAULT 0,
    error_jobs  INT         NOT NULL DEFAULT 0,
    status      VARCHAR(20) NOT NULL DEFAULT 'running',
    finished_at TIMESTAMPTZ
);

-- ============================================================
-- 17. Historical import jobs
-- ============================================================
CREATE TABLE IF NOT EXISTS import_jobs (
    id            BIGSERIAL   PRIMARY KEY,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_by    INT         REFERENCES users(id)          ON DELETE SET NULL,
    session_id    INT         REFERENCES import_sessions(id) ON DELETE SET NULL,
    ad_account_id VARCHAR(50) NOT NULL REFERENCES ad_accounts(id) ON DELETE CASCADE,
    import_date   DATE        NOT NULL,
    status        VARCHAR(20) NOT NULL DEFAULT 'pending',
    priority      SMALLINT    NOT NULL DEFAULT 5,
    started_at    TIMESTAMPTZ,
    finished_at   TIMESTAMPTZ,
    rows_upserted INT         NOT NULL DEFAULT 0,
    attempts      SMALLINT    NOT NULL DEFAULT 0,
    error_msg     TEXT,
    sync_log_id   INT         REFERENCES sync_log(id) ON DELETE SET NULL,
    UNIQUE (ad_account_id, import_date)
);

CREATE INDEX IF NOT EXISTS idx_ij_status  ON import_jobs(status, priority, import_date DESC);
CREATE INDEX IF NOT EXISTS idx_ij_account ON import_jobs(ad_account_id, import_date);
CREATE INDEX IF NOT EXISTS idx_ij_session ON import_jobs(session_id);
CREATE INDEX IF NOT EXISTS idx_ij_created ON import_jobs(created_at DESC);

-- FK from fb_quota_log to import_jobs
ALTER TABLE fb_quota_log
    ADD CONSTRAINT fk_quota_import_job
    FOREIGN KEY (import_job_id) REFERENCES import_jobs(id) ON DELETE SET NULL;

-- ============================================================
-- 18. VIEWs
-- ============================================================

-- Spend by BM for today
CREATE OR REPLACE VIEW v_bm_spend_today AS
SELECT
    bm.id            AS bm_id,
    bm.name          AS bm_name,
    COUNT(DISTINCT aa.id)  AS accounts_count,
    SUM(id.spend)          AS spend,
    SUM(id.impressions)    AS impressions,
    SUM(id.clicks)         AS clicks
FROM business_managers bm
JOIN ad_accounts aa ON aa.bm_id = bm.id
JOIN ads a          ON a.ad_account_id = aa.id
JOIN insights_daily id ON id.ad_id = a.id
WHERE id.date = CURRENT_DATE
GROUP BY bm.id, bm.name;

-- Spend by campaign for today
CREATE OR REPLACE VIEW v_campaign_spend_today AS
SELECT
    c.id          AS campaign_id,
    c.name        AS campaign_name,
    c.ad_account_id,
    aa.bm_id,
    c.status,
    SUM(id.spend)       AS spend,
    SUM(id.impressions) AS impressions,
    SUM(id.clicks)      AS clicks
FROM campaigns c
JOIN ad_accounts aa ON aa.id = c.ad_account_id
JOIN ads a          ON a.campaign_id = c.id
JOIN insights_daily id ON id.ad_id = a.id
WHERE id.date = CURRENT_DATE
GROUP BY c.id, c.name, c.ad_account_id, aa.bm_id, c.status;

-- Full statistics for an ad with conversions
CREATE OR REPLACE VIEW v_ad_stats_full AS
SELECT
    a.id          AS ad_id,
    a.name        AS ad_name,
    a.campaign_id,
    a.ad_set_id,
    a.ad_account_id,
    aa.bm_id,
    a.status,
    id.date,
    id.impressions, id.clicks, id.spend, id.cpc, id.ctr, id.cpm, id.frequency,
    id.leads, id.regs, id.deps, id.revenue
FROM ads a
JOIN ad_accounts aa ON aa.id = a.ad_account_id
JOIN insights_daily id ON id.ad_id = a.id;

-- Errors with sync context
CREATE OR REPLACE VIEW v_recent_errors AS
SELECT e.id, e.ts, e.severity, e.category, e.fb_error_code,
       e.message, e.context, e.resolved, e.note,
       sl.source AS sync_source, sl.started_at AS sync_started
FROM fb_errors e
LEFT JOIN sync_log sl ON sl.id = e.sync_log_id
ORDER BY e.ts DESC;

-- Sync statistics
CREATE OR REPLACE VIEW v_sync_stats AS
SELECT sl.id, sl.started_at, sl.finished_at, sl.source, sl.status,
       sl.duration_sec, sl.accounts, sl.campaigns, sl.ad_sets,
       sl.ads_count, sl.insight_rows, sl.total_requests,
       sl.failed_requests, sl.rate_limit_hits, sl.total_retries, sl.error_msg,
       COUNT(e.id) FILTER (WHERE e.severity = 'fatal')   AS fatal_count,
       COUNT(e.id) FILTER (WHERE e.severity = 'error')   AS error_count,
       COUNT(e.id) FILTER (WHERE e.severity = 'warning') AS warning_count
FROM sync_log sl
LEFT JOIN fb_errors e ON e.sync_log_id = sl.id
GROUP BY sl.id
ORDER BY sl.started_at DESC;

-- Current quota
CREATE OR REPLACE VIEW v_quota_current AS
SELECT DISTINCT ON (ad_account_id)
    ad_account_id, ts, buc_type, call_count, total_cputime, total_time,
    max_pct, throttle_status, estimated_time_to_regain_access
FROM fb_quota_log
ORDER BY ad_account_id, ts DESC;

-- Quota for 24h
CREATE OR REPLACE VIEW v_quota_history_24h AS
SELECT date_trunc('hour', ts) AS hour, ad_account_id,
       MAX(max_pct) AS peak_pct, AVG(max_pct) AS avg_pct,
       COUNT(*) FILTER (WHERE throttle_status = 'warning')   AS warnings,
       COUNT(*) FILTER (WHERE throttle_status = 'critical')  AS criticals,
       COUNT(*) FILTER (WHERE throttle_status = 'throttled') AS throttled
FROM fb_quota_log
WHERE ts >= NOW() - INTERVAL '24 hours'
GROUP BY date_trunc('hour', ts), ad_account_id
ORDER BY hour DESC, ad_account_id;

-- Import progress
CREATE OR REPLACE VIEW v_import_progress AS
SELECT s.id, s.created_at, s.label, s.date_from, s.date_to,
       s.status, s.total_jobs, s.done_jobs, s.error_jobs,
       s.total_jobs - s.done_jobs - s.error_jobs AS pending_jobs,
       CASE WHEN s.total_jobs > 0 THEN ROUND(s.done_jobs::numeric / s.total_jobs * 100, 1) ELSE 0 END AS pct_done,
       u.username AS created_by_name,
       AVG(EXTRACT(EPOCH FROM (j.finished_at - j.started_at))) FILTER (WHERE j.status = 'done') AS avg_job_sec
FROM import_sessions s
LEFT JOIN users u       ON u.id = s.created_by
LEFT JOIN import_jobs j ON j.session_id = s.id
GROUP BY s.id, u.username
ORDER BY s.created_at DESC;

-- ============================================================
-- 19. First admin (password: "password" - CHANGE!)
-- ============================================================
INSERT INTO users (username, password_hash, role, display_name)
VALUES ('admin', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 'Administrator')
ON CONFLICT (username) DO NOTHING;

COMMIT;
