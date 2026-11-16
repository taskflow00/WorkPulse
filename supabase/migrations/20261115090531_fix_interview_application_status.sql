
CREATE OR REPLACE FUNCTION public.send_application_interview_invitation(
    p_application_id uuid,
    p_message text DEFAULT NULL::text,
    p_interview_at timestamp with time zone DEFAULT NULL::timestamp with time zone,
    p_interview_type text DEFAULT NULL::text,
    p_meeting_details text DEFAULT NULL::text
)
RETURNS jsonb
LANGUAGE plpgsql
SET search_path TO 'public', 'pg_temp'
AS $function$
DECLARE
    selected_application public.applications%ROWTYPE;
    existing_invitation public.invitations%ROWTYPE;
    new_invitation_id uuid;
BEGIN
    -- Check login
    IF (SELECT auth.uid()) IS NULL THEN
        RAISE EXCEPTION 'You must be logged in.';
    END IF;

    -- Check account status
    IF NOT public.is_active_user() THEN
        RAISE EXCEPTION 'Your account is not active.';
    END IF;

    -- Only employers can send interview invitations
    IF (SELECT public.current_user_role()) <> 'employer' THEN
        RAISE EXCEPTION 'Only employers can invite applicants to interviews.';
    END IF;

    -- Validate interview type
    IF p_interview_type IS NOT NULL
       AND p_interview_type NOT IN ('online', 'phone', 'in_person', 'other') THEN
        RAISE EXCEPTION 'Invalid interview type.';
    END IF;

    -- Interview date must be in the future
    IF p_interview_at IS NOT NULL AND p_interview_at <= now() THEN
        RAISE EXCEPTION 'The interview date and time must be in the future.';
    END IF;

    -- Find an application belonging to this employer
    SELECT a.*
    INTO selected_application
    FROM public.applications a
    JOIN public.jobs j ON j.id = a.job_id
    WHERE a.id = p_application_id
      AND j.employer_id = (SELECT auth.uid())
      AND j.status = 'open'
    FOR UPDATE OF a;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Application not found or it does not belong to your account.';
    END IF;

    -- Only applications that are still eligible can receive an interview invitation
    IF selected_application.status NOT IN ('pending', 'shortlisted', 'interview') THEN
        RAISE EXCEPTION 'This application is no longer eligible for an interview invitation.';
    END IF;

    -- Reuse an existing active invitation for the same job and worker
    SELECT i.*
    INTO existing_invitation
    FROM public.invitations i
    WHERE i.job_id = selected_application.job_id
      AND i.worker_id = selected_application.worker_id
      AND i.status IN ('pending', 'accepted')
    ORDER BY i.created_at DESC
    LIMIT 1
    FOR UPDATE;

    IF FOUND THEN
        IF existing_invitation.application_id IS NOT NULL
           AND existing_invitation.application_id <> selected_application.id THEN
            RAISE EXCEPTION 'An active invitation already exists for another application.';
        END IF;

        -- Keep the application in interview status
        UPDATE public.applications
        SET status = 'interview'
        WHERE id = selected_application.id;

        RETURN jsonb_build_object(
            'success', true,
            'invitation_id', existing_invitation.id,
            'status', existing_invitation.status,
            'already_exists', true
        );
    END IF;

    -- Create the interview invitation
    INSERT INTO public.invitations (
        job_id,
        employer_id,
        worker_id,
        application_id,
        message,
        status,
        interview_at,
        interview_type,
        meeting_details
    )
    VALUES (
        selected_application.job_id,
        (SELECT auth.uid()),
        selected_application.worker_id,
        selected_application.id,
        NULLIF(BTRIM(COALESCE(p_message, '')), ''),
        'pending',
        p_interview_at,
        p_interview_type,
        NULLIF(BTRIM(COALESCE(p_meeting_details, '')), '')
    )
    RETURNING id INTO new_invitation_id;

    -- Mark the application as being in the interview stage
    UPDATE public.applications
    SET status = 'interview'
    WHERE id = selected_application.id;

    RETURN jsonb_build_object(
        'success', true,
        'invitation_id', new_invitation_id,
        'status', 'pending',
        'already_exists', false
    );
END;
$function$;
