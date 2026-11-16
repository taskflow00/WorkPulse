CREATE TABLE public.workpulse_sessions (
    id text PRIMARY KEY,
    data text NOT NULL DEFAULT '',
    expires_at timestamptz NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
  );

  CREATE INDEX workpulse_sessions_expires_at_idx
    ON public.workpulse_sessions (expires_at);

  ALTER TABLE public.workpulse_sessions ENABLE ROW LEVEL SECURITY;

  REVOKE ALL ON TABLE public.workpulse_sessions FROM PUBLIC, anon, authenticated;
  GRANT ALL ON TABLE public.workpulse_sessions TO service_role;;
