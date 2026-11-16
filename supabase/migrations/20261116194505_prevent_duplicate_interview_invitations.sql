
CREATE OR REPLACE FUNCTION public.send_application_interview_invitation(
    p_application_id uuid,
    p_message text DEFAULT NULL,
    p_interview_at timestamptz DEFAULT NULL,
    p_interview_type text DEFAULT 'online',
    p_meeting_details text DEFAULT NULL,
    p_meeting_link text DEFAULT NULL
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

    -- AUTHENTICATION

    IF auth.uid() IS NULL THEN
        RAISE EXCEPTION 'You must be logged in.';
    END IF;

    IF NOT public.is_active_user() THEN
        RAISE EXCEPTION 'Your account is not active.';
    END IF;

    IF public.current_user_role() <> 'employer' THEN
        RAISE EXCEPTION 'Only employers can send interview invitations.';
    END IF;


    -- VALIDATE INTERVIEW DETAILS

    IF p_interview_type IS DISTINCT FROM 'online' THEN
        RAISE EXCEPTION 'Only online interviews are supported.';
    END IF;

    IF p_interview_at IS NULL OR p_interview_at <= NOW() THEN
        RAISE EXCEPTION 'The interview date and time must be in the future.';
    END IF;

    IF p_meeting_link IS NOT NULL
       AND p_meeting_link !~ '^https://meet\.google\.com/[A-Za-z0-9-]+$' THEN
        RAISE EXCEPTION 'Invalid meeting link.';
    END IF;


    -- VERIFY APPLICATION OWNERSHIP AND LOCK THE APPLICATION

    SELECT a.*
    INTO selected_application
    FROM public.applications a
    JOIN public.jobs j ON j.id = a.job_id
    WHERE a.id = p_application_id
      AND j.employer_id = auth.uid()
      AND j.status = 'open'
    FOR UPDATE OF a;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Application not found or it does not belong to your account.';
    END IF;

    IF selected_application.status NOT IN (
        'pending',
        'shortlisted',
        'interview'
    ) THEN
        RAISE EXCEPTION 'This application is no longer eligible for an interview invitation.';
    END IF;


    -- CHECK THE LATEST INTERVIEW INVITATION

    SELECT i.*
    INTO existing_invitation
    FROM public.invitations i
    WHERE i.application_id = selected_application.id
    ORDER BY i.created_at DESC, i.id DESC
    LIMIT 1
    FOR UPDATE;

    IF FOUND AND existing_invitation.status <> 'declined' THEN

        IF existing_invitation.status = 'pending' THEN

            RETURN jsonb_build_object(
                'success', true,
                'invitation_id', existing_invitation.id,
                'status', existing_invitation.status,
                'already_exists', true
            );

        END IF;

        RETURN jsonb_build_object(
            'success', false,
            'message', 'The worker has already accepted the latest interview invitation. You can schedule another interview only after the worker declines it.'
        );

    END IF;


    -- CREATE A NEW INTERVIEW INVITATION

    INSERT INTO public.invitations (
        job_id,
        employer_id,
        worker_id,
        application_id,
        message,
        status,
        interview_at,
        interview_type,
        meeting_details,
        meeting_link
    )
    VALUES (
        selected_application.job_id,
        auth.uid(),
        selected_application.worker_id,
        selected_application.id,
        NULLIF(BTRIM(COALESCE(p_message, '')), ''),
        'pending',
        p_interview_at,
        'online',
        NULLIF(BTRIM(COALESCE(p_meeting_details, '')), ''),
        NULLIF(BTRIM(COALESCE(p_meeting_link, '')), '')
    )
    ON CONFLICT (application_id)
    WHERE application_id IS NOT NULL
      AND status = 'pending'
    DO NOTHING
    RETURNING id INTO new_invitation_id;


    -- HANDLE SIMULTANEOUS REQUESTS

    IF new_invitation_id IS NULL THEN

        SELECT i.*
        INTO existing_invitation
        FROM public.invitations i
        WHERE i.application_id = selected_application.id
          AND i.status = 'pending'
        ORDER BY i.created_at DESC
        LIMIT 1;

        IF NOT FOUND THEN
            RAISE EXCEPTION 'Unable to create or retrieve the interview invitation.';
        END IF;

        RETURN jsonb_build_object(
            'success', true,
            'invitation_id', existing_invitation.id,
            'status', existing_invitation.status,
            'already_exists', true
        );

    END IF;


    -- UPDATE APPLICATION STATUS

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
