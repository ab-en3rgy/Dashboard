ALTER TABLE public.business_managers
    ADD COLUMN IF NOT EXISTS auto_rules_cron_enabled BOOLEAN NOT NULL DEFAULT FALSE;
