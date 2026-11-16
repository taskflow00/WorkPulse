
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
    v_application_id uuid;
    v_rows_inserted integer := 0;
    v_invitation_type text;
BEGIN
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

    SELECT *
    INTO v_invitation
    FROM public.invitations
    WHERE id = p_invitation_id
      AND worker_id = v_worker_id
    FOR UPDATE;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Invitation not found or access denied.';
    END IF;

    v_invitation_type :=
        CASE WHEN v_invitation.application_id IS NULL THEN 'job' ELSE 'interview' END;

    IF v_invitation.status <> 'pending' THEN
        IF v_invitation.status <> p_decision THEN
            RAISE EXCEPTION 'This invitation has already been responded to.';
        END IF;

        IF v_invitation_type = 'job' AND p_decision = 'accepted' THEN
            INSERT INTO public.applications (
                job_id, worker_id, status, source, invitation_id
            )
            VALUES (
                v_invitation.job_id, v_worker_id, 'pending', 'direct_invitation', v_invitation.id
            )
            ON CONFLICT (job_id, worker_id) DO NOTHING;

            GET DIAGNOSTICS v_rows_inserted = ROW_COUNT;

            SELECT id INTO v_application_id
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
            'application_created', (v_rows_inserted > 0)
        );
    END IF;

    IF v_invitation_type = 'job' AND p_decision = 'accepted' THEN
        INSERT INTO public.applications (
            job_id, worker_id, status, source, invitation_id
        )
        VALUES (
            v_invitation.job_id, v_worker_id, 'pending', 'direct_invitation', v_invitation.id
        )
        ON CONFLICT (job_id, worker_id) DO NOTHING;

        GET DIAGNOSTICS v_rows_inserted = ROW_COUNT;

        SELECT id INTO v_application_id
        FROM public.applications
        WHERE job_id = v_invitation.job_id
          AND worker_id = v_worker_id;

        IF v_application_id IS NULL THEN
            RAISE EXCEPTION 'Unable to create or locate your application.';
        END IF;
    ELSE
        v_application_id := v_invitation.application_id;
    END IF;

    UPDATE public.invitations
    SET status = p_decision
    WHERE id = v_invitation.id
      AND worker_id = v_worker_id
      AND status = 'pending';

    RETURN jsonb_build_object(
        'success', true,
        'invitation_type', v_invitation_type,
        'invitation_status', p_decision,
        'application_id', v_application_id,
        'application_created', (v_rows_inserted > 0)
    );
END;
$function$;

REVOKE ALL ON FUNCTION public.respond_to_worker_invitation(uuid, text) FROM PUBLIC, anon;
GRANT EXECUTE ON FUNCTION public.respond_to_worker_invitation(uuid, text) TO authenticated;
;
