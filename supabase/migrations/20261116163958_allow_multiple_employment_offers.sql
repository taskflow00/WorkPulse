-- Allow multiple historical offers for one application.
ALTER TABLE public.employment_offers
DROP CONSTRAINT IF EXISTS employment_offers_application_id_key;

-- Prevent multiple active offers for the same application.
CREATE UNIQUE INDEX employment_offers_one_active_per_application
ON public.employment_offers (application_id)
WHERE application_id IS NOT NULL
  AND status IN ('pending', 'revision_requested', 'accepted');

-- Replace the old insert policy.
DROP POLICY IF EXISTS "Employers can send offers"
ON public.employment_offers;

-- An employer can offer employment to an accepted application,
-- whether the worker applied or accepted a direct invitation.
CREATE POLICY "Employers can send offers"
ON public.employment_offers
FOR INSERT
TO authenticated
WITH CHECK (
    employer_id = (SELECT auth.uid())
    AND (SELECT current_user_role()) = 'employer'
    AND status = 'pending'
    AND application_id IS NOT NULL
    AND invitation_id IS NULL
    AND EXISTS (
        SELECT 1
        FROM public.applications a
        JOIN public.jobs j ON j.id = a.job_id
        WHERE a.id = employment_offers.application_id
          AND a.job_id = employment_offers.job_id
          AND a.worker_id = employment_offers.worker_id
          AND a.status = 'accepted'
          AND j.employer_id = (SELECT auth.uid())
    )
);