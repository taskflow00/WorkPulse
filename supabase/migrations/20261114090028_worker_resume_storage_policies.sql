-- Workers can upload resumes to their own folder
CREATE POLICY "Workers can upload own resumes"
ON storage.objects
FOR INSERT
TO authenticated
WITH CHECK (
    bucket_id = 'resumes'
    AND (storage.foldername(name))[1] = (SELECT auth.uid()::text)
);

-- Workers can view their own resumes
CREATE POLICY "Workers can view own resumes"
ON storage.objects
FOR SELECT
TO authenticated
USING (
    bucket_id = 'resumes'
    AND (storage.foldername(name))[1] = (SELECT auth.uid()::text)
);

-- Workers can replace their own resumes
CREATE POLICY "Workers can replace own resumes"
ON storage.objects
FOR UPDATE
TO authenticated
USING (
    bucket_id = 'resumes'
    AND (storage.foldername(name))[1] = (SELECT auth.uid()::text)
)
WITH CHECK (
    bucket_id = 'resumes'
    AND (storage.foldername(name))[1] = (SELECT auth.uid()::text)
);