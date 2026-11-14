CREATE OR REPLACE VIEW public.earnings_records
WITH (security_invoker = true)
AS
SELECT
    t.id AS timesheet_id,
    t.employment_id,
    t.worker_id,
    t.month,
    t.status AS timesheet_status,

    t.total_working_seconds,
    t.total_idle_seconds,

    e.employer_id,
    e.hourly_rate,

    ROUND(
        (t.total_working_seconds::NUMERIC / 3600)
        * e.hourly_rate,
        2
    ) AS earnings_amount,

    t.submitted_at,
    t.reviewed_at,
    t.employer_comment

FROM public.timesheets t

INNER JOIN public.employments e
    ON t.employment_id = e.id;

GRANT SELECT ON public.earnings_records TO authenticated;