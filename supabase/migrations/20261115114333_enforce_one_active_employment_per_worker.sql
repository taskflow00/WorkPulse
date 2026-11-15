
-- 2. Enforce one active employment per worker.
-- A worker may have multiple ended employments,
-- but only one employment with status = active.

CREATE UNIQUE INDEX IF NOT EXISTS
    employments_one_active_per_worker_idx
ON public.employments (worker_id)
WHERE status = 'active';
