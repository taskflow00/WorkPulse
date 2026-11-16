-- Store daily screenshots submitted by workers.
create table public.daily_time_proofs (
    id uuid primary key default gen_random_uuid(),

    employment_id uuid not null
        references public.employments(id) on delete cascade,

    worker_id uuid not null
        references auth.users(id) on delete cascade,

    work_date date not null,

    screenshot_path text not null unique,

    worker_note text,

    status text not null default 'pending'
        check (status in ('pending', 'reviewed', 'needs_clarification')),

    employer_comment text,

    submitted_at timestamptz not null default now(),

    reviewed_at timestamptz,

    reviewed_by uuid references auth.users(id),

    created_at timestamptz not null default now(),

    -- Make sure the screenshot belongs to the correct worker and employment.
    constraint daily_time_proofs_path_check check (
        split_part(screenshot_path, '/', 1) = worker_id::text
        and split_part(screenshot_path, '/', 2) = employment_id::text
    ),

    -- Pending proofs must not have review information.
    constraint daily_time_proofs_review_check check (
        (
            status = 'pending'
            and reviewed_at is null
            and reviewed_by is null
        )
        or
        (
            status in ('reviewed', 'needs_clarification')
            and reviewed_at is not null
            and reviewed_by is not null
        )
    )
);
-- Make daily proof retrieval faster.
create index daily_time_proofs_employment_date_idx
on public.daily_time_proofs (employment_id, work_date desc);
create index daily_time_proofs_worker_date_idx
on public.daily_time_proofs (worker_id, work_date desc);
-- Enable Row Level Security.
alter table public.daily_time_proofs enable row level security;
-- Allow users to retrieve permitted proof records.
grant select on public.daily_time_proofs to authenticated;
-- Workers can only insert their own proof information.
grant insert (
    employment_id,
    worker_id,
    work_date,
    screenshot_path,
    worker_note
)
on public.daily_time_proofs to authenticated;
-- Employers can only change review-related fields.
grant update (
    status,
    employer_comment,
    reviewed_at,
    reviewed_by
)
on public.daily_time_proofs to authenticated;
-- Workers can view their own proofs.
create policy "Workers can view own daily proofs"
on public.daily_time_proofs
for select
to authenticated
using (
    worker_id = (select auth.uid())
);
-- Employers can view proofs for their own workers.
create policy "Employers can view their workers daily proofs"
on public.daily_time_proofs
for select
to authenticated
using (
    exists (
        select 1
        from public.employments e
        where e.id = daily_time_proofs.employment_id
        and e.employer_id = (select auth.uid())
    )
);
-- Workers can submit proof for their own employment.
create policy "Workers can submit own daily proofs"
on public.daily_time_proofs
for insert
to authenticated
with check (
    worker_id = (select auth.uid())
    and status = 'pending'
    and reviewed_at is null
    and reviewed_by is null
    and employer_comment is null
    and work_date <= (now() at time zone 'Asia/Manila')::date
    and exists (
        select 1
        from public.employments e
        where e.id = employment_id
        and e.worker_id = (select auth.uid())
        and work_date >= (e.started_at at time zone 'Asia/Manila')::date
        and (
            e.ended_at is null
            or work_date <= (e.ended_at at time zone 'Asia/Manila')::date
        )
    )
);
-- Employers can review proofs belonging to their employments.
create policy "Employers can review their workers daily proofs"
on public.daily_time_proofs
for update
to authenticated
using (
    exists (
        select 1
        from public.employments e
        where e.id = daily_time_proofs.employment_id
        and e.employer_id = (select auth.uid())
    )
)
with check (
    status in ('reviewed', 'needs_clarification')
    and reviewed_at is not null
    and reviewed_by = (select auth.uid())
    and exists (
        select 1
        from public.employments e
        where e.id = daily_time_proofs.employment_id
        and e.employer_id = (select auth.uid())
        and e.worker_id = daily_time_proofs.worker_id
    )
);
-- Create a private bucket for time-proof screenshots.
insert into storage.buckets (
    id,
    name,
    public,
    file_size_limit,
    allowed_mime_types
)
values (
    'daily-time-proofs',
    'daily-time-proofs',
    false,
    5242880,
    array['image/png', 'image/jpeg', 'image/webp']
)
on conflict (id) do update
set
    public = false,
    file_size_limit = 5242880,
    allowed_mime_types = array['image/png', 'image/jpeg', 'image/webp'];
-- Workers can upload screenshots only under their own worker/employment folders.
create policy "Workers can upload own time proof screenshots"
on storage.objects
for insert
to authenticated
with check (
    bucket_id = 'daily-time-proofs'
    and (storage.foldername(name))[1] = (select auth.uid())::text
    and exists (
        select 1
        from public.employments e
        where e.id::text = (storage.foldername(name))[2]
        and e.worker_id = (select auth.uid())
    )
);
-- Workers can view their own screenshots.
create policy "Workers can view own time proof screenshots"
on storage.objects
for select
to authenticated
using (
    bucket_id = 'daily-time-proofs'
    and (storage.foldername(name))[1] = (select auth.uid())::text
);
-- Employers can view screenshots attached to their workers' proof records.
create policy "Employers can view workers time proof screenshots"
on storage.objects
for select
to authenticated
using (
    bucket_id = 'daily-time-proofs'
    and exists (
        select 1
        from public.daily_time_proofs p
        join public.employments e
            on e.id = p.employment_id
        where p.screenshot_path = storage.objects.name
        and e.employer_id = (select auth.uid())
    )
);
-- Allow workers to clean up an uploaded file if saving its database record fails.
create policy "Workers can delete unsubmitted time proof screenshots"
on storage.objects
for delete
to authenticated
using (
    bucket_id = 'daily-time-proofs'
    and (storage.foldername(name))[1] = (select auth.uid())::text
    and not exists (
        select 1
        from public.daily_time_proofs p
        where p.screenshot_path = storage.objects.name
    )
);
