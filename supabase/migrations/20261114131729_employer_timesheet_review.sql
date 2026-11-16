CREATE OR REPLACE FUNCTION public.review_monthly_timesheet(
    p_timesheet_id UUID,
    p_status TEXT,
    p_comment TEXT DEFAULT NULL
)
RETURNS JSONB
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public, pg_temp
AS $$
DECLARE
    v_employer_id UUID;
    v_current_status TEXT;
BEGIN
    -- Make sure the user is logged in.
    IF auth.uid() IS NULL THEN
        RETURN jsonb_build_object(
            'success', false,
            'message', 'You must be logged in.'
        );
    END IF;

    -- Only employers can review timesheets.
    IF NOT EXISTS (
        SELECT 1
        FROM public.profiles
        WHERE id = auth.uid()
        AND role = 'employer'
    ) THEN
        RETURN jsonb_build_object(
            'success', false,
            'message', 'Only employers can review timesheets.'
        );
    END IF;

    -- Only approved or rejected are allowed.
    IF p_status IS NULL OR p_status NOT IN ('approved', 'rejected') THEN
        RETURN jsonb_build_object(
            'success', false,
            'message', 'Invalid review status.'
        );
    END IF;

    -- Find the timesheet and its employer.
    -- Lock the timesheet while it is being reviewed.
    SELECT e.employer_id, t.status
    INTO v_employer_id, v_current_status
    FROM public.timesheets t
    JOIN public.employments e
        ON e.id = t.employment_id
    WHERE t.id = p_timesheet_id
    FOR UPDATE OF t;

    -- Check whether the timesheet exists.
    IF NOT FOUND THEN
        RETURN jsonb_build_object(
            'success', false,
            'message', 'Timesheet not found.'
        );
    END IF;

    -- Make sure this employer owns the employment.
    IF v_employer_id <> auth.uid() THEN
        RETURN jsonb_build_object(
            'success', false,
            'message', 'You cannot review this timesheet.'
        );
    END IF;

    -- Only submitted timesheets can be reviewed.
    IF v_current_status <> 'submitted' THEN
        RETURN jsonb_build_object(
            'success', false,
            'message', 'This timesheet has already been reviewed or is not submitted.'
        );
    END IF;

    -- Save the employer's decision.
    UPDATE public.timesheets
    SET
        status = p_status,
        employer_comment = NULLIF(TRIM(p_comment), ''),
        reviewed_at = NOW(),
        reviewed_by = auth.uid(),
        updated_at = NOW()
    WHERE id = p_timesheet_id;

    RETURN jsonb_build_object(
        'success', true,
        'message', 'Timesheet successfully ' || p_status || '.',
        'timesheet_id', p_timesheet_id,
        'status', p_status
    );
END;
$$;
-- Allow logged-in users to call the function.
REVOKE ALL ON FUNCTION public.review_monthly_timesheet(UUID, TEXT, TEXT)
FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.review_monthly_timesheet(UUID, TEXT, TEXT)
TO authenticated;
