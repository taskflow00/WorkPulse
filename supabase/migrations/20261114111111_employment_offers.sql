-- Stores employment offers sent by employers to accepted applicants.

CREATE TABLE public.employment_offers (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),

    application_id UUID NOT NULL UNIQUE
        REFERENCES public.applications(id) ON DELETE CASCADE,

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

    offer_message TEXT,

    status TEXT NOT NULL DEFAULT 'pending'
        CHECK (status IN ('pending', 'accepted', 'declined', 'withdrawn')),

    expires_at TIMESTAMPTZ NOT NULL DEFAULT (NOW() + INTERVAL '7 days'),

    responded_at TIMESTAMPTZ,

    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
-- Enable Row Level Security.
ALTER TABLE public.employment_offers ENABLE ROW LEVEL SECURITY;
-- Employers and workers can only view offers involving their accounts.
CREATE POLICY "Users can view their employment offers"
ON public.employment_offers
FOR SELECT
TO authenticated
USING (
    employer_id = (SELECT auth.uid())
    OR worker_id = (SELECT auth.uid())
    OR (SELECT public.current_user_role()) = 'admin'
);
-- Employers can only send offers for accepted applications
-- belonging to their own job listings.
CREATE POLICY "Employers can send offers"
ON public.employment_offers
FOR INSERT
TO authenticated
WITH CHECK (
    employer_id = (SELECT auth.uid())
    AND (SELECT public.current_user_role()) = 'employer'
    AND EXISTS (
        SELECT 1
        FROM public.applications AS a
        JOIN public.jobs AS j ON j.id = a.job_id
        WHERE a.id = application_id
          AND a.job_id = job_id
          AND a.worker_id = worker_id
          AND a.status = 'accepted'
          AND j.employer_id = (SELECT auth.uid())
    )
);
-- Useful lookup indexes.
CREATE INDEX employment_offers_worker_idx
ON public.employment_offers(worker_id);
CREATE INDEX employment_offers_employer_idx
ON public.employment_offers(employer_id);
