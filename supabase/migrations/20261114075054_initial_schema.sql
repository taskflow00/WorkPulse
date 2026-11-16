-- ==========================================
-- WORKPULSE: INITIAL DATABASE STRUCTURE
-- ==========================================

-- 1. USER PROFILES
-- Every registered Supabase Auth user gets a profile.

CREATE TABLE public.profiles (
    id UUID PRIMARY KEY REFERENCES auth.users(id) ON DELETE CASCADE,
    full_name TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'worker'
        CHECK (role IN ('worker', 'employer', 'admin')),

    avatar_url TEXT,
    bio TEXT,
    skills TEXT[],
    experience TEXT,
    education TEXT,
    resume_url TEXT,
    portfolio_url TEXT,
    preferred_hourly_rate NUMERIC(10,2),

    company_name TEXT,
    company_description TEXT,

    created_at TIMESTAMPTZ DEFAULT NOW()
);
-- 2. JOB LISTINGS
-- Employers publish remote job opportunities.

CREATE TABLE public.jobs (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    employer_id UUID NOT NULL REFERENCES public.profiles(id) ON DELETE CASCADE,

    title TEXT NOT NULL,
    company_name TEXT NOT NULL,
    description TEXT NOT NULL,
    requirements TEXT,
    required_skills TEXT[],

    hourly_rate NUMERIC(10,2) NOT NULL CHECK (hourly_rate > 0),
    employment_type TEXT NOT NULL
        CHECK (employment_type IN ('full_time', 'part_time')),

    work_schedule TEXT,
    interview_required BOOLEAN DEFAULT TRUE,

    status TEXT NOT NULL DEFAULT 'open'
        CHECK (status IN ('open', 'closed')),

    created_at TIMESTAMPTZ DEFAULT NOW()
);
-- 3. JOB APPLICATIONS
-- Workers apply for available jobs.

CREATE TABLE public.applications (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    job_id UUID NOT NULL REFERENCES public.jobs(id) ON DELETE CASCADE,
    worker_id UUID NOT NULL REFERENCES public.profiles(id) ON DELETE CASCADE,

    cover_letter TEXT,
    status TEXT NOT NULL DEFAULT 'pending'
        CHECK (status IN (
            'pending', 'shortlisted', 'interview',
            'accepted', 'rejected', 'withdrawn'
        )),

    applied_at TIMESTAMPTZ DEFAULT NOW(),

    UNIQUE (job_id, worker_id)
);
-- 4. EMPLOYER INVITATIONS
-- Employers can invite workers to apply for a job.

CREATE TABLE public.invitations (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    job_id UUID NOT NULL REFERENCES public.jobs(id) ON DELETE CASCADE,
    employer_id UUID NOT NULL REFERENCES public.profiles(id) ON DELETE CASCADE,
    worker_id UUID NOT NULL REFERENCES public.profiles(id) ON DELETE CASCADE,

    message TEXT,
    status TEXT NOT NULL DEFAULT 'pending'
        CHECK (status IN ('pending', 'accepted', 'declined')),

    created_at TIMESTAMPTZ DEFAULT NOW(),

    UNIQUE (job_id, worker_id)
);
-- ==========================================
-- AUTOMATIC PROFILE CREATION
-- ==========================================

-- Create a profile whenever someone registers.
-- Public registration can only create worker/employer accounts.
-- Admin accounts will be assigned manually.

CREATE OR REPLACE FUNCTION public.handle_new_user()
RETURNS TRIGGER
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public
AS $$
BEGIN
    INSERT INTO public.profiles (id, full_name, role)
    VALUES (
        NEW.id,
        COALESCE(NEW.raw_user_meta_data ->> 'full_name', 'New User'),
        CASE
            WHEN NEW.raw_user_meta_data ->> 'role' = 'employer'
                THEN 'employer'
            ELSE 'worker'
        END
    );

    RETURN NEW;
END;
$$;
CREATE TRIGGER on_auth_user_created
AFTER INSERT ON auth.users
FOR EACH ROW
EXECUTE FUNCTION public.handle_new_user();
-- ==========================================
-- USER ROLE HELPER
-- ==========================================

-- Used by security policies to identify the
-- currently logged-in user's role.

CREATE OR REPLACE FUNCTION public.current_user_role()
RETURNS TEXT
LANGUAGE SQL
STABLE
SECURITY DEFINER
SET search_path = public
AS $$
    SELECT role
    FROM public.profiles
    WHERE id = auth.uid();
$$;
REVOKE ALL ON FUNCTION public.current_user_role() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.current_user_role() TO authenticated;
-- Prevent users from changing their own role.

CREATE OR REPLACE FUNCTION public.prevent_profile_role_change()
RETURNS TRIGGER
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public
AS $$
BEGIN
    IF NEW.role IS DISTINCT FROM OLD.role
       AND public.current_user_role() IS DISTINCT FROM 'admin' THEN
        RAISE EXCEPTION 'You cannot change your own user role.';
    END IF;

    RETURN NEW;
END;
$$;
CREATE TRIGGER protect_profile_role
BEFORE UPDATE ON public.profiles
FOR EACH ROW
EXECUTE FUNCTION public.prevent_profile_role_change();
-- ==========================================
-- ENABLE ROW LEVEL SECURITY
-- ==========================================

