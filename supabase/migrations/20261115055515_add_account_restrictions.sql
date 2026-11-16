-- Add account restriction information to profiles.

ALTER TABLE public.profiles
ADD COLUMN IF NOT EXISTS account_status TEXT
NOT NULL DEFAULT 'active';

ALTER TABLE public.profiles
ADD COLUMN IF NOT EXISTS restriction_reason TEXT;

ALTER TABLE public.profiles
ADD COLUMN IF NOT EXISTS restricted_until TIMESTAMPTZ;

ALTER TABLE public.profiles
ADD COLUMN IF NOT EXISTS restricted_by UUID
REFERENCES public.profiles(id)
ON DELETE SET NULL;

-- Allow only these account statuses.

ALTER TABLE public.profiles
ADD CONSTRAINT profiles_account_status_check
CHECK (
    account_status IN ('active', 'suspended', 'banned')
);