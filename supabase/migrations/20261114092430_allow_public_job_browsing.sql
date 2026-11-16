-- Remove the previous job viewing policy
DROP POLICY IF EXISTS "View available jobs"
ON public.jobs;
-- Allow guests and logged-in users to browse open jobs.
-- Employers can also view their own closed jobs.
-- Admins can view all jobs.

CREATE POLICY "View available jobs"
ON public.jobs
FOR SELECT
TO anon, authenticated
USING (
    status = 'open'
    OR employer_id = auth.uid()
    OR public.current_user_role() = 'admin'
);
-- Allow the public and authenticated users to read job listings.
GRANT SELECT ON public.jobs TO anon, authenticated;
