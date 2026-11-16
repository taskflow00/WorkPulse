-- Remove the old restrictive policy from profiles only.
DROP POLICY IF EXISTS active_account_required
ON public.profiles;
-- Allow users to read their own profile even when suspended.
CREATE POLICY active_account_read_required
ON public.profiles
AS RESTRICTIVE
FOR SELECT
TO authenticated
USING (
    public.is_active_user()
    OR id = (SELECT auth.uid())
);
-- Only active users can insert profiles.
CREATE POLICY active_account_insert_required
ON public.profiles
AS RESTRICTIVE
FOR INSERT
TO authenticated
WITH CHECK (
    public.is_active_user()
);
-- Only active users can update profiles.
CREATE POLICY active_account_update_required
ON public.profiles
AS RESTRICTIVE
FOR UPDATE
TO authenticated
USING (
    public.is_active_user()
)
WITH CHECK (
    public.is_active_user()
);
-- Only active users can delete profiles.
CREATE POLICY active_account_delete_required
ON public.profiles
AS RESTRICTIVE
FOR DELETE
TO authenticated
USING (
    public.is_active_user()
);
