-- =========================================================
-- WORKPULSE: TASK SUBMISSIONS AND EMPLOYER REVIEW
-- =========================================================

-- 1. Store completed-work submissions.
CREATE TABLE public.task_submissions (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),

    task_id UUID NOT NULL
        REFERENCES public.tasks(id) ON DELETE CASCADE,

    worker_id UUID NOT NULL
        REFERENCES public.profiles(id) ON DELETE CASCADE,

    message TEXT NOT NULL
        CHECK (char_length(trim(message)) BETWEEN 1 AND 5000),

    status TEXT NOT NULL DEFAULT 'pending_review'
        CHECK (status IN (
            'pending_review',
            'approved',
            'revision_requested'
        )),

    submitted_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    reviewed_at TIMESTAMPTZ,

    reviewed_by UUID
        REFERENCES public.profiles(id) ON DELETE SET NULL,

    employer_feedback TEXT,

    CONSTRAINT task_submission_review_fields CHECK (
        (
            status = 'pending_review'
            AND reviewed_at IS NULL
            AND reviewed_by IS NULL
        )
        OR
        (
            status <> 'pending_review'
            AND reviewed_at IS NOT NULL
            AND reviewed_by IS NOT NULL
        )
    )
);
-- 2. Store screenshot information.
CREATE TABLE public.task_submission_files (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),

    submission_id UUID NOT NULL
        REFERENCES public.task_submissions(id) ON DELETE CASCADE,

    file_path TEXT NOT NULL UNIQUE,

    file_name TEXT NOT NULL
        CHECK (char_length(trim(file_name)) BETWEEN 1 AND 255),

    uploaded_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
-- 3. Add indexes for frequently accessed records.
CREATE INDEX idx_task_submissions_task
ON public.task_submissions(task_id, submitted_at DESC);
CREATE INDEX idx_task_submissions_worker
ON public.task_submissions(worker_id);
CREATE INDEX idx_task_submissions_status
ON public.task_submissions(status);
CREATE INDEX idx_task_submission_files_submission
ON public.task_submission_files(submission_id);
-- 4. Enable RLS.
ALTER TABLE public.task_submissions ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.task_submission_files ENABLE ROW LEVEL SECURITY;
-- 5. Allow authenticated users to read authorized submissions.
GRANT SELECT ON public.task_submissions TO authenticated;
GRANT SELECT ON public.task_submission_files TO authenticated;
REVOKE ALL ON public.task_submissions FROM anon;
REVOKE ALL ON public.task_submission_files FROM anon;
-- Workers can view their own submissions.
CREATE POLICY "Workers can view their own submissions"
ON public.task_submissions
FOR SELECT
TO authenticated
USING (
    public.is_active_user()
    AND worker_id = (SELECT auth.uid())
);
-- Employers can view submissions for their own tasks.
CREATE POLICY "Employers can view their task submissions"
ON public.task_submissions
FOR SELECT
TO authenticated
USING (
    public.is_active_user()
    AND EXISTS (
        SELECT 1
        FROM public.tasks t
        WHERE t.id = task_submissions.task_id
          AND t.employer_id = (SELECT auth.uid())
    )
);
-- Admins can view all submissions.
CREATE POLICY "Admins can view all task submissions"
ON public.task_submissions
FOR SELECT
TO authenticated
USING (
    public.is_active_user()
    AND public.current_user_role() = 'admin'
);
-- Workers can view their own submission files.
CREATE POLICY "Workers can view their submission files"
ON public.task_submission_files
FOR SELECT
TO authenticated
USING (
    public.is_active_user()
    AND EXISTS (
        SELECT 1
        FROM public.task_submissions s
        WHERE s.id = task_submission_files.submission_id
          AND s.worker_id = (SELECT auth.uid())
    )
);
-- Employers can view files submitted for their own tasks.
CREATE POLICY "Employers can view task submission files"
ON public.task_submission_files
FOR SELECT
TO authenticated
USING (
    public.is_active_user()
    AND EXISTS (
        SELECT 1
        FROM public.task_submissions s
        JOIN public.tasks t ON t.id = s.task_id
        WHERE s.id = task_submission_files.submission_id
          AND t.employer_id = (SELECT auth.uid())
    )
);
-- Admins can view all submission files.
CREATE POLICY "Admins can view all task submission files"
ON public.task_submission_files
FOR SELECT
TO authenticated
USING (
    public.is_active_user()
    AND public.current_user_role() = 'admin'
);
-- 6. Create the private Storage bucket.
INSERT INTO storage.buckets (
    id,
    name,
    public,
    file_size_limit,
    allowed_mime_types
)
VALUES (
    'task-proofs',
    'task-proofs',
    false,
    5242880,
    ARRAY[
        'image/png',
        'image/jpeg',
        'image/webp'
    ]
)
ON CONFLICT (id)
DO UPDATE SET
    public = false,
    file_size_limit = 5242880,
    allowed_mime_types = ARRAY[
        'image/png',
        'image/jpeg',
        'image/webp'
    ];
