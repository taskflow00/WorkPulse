-- Allow employers to send interview invitations
-- for applications that are still in the recruitment process.

DROP POLICY IF EXISTS "Employers send invitations"
ON public.invitations;
CREATE POLICY "Employers send invitations"
ON public.invitations
FOR INSERT
TO authenticated
WITH CHECK (
    employer_id = (SELECT auth.uid())

    AND (SELECT public.current_user_role()) = 'employer'

    AND status = 'pending'

    AND EXISTS (
        SELECT 1
        FROM public.jobs j
        WHERE j.id = invitations.job_id
          AND j.employer_id = (SELECT auth.uid())
          AND j.status = 'open'
    )

    AND EXISTS (
        SELECT 1
        FROM public.profiles p
        WHERE p.id = invitations.worker_id
          AND p.role = 'worker'
          AND (
              p.account_status = 'active'
              OR (
                  p.account_status = 'suspended'
                  AND p.restricted_until IS NOT NULL
                  AND p.restricted_until <= NOW()
              )
          )
    )

    AND (
        application_id IS NULL
        OR EXISTS (
            SELECT 1
            FROM public.applications a
            WHERE a.id = invitations.application_id
              AND a.job_id = invitations.job_id
              AND a.worker_id = invitations.worker_id
              AND a.status IN (
                  'pending',
                  'shortlisted',
                  'interview',
                  'accepted'
              )
        )
    )
);
