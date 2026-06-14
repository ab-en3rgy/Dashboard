-- Migration: change the unique collations key from period -> month
-- PGPASSWORD='...' psql -h 127.0.0.1 -U fb_ads_user -d fb_ads -f /var/www/html/sql/collations_conflict_month.sql

ALTER TABLE public.collations
    DROP CONSTRAINT IF EXISTS collations_source_brand_name_country_tracking_code_period_key;

ALTER TABLE public.collations
    ADD CONSTRAINT collations_source_brand_name_country_tracking_code_month_key
    UNIQUE (source, brand_name, country, tracking_code, month);
