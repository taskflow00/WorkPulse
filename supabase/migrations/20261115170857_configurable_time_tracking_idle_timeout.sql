
-- =============================================
-- 1. CREATE TIME TRACKING SETTINGS
-- =============================================

CREATE TABLE public.time_tracking_settings (
    id SMALLINT PRIMARY KEY CHECK (id = 1),
    idle_timeout_seconds INTEGER NOT NULL DEFAULT 300
        CHECK (idle_timeout_seconds BETWEEN 1 AND 3600)
);

-- Default timeout: 300 seconds (5 minutes).
INSERT INTO public.time_tracking_settings (
    id,
    idle_timeout_seconds
)
VALUES (1, 300)
ON CONFLICT (id) DO NOTHING;

-- Workers can read the setting.
ALTER TABLE public.time_tracking_settings ENABLE ROW LEVEL SECURITY;

GRANT SELECT ON public.time_tracking_settings TO authenticated;

CREATE POLICY "Workers can read time tracking settings"
ON public.time_tracking_settings
FOR SELECT
TO authenticated
USING (
    public.current_user_role() = 'worker'
    AND public.is_active_user()
);


-- =============================================
-- 2. UPDATE MARK IDLE FUNCTION
-- =============================================

CREATE OR REPLACE FUNCTION public.mark_time_tracking_idle()
RETURNS JSONB
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path TO 'public', 'pg_temp'
AS $function$
DECLARE
    v_worker_id UUID := auth.uid();
    v_session public.time_tracking_sessions%ROWTYPE;
    v_now TIMESTAMPTZ := clock_timestamp();

    v_idle_timeout_seconds INTEGER;
    v_idle_started_at TIMESTAMPTZ;
    v_work_elapsed INTEGER;
BEGIN
    IF v_worker_id IS NULL
       OR public.current_user_role() IS DISTINCT FROM 'worker' THEN

        RETURN jsonb_build_object(
            'success', false,
            'message', 'Only workers can update idle status.'
        );
    END IF;

    -- Get the configured idle timeout.
    SELECT idle_timeout_seconds
    INTO v_idle_timeout_seconds
    FROM public.time_tracking_settings
    WHERE id = 1;

    v_idle_timeout_seconds := COALESCE(v_idle_timeout_seconds, 300);

    -- Find and lock the running session.
    SELECT *
    INTO v_session
    FROM public.time_tracking_sessions
    WHERE worker_id = v_worker_id
      AND status = 'running'
    FOR UPDATE;

    IF NOT FOUND THEN
        RETURN jsonb_build_object(
            'success', false,
            'message', 'No running session found.'
        );
    END IF;

    -- Check whether the configured idle timeout has passed.
    IF v_session.last_activity_at IS NULL
       OR v_session.last_activity_at >
          v_now - make_interval(secs => v_idle_timeout_seconds) THEN

        RETURN jsonb_build_object(
            'success', false,
            'message', 'The idle threshold has not been reached.'
        );
    END IF;

    -- Idle time begins after the configured timeout.
    v_idle_started_at :=
        v_session.last_activity_at
        + make_interval(secs => v_idle_timeout_seconds);

    -- Count working time only up to the idle start.
    v_work_elapsed := GREATEST(
        0,
        FLOOR(
            EXTRACT(EPOCH FROM (
                v_idle_started_at - v_session.last_resumed_at
            ))
        )::INTEGER
    );

    UPDATE public.time_tracking_sessions
    SET
        total_seconds = total_seconds + v_work_elapsed,
        status = 'idle',
        last_resumed_at = NULL,
        last_activity_at = NULL,
        idle_started_at = v_idle_started_at
    WHERE id = v_session.id;

    RETURN jsonb_build_object(
        'success', true,
        'status', 'idle',
        'idle_started_at', v_idle_started_at
    );
END;
$function$;


-- =============================================
-- 3. UPDATE DAILY ROLLOVER FUNCTION
-- =============================================

CREATE OR REPLACE FUNCTION public.rollover_time_tracking_session()
RETURNS JSONB
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path TO 'public', 'pg_temp'
AS $function$
DECLARE
    v_worker_id UUID := auth.uid();
    v_now TIMESTAMPTZ;
    v_today DATE;

    v_session public.time_tracking_sessions%ROWTYPE;

    v_session_date DATE;
    v_boundary TIMESTAMPTZ;
    v_idle_threshold_at TIMESTAMPTZ;
    v_work_end TIMESTAMPTZ;

    v_idle_timeout_seconds INTEGER;
    v_work_elapsed INTEGER;
    v_idle_elapsed INTEGER;

    v_next_status TEXT;
    v_next_last_resumed_at TIMESTAMPTZ;
    v_next_last_activity_at TIMESTAMPTZ;
    v_next_idle_started_at TIMESTAMPTZ;

    v_rolled_over INTEGER := 0;
BEGIN
    IF v_worker_id IS NULL
       OR public.current_user_role() IS DISTINCT FROM 'worker' THEN

        RETURN jsonb_build_object(
            'success', false,
            'message', 'Only workers can roll over tracking sessions.'
        );
    END IF;

    -- Get the configured idle timeout.
    SELECT idle_timeout_seconds
    INTO v_idle_timeout_seconds
    FROM public.time_tracking_settings
    WHERE id = 1;

    v_idle_timeout_seconds := COALESCE(v_idle_timeout_seconds, 300);

    -- Find and lock the active session.
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

        -- RUNNING SESSION
        IF v_session.status = 'running' THEN

            -- Idle begins after the configured timeout.
            v_idle_threshold_at :=
                COALESCE(
                    v_session.last_activity_at,
                    v_session.last_resumed_at
                ) + make_interval(secs => v_idle_timeout_seconds);

            v_work_end := LEAST(
                v_boundary,
                v_idle_threshold_at
            );

            -- Count working time up to midnight or idle threshold.
            v_work_elapsed := GREATEST(
                0,
                FLOOR(
                    EXTRACT(EPOCH FROM (
                        v_work_end - v_session.last_resumed_at
                    ))
                )::INTEGER
            );

            IF v_idle_threshold_at <= v_boundary THEN

                -- Worker became idle before midnight.
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

                -- Worker was still active at midnight.
                v_next_status := 'running';
                v_next_last_resumed_at := v_boundary;
                v_next_last_activity_at := v_session.last_activity_at;

            END IF;

        -- IDLE SESSION
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

        -- PAUSED SESSION
        ELSIF v_session.status = 'paused' THEN

            v_next_status := 'paused';

        END IF;

        -- CLOSE THE PREVIOUS DAY
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

        -- CREATE THE NEXT DAY'S SESSION
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
$function$;