ALTER TABLE public.profiles ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.jobs ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.applications ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.invitations ENABLE ROW LEVEL SECURITY;
-- ==========================================
-- PROFILE POLICIES
-- ==========================================

-- Users can view their own profile.
-- Employers can view worker profiles.
-- Admins can view all profiles.

CREATE POLICY "View authorized profiles"
ON public.profiles
FOR SELECT
TO authenticated
USING (
    id = auth.uid()
    OR (
        public.current_user_role() = 'employer'
        AND role = 'worker'
    )
    OR public.current_user_role() = 'admin'
);
-- Users can update their own profile.
-- Admins can update any profile.

CREATE POLICY "Update own profile or admin"
ON public.profiles
FOR UPDATE
TO authenticated
USING (
    id = auth.uid()
    OR public.current_user_role() = 'admin'
)
WITH CHECK (
    id = auth.uid()
    OR public.current_user_role() = 'admin'
);
-- ==========================================
-- JOB POLICIES
-- ==========================================

-- Authenticated users can see open jobs.
-- Employers can also see their own closed jobs.
-- Admins can see all jobs.

CREATE POLICY "View available jobs"
ON public.jobs
FOR SELECT
TO authenticated
USING (
    status = 'open'
    OR employer_id = auth.uid()
    OR public.current_user_role() = 'admin'
);
-- Only employers can publish their own jobs.

CREATE POLICY "Employers create jobs"
ON public.jobs
FOR INSERT
TO authenticated
WITH CHECK (
    employer_id = auth.uid()
    AND public.current_user_role() = 'employer'
);
-- Employers manage their own listings.
-- Admins can manage all listings.

CREATE POLICY "Employers update jobs"
ON public.jobs
FOR UPDATE
TO authenticated
USING (
    employer_id = auth.uid()
    OR public.current_user_role() = 'admin'
)
WITH CHECK (
    employer_id = auth.uid()
    OR public.current_user_role() = 'admin'
);
CREATE POLICY "Employers delete jobs"
ON public.jobs
FOR DELETE
TO authenticated
USING (
    employer_id = auth.uid()
    OR public.current_user_role() = 'admin'
);
-- ==========================================
-- APPLICATION POLICIES
-- ==========================================

-- Workers see their own applications.
-- Employers see applications for their own jobs.
-- Admins see all applications.

CREATE POLICY "View authorized applications"
ON public.applications
FOR SELECT
TO authenticated
USING (
    worker_id = auth.uid()
    OR EXISTS (
        SELECT 1
        FROM public.jobs
        WHERE jobs.id = applications.job_id
        AND jobs.employer_id = auth.uid()
    )
    OR public.current_user_role() = 'admin'
);
-- Only workers can apply for jobs.

CREATE POLICY "Workers submit applications"
ON public.applications
FOR INSERT
TO authenticated
WITH CHECK (
    worker_id = auth.uid()
    AND public.current_user_role() = 'worker'
);
-- Employers can update applications for their jobs.
-- Admins can update all applications.

CREATE POLICY "Employers manage applications"
ON public.applications
FOR UPDATE
TO authenticated
USING (
    EXISTS (
        SELECT 1
        FROM public.jobs
        WHERE jobs.id = applications.job_id
        AND jobs.employer_id = auth.uid()
    )
    OR public.current_user_role() = 'admin'
)
WITH CHECK (
    EXISTS (
        SELECT 1
        FROM public.jobs
        WHERE jobs.id = applications.job_id
        AND jobs.employer_id = auth.uid()
    )
    OR public.current_user_role() = 'admin'
);
-- ==========================================
-- INVITATION POLICIES
-- ==========================================

-- Employers and invited workers can see their invitations.
-- Admins can see all invitations.

CREATE POLICY "View authorized invitations"
ON public.invitations
FOR SELECT
TO authenticated
USING (
    employer_id = auth.uid()
    OR worker_id = auth.uid()
    OR public.current_user_role() = 'admin'
);
-- Only employers can send invitations for their own jobs.

CREATE POLICY "Employers send invitations"
ON public.invitations
FOR INSERT
TO authenticated
WITH CHECK (
    employer_id = auth.uid()
    AND public.current_user_role() = 'employer'
    AND EXISTS (
        SELECT 1
        FROM public.jobs
        WHERE jobs.id = invitations.job_id
        AND jobs.employer_id = auth.uid()
    )
);
-- Invited workers can respond to their invitations.

CREATE POLICY "Workers respond to invitations"
ON public.invitations
FOR UPDATE
TO authenticated
USING (worker_id = auth.uid())
WITH CHECK (worker_id = auth.uid());
-- ==========================================
-- INDEXES
-- ==========================================

CREATE INDEX idx_jobs_employer ON public.jobs(employer_id);
CREATE INDEX idx_jobs_status ON public.jobs(status);
CREATE INDEX idx_applications_worker ON public.applications(worker_id);
CREATE INDEX idx_applications_job ON public.applications(job_id);
CREATE INDEX idx_invitations_worker ON public.invitations(worker_id);