-- Storage paths follow:
-- worker_id/task_id/submission_id/filename.png

-- Workers can upload only to their own active tasks.
CREATE POLICY "Workers can upload task proof screenshots"
ON storage.objects
FOR INSERT
TO authenticated
WITH CHECK (
    bucket_id = 'task-proofs'
    AND public.is_active_user()
    AND public.current_user_role() = 'worker'
    AND (storage.foldername(name))[1] = (SELECT auth.uid())::TEXT
    AND array_length(storage.foldername(name), 1) = 3
    AND EXISTS (
        SELECT 1
        FROM public.tasks t
        WHERE t.id::TEXT = (storage.foldername(name))[2]
          AND t.worker_id = (SELECT auth.uid())
          AND t.status = 'in_progress'
    )
);
-- Only files belonging to a recorded submission can be viewed.
CREATE POLICY "Authorized users can view task proof screenshots"
ON storage.objects
FOR SELECT
TO authenticated
USING (
    bucket_id = 'task-proofs'
    AND public.is_active_user()
    AND EXISTS (
        SELECT 1
        FROM public.task_submission_files f
        JOIN public.task_submissions s
            ON s.id = f.submission_id
        JOIN public.tasks t
            ON t.id = s.task_id
        WHERE f.file_path = storage.objects.name
          AND (
              s.worker_id = (SELECT auth.uid())
              OR t.employer_id = (SELECT auth.uid())
              OR public.current_user_role() = 'admin'
          )
    )
);
-- Workers can delete their own unsubmitted uploads
-- if the submission RPC fails before recording them.
CREATE POLICY "Workers can clean up unsubmitted task proof files"
ON storage.objects
FOR DELETE
TO authenticated
USING (
    bucket_id = 'task-proofs'
    AND public.is_active_user()
    AND public.current_user_role() = 'worker'
    AND (storage.foldername(name))[1] = (SELECT auth.uid())::TEXT
    AND NOT EXISTS (
        SELECT 1
        FROM public.task_submission_files f
        WHERE f.file_path = storage.objects.name
    )
);
-- 7. RPC: worker submits completed work.
CREATE OR REPLACE FUNCTION public.submit_task_work(
    p_task_id UUID,
    p_submission_id UUID,
    p_message TEXT,
    p_files JSONB
)
RETURNS JSONB
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public, pg_temp
AS $function$
DECLARE
    selected_task public.tasks%ROWTYPE;
    file_item JSONB;
    file_path TEXT;
    file_name TEXT;
    file_prefix TEXT;
    file_count INTEGER;
