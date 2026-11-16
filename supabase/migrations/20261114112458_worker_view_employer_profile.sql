CREATE POLICY "Workers can view their employers"
ON public.profiles
FOR SELECT
TO authenticated
USING (
    (SELECT public.current_user_role()) = 'worker'
    AND EXISTS (
        SELECT 1
        FROM public.employments AS e
        WHERE e.employer_id = profiles.id
          AND e.worker_id = (SELECT auth.uid())
    )
);
