-- Migration: M1 fields for collations
-- PGPASSWORD='...' psql -h 127.0.0.1 -U fb_ads_user -d fb_ads -f /var/www/html/sql/collations_m1_fields.sql

ALTER TABLE public.collations
    ADD COLUMN IF NOT EXISTS sum_m1_deposits NUMERIC(15,2) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS sum_m1_marketing_spend NUMERIC(15,2) NOT NULL DEFAULT 0;
