
-- 1. End the test employment.
-- Only the specific active test record will be changed.

DO $$
DECLARE
    affected_rows INTEGER;
BEGIN
    UPDATE public.employments
    SET
        status = 'ended',
        ended_at = NOW()
    WHERE id = '3138f198-4c4a-4b3e-ac55-36b138aefd27'
      AND worker_id = '280110d6-b3a2-4b28-a184-93bd62de3a90'
      AND job_id = 'b57438f3-6ab6-4164-9ad0-553991f397c7'
      AND status = 'active';

    GET DIAGNOSTICS affected_rows = ROW_COUNT;

    IF affected_rows <> 1 THEN
        RAISE EXCEPTION
            'Expected exactly one active test employment, but found %.',
            affected_rows;
    END IF;
END;
$$;

-- 2. Enforce one active employment per worker.
-- A worker may have multiple ended employments,
-- but only one employment with status = active.

CREATE UNIQUE INDEX IF NOT EXISTS
    employments_one_active_per_worker_idx
ON public.employments (worker_id)
WHERE status = 'active';
