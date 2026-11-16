-- ==========================================
-- 1. START TRACKING
-- ==========================================

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
    v_now TIMESTAMPTZ := NOW();
    v_session_id UUID;
BEGIN
    -- Only workers can use the timer.
    IF (SELECT public.current_user_role()) IS DISTINCT FROM 'worker' THEN
        RAISE EXCEPTION 'Only workers can start tracking time.';
    END IF;

    -- Check that this is the worker's active employment.
    IF NOT EXISTS (
        SELECT 1
        FROM public.employments
        WHERE id = p_employment_id
          AND worker_id = v_worker_id
          AND status = 'active'
    ) THEN
        RAISE EXCEPTION 'Active employment not found.';
    END IF;

    -- If a task was selected, verify that it belongs
    -- to this worker and employment.
    IF p_task_id IS NOT NULL AND NOT EXISTS (
        SELECT 1
        FROM public.tasks
        WHERE id = p_task_id
          AND employment_id = p_employment_id
          AND worker_id = v_worker_id
          AND status IN ('pending', 'in_progress')
    ) THEN
        RAISE EXCEPTION 'Invalid or unavailable task.';
    END IF;

    -- Create a new running session.
    INSERT INTO public.time_tracking_sessions (
        employment_id,
        worker_id,
        task_id,
        started_at,
        last_resumed_at,
        status
    )
    VALUES (
        p_employment_id,
        v_worker_id,
        p_task_id,
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
END;
$$;
-- ==========================================
-- 2. PAUSE TRACKING
-- ==========================================

CREATE OR REPLACE FUNCTION public.pause_time_tracking()
RETURNS JSONB
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public, pg_temp
AS $$
DECLARE
    v_session public.time_tracking_sessions%ROWTYPE;
    v_now TIMESTAMPTZ := NOW();
    v_elapsed INTEGER;
    v_total INTEGER;
BEGIN
    IF (SELECT public.current_user_role()) IS DISTINCT FROM 'worker' THEN
        RAISE EXCEPTION 'Only workers can pause tracking.';
    END IF;

    -- Find and lock the worker's running session.
    SELECT *
    INTO v_session
    FROM public.time_tracking_sessions
    WHERE worker_id = auth.uid()
      AND status = 'running'
    FOR UPDATE;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'No running session to pause.';
    END IF;

    -- Calculate time since the last start or resume.
    v_elapsed := GREATEST(
        0,
        FLOOR(EXTRACT(EPOCH FROM (v_now - v_session.last_resumed_at)))::INTEGER
    );

    v_total := v_session.total_seconds + v_elapsed;

    UPDATE public.time_tracking_sessions
    SET
        total_seconds = v_total,
        status = 'paused',
        last_resumed_at = NULL
    WHERE id = v_session.id;

    RETURN jsonb_build_object(
        'success', true,
        'session_id', v_session.id,
        'status', 'paused',
        'total_seconds', v_total
    );
END;
$$;
-- ==========================================
-- 3. RESUME TRACKING
-- ==========================================

CREATE OR REPLACE FUNCTION public.resume_time_tracking()
RETURNS JSONB
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public, pg_temp
AS $$
DECLARE
    v_session public.time_tracking_sessions%ROWTYPE;
BEGIN
    IF (SELECT public.current_user_role()) IS DISTINCT FROM 'worker' THEN
        RAISE EXCEPTION 'Only workers can resume tracking.';
    END IF;

    -- Find and lock the worker's paused session.
    SELECT *
    INTO v_session
    FROM public.time_tracking_sessions
    WHERE worker_id = auth.uid()
      AND status = 'paused'
    FOR UPDATE;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'No paused session to resume.';
    END IF;

    UPDATE public.time_tracking_sessions
    SET
        status = 'running',
        last_resumed_at = NOW()
    WHERE id = v_session.id;

    RETURN jsonb_build_object(
        'success', true,
        'session_id', v_session.id,
        'status', 'running'
    );
END;
$$;
-- ==========================================
-- 4. STOP TRACKING
-- ==========================================

CREATE OR REPLACE FUNCTION public.stop_time_tracking()
RETURNS JSONB
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public, pg_temp
AS $$
DECLARE
    v_session public.time_tracking_sessions%ROWTYPE;
    v_now TIMESTAMPTZ := NOW();
    v_elapsed INTEGER := 0;
    v_total INTEGER;
BEGIN
    IF (SELECT public.current_user_role()) IS DISTINCT FROM 'worker' THEN
        RAISE EXCEPTION 'Only workers can stop tracking.';
    END IF;

    -- Find and lock the worker's active session.
    SELECT *
    INTO v_session
    FROM public.time_tracking_sessions
    WHERE worker_id = auth.uid()
      AND status IN ('running', 'paused')
    FOR UPDATE;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'No active session to stop.';
    END IF;

    -- Add the remaining time if the timer is running.
    IF v_session.status = 'running' THEN
        v_elapsed := GREATEST(
            0,
            FLOOR(EXTRACT(EPOCH FROM (v_now - v_session.last_resumed_at)))::INTEGER
        );
    END IF;

    v_total := v_session.total_seconds + v_elapsed;

    UPDATE public.time_tracking_sessions
    SET
        total_seconds = v_total,
        status = 'stopped',
        ended_at = v_now,
        last_resumed_at = NULL
    WHERE id = v_session.id;

    RETURN jsonb_build_object(
        'success', true,
        'session_id', v_session.id,
        'status', 'stopped',
        'total_seconds', v_total,
        'ended_at', v_now
    );
END;
$$;
-- ==========================================
-- PERMISSIONS
-- ==========================================

REVOKE ALL ON FUNCTION public.start_time_tracking(UUID, UUID)
FROM PUBLIC;
REVOKE ALL ON FUNCTION public.pause_time_tracking()
FROM PUBLIC;
REVOKE ALL ON FUNCTION public.resume_time_tracking()
FROM PUBLIC;
REVOKE ALL ON FUNCTION public.stop_time_tracking()
FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.start_time_tracking(UUID, UUID)
TO authenticated;
GRANT EXECUTE ON FUNCTION public.pause_time_tracking()
TO authenticated;
GRANT EXECUTE ON FUNCTION public.resume_time_tracking()
TO authenticated;
GRANT EXECUTE ON FUNCTION public.stop_time_tracking()
TO authenticated;
-- Refresh the Supabase REST API schema.
NOTIFY pgrst, 'reload schema';
