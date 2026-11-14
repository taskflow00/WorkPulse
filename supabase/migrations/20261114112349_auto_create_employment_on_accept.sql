
CREATE OR REPLACE FUNCTION public.respond_to_employment_offer(
    p_offer_id UUID,
    p_decision TEXT
)
RETURNS JSONB
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public, pg_temp
AS $$
DECLARE
    selected_offer public.employment_offers%ROWTYPE;
    new_employment_id UUID;
BEGIN
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

    -- Update the offer response.
    UPDATE public.employment_offers
    SET
        status = p_decision,
        responded_at = NOW()
    WHERE id = p_offer_id;

    -- If accepted, automatically create an employment record.
    IF p_decision = 'accepted' THEN

        INSERT INTO public.employments (
            offer_id,
            application_id,
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
$$;

GRANT EXECUTE ON FUNCTION public.respond_to_employment_offer(UUID, TEXT)
TO authenticated;

NOTIFY pgrst, 'reload schema';
