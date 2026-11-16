-- ==========================================
-- DAILY TIME TRACKING ROLLOVER
-- Timezone: Asia/Manila
-- ==========================================

CREATE OR REPLACE FUNCTION public.rollover_time_tracking_session()
RETURNS JSONB
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public, pg_temp
AS $$
DECLARE
    v_worker_id UUID := auth.uid();
    v_now TIMESTAMPTZ;
    v_today DATE;

    v_session public.time_tracking_sessions%ROWTYPE;

    v_session_date DATE;
    v_boundary TIMESTAMPTZ;
    v_idle_threshold_at TIMESTAMPTZ;
    v_work_end TIMESTAMPTZ;

    v_work_elapsed INTEGER;
    v_idle_elapsed INTEGER;

    v_next_status TEXT;
    v_next_last_resumed_at TIMESTAMPTZ;
    v_next_last_activity_at TIMESTAMPTZ;
    v_next_idle_started_at TIMESTAMPTZ;

    v_rolled_over INTEGER := 0;
BEGIN
    -- Only authenticated workers can use this function.
    IF v_worker_id IS NULL
       OR public.current_user_role() IS DISTINCT FROM 'worker' THEN

        RETURN jsonb_build_object(
            'success', false,
            'message', 'Only workers can roll over tracking sessions.'
        );
    END IF;

    -- Find and lock the worker's active session.
    SELECT *
    INTO v_session
    FROM public.time_tracking_sessions
    WHERE worker_id = v_worker_id
      AND status IN ('running', 'paused', 'idle')
    FOR UPDATE;

    IF NOT FOUND THEN
        RETURN jsonb_build_object(
            'success', true,
            'rolled_over', 0,
            'status', NULL
        );
    END IF;

    v_now := clock_timestamp();

    -- Get today's date using Philippine time.
    v_today := (v_now AT TIME ZONE 'Asia/Manila')::DATE;

    -- Process every missed midnight.
    LOOP

        v_session_date :=
            (v_session.started_at AT TIME ZONE 'Asia/Manila')::DATE;

        EXIT WHEN v_session_date >= v_today;

        -- Exact midnight of the next Philippine day.
        v_boundary :=
            ((v_session_date + 1)::TIMESTAMP AT TIME ZONE 'Asia/Manila');

        v_work_elapsed := 0;
        v_idle_elapsed := 0;

        v_next_status := NULL;
        v_next_last_resumed_at := NULL;
        v_next_last_activity_at := NULL;
        v_next_idle_started_at := NULL;

        -- ======================================
        -- RUNNING SESSION
        -- ======================================

        IF v_session.status = 'running' THEN

            -- Idle begins five minutes after last activity.
            v_idle_threshold_at :=
                COALESCE(
                    v_session.last_activity_at,
                    v_session.last_resumed_at
                ) + INTERVAL '5 minutes';

            v_work_end := LEAST(
                v_boundary,
                v_idle_threshold_at
            );

            -- Count working time up to midnight
            -- or the idle threshold, whichever comes first.
            v_work_elapsed := GREATEST(
                0,
                FLOOR(
                    EXTRACT(EPOCH FROM (
                        v_work_end - v_session.last_resumed_at
                    ))
                )::INTEGER
            );

            IF v_idle_threshold_at <= v_boundary THEN

                -- The worker became idle before midnight.
                v_idle_elapsed := GREATEST(
                    0,
                    FLOOR(
                        EXTRACT(EPOCH FROM (
                            v_boundary - v_idle_threshold_at
                        ))
                    )::INTEGER
                );

                v_next_status := 'idle';
                v_next_idle_started_at := v_boundary;

            ELSE

                -- The worker was still active at midnight.
                v_next_status := 'running';
                v_next_last_resumed_at := v_boundary;
                v_next_last_activity_at := v_session.last_activity_at;

            END IF;

        -- ======================================
        -- IDLE SESSION
        -- ======================================

        ELSIF v_session.status = 'idle' THEN

            v_idle_elapsed := GREATEST(
                0,
                FLOOR(
                    EXTRACT(EPOCH FROM (
                        v_boundary - v_session.idle_started_at
                    ))
                )::INTEGER
            );

            v_next_status := 'idle';
            v_next_idle_started_at := v_boundary;

        -- ======================================
        -- PAUSED SESSION
        -- ======================================

        ELSIF v_session.status = 'paused' THEN

            v_next_status := 'paused';

        END IF;

        -- ======================================
        -- CLOSE THE PREVIOUS DAY
        -- ======================================

        UPDATE public.time_tracking_sessions
        SET
            total_seconds = total_seconds + v_work_elapsed,
            idle_seconds = idle_seconds + v_idle_elapsed,
            status = 'stopped',
            ended_at = v_boundary,
            last_resumed_at = NULL,
            last_activity_at = NULL,
            idle_started_at = NULL
        WHERE id = v_session.id;

        -- ======================================
        -- CREATE THE NEXT DAY'S SESSION
        -- ======================================

        INSERT INTO public.time_tracking_sessions (
            employment_id,
            worker_id,
            task_id,
            started_at,
            last_resumed_at,
            last_activity_at,
            idle_started_at,
            total_seconds,
            idle_seconds,
            status
        )
        VALUES (
            v_session.employment_id,
            v_session.worker_id,
            v_session.task_id,
            v_boundary,
            v_next_last_resumed_at,
            v_next_last_activity_at,
            v_next_idle_started_at,
            0,
            0,
            v_next_status
        )
        RETURNING * INTO v_session;

        v_rolled_over := v_rolled_over + 1;

    END LOOP;

    RETURN jsonb_build_object(
        'success', true,
        'rolled_over', v_rolled_over,
        'session_id', v_session.id,
        'status', v_session.status
    );

END;
$$;
-- ==========================================
-- PERMISSIONS
-- ==========================================

REVOKE ALL ON FUNCTION public.rollover_time_tracking_session()
FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.rollover_time_tracking_session()
TO authenticated;
NOTIFY pgrst, 'reload schema';
