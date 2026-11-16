-- Admins can view all daily time proofs.
CREATE POLICY "Admins can view all daily time proofs"
ON public.daily_time_proofs
FOR SELECT
TO authenticated
USING (
    public.current_user_role() = 'admin'
);


-- Admins can view all timesheets.
CREATE POLICY "Admins can view all timesheets"
ON public.timesheets
FOR SELECT
TO authenticated
USING (
    public.current_user_role() = 'admin'
);


-- Admins can view all timesheet entries.
CREATE POLICY "Admins can view all timesheet entries"
ON public.timesheet_entries
FOR SELECT
TO authenticated
USING (
    public.current_user_role() = 'admin'
);