
-- ============================================
-- WORKPULSE: SECURE RECRUITMENT WORKFLOWS
-- ============================================

-- 1. INVITATIONS
-- Allow another invitation after a previous one
-- was declined, but prevent duplicate pending
-- or accepted invitations for the same job/worker.

ALTER TABLE public.invitations
DROP CONSTRAINT IF EXISTS invitations_job_id_worker_id_key;

CREATE UNIQUE INDEX IF NOT EXISTS invitations_unique_active_per_job_worker
ON public.invitations (job_id, worker_id)
WHERE status IN ('pending', 'accepted');


-- Employers can invite workers only to their own
-- open jobs. New invitations must start as pending.

DROP POLICY IF EXISTS "Employers send invitations"
ON public.invitations;

CREATE POLICY "Employers send invitations"
ON public.invitations
FOR INSERT
TO authenticated
WITH CHECK (
    employer_id = auth.uid()
    AND current_user_role() = 'employer'
    AND status = 'pending'

    AND EXISTS (
        SELECT 1
        FROM public.jobs j
        WHERE j.id = invitations.job_id
          AND j.employer_id = auth.uid()
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
                  AND p.restricted_until <= now()
              )
          )
    )
);


-- Workers can respond only to their own pending
-- invitations, and only with accepted or declined.

DROP POLICY IF EXISTS "Workers respond to invitations"
ON public.invitations;

CREATE POLICY "Workers respond to invitations"
ON public.invitations
FOR UPDATE
TO authenticated
USING (
    worker_id = auth.uid()
    AND current_user_role() = 'worker'
    AND status = 'pending'
)
WITH CHECK (
    worker_id = auth.uid()
    AND current_user_role() = 'worker'
    AND status IN ('accepted', 'declined')
);


-- Restrict invitation updates to the status column.
-- This prevents workers from changing the employer,
-- worker, job, or original invitation message.

REVOKE UPDATE ON TABLE public.invitations
FROM anon, authenticated;

GRANT UPDATE (status)
ON TABLE public.invitations
TO authenticated;


-- ============================================
-- 2. APPLICATIONS
-- ============================================

-- Workers can submit applications only to open jobs.
-- Every new application must begin as pending.

DROP POLICY IF EXISTS "Workers submit applications"
ON public.applications;

CREATE POLICY "Workers submit applications"
ON public.applications
FOR INSERT
TO authenticated
WITH CHECK (
    worker_id = auth.uid()
    AND current_user_role() = 'worker'
    AND status = 'pending'

    AND EXISTS (
        SELECT 1
        FROM public.jobs j
        WHERE j.id = applications.job_id
          AND j.status = 'open'
    )
);


-- Employers can update applications belonging to
-- their own jobs. Admins can manage application status.

DROP POLICY IF EXISTS "Employers manage applications"
ON public.applications;

CREATE POLICY "Employers manage applications"
ON public.applications
FOR UPDATE
TO authenticated
USING (
    current_user_role() = 'admin'

    OR (
        current_user_role() = 'employer'
        AND EXISTS (
            SELECT 1
            FROM public.jobs j
            WHERE j.id = applications.job_id
              AND j.employer_id = auth.uid()
        )
    )
)
WITH CHECK (
    current_user_role() = 'admin'

    OR (
        current_user_role() = 'employer'
        AND status IN (
            'shortlisted',
            'interview',
            'accepted',
            'rejected'
        )
        AND EXISTS (
            SELECT 1
            FROM public.jobs j
            WHERE j.id = applications.job_id
              AND j.employer_id = auth.uid()
        )
    )
);


-- Employers and admins can update only application
-- status through the authenticated API.

REVOKE UPDATE ON TABLE public.applications
FROM anon, authenticated;

GRANT UPDATE (status)
ON TABLE public.applications
TO authenticated;
