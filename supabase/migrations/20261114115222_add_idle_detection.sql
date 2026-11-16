-- =========================================
-- 1. ADD IDLE TRACKING COLUMNS
-- =========================================

ALTER TABLE public.time_tracking_sessions
ADD COLUMN IF NOT EXISTS idle_started_at TIMESTAMPTZ,
ADD COLUMN IF NOT EXISTS last_activity_at TIMESTAMPTZ;
-- Give existing running sessions a fresh activity baseline.
UPDATE public.time_tracking_sessions
SET last_activity_at = clock_timestamp()
WHERE status = 'running'
AND last_activity_at IS NULL;
-- =========================================
-- 2. UPDATE SESSION STATUS CONSTRAINTS
-- =========================================

ALTER TABLE public.time_tracking_sessions
DROP CONSTRAINT IF EXISTS time_tracking_sessions_status_check;
ALTER TABLE public.time_tracking_sessions
DROP CONSTRAINT IF EXISTS time_tracking_sessions_check;
ALTER TABLE public.time_tracking_sessions
ADD CONSTRAINT time_tracking_sessions_status_check
CHECK (status IN ('running', 'paused', 'idle', 'stopped'));
ALTER TABLE public.time_tracking_sessions
ADD CONSTRAINT time_tracking_sessions_state_check
CHECK (
    (
        status = 'running'
        AND ended_at IS NULL
        AND last_resumed_at IS NOT NULL
        AND idle_started_at IS NULL
    )
    OR
    (
        status = 'paused'
        AND ended_at IS NULL
        AND last_resumed_at IS NULL
        AND idle_started_at IS NULL
    )
    OR
    (
        status = 'idle'
        AND ended_at IS NULL
        AND last_resumed_at IS NULL
        AND idle_started_at IS NOT NULL
    )
    OR
    (
        status = 'stopped'
        AND ended_at IS NOT NULL
        AND last_resumed_at IS NULL
        AND idle_started_at IS NULL
    )
);
-- Include idle sessions in the one-active-session rule.
DROP INDEX IF EXISTS public.one_active_tracking_session_per_worker;
CREATE UNIQUE INDEX one_active_tracking_session_per_worker
ON public.time_tracking_sessions(worker_id)
WHERE status IN ('running', 'paused', 'idle');
-- =========================================
-- 3. UPDATE START TRACKING FUNCTION
-- =========================================

CREATE OR REPLACE FUNCTION public.start_time_tracking(
    p_employment_id UUID,
    p_task_id UUID DEFAULT NULL
)
RETURNS JSONB
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public, pg_temp
AS $$
DECLARE
    v_worker_id UUID := auth.uid();
    v_session_id UUID;
    v_now TIMESTAMPTZ := clock_timestamp();
BEGIN
    IF v_worker_id IS NULL
       OR public.current_user_role() IS DISTINCT FROM 'worker' THEN
        RETURN jsonb_build_object(
            'success', false,
            'message', 'Only workers can start tracking.'
        );
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM public.employments
        WHERE id = p_employment_id
          AND worker_id = v_worker_id
          AND status = 'active'
    ) THEN
        RETURN jsonb_build_object(
            'success', false,
            'message', 'Active employment not found.'
        );
    END IF;

    IF p_task_id IS NOT NULL AND NOT EXISTS (
        SELECT 1
        FROM public.tasks
        WHERE id = p_task_id
          AND worker_id = v_worker_id
          AND employment_id = p_employment_id
          AND status IN ('pending', 'in_progress')
    ) THEN
        RETURN jsonb_build_object(
            'success', false,
            'message', 'Invalid task.'
        );
    END IF;

    INSERT INTO public.time_tracking_sessions (
        employment_id,
        worker_id,
        task_id,
        started_at,
        last_resumed_at,
        last_activity_at,
        status
    )
    VALUES (
        p_employment_id,
        v_worker_id,
        p_task_id,
        v_now,
        v_now,
        v_now,
        'running'
    )
    RETURNING id INTO v_session_id;

    RETURN jsonb_build_object(
        'success', true,
        'session_id', v_session_id,
        'status', 'running'
    );

EXCEPTION
    WHEN unique_violation THEN
        RETURN jsonb_build_object(
            'success', false,
            'message', 'You already have an active tracking session.'
        );
