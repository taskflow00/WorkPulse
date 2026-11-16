BEGIN;
-- Allow an employment offer to originate from either
-- an accepted application or an accepted direct invitation.

ALTER TABLE public.employment_offers
    ALTER COLUMN application_id DROP NOT NULL;
ALTER TABLE public.employments
    ALTER COLUMN application_id DROP NOT NULL;
-- Add the direct invitation reference.

ALTER TABLE public.employment_offers
    ADD COLUMN invitation_id UUID;
ALTER TABLE public.employments
    ADD COLUMN invitation_id UUID;
-- Link both records to the original invitation.

ALTER TABLE public.employment_offers
    ADD CONSTRAINT employment_offers_invitation_id_fkey
    FOREIGN KEY (invitation_id)
    REFERENCES public.invitations(id)
    ON DELETE RESTRICT;
ALTER TABLE public.employments
    ADD CONSTRAINT employments_invitation_id_fkey
    FOREIGN KEY (invitation_id)
    REFERENCES public.invitations(id)
    ON DELETE RESTRICT;
-- Every offer and employment must have exactly one source:
-- application_id OR invitation_id, never both or neither.

ALTER TABLE public.employment_offers
    ADD CONSTRAINT employment_offers_source_check
    CHECK (
        (application_id IS NOT NULL AND invitation_id IS NULL)
        OR
        (application_id IS NULL AND invitation_id IS NOT NULL)
    );
ALTER TABLE public.employments
    ADD CONSTRAINT employments_source_check
    CHECK (
        (application_id IS NOT NULL AND invitation_id IS NULL)
        OR
        (application_id IS NULL AND invitation_id IS NOT NULL)
    );
-- Prevent duplicate offers or employment records
-- for the same invitation.

ALTER TABLE public.employment_offers
    ADD CONSTRAINT employment_offers_invitation_id_key
    UNIQUE (invitation_id);
ALTER TABLE public.employments
    ADD CONSTRAINT employments_invitation_id_key
    UNIQUE (invitation_id);
-- Replace the employer offer policy.
-- Employers can send offers only for accepted applications
-- or accepted invitations belonging to their own jobs.

DROP POLICY IF EXISTS "Employers can send offers"
ON public.employment_offers;
CREATE POLICY "Employers can send offers"
ON public.employment_offers
FOR INSERT
TO authenticated
WITH CHECK (
    employer_id = (SELECT auth.uid())
    AND (SELECT current_user_role()) = 'employer'
    AND (
        (
            application_id IS NOT NULL
            AND invitation_id IS NULL
            AND EXISTS (
                SELECT 1
                FROM public.applications a
                JOIN public.jobs j ON j.id = a.job_id
                WHERE a.id = employment_offers.application_id
                  AND a.job_id = employment_offers.job_id
                  AND a.worker_id = employment_offers.worker_id
                  AND a.status = 'accepted'
                  AND j.employer_id = (SELECT auth.uid())
            )
        )
        OR
        (
            invitation_id IS NOT NULL
            AND application_id IS NULL
            AND EXISTS (
                SELECT 1
                FROM public.invitations i
                JOIN public.jobs j ON j.id = i.job_id
                WHERE i.id = employment_offers.invitation_id
                  AND i.job_id = employment_offers.job_id
                  AND i.worker_id = employment_offers.worker_id
                  AND i.employer_id = (SELECT auth.uid())
                  AND i.status = 'accepted'
                  AND j.employer_id = (SELECT auth.uid())
            )
        )
    )
);
-- Update the worker's formal-offer response function.
-- Accepting a valid offer creates an employment record
-- using the same recruitment source as the offer.

CREATE OR REPLACE FUNCTION public.respond_to_employment_offer(
    p_offer_id UUID,
    p_decision TEXT
)
RETURNS JSONB
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path TO 'public', 'pg_temp'
AS $function$
DECLARE
    selected_offer public.employment_offers%ROWTYPE;
    new_employment_id UUID;
BEGIN
    -- Require an authenticated, active worker.
    IF (SELECT auth.uid()) IS NULL THEN
        RAISE EXCEPTION 'You must be logged in to respond to an offer.';
    END IF;

    IF NOT public.is_active_user() THEN
        RAISE EXCEPTION 'Your account is not active.';
    END IF;

    -- Validate the decision.
    IF p_decision IS NULL
       OR p_decision NOT IN ('accepted', 'declined') THEN
        RAISE EXCEPTION 'Invalid offer response.';
    END IF;

    -- Find and lock the worker's own offer.
    SELECT *
    INTO selected_offer
    FROM public.employment_offers
    WHERE id = p_offer_id
      AND worker_id = (SELECT auth.uid())
    FOR UPDATE;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Employment offer not found.';
    END IF;

    IF selected_offer.status <> 'pending' THEN
        RAISE EXCEPTION 'This offer has already been answered.';
    END IF;

    IF selected_offer.expires_at <= NOW() THEN
        RAISE EXCEPTION 'This employment offer has expired.';
    END IF;

    -- Recheck the original recruitment source.
    IF selected_offer.application_id IS NOT NULL THEN

        IF NOT EXISTS (
            SELECT 1
            FROM public.applications a
            JOIN public.jobs j ON j.id = a.job_id
            WHERE a.id = selected_offer.application_id
              AND a.job_id = selected_offer.job_id
              AND a.worker_id = selected_offer.worker_id
              AND a.status = 'accepted'
              AND j.employer_id = selected_offer.employer_id
        ) THEN
            RAISE EXCEPTION 'The original application is no longer valid.';
        END IF;

    ELSIF selected_offer.invitation_id IS NOT NULL THEN

        IF NOT EXISTS (
            SELECT 1
            FROM public.invitations i
            JOIN public.jobs j ON j.id = i.job_id
            WHERE i.id = selected_offer.invitation_id
              AND i.job_id = selected_offer.job_id
              AND i.worker_id = selected_offer.worker_id
              AND i.employer_id = selected_offer.employer_id
              AND i.status = 'accepted'
              AND j.employer_id = selected_offer.employer_id
        ) THEN
            RAISE EXCEPTION 'The original invitation is no longer valid.';
        END IF;

    ELSE
        RAISE EXCEPTION 'The offer has no valid recruitment source.';
    END IF;

    -- Record the worker's response.
    UPDATE public.employment_offers
    SET
        status = p_decision,
        responded_at = NOW()
    WHERE id = p_offer_id;

    -- Create employment only after accepting the formal offer.
    IF p_decision = 'accepted' THEN

        INSERT INTO public.employments (
            offer_id,
            application_id,
            invitation_id,
            job_id,
            employer_id,
            worker_id,
            hourly_rate,
            employment_type,
            work_schedule
        )
        VALUES (
            selected_offer.id,
            selected_offer.application_id,
            selected_offer.invitation_id,
            selected_offer.job_id,
            selected_offer.employer_id,
            selected_offer.worker_id,
            selected_offer.hourly_rate,
            selected_offer.employment_type,
            selected_offer.work_schedule
        )
        RETURNING id INTO new_employment_id;

    END IF;

    RETURN jsonb_build_object(
        'success', true,
        'status', p_decision,
        'employment_id', new_employment_id
    );
END;
$function$;
NOTIFY pgrst, 'reload schema';
COMMIT;
