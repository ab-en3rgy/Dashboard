-- ============================================================
-- Migration: insights_hourly -> insights_daily
-- WARNING: deletes all data except users
-- ============================================================

\set ON_ERROR_STOP on
BEGIN;

-- ============================================================
-- 1. Clean all data except users
-- ============================================================
TRUNCATE TABLE
    fb_errors,
    sync_log,
    ads,
    ad_sets,
    campaigns,
    ad_accounts,
    user_bm_accounts,
    business_managers
CASCADE;

-- ============================================================
-- 2. Remove insights_hourly and all partitions
-- ============================================================
DROP TABLE IF EXISTS insights_hourly CASCADE;

-- ============================================================
-- 3. Remove outdated historical import tables
-- ============================================================
DROP TABLE IF EXISTS import_jobs    CASCADE;
DROP TABLE IF EXISTS import_sessions CASCADE;
DROP TABLE IF EXISTS fb_quota_log   CASCADE;
DROP TABLE IF EXISTS fb_request_log CASCADE;

-- ============================================================
-- 4. Create insights_daily
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
-- 5. Update VIEWs
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

COMMIT;
