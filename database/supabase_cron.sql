-- ==============================================================================
-- SolRecall • Supabase pg_cron & pg_net Automated Escrow Timer Setup
-- ==============================================================================
-- Run this script inside your Supabase Dashboard -> SQL Editor.
-- This attaches the 1-minute SolRecall escrow expiration & deposit watcher timer
-- directly to Supabase PostgreSQL, bypassing Vercel Hobby plan cron limits.
-- ==============================================================================

-- 1. Enable required PostgreSQL extensions in Supabase
CREATE EXTENSION IF NOT EXISTS pg_cron WITH SCHEMA extensions;
CREATE EXTENSION IF NOT EXISTS pg_net WITH SCHEMA extensions;

-- Grant permissions if necessary
GRANT USAGE ON SCHEMA cron TO postgres;
GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA cron TO postgres;

-- 2. Clean up any existing SolRecall cron job to prevent duplicates
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM cron.job WHERE jobname = 'solrecall-escrow-timer') THEN
        PERFORM cron.unschedule('solrecall-escrow-timer');
    END IF;
END $$;

-- 3. Schedule the SolRecall Escrow & Deposit Processor to run EVERY MINUTE (* * * * *)
-- Authenticates with CRON_SECRET via both Authorization Bearer header and ?secret= query param
-- to guarantee 100% authorization across all Vercel edge/serverless proxies.
SELECT cron.schedule(
    'solrecall-escrow-timer',
    '* * * * *', -- Runs every 60 seconds natively inside Supabase
    $$
    SELECT net.http_get(
        url := 'https://solrecall.vercel.app/api/cron?secret=7cc41736a91fb48ef414e7453c151b14cc03c829230842e7f68624ae0996636b',
        headers := jsonb_build_object(
            'Content-Type', 'application/json',
            'User-Agent', 'Supabase-pg_cron/SolRecall',
            'Authorization', 'Bearer 7cc41736a91fb48ef414e7453c151b14cc03c829230842e7f68624ae0996636b'
        )
    );
    $$
);

-- ==============================================================================
-- Verification Queries (Run anytime to verify status in Supabase SQL Editor)
-- ==============================================================================

-- Check registered cron jobs:
-- SELECT jobid, jobname, schedule, command, active FROM cron.job;

-- Check execution logs:
-- SELECT * FROM cron.job_run_details ORDER BY start_time DESC LIMIT 10;
