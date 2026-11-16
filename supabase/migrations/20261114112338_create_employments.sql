
-- Stores the actual employment relationship after an offer is accepted.

CREATE TABLE public.employments (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),

    offer_id UUID NOT NULL UNIQUE
        REFERENCES public.employment_offers(id),

    application_id UUID NOT NULL UNIQUE
        REFERENCES public.applications(id),

    job_id UUID NOT NULL
        REFERENCES public.jobs(id),

    employer_id UUID NOT NULL
        REFERENCES public.profiles(id),

    worker_id UUID NOT NULL
        REFERENCES public.profiles(id),

    hourly_rate NUMERIC(10, 2) NOT NULL
        CHECK (hourly_rate > 0),

    employment_type TEXT NOT NULL
        CHECK (employment_type IN ('full_time', 'part_time')),

    work_schedule TEXT NOT NULL,

    status TEXT NOT NULL DEFAULT 'active'
        CHECK (status IN ('active', 'ended')),

    started_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    ended_at TIMESTAMPTZ,

    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CHECK (
        (status = 'active' AND ended_at IS NULL)
        OR
        (status = 'ended' AND ended_at IS NOT NULL)
    )
);

-- Enable Row Level Security.
ALTER TABLE public.employments ENABLE ROW LEVEL SECURITY;

-- Employers and workers can view only their own employment records.
CREATE POLICY "Users can view their employments"
ON public.employments
FOR SELECT
TO authenticated
USING (
    employer_id = (SELECT auth.uid())
    OR worker_id = (SELECT auth.uid())
    OR (SELECT public.current_user_role()) = 'admin'
);

-- Users can only read employment records directly.
GRANT SELECT ON public.employments TO authenticated;

REVOKE INSERT, UPDATE, DELETE
ON public.employments
FROM authenticated;

-- Lookup indexes.
CREATE INDEX employments_worker_idx
ON public.employments(worker_id);

CREATE INDEX employments_employer_idx
ON public.employments(employer_id);

CREATE INDEX employments_status_idx
ON public.employments(status);

-- Create records for employment offers already accepted before this table existed.
INSERT INTO public.employments (
    offer_id,
    application_id,
    job_id,
    employer_id,
    worker_id,
    hourly_rate,
    employment_type,
    work_schedule,
    status,
    started_at
)
SELECT
    id,
    application_id,
    job_id,
    employer_id,
    worker_id,
    hourly_rate,
    employment_type,
    work_schedule,
    'active',
    COALESCE(responded_at, created_at)
FROM public.employment_offers
WHERE status = 'accepted'
ON CONFLICT (application_id) DO NOTHING;
