CREATE POLICY "Employers can view resumes submitted to their jobs"
ON storage.objects
FOR SELECT
TO authenticated
USING (
    bucket_id = 'resumes'
    AND EXISTS (
        SELECT 1
        FROM public.applications AS a
        JOIN public.jobs AS j
            ON j.id = a.job_id
        WHERE a.resume_path = storage.objects.name
        AND j.employer_id = (SELECT auth.uid())
    )
);