BEGIN
    IF (SELECT auth.uid()) IS NULL THEN
        RAISE EXCEPTION 'You must be logged in.';
    END IF;

    IF public.current_user_role() <> 'worker'
       OR NOT public.is_active_user() THEN
        RAISE EXCEPTION 'Only active workers can submit completed work.';
    END IF;

    IF p_message IS NULL
       OR char_length(trim(p_message)) NOT BETWEEN 1 AND 5000 THEN
        RAISE EXCEPTION 'Please provide a valid submission message.';
    END IF;

    IF p_files IS NULL
       OR jsonb_typeof(p_files) <> 'array' THEN
        RAISE EXCEPTION 'Invalid screenshot list.';
    END IF;

    file_count := jsonb_array_length(p_files);

    IF file_count < 1 OR file_count > 5 THEN
        RAISE EXCEPTION 'Upload between 1 and 5 screenshots.';
    END IF;

    -- Lock the task to prevent simultaneous duplicate submissions.
    SELECT *
    INTO selected_task
    FROM public.tasks
    WHERE id = p_task_id
      AND worker_id = (SELECT auth.uid())
    FOR UPDATE;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Task not found or not assigned to you.';
    END IF;

    IF selected_task.status <> 'in_progress' THEN
        RAISE EXCEPTION 'Only tasks in progress can be submitted.';
    END IF;

    -- Do not allow another submission while one is awaiting review.
    IF EXISTS (
        SELECT 1
        FROM public.task_submissions s
        WHERE s.task_id = p_task_id
          AND s.status = 'pending_review'
    ) THEN
        RAISE EXCEPTION 'Your previous submission is still awaiting review.';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM public.task_submissions
        WHERE id = p_submission_id
    ) THEN
        RAISE EXCEPTION 'Submission ID already exists.';
    END IF;

    file_prefix :=
        (SELECT auth.uid())::TEXT
        || '/' || p_task_id::TEXT
        || '/' || p_submission_id::TEXT
        || '/';

    -- Verify every screenshot was uploaded to the correct location.
    FOR file_item IN
        SELECT value
        FROM jsonb_array_elements(p_files)
    LOOP
        IF jsonb_typeof(file_item) <> 'object' THEN
            RAISE EXCEPTION 'Invalid screenshot information.';
        END IF;

        file_path := file_item->>'path';
        file_name := file_item->>'name';

        IF file_path IS NULL
           OR file_name IS NULL
           OR char_length(trim(file_name)) NOT BETWEEN 1 AND 255
           OR left(file_path, char_length(file_prefix)) <> file_prefix
           OR file_path <> file_prefix || file_name
           OR file_name LIKE '%/%'
           OR file_name LIKE '%\%' THEN
            RAISE EXCEPTION 'Invalid screenshot path or filename.';
        END IF;

        IF NOT EXISTS (
            SELECT 1
            FROM storage.objects o
            WHERE o.bucket_id = 'task-proofs'
              AND o.name = file_path
        ) THEN
            RAISE EXCEPTION 'A screenshot could not be found in Storage.';
        END IF;
    END LOOP;

    -- Create the submission.
    INSERT INTO public.task_submissions (
        id,
        task_id,
        worker_id,
        message
    )
    VALUES (
        p_submission_id,
        p_task_id,
        (SELECT auth.uid()),
        trim(p_message)
    );

    -- Record all uploaded screenshots.
    FOR file_item IN
        SELECT value
        FROM jsonb_array_elements(p_files)
    LOOP
        INSERT INTO public.task_submission_files (
            submission_id,
            file_path,
            file_name
        )
        VALUES (
            p_submission_id,
            file_item->>'path',
            file_item->>'name'
        );
    END LOOP;

    RETURN jsonb_build_object(
        'success', true,
        'submission_id', p_submission_id,
        'status', 'pending_review'
    );
END;
$function$;
-- 8. RPC: employer approves or requests revisions.
CREATE OR REPLACE FUNCTION public.review_task_submission(
    p_submission_id UUID,
    p_decision TEXT,
    p_feedback TEXT DEFAULT NULL
)
RETURNS JSONB
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public, pg_temp
AS $function$
DECLARE
    selected_submission public.task_submissions%ROWTYPE;
    selected_task public.tasks%ROWTYPE;
