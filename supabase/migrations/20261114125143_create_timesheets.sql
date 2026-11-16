
-- =========================================
-- TIMESHEETS
-- =========================================

CREATE TABLE public.timesheets (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),

    employment_id UUID NOT NULL
        REFERENCES public.employments(id) ON DELETE CASCADE,

    worker_id UUID NOT NULL
        REFERENCES auth.users(id) ON DELETE CASCADE,

    month DATE NOT NULL,

    total_working_seconds INTEGER NOT NULL DEFAULT 0,
    total_idle_seconds INTEGER NOT NULL DEFAULT 0,

    status TEXT NOT NULL DEFAULT 'draft'
        CHECK (
            status IN (
                'draft',
                'submitted',
                'approved',
                'rejected'
            )
        ),

    submitted_at TIMESTAMPTZ,
    reviewed_at TIMESTAMPTZ,
    reviewed_by UUID REFERENCES auth.users(id),

    employer_comment TEXT,

    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    UNIQUE (employment_id, month)
);

-- =========================================
-- TIMESHEET DAILY ENTRIES
-- =========================================

CREATE TABLE public.timesheet_entries (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),

    timesheet_id UUID NOT NULL
        REFERENCES public.timesheets(id) ON DELETE CASCADE,

    work_date DATE NOT NULL,

    working_seconds INTEGER NOT NULL DEFAULT 0,
    idle_seconds INTEGER NOT NULL DEFAULT 0,

    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    UNIQUE (timesheet_id, work_date)
);

-- =========================================
-- INDEXES
-- =========================================

CREATE INDEX idx_timesheets_worker
ON public.timesheets(worker_id, month DESC);

CREATE INDEX idx_timesheets_employment
ON public.timesheets(employment_id, month DESC);

CREATE INDEX idx_timesheet_entries_date
ON public.timesheet_entries(timesheet_id, work_date);

-- =========================================
-- ROW LEVEL SECURITY
-- =========================================

ALTER TABLE public.timesheets ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.timesheet_entries ENABLE ROW LEVEL SECURITY;

-- Workers can view their own timesheets.
CREATE POLICY "Workers view own timesheets"
ON public.timesheets
FOR SELECT
TO authenticated
USING (worker_id = auth.uid());

-- Employers can view timesheets for their employments.
CREATE POLICY "Employers view employment timesheets"
ON public.timesheets
FOR SELECT
TO authenticated
USING (
    EXISTS (
        SELECT 1
        FROM public.employments e
        WHERE e.id = timesheets.employment_id
        AND e.employer_id = auth.uid()
    )
);

-- Workers can view their own daily entries.
CREATE POLICY "Workers view own timesheet entries"
ON public.timesheet_entries
FOR SELECT
TO authenticated
USING (
    EXISTS (
        SELECT 1
        FROM public.timesheets t
        WHERE t.id = timesheet_entries.timesheet_id
        AND t.worker_id = auth.uid()
    )
);

-- Employers can view daily entries for their employments.
CREATE POLICY "Employers view timesheet entries"
ON public.timesheet_entries
FOR SELECT
TO authenticated
USING (
    EXISTS (
        SELECT 1
        FROM public.timesheets t
        JOIN public.employments e
            ON e.id = t.employment_id
        WHERE t.id = timesheet_entries.timesheet_id
        AND e.employer_id = auth.uid()
    )
);
