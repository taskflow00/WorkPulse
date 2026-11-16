DROP POLICY "Employers can send offers"
ON public.employment_offers;
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
        JOIN public.jobs AS j
            ON j.id = a.job_id
        WHERE a.id = employment_offers.application_id
          AND a.job_id = employment_offers.job_id
          AND a.worker_id = employment_offers.worker_id
          AND a.status = 'accepted'
          AND j.employer_id = (SELECT auth.uid())
    )
);
