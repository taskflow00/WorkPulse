-- =========================================
-- SUBMIT MONTHLY TIMESHEET
-- =========================================

CREATE OR REPLACE FUNCTION public.submit_monthly_timesheet(
    p_employment_id UUID,
    p_month DATE
)
RETURNS JSONB
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public, pg_temp
AS $$
DECLARE
    v_worker_id UUID;
    v_month DATE;
    v_next_month DATE;
    v_timesheet_id UUID;
    v_working_seconds INTEGER;
    v_idle_seconds INTEGER;
BEGIN

    -- 1. Verify the logged-in user
    IF auth.uid() IS NULL THEN
        RETURN jsonb_build_object(
            'success', false,
            'message', 'You must be logged in.'
        );
    END IF;

    -- 2. Only workers can submit timesheets
    IF NOT EXISTS (
        SELECT 1
        FROM public.profiles
        WHERE id = auth.uid()
        AND role = 'worker'
    ) THEN
        RETURN jsonb_build_object(
            'success', false,
            'message', 'Only workers can submit timesheets.'
        );
    END IF;

    -- 3. Normalize the selected month
    v_month := date_trunc('month', p_month)::DATE;

    v_next_month :=
        (v_month + INTERVAL '1 month')::DATE;

    -- 4. Only allow completed months
    IF v_month >=
        date_trunc(
            'month',
            CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Manila'
        )::DATE
    THEN
        RETURN jsonb_build_object(
            'success', false,
            'message', 'You can only submit completed months.'
        );
    END IF;

    -- 5. Verify employment ownership
    SELECT worker_id
    INTO v_worker_id
    FROM public.employments
    WHERE id = p_employment_id;

    IF v_worker_id IS NULL
       OR v_worker_id <> auth.uid() THEN
        RETURN jsonb_build_object(
            'success', false,
            'message', 'Employment not found or unauthorized.'
        );
    END IF;

    -- 6. Check for existing timesheet
    IF EXISTS (
        SELECT 1
        FROM public.timesheets
        WHERE employment_id = p_employment_id
        AND month = v_month
    ) THEN
        RETURN jsonb_build_object(
            'success', false,
            'message', 'Timesheet already exists for this month.'
        );
    END IF;

    -- 7. Calculate monthly totals
    SELECT
        COALESCE(SUM(total_seconds), 0)::INTEGER,
        COALESCE(SUM(idle_seconds), 0)::INTEGER
    INTO
        v_working_seconds,
        v_idle_seconds
    FROM public.time_tracking_sessions
    WHERE employment_id = p_employment_id
    AND worker_id = auth.uid()
    AND started_at >=
        (v_month::TIMESTAMP AT TIME ZONE 'Asia/Manila')
    AND started_at <
        (v_next_month::TIMESTAMP AT TIME ZONE 'Asia/Manila');

    -- 8. Require recorded work
    IF v_working_seconds = 0 THEN
        RETURN jsonb_build_object(
            'success', false,
            'message', 'No recorded working hours for this month.'
        );
    END IF;

    -- 9. Create monthly timesheet
    INSERT INTO public.timesheets (
        employment_id,
        worker_id,
        month,
        total_working_seconds,
        total_idle_seconds,
        status,
        submitted_at
    )
    VALUES (
        p_employment_id,
        auth.uid(),
        v_month,
        v_working_seconds,
        v_idle_seconds,
        'submitted',
        NOW()
    )
    RETURNING id INTO v_timesheet_id;

    -- 10. Generate daily entries
    INSERT INTO public.timesheet_entries (
        timesheet_id,
        work_date,
        working_seconds,
        idle_seconds
    )
    SELECT
        v_timesheet_id,
        (started_at AT TIME ZONE 'Asia/Manila')::DATE,
        SUM(total_seconds)::INTEGER,
        SUM(idle_seconds)::INTEGER
    FROM public.time_tracking_sessions
    WHERE employment_id = p_employment_id
    AND worker_id = auth.uid()
    AND started_at >=
        (v_month::TIMESTAMP AT TIME ZONE 'Asia/Manila')
    AND started_at <
        (v_next_month::TIMESTAMP AT TIME ZONE 'Asia/Manila')
    GROUP BY
        (started_at AT TIME ZONE 'Asia/Manila')::DATE;

    -- 11. Return success
    RETURN jsonb_build_object(
        'success', true,
        'message', 'Timesheet submitted successfully.',
        'timesheet_id', v_timesheet_id,
        'total_working_seconds', v_working_seconds,
        'total_idle_seconds', v_idle_seconds
    );

END;
$$;
-- =========================================
-- RPC PERMISSIONS
-- =========================================

REVOKE ALL ON FUNCTION
public.submit_monthly_timesheet(UUID, DATE)
FROM PUBLIC;
GRANT EXECUTE ON FUNCTION
public.submit_monthly_timesheet(UUID, DATE)
TO authenticated;
