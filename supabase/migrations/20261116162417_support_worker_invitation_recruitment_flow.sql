
ALTER TABLE public.applications
    ADD COLUMN IF NOT EXISTS source text NOT NULL DEFAULT 'job_application',
    ADD COLUMN IF NOT EXISTS invitation_id uuid;

ALTER TABLE public.applications
    ADD CONSTRAINT applications_source_check
    CHECK (source IN ('job_application', 'direct_invitation'));

ALTER TABLE public.applications
    ADD CONSTRAINT applications_invitation_id_fkey
    FOREIGN KEY (invitation_id)
    REFERENCES public.invitations(id)
    ON DELETE SET NULL;

CREATE UNIQUE INDEX applications_invitation_id_unique
    ON public.applications(invitation_id)
    WHERE invitation_id IS NOT NULL;

-- Preserve existing applications as independently submitted applications.
-- Convert previously accepted direct invitations that have no application yet.
INSERT INTO public.applications (
    job_id,
    worker_id,
    status,
    source,
    invitation_id
)
SELECT
    i.job_id,
    i.worker_id,
    'pending',
    'direct_invitation',
    i.id
FROM public.invitations i
WHERE i.application_id IS NULL
  AND i.status = 'accepted'
  AND NOT EXISTS (
      SELECT 1
      FROM public.applications a
      WHERE a.job_id = i.job_id
        AND a.worker_id = i.worker_id
  )
ON CONFLICT (job_id, worker_id) DO NOTHING;

-- Interview responses must not automatically accept/reject an application.
DROP TRIGGER IF EXISTS invitations_sync_application_status ON public.invitations;
DROP FUNCTION IF EXISTS public.sync_application_status_from_invitation();

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
    v_application_created boolean := false;
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
        CASE
            WHEN v_invitation.application_id IS NULL THEN 'job'
            ELSE 'interview'
        END;

    -- Repeated requests with the same decision are safe and do not duplicate applications.
    IF v_invitation.status <> 'pending' THEN
        IF v_invitation.status <> p_decision THEN
            RAISE EXCEPTION 'This invitation has already been responded to.';
        END IF;

        IF v_invitation_type = 'job' AND p_decision = 'accepted' THEN
            SELECT id
            INTO v_application_id
            FROM public.applications
            WHERE job_id = v_invitation.job_id
              AND worker_id = v_worker_id;

            IF v_application_id IS NULL THEN
                INSERT INTO public.applications (
                    job_id, worker_id, status, source, invitation_id
                )
                VALUES (
                    v_invitation.job_id,
                    v_worker_id,
                    'pending',
                    'direct_invitation',
                    v_invitation.id
                )
                ON CONFLICT (job_id, worker_id) DO NOTHING;

                SELECT id
                INTO v_application_id
                FROM public.applications
                WHERE job_id = v_invitation.job_id
                  AND worker_id = v_worker_id;

                v_application_created := FOUND;
            END IF;
        ELSE
            v_application_id := v_invitation.application_id;
        END IF;

        RETURN jsonb_build_object(
            'success', true,
            'invitation_type', v_invitation_type,
            'invitation_status', v_invitation.status,
            'application_id', v_application_id,
            'application_created', v_application_created
        );
    END IF;

    IF v_invitation_type = 'job' AND p_decision = 'accepted' THEN
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

        SELECT id
        INTO v_application_id
        FROM public.applications
        WHERE job_id = v_invitation.job_id
          AND worker_id = v_worker_id;

        IF v_application_id IS NULL THEN
            RAISE EXCEPTION 'Unable to create or locate your application.';
        END IF;

        SELECT NOT EXISTS (
            SELECT 1
            FROM public.applications
            WHERE id = v_application_id
              AND source = 'job_application'
        )
        INTO v_application_created;
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
        'application_created', v_application_created
    );
END;
$function$;

REVOKE ALL ON FUNCTION public.respond_to_worker_invitation(uuid, text) FROM PUBLIC, anon;
GRANT EXECUTE ON FUNCTION public.respond_to_worker_invitation(uuid, text) TO authenticated;
;
