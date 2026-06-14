ALTER TABLE public.users DROP CONSTRAINT IF EXISTS users_role_check;
ALTER TABLE public.users ALTER COLUMN role SET DEFAULT 'user';
UPDATE public.users SET role = 'user' WHERE role = 'buyer';
ALTER TABLE public.users
    ADD CONSTRAINT users_role_check CHECK (role IN ('admin', 'user'));
