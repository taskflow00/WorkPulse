
-- ==========================================
-- ADD USERNAME COLUMN
-- ==========================================

ALTER TABLE public.profiles
ADD COLUMN username text;


-- ==========================================
-- GENERATE USERNAMES FOR EXISTING ACCOUNTS
-- ==========================================

UPDATE public.profiles
SET username =
    COALESCE(
        NULLIF(
            LEFT(
                TRIM(
                    BOTH '_' FROM REGEXP_REPLACE(
                        LOWER(COALESCE(NULLIF(BTRIM(full_name), ''), 'user')),
                        '[^a-z0-9]+',
                        '_',
                        'g'
                    )
                ),
                17
            ),
            ''
        ),
        'user'
    )
    || '_' ||
    SUBSTRING(REPLACE(id::text, '-', ''), 1, 12);


-- ==========================================
-- REQUIRE A USERNAME
-- ==========================================

ALTER TABLE public.profiles
ALTER COLUMN username SET NOT NULL;


-- ==========================================
-- VALIDATE USERNAME FORMAT
-- 3-30 CHARACTERS
-- LOWERCASE LETTERS, NUMBERS, UNDERSCORES
-- ==========================================

ALTER TABLE public.profiles
ADD CONSTRAINT profiles_username_format_check
CHECK (username ~ '^[a-z0-9_]{3,30}$');


-- ==========================================
-- ENFORCE CASE-INSENSITIVE UNIQUENESS
-- ==========================================

CREATE UNIQUE INDEX profiles_username_lower_uidx
ON public.profiles (LOWER(username));


-- ==========================================
-- UPDATE AUTOMATIC PROFILE CREATION
-- ==========================================

CREATE OR REPLACE FUNCTION public.handle_new_user()
RETURNS trigger
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path TO 'public'
AS $function$
DECLARE
    new_username text;
BEGIN

    new_username := LOWER(
        TRIM(NEW.raw_user_meta_data ->> 'username')
    );

    IF new_username IS NULL OR new_username = '' THEN
        new_username := 'user_' ||
            SUBSTRING(REPLACE(NEW.id::text, '-', ''), 1, 12);
    END IF;

    INSERT INTO public.profiles (
        id,
        full_name,
        role,
        username
    )
    VALUES (
        NEW.id,
        COALESCE(NEW.raw_user_meta_data ->> 'full_name', 'New User'),
        CASE
            WHEN NEW.raw_user_meta_data ->> 'role' = 'employer'
                THEN 'employer'
            ELSE 'worker'
        END,
        new_username
    );

    RETURN NEW;

END;
$function$;
