
BEGIN;

-- =========================================
-- 1. FIX SENDING INTERVIEW INVITATIONS
-- =========================================

CREATE OR REPLACE FUNCTION public.send_application_interview_invitation(
    p_application_id uuid,
    p_message text DEFAULT NULL,
    p_interview_at timestamptz DEFAULT NULL,
    p_interview_type text DEFAULT NULL,
    p_meeting_details text DEFAULT NULL
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
    IF (SELECT auth.uid()) IS NULL THEN
        RAISE EXCEPTION 'You must be logged in.';
    END IF;

    IF NOT public.is_active_user() THEN
        RAISE EXCEPTION 'Your account is not active.';
    END IF;

    IF (SELECT public.current_user_role()) <> 'employer' THEN
        RAISE EXCEPTION 'Only employers can invite applicants.';
    END IF;

    IF p_interview_type IS NOT NULL
       AND p_interview_type NOT IN ('online', 'phone', 'in_person', 'other') THEN
        RAISE EXCEPTION 'Invalid interview type.';
    END IF;

    IF p_interview_at IS NOT NULL AND p_interview_at <= now() THEN
        RAISE EXCEPTION 'The interview date and time must be in the future.';
    END IF;

    -- Find an application belonging to this employer.
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

    IF selected_application.status NOT IN (
        'pending', 'shortlisted', 'interview', 'accepted'
    ) THEN
        RAISE EXCEPTION 'This application is no longer eligible for an interview.';
    END IF;

    -- Look for an existing pending or accepted invitation.
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

        -- Do not downgrade an already accepted interview.
        UPDATE public.applications
        SET status = CASE
            WHEN existing_invitation.status = 'accepted' THEN 'accepted'
            ELSE 'interview'
        END
        WHERE id = selected_application.id;

        RETURN jsonb_build_object(
            'success', true,
            'invitation_id', existing_invitation.id,
            'status', existing_invitation.status,
            'already_exists', true
        );
    END IF;

    -- Create a new pending interview invitation.
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

    -- Sending an invitation means the application is in the interview stage.
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


-- =========================================
-- 2. SYNC APPLICATION WHEN WORKER RESPONDS
-- =========================================

CREATE OR REPLACE FUNCTION public.sync_application_status_from_invitation()
RETURNS trigger
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path TO 'public', 'pg_temp'
AS $function$
BEGIN

    -- Only process a worker's first response to a pending invitation.
    IF OLD.status = 'pending'
       AND NEW.status IN ('accepted', 'declined')
       AND NEW.application_id IS NOT NULL THEN

        UPDATE public.applications
        SET status = CASE
            WHEN NEW.status = 'accepted' THEN 'accepted'
            ELSE 'interview'
        END
        WHERE id = NEW.application_id
          AND job_id = NEW.job_id
          AND worker_id = NEW.worker_id
          AND status IN (
              'pending', 'shortlisted', 'interview', 'accepted'
          );

    END IF;

    RETURN NEW;
END;
$function$;


-- Run the synchronization automatically whenever
-- a worker accepts or declines an interview invitation.

DROP TRIGGER IF EXISTS invitations_sync_application_status
ON public.invitations;

CREATE TRIGGER invitations_sync_application_status
AFTER UPDATE OF status ON public.invitations
FOR EACH ROW
WHEN (OLD.status IS DISTINCT FROM NEW.status)
EXECUTE FUNCTION public.sync_application_status_from_invitation();


-- The trigger is the only intended caller of this function.
REVOKE ALL ON FUNCTION public.sync_application_status_from_invitation()
FROM PUBLIC, anon, authenticated;


NOTIFY pgrst, 'reload schema';

COMMIT;
