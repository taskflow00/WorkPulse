-- Stores tasks assigned by employers to their active employees.

CREATE TABLE public.tasks (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),

    employment_id UUID NOT NULL
        REFERENCES public.employments(id) ON DELETE CASCADE,

    employer_id UUID NOT NULL
        REFERENCES public.profiles(id),

    worker_id UUID NOT NULL
        REFERENCES public.profiles(id),

    title TEXT NOT NULL,

    description TEXT,

    priority TEXT NOT NULL DEFAULT 'medium'
        CHECK (priority IN ('low', 'medium', 'high')),

    due_date DATE,

    estimated_hours NUMERIC(8, 2)
        CHECK (estimated_hours IS NULL OR estimated_hours > 0),

    status TEXT NOT NULL DEFAULT 'pending'
        CHECK (status IN ('pending', 'in_progress', 'completed', 'cancelled')),

    completed_at TIMESTAMPTZ,

    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
ALTER TABLE public.tasks ENABLE ROW LEVEL SECURITY;
-- Employers and assigned workers can view their own tasks.
CREATE POLICY "Users can view their own tasks"
ON public.tasks
FOR SELECT
TO authenticated
USING (
    employer_id = (SELECT auth.uid())
    OR worker_id = (SELECT auth.uid())
    OR (SELECT public.current_user_role()) = 'admin'
);
-- Only employers can assign tasks to their active employees.
CREATE POLICY "Employers can assign tasks"
ON public.tasks
FOR INSERT
TO authenticated
WITH CHECK (
    employer_id = (SELECT auth.uid())
    AND (SELECT public.current_user_role()) = 'employer'
    AND EXISTS (
        SELECT 1
        FROM public.employments AS e
        WHERE e.id = tasks.employment_id
          AND e.employer_id = (SELECT auth.uid())
          AND e.worker_id = tasks.worker_id
          AND e.status = 'active'
    )
);
GRANT SELECT, INSERT ON public.tasks TO authenticated;
REVOKE UPDATE, DELETE ON public.tasks FROM authenticated;
CREATE INDEX tasks_employment_idx
ON public.tasks(employment_id);
CREATE INDEX tasks_worker_status_idx
ON public.tasks(worker_id, status);
CREATE INDEX tasks_employer_idx
ON public.tasks(employer_id);
