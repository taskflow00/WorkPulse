
CREATE OR REPLACE FUNCTION public.update_task_status(
    p_task_id UUID,
    p_status TEXT
)
RETURNS JSONB
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public, pg_temp
AS $$
DECLARE
    selected_task public.tasks%ROWTYPE;
BEGIN
    IF (SELECT public.current_user_role()) <> 'worker' THEN
        RAISE EXCEPTION 'Only workers can update task progress.';
    END IF;

    IF p_status NOT IN ('in_progress', 'completed') THEN
        RAISE EXCEPTION 'Invalid task status.';
    END IF;

    SELECT *
    INTO selected_task
    FROM public.tasks
    WHERE id = p_task_id
      AND worker_id = (SELECT auth.uid())
    FOR UPDATE;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Task not found.';
    END IF;

    IF p_status = 'in_progress'
       AND selected_task.status <> 'pending' THEN
        RAISE EXCEPTION 'This task cannot be started.';
    END IF;

    IF p_status = 'completed'
       AND selected_task.status <> 'in_progress' THEN
        RAISE EXCEPTION 'Start the task before completing it.';
    END IF;

    UPDATE public.tasks
    SET
        status = p_status,
        completed_at = CASE
            WHEN p_status = 'completed' THEN NOW()
            ELSE NULL
        END,
        updated_at = NOW()
    WHERE id = p_task_id;

    RETURN jsonb_build_object(
        'success', true,
        'status', p_status
    );
END;
$$;

REVOKE ALL ON FUNCTION public.update_task_status(UUID, TEXT)
FROM PUBLIC;

GRANT EXECUTE ON FUNCTION public.update_task_status(UUID, TEXT)
TO authenticated;

NOTIFY pgrst, 'reload schema';
