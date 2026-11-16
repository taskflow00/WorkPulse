
BEGIN;

-- Fix worker interview invitation responses.
-- Workers can read their own applications but cannot update them.
-- FOR UPDATE checks UPDATE permissions and can make the application
-- appear missing. Use a normal SELECT instead.

CREATE OR REPLACE FUNCTION public.respond_to_worker_invitation(
    p_invitation_id uuid,
    p_decision text
)
RETURNS jsonb
LANGUAGE plpgsql
SECURITY INVOKER
SET search_path = public, pg_temp
AS $function$
DECLARE
    v_worker_id uuid;
    v_invitation public.invitations%ROWTYPE;
    v_application public.applications%ROWTYPE;
    v_application_id uuid;
    v_rows_inserted integer := 0;
    v_invitation_type text;
    v_response_changed boolean := false;
    v_updated_invitation_id uuid;
BEGIN
    -- 1. Authenticate the worker.
    v_worker_id := auth.uid();

    IF v_worker_id IS NULL THEN
        RAISE EXCEPTION 'You must be signed in to respond to an invitation.';
    END IF;

    IF public.current_user_role() <> 'worker' THEN
        RAISE EXCEPTION 'Only workers can respond to their invitations.';
    END IF;

    IF NOT public.is_active_user() THEN
        RAISE EXCEPTION 'Your account must be active to respond to invitations.';
    END IF;

    IF p_decision NOT IN ('accepted', 'declined') THEN
        RAISE EXCEPTION 'Invalid invitation decision.';
    END IF;

    -- 2. Find the invitation belonging to this worker.
    SELECT *
    INTO v_invitation
    FROM public.invitations
    WHERE id = p_invitation_id
      AND worker_id = v_worker_id;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Invitation not found or access denied.';
    END IF;

    v_invitation_type :=
        CASE
            WHEN v_invitation.application_id IS NULL THEN 'job'
            ELSE 'interview'
        END;

    -- 3. Validate the linked application.
    -- IMPORTANT: Do not use FOR UPDATE here.
    IF v_invitation.application_id IS NOT NULL THEN

        SELECT *
        INTO v_application
        FROM public.applications
        WHERE id = v_invitation.application_id
          AND worker_id = v_worker_id
          AND job_id = v_invitation.job_id;

        IF NOT FOUND THEN
            RAISE EXCEPTION
                'The application connected to this interview could not be found.';
        END IF;

        IF v_application.status NOT IN (
            'pending',
            'shortlisted',
            'interview'
        ) THEN
            RAISE EXCEPTION
                'This interview invitation is no longer active because the application is no longer in the recruitment process.';
        END IF;

    END IF;

    -- 4. Lock the invitation and read its latest status.
    SELECT *
    INTO v_invitation
    FROM public.invitations
    WHERE id = p_invitation_id
      AND worker_id = v_worker_id
    FOR UPDATE;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Invitation not found or access denied.';
    END IF;

    IF v_invitation.status = 'cancelled' THEN
        RAISE EXCEPTION
            'This invitation was cancelled because the application is no longer active.';
    END IF;

    -- 5. Handle invitations that were already answered.
    IF v_invitation.status <> 'pending' THEN

        IF v_invitation.status <> p_decision THEN
            RAISE EXCEPTION
                'This invitation has already been responded to.';
        END IF;

        -- Recover or locate the application for an accepted job invitation.
        IF (
            v_invitation_type = 'job'
            AND p_decision = 'accepted'
        ) THEN

            INSERT INTO public.applications (
                job_id,
                worker_id,
                status,
                source,
                invitation_id
            )
            VALUES (
                v_invitation.job_id,
                v_worker_id,
                'pending',
                'direct_invitation',
                v_invitation.id
            )
            ON CONFLICT (job_id, worker_id) DO NOTHING;

            GET DIAGNOSTICS v_rows_inserted = ROW_COUNT;

            SELECT id
            INTO v_application_id
            FROM public.applications
            WHERE job_id = v_invitation.job_id
              AND worker_id = v_worker_id;

        ELSE
            v_application_id := v_invitation.application_id;
        END IF;

        RETURN jsonb_build_object(
            'success', true,
            'invitation_type', v_invitation_type,
            'invitation_status', v_invitation.status,
            'application_id', v_application_id,
            'application_created', (v_rows_inserted > 0),
            'response_changed', false
        );

    END IF;

    -- 6. Accept a direct job invitation.
    IF (
        v_invitation_type = 'job'
        AND p_decision = 'accepted'
    ) THEN

        INSERT INTO public.applications (
            job_id,
            worker_id,
            status,
            source,
            invitation_id
        )
        VALUES (
            v_invitation.job_id,
            v_worker_id,
            'pending',
            'direct_invitation',
            v_invitation.id
        )
        ON CONFLICT (job_id, worker_id) DO NOTHING;

        GET DIAGNOSTICS v_rows_inserted = ROW_COUNT;

        SELECT id
        INTO v_application_id
        FROM public.applications
        WHERE job_id = v_invitation.job_id
          AND worker_id = v_worker_id;

        IF v_application_id IS NULL THEN
            RAISE EXCEPTION
                'Unable to create or locate your application.';
        END IF;

    ELSE
        v_application_id := v_invitation.application_id;
    END IF;

    -- 7. Update only this worker's pending invitation.
    UPDATE public.invitations
    SET status = p_decision
    WHERE id = v_invitation.id
      AND worker_id = v_worker_id
      AND status = 'pending'
    RETURNING id INTO v_updated_invitation_id;

    IF v_updated_invitation_id IS NULL THEN
        RAISE EXCEPTION
            'Unable to update the invitation. Please refresh and try again.';
    END IF;

    v_response_changed := true;

    -- 8. Return the response.
    -- Accepting an interview does not automatically hire the worker.
    RETURN jsonb_build_object(
        'success', true,
        'invitation_type', v_invitation_type,
        'invitation_status', p_decision,
        'application_id', v_application_id,
        'application_created', (v_rows_inserted > 0),
        'response_changed', v_response_changed
    );

END;
$function$;

-- Preserve the existing RPC permissions.
REVOKE ALL ON FUNCTION
    public.respond_to_worker_invitation(uuid, text)
FROM PUBLIC, anon;

GRANT EXECUTE ON FUNCTION
    public.respond_to_worker_invitation(uuid, text)
TO authenticated;

NOTIFY pgrst, 'reload schema';

COMMIT;