BEGIN
    IF (SELECT auth.uid()) IS NULL THEN
        RAISE EXCEPTION 'You must be logged in.';
    END IF;

    IF NOT public.is_active_user() THEN
        RAISE EXCEPTION 'Your account cannot perform this action.';
    END IF;

    IF p_decision NOT IN ('approved', 'revision_requested') THEN
        RAISE EXCEPTION 'Invalid review decision.';
    END IF;

    IF p_decision = 'revision_requested'
       AND (
           p_feedback IS NULL
           OR char_length(trim(p_feedback)) = 0
       ) THEN
        RAISE EXCEPTION 'Please provide revision instructions.';
    END IF;

    SELECT *
    INTO selected_submission
    FROM public.task_submissions
    WHERE id = p_submission_id
    FOR UPDATE;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Submission not found.';
    END IF;

    SELECT *
    INTO selected_task
    FROM public.tasks
    WHERE id = selected_submission.task_id
    FOR UPDATE;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Task not found.';
    END IF;

    -- Employers can review only their own tasks.
    IF public.current_user_role() = 'employer' THEN
        IF selected_task.employer_id <> (SELECT auth.uid()) THEN
            RAISE EXCEPTION 'You cannot review another employer''s task.';
        END IF;
    ELSIF public.current_user_role() <> 'admin' THEN
        RAISE EXCEPTION 'Only employers and admins can review submissions.';
    END IF;

    IF selected_submission.status <> 'pending_review' THEN
        RAISE EXCEPTION 'This submission has already been reviewed.';
    END IF;

    UPDATE public.task_submissions
    SET
        status = p_decision,
        reviewed_at = NOW(),
        reviewed_by = (SELECT auth.uid()),
        employer_feedback = NULLIF(trim(p_feedback), '')
    WHERE id = p_submission_id;

    IF p_decision = 'approved' THEN
        UPDATE public.tasks
        SET
            status = 'completed',
            completed_at = NOW(),
            updated_at = NOW()
        WHERE id = selected_task.id;
    ELSE
        UPDATE public.tasks
        SET
            status = 'in_progress',
            completed_at = NULL,
            updated_at = NOW()
        WHERE id = selected_task.id;
    END IF;

    RETURN jsonb_build_object(
        'success', true,
        'submission_status', p_decision,
        'task_status',
            CASE
                WHEN p_decision = 'approved'
                THEN 'completed'
                ELSE 'in_progress'
            END
    );
END;
$function$;
-- 9. Workers may start tasks but cannot directly complete them.
CREATE OR REPLACE FUNCTION public.update_task_status(
    p_task_id UUID,
    p_status TEXT
)
RETURNS JSONB
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public, pg_temp
AS $function$
DECLARE
    selected_task public.tasks%ROWTYPE;
BEGIN
    IF (SELECT auth.uid()) IS NULL THEN
        RAISE EXCEPTION 'You must be logged in.';
    END IF;

    IF public.current_user_role() <> 'worker'
       OR NOT public.is_active_user() THEN
        RAISE EXCEPTION 'Only active workers can update task progress.';
    END IF;

    -- Completion is now handled by employer approval.
    IF p_status <> 'in_progress' THEN
        RAISE EXCEPTION 'Workers must submit completed work for employer review.';
    END IF;

    SELECT *
    INTO selected_task
    FROM public.tasks
    WHERE id = p_task_id
      AND worker_id = (SELECT auth.uid())
    FOR UPDATE;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Task not found.';
    END IF;

    IF selected_task.status <> 'pending' THEN
        RAISE EXCEPTION 'This task cannot be started.';
    END IF;

    UPDATE public.tasks
    SET
        status = 'in_progress',
        completed_at = NULL,
        updated_at = NOW()
    WHERE id = p_task_id;

    RETURN jsonb_build_object(
        'success', true,
        'status', 'in_progress'
    );
END;
$function$;
-- 10. Restrict RPC execution.
REVOKE ALL ON FUNCTION public.submit_task_work(UUID, UUID, TEXT, JSONB)
FROM PUBLIC, anon;
GRANT EXECUTE ON FUNCTION public.submit_task_work(UUID, UUID, TEXT, JSONB)
TO authenticated;
REVOKE ALL ON FUNCTION public.review_task_submission(UUID, TEXT, TEXT)
FROM PUBLIC, anon;
GRANT EXECUTE ON FUNCTION public.review_task_submission(UUID, TEXT, TEXT)
TO authenticated;
REVOKE ALL ON FUNCTION public.update_task_status(UUID, TEXT)
FROM PUBLIC, anon;
GRANT EXECUTE ON FUNCTION public.update_task_status(UUID, TEXT)
TO authenticated;
