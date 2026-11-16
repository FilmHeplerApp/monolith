CREATE EXTENSION IF NOT EXISTS vector;

-- Separate database for PostgreSQL-backed tests (see the pgsql_testing connection).
SELECT 'CREATE DATABASE filmhelper_test'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'filmhelper_test')\gexec

\connect filmhelper_test
CREATE EXTENSION IF NOT EXISTS vector;