END;
$$;
-- =========================================
-- 4. UPDATE PAUSE TRACKING FUNCTION
-- =========================================

CREATE OR REPLACE FUNCTION public.pause_time_tracking()
RETURNS JSONB
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public, pg_temp
AS $$
DECLARE
    v_worker_id UUID := auth.uid();
    v_session public.time_tracking_sessions%ROWTYPE;
    v_now TIMESTAMPTZ := clock_timestamp();
    v_elapsed INTEGER;
BEGIN
    IF v_worker_id IS NULL
       OR public.current_user_role() IS DISTINCT FROM 'worker' THEN
        RETURN jsonb_build_object(
            'success', false,
            'message', 'Only workers can pause tracking.'
        );
    END IF;

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

    v_elapsed := GREATEST(
        0,
        FLOOR(EXTRACT(EPOCH FROM (v_now - v_session.last_resumed_at)))::INTEGER
    );

    UPDATE public.time_tracking_sessions
    SET
        total_seconds = total_seconds + v_elapsed,
        status = 'paused',
        last_resumed_at = NULL,
        last_activity_at = NULL
    WHERE id = v_session.id;

    RETURN jsonb_build_object(
        'success', true,
        'status', 'paused',
        'total_seconds', v_session.total_seconds + v_elapsed
    );
END;
$$;
-- =========================================
-- 5. UPDATE RESUME TRACKING FUNCTION
-- =========================================

CREATE OR REPLACE FUNCTION public.resume_time_tracking()
RETURNS JSONB
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public, pg_temp
AS $$
DECLARE
    v_worker_id UUID := auth.uid();
    v_session public.time_tracking_sessions%ROWTYPE;
    v_now TIMESTAMPTZ := clock_timestamp();
    v_idle_elapsed INTEGER := 0;
BEGIN
    IF v_worker_id IS NULL
       OR public.current_user_role() IS DISTINCT FROM 'worker' THEN
        RETURN jsonb_build_object(
            'success', false,
            'message', 'Only workers can resume tracking.'
        );
    END IF;

    SELECT *
    INTO v_session
    FROM public.time_tracking_sessions
    WHERE worker_id = v_worker_id
      AND status IN ('paused', 'idle')
    FOR UPDATE;

    IF NOT FOUND THEN
        RETURN jsonb_build_object(
            'success', false,
            'message', 'No paused or idle session found.'
        );
    END IF;

    IF v_session.status = 'idle' THEN
        v_idle_elapsed := GREATEST(
            0,
            FLOOR(EXTRACT(EPOCH FROM (v_now - v_session.idle_started_at)))::INTEGER
        );
    END IF;

    UPDATE public.time_tracking_sessions
    SET
        idle_seconds = idle_seconds + v_idle_elapsed,
        status = 'running',
        last_resumed_at = v_now,
        last_activity_at = v_now,
        idle_started_at = NULL
    WHERE id = v_session.id;

    RETURN jsonb_build_object(
        'success', true,
        'status', 'running',
        'idle_seconds', v_session.idle_seconds + v_idle_elapsed
    );
END;
$$;
-- =========================================
-- 6. RECORD WORKER ACTIVITY
-- =========================================

CREATE OR REPLACE FUNCTION public.record_time_tracking_activity()
RETURNS JSONB
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public, pg_temp
AS $$
DECLARE
    v_worker_id UUID := auth.uid();
BEGIN
    IF v_worker_id IS NULL
       OR public.current_user_role() IS DISTINCT FROM 'worker' THEN
        RETURN jsonb_build_object(
            'success', false,
            'message', 'Only workers can record activity.'
        );
    END IF;

    UPDATE public.time_tracking_sessions
    SET last_activity_at = clock_timestamp()
    WHERE worker_id = v_worker_id
      AND status = 'running';

    IF NOT FOUND THEN
        RETURN jsonb_build_object(
            'success', false,
            'message', 'No running session found.'
        );
    END IF;

    RETURN jsonb_build_object('success', true);
END;
$$;
-- =========================================
-- 7. MARK SESSION AS IDLE
-- =========================================

