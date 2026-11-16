-- ==========================================
-- WORKPULSE AVATARS STORAGE
-- ==========================================

-- Create the avatars bucket
-- Public viewing, private upload permissions
INSERT INTO storage.buckets (
    id,
    name,
    public,
    file_size_limit,
    allowed_mime_types
)
VALUES (
    'avatars',
    'avatars',
    true,
    2097152,
    ARRAY[
        'image/jpeg',
        'image/png',
        'image/webp'
    ]
)
ON CONFLICT (id)
DO UPDATE SET
    public = EXCLUDED.public,
    file_size_limit = EXCLUDED.file_size_limit,
    allowed_mime_types = EXCLUDED.allowed_mime_types;
-- ==========================================
-- WORKER AVATAR POLICIES
-- ==========================================

-- Workers can upload their own profile pictures
CREATE POLICY "Workers can upload own avatars"
ON storage.objects
FOR INSERT
TO authenticated
WITH CHECK (
    bucket_id = 'avatars'
    AND (storage.foldername(name))[1] = auth.uid()::text
    AND current_user_role() = 'worker'
    AND is_active_user()
);
-- Workers can view their own avatar objects
-- Public visitors can still view images through public URLs
CREATE POLICY "Workers can view own avatars"
ON storage.objects
FOR SELECT
TO authenticated
USING (
    bucket_id = 'avatars'
    AND (storage.foldername(name))[1] = auth.uid()::text
);
-- Workers can replace their own profile pictures
CREATE POLICY "Workers can update own avatars"
ON storage.objects
FOR UPDATE
TO authenticated
USING (
    bucket_id = 'avatars'
    AND (storage.foldername(name))[1] = auth.uid()::text
    AND current_user_role() = 'worker'
    AND is_active_user()
)
WITH CHECK (
    bucket_id = 'avatars'
    AND (storage.foldername(name))[1] = auth.uid()::text
    AND current_user_role() = 'worker'
    AND is_active_user()
);
-- Workers can delete their own profile pictures
CREATE POLICY "Workers can delete own avatars"
ON storage.objects
FOR DELETE
TO authenticated
USING (
    bucket_id = 'avatars'
    AND (storage.foldername(name))[1] = auth.uid()::text
    AND current_user_role() = 'worker'
    AND is_active_user()
);
