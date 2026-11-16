CREATE TABLE public.time_tracking_sessions (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),

    employment_id UUID NOT NULL
        REFERENCES public.employments(id),

    worker_id UUID NOT NULL
        REFERENCES public.profiles(id),

    task_id UUID
        REFERENCES public.tasks(id),

    started_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    last_resumed_at TIMESTAMPTZ,

    ended_at TIMESTAMPTZ,

    total_seconds INTEGER NOT NULL DEFAULT 0
        CHECK (total_seconds >= 0),

    idle_seconds INTEGER NOT NULL DEFAULT 0
        CHECK (idle_seconds >= 0),

    status TEXT NOT NULL DEFAULT 'running'
        CHECK (status IN ('running', 'paused', 'stopped')),

    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CHECK (
        (status = 'running'
            AND ended_at IS NULL
            AND last_resumed_at IS NOT NULL)
        OR
        (status = 'paused'
            AND ended_at IS NULL
            AND last_resumed_at IS NULL)
        OR
        (status = 'stopped'
            AND ended_at IS NOT NULL
            AND last_resumed_at IS NULL)
    )
);
-- Enable Row Level Security.
ALTER TABLE public.time_tracking_sessions
ENABLE ROW LEVEL SECURITY;
-- Workers can view their own tracking sessions.
CREATE POLICY "Workers can view own time tracking sessions"
ON public.time_tracking_sessions
FOR SELECT
TO authenticated
USING (
    worker_id = (SELECT auth.uid())
);
-- Employers can view tracking sessions of their employees.
CREATE POLICY "Employers can view employee tracking sessions"
ON public.time_tracking_sessions
FOR SELECT
TO authenticated
USING (
    EXISTS (
        SELECT 1
        FROM public.employments AS e
        WHERE e.id = time_tracking_sessions.employment_id
          AND e.employer_id = (SELECT auth.uid())
    )
);
-- Admins can view all tracking sessions.
CREATE POLICY "Admins can view all tracking sessions"
ON public.time_tracking_sessions
FOR SELECT
TO authenticated
USING (
    (SELECT public.current_user_role()) = 'admin'
);
-- Only allow reading directly.
-- All modifications will happen through secure database functions.
GRANT SELECT ON public.time_tracking_sessions TO authenticated;
REVOKE INSERT, UPDATE, DELETE
ON public.time_tracking_sessions
FROM authenticated;
-- Only one running or paused session per worker.
CREATE UNIQUE INDEX one_active_tracking_session_per_worker
ON public.time_tracking_sessions(worker_id)
WHERE status IN ('running', 'paused');
-- Indexes for common queries.
CREATE INDEX time_tracking_worker_idx
ON public.time_tracking_sessions(worker_id, started_at DESC);
CREATE INDEX time_tracking_employment_idx
ON public.time_tracking_sessions(employment_id);
CREATE INDEX time_tracking_task_idx
ON public.time_tracking_sessions(task_id);