CREATE OR REPLACE FUNCTION public.mark_time_tracking_idle()
RETURNS JSONB
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public, pg_temp
AS $$
DECLARE
    v_worker_id UUID := auth.uid();
    v_session public.time_tracking_sessions%ROWTYPE;
    v_now TIMESTAMPTZ := clock_timestamp();
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

    -- Check whether five minutes have passed since the last activity.
    IF v_session.last_activity_at IS NULL
       OR v_session.last_activity_at > v_now - INTERVAL '5 minutes' THEN
        RETURN jsonb_build_object(
            'success', false,
            'message', 'The idle threshold has not been reached.'
        );
    END IF;

    -- Idle time begins five minutes after the last recorded activity.
    v_idle_started_at :=
        v_session.last_activity_at + INTERVAL '5 minutes';

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
$$;
-- =========================================
-- 8. UPDATE STOP TRACKING FUNCTION
-- =========================================

CREATE OR REPLACE FUNCTION public.stop_time_tracking()
RETURNS JSONB
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public, pg_temp
AS $$
DECLARE
    v_worker_id UUID := auth.uid();
    v_session public.time_tracking_sessions%ROWTYPE;
    v_now TIMESTAMPTZ := clock_timestamp();
    v_work_elapsed INTEGER := 0;
    v_idle_elapsed INTEGER := 0;
BEGIN
    IF v_worker_id IS NULL
       OR public.current_user_role() IS DISTINCT FROM 'worker' THEN
        RETURN jsonb_build_object(
            'success', false,
            'message', 'Only workers can stop tracking.'
        );
    END IF;

    SELECT *
    INTO v_session
    FROM public.time_tracking_sessions
    WHERE worker_id = v_worker_id
      AND status IN ('running', 'paused', 'idle')
    FOR UPDATE;

    IF NOT FOUND THEN
        RETURN jsonb_build_object(
            'success', false,
            'message', 'No active session found.'
        );
    END IF;

    IF v_session.status = 'running' THEN

        v_work_elapsed := GREATEST(
            0,
            FLOOR(EXTRACT(EPOCH FROM (
                v_now - v_session.last_resumed_at
            )))::INTEGER
        );

    ELSIF v_session.status = 'idle' THEN

        v_idle_elapsed := GREATEST(
            0,
            FLOOR(EXTRACT(EPOCH FROM (
                v_now - v_session.idle_started_at
            )))::INTEGER
        );

    END IF;

    UPDATE public.time_tracking_sessions
    SET
        total_seconds = total_seconds + v_work_elapsed,
        idle_seconds = idle_seconds + v_idle_elapsed,
        status = 'stopped',
        ended_at = v_now,
        last_resumed_at = NULL,
        last_activity_at = NULL,
        idle_started_at = NULL
    WHERE id = v_session.id;

    RETURN jsonb_build_object(
        'success', true,
        'status', 'stopped',
        'total_seconds', v_session.total_seconds + v_work_elapsed,
        'idle_seconds', v_session.idle_seconds + v_idle_elapsed,
        'ended_at', v_now
    );
END;
$$;
-- =========================================
-- 9. FUNCTION PERMISSIONS
-- =========================================

REVOKE ALL ON FUNCTION public.start_time_tracking(UUID, UUID) FROM PUBLIC;
REVOKE ALL ON FUNCTION public.pause_time_tracking() FROM PUBLIC;
REVOKE ALL ON FUNCTION public.resume_time_tracking() FROM PUBLIC;
REVOKE ALL ON FUNCTION public.record_time_tracking_activity() FROM PUBLIC;
REVOKE ALL ON FUNCTION public.mark_time_tracking_idle() FROM PUBLIC;
REVOKE ALL ON FUNCTION public.stop_time_tracking() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.start_time_tracking(UUID, UUID) TO authenticated;
GRANT EXECUTE ON FUNCTION public.pause_time_tracking() TO authenticated;
GRANT EXECUTE ON FUNCTION public.resume_time_tracking() TO authenticated;
GRANT EXECUTE ON FUNCTION public.record_time_tracking_activity() TO authenticated;
GRANT EXECUTE ON FUNCTION public.mark_time_tracking_idle() TO authenticated;
GRANT EXECUTE ON FUNCTION public.stop_time_tracking() TO authenticated;
NOTIFY pgrst, 'reload schema';
