
-- Workers can respond only to their own pending offers.
-- The function controls exactly which fields can be changed.

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
BEGIN
    -- Validate the requested decision.
    IF p_decision NOT IN ('accepted', 'declined') THEN
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

    -- Update only the response fields.
    UPDATE public.employment_offers
    SET
        status = p_decision,
        responded_at = NOW()
    WHERE id = p_offer_id;

    RETURN jsonb_build_object(
        'success', true,
        'status', p_decision
    );
END;
$$;

-- Only authenticated users can call this function.
REVOKE ALL ON FUNCTION public.respond_to_employment_offer(UUID, TEXT)
FROM PUBLIC;

GRANT EXECUTE ON FUNCTION public.respond_to_employment_offer(UUID, TEXT)
TO authenticated;
