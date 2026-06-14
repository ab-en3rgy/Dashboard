-- Migration: assign domains_fp records to a user owner.
-- Existing rows are assigned to the first admin user.

ALTER TABLE public.domains_fp
    ADD COLUMN IF NOT EXISTS user_id int REFERENCES public.users(id) ON DELETE SET NULL;

UPDATE public.domains_fp
SET user_id = (
    SELECT id
    FROM public.users
    WHERE role = 'admin'
    ORDER BY id
    LIMIT 1
)
WHERE user_id IS NULL
  AND EXISTS (SELECT 1 FROM public.users WHERE role = 'admin');

CREATE INDEX IF NOT EXISTS idx_dfp_user ON public.domains_fp (user_id);
