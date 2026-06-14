-- Migration: remove fp_url from domains_fp
-- PGPASSWORD='...' psql -h 127.0.0.1 -U fb_ads_user -d fb_ads -f /var/www/html/sql/drop_fp_url.sql

ALTER TABLE public.domains_fp DROP COLUMN IF EXISTS fp_url;
