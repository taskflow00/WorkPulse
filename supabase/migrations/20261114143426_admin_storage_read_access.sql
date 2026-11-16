-- Admins can view all resumes.
CREATE POLICY "Admins can view all resumes"
ON storage.objects
FOR SELECT
TO authenticated
USING (
    bucket_id = 'resumes'
    AND public.current_user_role() = 'admin'
);


-- Admins can view all daily time proof screenshots.
CREATE POLICY "Admins can view all daily time proofs"
ON storage.objects
FOR SELECT
TO authenticated
USING (
    bucket_id = 'daily-time-proofs'
    AND public.current_user_role() = 'admin'
);