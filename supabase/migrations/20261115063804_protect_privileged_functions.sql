
-- Prevent inactive accounts from modifying protected records.
CREATE OR REPLACE FUNCTION public.block_inactive_account_writes()
RETURNS TRIGGER
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = ''
AS $$
BEGIN
    -- Allow trusted server-side operations without a user JWT.
    IF auth.uid() IS NOT NULL
       AND NOT public.is_active_user() THEN
        RAISE EXCEPTION 'Your account is not active.'
            USING ERRCODE = '42501';
    END IF;

    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;

    RETURN NEW;
END;
$$;


-- Protect tables modified by privileged functions.
DO $$
DECLARE
    table_name TEXT;
BEGIN
    FOREACH table_name IN ARRAY ARRAY[
        'employment_offers',
        'employments',
        'tasks',
        'time_tracking_sessions',
        'timesheets',
        'timesheet_entries'
    ]
    LOOP
        EXECUTE format(
            'DROP TRIGGER IF EXISTS active_account_write_guard ON public.%I',
            table_name
        );

        EXECUTE format(
            'CREATE TRIGGER active_account_write_guard
             BEFORE INSERT OR UPDATE OR DELETE
             ON public.%I
             FOR EACH ROW
             EXECUTE FUNCTION public.block_inactive_account_writes()',
            table_name
        );
    END LOOP;
END;
$$;


-- Remove public/anonymous access to user-facing RPC functions.
-- Keep these functions available to authenticated users.
REVOKE EXECUTE ON FUNCTION public.mark_time_tracking_idle()
FROM PUBLIC, anon;
GRANT EXECUTE ON FUNCTION public.mark_time_tracking_idle()
TO authenticated;

REVOKE EXECUTE ON FUNCTION public.pause_time_tracking()
FROM PUBLIC, anon;
GRANT EXECUTE ON FUNCTION public.pause_time_tracking()
TO authenticated;

REVOKE EXECUTE ON FUNCTION public.record_time_tracking_activity()
FROM PUBLIC, anon;
GRANT EXECUTE ON FUNCTION public.record_time_tracking_activity()
TO authenticated;

REVOKE EXECUTE ON FUNCTION public.respond_to_employment_offer(uuid, text)
FROM PUBLIC, anon;
GRANT EXECUTE ON FUNCTION public.respond_to_employment_offer(uuid, text)
TO authenticated;

REVOKE EXECUTE ON FUNCTION public.resume_time_tracking()
FROM PUBLIC, anon;
GRANT EXECUTE ON FUNCTION public.resume_time_tracking()
TO authenticated;

REVOKE EXECUTE ON FUNCTION public.review_monthly_timesheet(uuid, text, text)
FROM PUBLIC, anon;
GRANT EXECUTE ON FUNCTION public.review_monthly_timesheet(uuid, text, text)
TO authenticated;

REVOKE EXECUTE ON FUNCTION public.rollover_time_tracking_session()
FROM PUBLIC, anon;
GRANT EXECUTE ON FUNCTION public.rollover_time_tracking_session()
TO authenticated;

REVOKE EXECUTE ON FUNCTION public.start_time_tracking(uuid, uuid)
FROM PUBLIC, anon;
GRANT EXECUTE ON FUNCTION public.start_time_tracking(uuid, uuid)
TO authenticated;

REVOKE EXECUTE ON FUNCTION public.stop_time_tracking()
FROM PUBLIC, anon;
GRANT EXECUTE ON FUNCTION public.stop_time_tracking()
TO authenticated;

REVOKE EXECUTE ON FUNCTION public.submit_monthly_timesheet(uuid, date)
FROM PUBLIC, anon;
GRANT EXECUTE ON FUNCTION public.submit_monthly_timesheet(uuid, date)
TO authenticated;

REVOKE EXECUTE ON FUNCTION public.update_task_status(uuid, text)
FROM PUBLIC, anon;
GRANT EXECUTE ON FUNCTION public.update_task_status(uuid, text)
TO authenticated;
