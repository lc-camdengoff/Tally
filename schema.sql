-- Tally — its table, beside the accounts in the shared database.
--
-- Applied automatically by lib.php on first request; see tally_migrate().
-- Every statement must be safe to re-run.

-- Every change gets the next number, so a phone can ask for "everything since
-- the last one I saw" without trusting any clock.
CREATE SEQUENCE IF NOT EXISTS tally_rev;

-- What each person's phones have saved to their account: sessions with their
-- markers, and the folders they're grouped in. Kept as the app's own JSON, so
-- the app can change shape without a migration here. A deleted one stays as a
-- row with deleted = true and no data, so the deletion reaches their other
-- devices too.
CREATE TABLE IF NOT EXISTS tally_items (
    user_id    bigint      NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    -- 'session' or 'folder'. A key of TALLY_KINDS in lib.php.
    kind       text        NOT NULL,
    -- The app's own id for it, made on the phone.
    id         text        NOT NULL,
    data       jsonb,
    -- When it was last changed on whichever device changed it, in ms. The
    -- later change wins.
    updated    bigint      NOT NULL,
    deleted    boolean     NOT NULL DEFAULT false,
    rev        bigint      NOT NULL DEFAULT nextval('tally_rev'),
    saved_at   timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (user_id, kind, id)
);
CREATE INDEX IF NOT EXISTS tally_items_rev_idx ON tally_items (user_id, rev);
