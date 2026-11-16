-- =========================================
-- PRIVATE SCHEMA
-- =========================================

CREATE SCHEMA IF NOT EXISTS private;
-- Prevent normal users from accessing this schema.
REVOKE ALL ON SCHEMA private FROM PUBLIC, anon, authenticated;
GRANT USAGE ON SCHEMA private TO postgres;
-- =========================================
-- AUTOMATIC MONTHLY TIMESHEET SUBMISSION
-- =========================================

CREATE OR REPLACE FUNCTION private.auto_submit_monthly_timesheets(
    p_month DATE DEFAULT (
        (
            date_trunc(
                'month',
                CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Manila'
            ) - INTERVAL '1 month'
        )::DATE
    ),
    p_employment_id UUID DEFAULT NULL
)
RETURNS JSONB
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = pg_catalog, public, pg_temp
AS $$
DECLARE
    v_month DATE;
    v_next_month DATE;
    v_session RECORD;
    v_timesheet_id UUID;

    v_created_count INTEGER := 0;
    v_skipped_count INTEGER := 0;
BEGIN

    -- 1. Validate the month.
    IF p_month IS NULL THEN
        RETURN jsonb_build_object(
            'success', false,
            'message', 'A month is required.'
        );
    END IF;

    -- Always use the first day of the selected month.
    v_month := date_trunc('month', p_month)::DATE;

    v_next_month :=
        (v_month + INTERVAL '1 month')::DATE;

    -- Do not process the current or a future month.
    IF v_month >= date_trunc(
        'month',
        CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Manila'
    )::DATE THEN
        RETURN jsonb_build_object(
            'success', false,
            'message', 'The selected month is not completed.'
        );
    END IF;


    -- 2. Find each employment with recorded working hours.
    FOR v_session IN
        SELECT
            s.employment_id,
            s.worker_id,

            COALESCE(
                SUM(s.total_seconds), 0
            )::INTEGER AS working_seconds,

            COALESCE(
                SUM(s.idle_seconds), 0
            )::INTEGER AS idle_seconds

        FROM public.time_tracking_sessions s

        WHERE s.started_at >=
            (v_month::TIMESTAMP AT TIME ZONE 'Asia/Manila')

        AND s.started_at <
            (v_next_month::TIMESTAMP AT TIME ZONE 'Asia/Manila')

        AND (
            p_employment_id IS NULL
            OR s.employment_id = p_employment_id
        )

        GROUP BY
            s.employment_id,
            s.worker_id

        HAVING COALESCE(SUM(s.total_seconds), 0) > 0

    LOOP

        v_timesheet_id := NULL;


        -- 3. Create the timesheet.
        -- Existing timesheets are never overwritten.

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
            v_session.employment_id,
            v_session.worker_id,
            v_month,
            v_session.working_seconds,
            v_session.idle_seconds,
            'submitted',
            NOW()
        )

        ON CONFLICT (employment_id, month)
        DO NOTHING

        RETURNING id INTO v_timesheet_id;


        -- 4. Skip if a timesheet already exists.
        IF v_timesheet_id IS NULL THEN

            v_skipped_count := v_skipped_count + 1;

            CONTINUE;

        END IF;


        -- 5. Generate the daily work breakdown.
        INSERT INTO public.timesheet_entries (
            timesheet_id,
            work_date,
            working_seconds,
            idle_seconds
        )

        SELECT
            v_timesheet_id,

            (s.started_at AT TIME ZONE 'Asia/Manila')::DATE,

            SUM(s.total_seconds)::INTEGER,

            SUM(s.idle_seconds)::INTEGER

        FROM public.time_tracking_sessions s

        WHERE s.employment_id = v_session.employment_id
        AND s.worker_id = v_session.worker_id

        AND s.started_at >=
            (v_month::TIMESTAMP AT TIME ZONE 'Asia/Manila')

        AND s.started_at <
            (v_next_month::TIMESTAMP AT TIME ZONE 'Asia/Manila')

        GROUP BY
            (s.started_at AT TIME ZONE 'Asia/Manila')::DATE;


        v_created_count := v_created_count + 1;

    END LOOP;


    -- 6. Return the processing summary.
    RETURN jsonb_build_object(
        'success', true,
        'month', v_month,
        'created_count', v_created_count,
        'skipped_existing_count', v_skipped_count
    );

END;
$$;
-- =========================================
-- FUNCTION PERMISSIONS
-- =========================================

REVOKE ALL ON FUNCTION
private.auto_submit_monthly_timesheets(DATE, UUID)
FROM PUBLIC, anon, authenticated;
GRANT EXECUTE ON FUNCTION
private.auto_submit_monthly_timesheets(DATE, UUID)
TO postgres;
-- =========================================
-- ENABLE SUPABASE CRON
-- =========================================

CREATE EXTENSION IF NOT EXISTS pg_cron
WITH SCHEMA pg_catalog;
GRANT USAGE ON SCHEMA cron TO postgres;
GRANT ALL PRIVILEGES
ON ALL TABLES IN SCHEMA cron
TO postgres;
-- =========================================
-- SCHEDULE AUTOMATIC SUBMISSION
-- =========================================

SELECT cron.schedule(
    'workpulse-auto-submit-monthly-timesheets',
    '0 0 1 * *',
    'SELECT private.auto_submit_monthly_timesheets();'
);
