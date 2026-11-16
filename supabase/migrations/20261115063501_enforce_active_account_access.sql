
-- Check whether the currently authenticated user is allowed access.
CREATE OR REPLACE FUNCTION public.is_active_user()
RETURNS BOOLEAN
LANGUAGE SQL
STABLE
SECURITY DEFINER
SET search_path = ''
AS $$
    SELECT EXISTS (
        SELECT 1
        FROM public.profiles
        WHERE id = (SELECT auth.uid())
        AND (
            account_status = 'active'
            OR (
                account_status = 'suspended'
                AND restricted_until IS NOT NULL
                AND restricted_until <= now()
            )
        )
    );
$$;

-- Allow authenticated users to use the access-check function.
GRANT EXECUTE ON FUNCTION public.is_active_user()
TO authenticated;


-- Apply an additional restrictive policy to WorkPulse tables.
DO $$
DECLARE
    table_name TEXT;
BEGIN
    FOREACH table_name IN ARRAY ARRAY[
        'profiles',
        'jobs',
        'applications',
        'invitations',
        'employment_offers',
        'employments',
        'tasks',
        'time_tracking_sessions',
        'timesheets',
        'timesheet_entries',
        'daily_time_proofs'
    ]
    LOOP

        IF to_regclass('public.' || table_name) IS NOT NULL THEN

            EXECUTE format(
                'ALTER TABLE public.%I ENABLE ROW LEVEL SECURITY',
                table_name
            );

            EXECUTE format(
                'DROP POLICY IF EXISTS active_account_required ON public.%I',
                table_name
            );

            EXECUTE format(
                'CREATE POLICY active_account_required
                 ON public.%I
                 AS RESTRICTIVE
                 FOR ALL
                 TO authenticated
                 USING (public.is_active_user())
                 WITH CHECK (public.is_active_user())',
                table_name
            );

        END IF;

    END LOOP;
END;
$$;


-- Also protect WorkPulse private storage buckets.
DROP POLICY IF EXISTS active_account_required_for_workpulse_storage
ON storage.objects;

CREATE POLICY active_account_required_for_workpulse_storage
ON storage.objects
AS RESTRICTIVE
FOR ALL
TO authenticated
USING (
    bucket_id NOT IN ('resumes', 'daily-time-proofs')
    OR public.is_active_user()
)
WITH CHECK (
    bucket_id NOT IN ('resumes', 'daily-time-proofs')
    OR public.is_active_user()
);
