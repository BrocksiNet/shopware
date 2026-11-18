-- MySQL compatibility objects for PostgreSQL.
-- Functions and operators that MySQL flavoured SQL in the core relies on and that can be provided inside the database.
-- Idempotent, apply with: psql -v ON_ERROR_STOP=1 -f mysql-compat.sql

-- HEX() / UNHEX() ---------------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION hex(uuid) RETURNS text LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
    RETURN upper(replace($1::text, '-', ''));
CREATE OR REPLACE FUNCTION hex(bytea) RETURNS text LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
    RETURN upper(encode($1, 'hex'));
CREATE OR REPLACE FUNCTION hex(text) RETURNS text LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
    RETURN upper(encode(convert_to($1, 'UTF8'), 'hex'));
CREATE OR REPLACE FUNCTION unhex(text) RETURNS bytea LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
    RETURN decode($1, 'hex');

-- UNHEX(...) is compared with and assigned to uuid columns
CREATE OR REPLACE FUNCTION mysql_compat_bytea_to_uuid(bytea) RETURNS uuid LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
    RETURN encode($1, 'hex')::uuid;
DO $$ BEGIN
    CREATE CAST (bytea AS uuid) WITH FUNCTION mysql_compat_bytea_to_uuid(bytea) AS IMPLICIT;
EXCEPTION WHEN duplicate_object THEN NULL; END $$;

-- IF() / IFNULL() ---------------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION "if"(boolean, anycompatible, anycompatible) RETURNS anycompatible LANGUAGE sql IMMUTABLE PARALLEL SAFE
    AS $$ SELECT CASE WHEN $1 THEN $2 ELSE $3 END $$;
CREATE OR REPLACE FUNCTION ifnull(anycompatible, anycompatible) RETURNS anycompatible LANGUAGE sql IMMUTABLE PARALLEL SAFE
    AS $$ SELECT COALESCE($1, $2) $$;

-- boolean compared with 0 / 1 -----------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION mysql_compat_bool_eq_int(boolean, integer) RETURNS boolean LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
    RETURN $1 = ($2 <> 0);
CREATE OR REPLACE FUNCTION mysql_compat_bool_ne_int(boolean, integer) RETURNS boolean LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
    RETURN $1 <> ($2 <> 0);
CREATE OR REPLACE FUNCTION mysql_compat_int_eq_bool(integer, boolean) RETURNS boolean LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
    RETURN $2 = ($1 <> 0);
DO $$ BEGIN
    CREATE OPERATOR = (LEFTARG = boolean, RIGHTARG = integer, FUNCTION = mysql_compat_bool_eq_int);
EXCEPTION WHEN duplicate_function THEN NULL; END $$;
DO $$ BEGIN
    CREATE OPERATOR <> (LEFTARG = boolean, RIGHTARG = integer, FUNCTION = mysql_compat_bool_ne_int);
EXCEPTION WHEN duplicate_function THEN NULL; END $$;
DO $$ BEGIN
    CREATE OPERATOR = (LEFTARG = integer, RIGHTARG = boolean, FUNCTION = mysql_compat_int_eq_bool);
EXCEPTION WHEN duplicate_function THEN NULL; END $$;

-- GROUP_CONCAT(expr [SEPARATOR sep]) -----------------------------------------------------------------------------------
-- The translator rewrites the SEPARATOR keyword to a comma, so the separator arrives as second argument.
CREATE OR REPLACE FUNCTION mysql_compat_group_concat_step(text, text, text) RETURNS text LANGUAGE sql IMMUTABLE PARALLEL SAFE
    RETURN CASE WHEN $2 IS NULL THEN $1 WHEN $1 IS NULL THEN $2 ELSE $1 || $3 || $2 END;
CREATE OR REPLACE FUNCTION mysql_compat_group_concat_step(text, text) RETURNS text LANGUAGE sql IMMUTABLE PARALLEL SAFE
    RETURN mysql_compat_group_concat_step($1, $2, ',');
CREATE OR REPLACE AGGREGATE group_concat(text) (SFUNC = mysql_compat_group_concat_step, STYPE = text);
CREATE OR REPLACE AGGREGATE group_concat(text, text) (SFUNC = mysql_compat_group_concat_step, STYPE = text);

-- MIN() / MAX() on ids -------------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION mysql_compat_uuid_smaller(uuid, uuid) RETURNS uuid LANGUAGE sql IMMUTABLE PARALLEL SAFE
    RETURN LEAST($1, $2);
CREATE OR REPLACE FUNCTION mysql_compat_uuid_larger(uuid, uuid) RETURNS uuid LANGUAGE sql IMMUTABLE PARALLEL SAFE
    RETURN GREATEST($1, $2);
CREATE OR REPLACE AGGREGATE min(uuid) (SFUNC = mysql_compat_uuid_smaller, STYPE = uuid, COMBINEFUNC = mysql_compat_uuid_smaller, PARALLEL = SAFE);
CREATE OR REPLACE AGGREGATE max(uuid) (SFUNC = mysql_compat_uuid_larger, STYPE = uuid, COMBINEFUNC = mysql_compat_uuid_larger, PARALLEL = SAFE);

-- JSON_EXTRACT() / JSON_UNQUOTE() ---------------------------------------------------------------------------------------
-- MySQL json paths like $.a.b[0] and $."a-b" are valid SQL/JSON path expressions.
CREATE OR REPLACE FUNCTION json_extract(jsonb, text) RETURNS jsonb LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
    RETURN jsonb_path_query_first($1, $2::jsonpath);
CREATE OR REPLACE FUNCTION json_extract(text, text) RETURNS jsonb LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
    RETURN jsonb_path_query_first($1::jsonb, $2::jsonpath);
CREATE OR REPLACE FUNCTION json_unquote(jsonb) RETURNS text LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
    RETURN $1 #>> '{}';
CREATE OR REPLACE FUNCTION json_unquote(text) RETURNS text LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
    RETURN CASE WHEN left($1, 1) = '"' THEN $1::jsonb #>> '{}' ELSE $1 END;

-- Implicit text to number conversion in arithmetic, e.g. JSON_UNQUOTE(...) * 1 ----------------------------------------
CREATE OR REPLACE FUNCTION mysql_compat_text_mul_int(text, integer) RETURNS numeric LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
    RETURN $1::numeric * $2;
DO $$ BEGIN
    CREATE OPERATOR * (LEFTARG = text, RIGHTARG = integer, FUNCTION = mysql_compat_text_mul_int);
EXCEPTION WHEN duplicate_function THEN NULL; END $$;
CREATE OR REPLACE FUNCTION mysql_compat_text_add_numeric(text, numeric) RETURNS numeric LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
    RETURN $1::numeric + $2;
DO $$ BEGIN
    CREATE OPERATOR + (LEFTARG = text, RIGHTARG = numeric, FUNCTION = mysql_compat_text_add_numeric);
EXCEPTION WHEN duplicate_function THEN NULL; END $$;

-- LOWER() on a JSON column returns the lower cased JSON text ---------------------------------------------------------------
CREATE OR REPLACE FUNCTION lower(jsonb) RETURNS text LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
    RETURN lower($1::text);

-- MIN() / MAX() / SUM() on booleans (TINYINT(1) on MySQL) -----------------------------------------------------------------
CREATE OR REPLACE FUNCTION mysql_compat_bool_sum_step(bigint, boolean) RETURNS bigint LANGUAGE sql IMMUTABLE PARALLEL SAFE
    RETURN CASE WHEN $2 IS NULL THEN $1 ELSE COALESCE($1, 0) + $2::int END;
CREATE OR REPLACE AGGREGATE max(boolean) (SFUNC = boolor_statefunc, STYPE = boolean);
CREATE OR REPLACE AGGREGATE min(boolean) (SFUNC = booland_statefunc, STYPE = boolean);
CREATE OR REPLACE AGGREGATE sum(boolean) (SFUNC = mysql_compat_bool_sum_step, STYPE = bigint);

-- SHA2(text, 256) ----------------------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION sha2(text, integer) RETURNS text LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
    RETURN CASE $2
        WHEN 224 THEN encode(sha224(convert_to($1, 'UTF8')), 'hex')
        WHEN 256 THEN encode(sha256(convert_to($1, 'UTF8')), 'hex')
        WHEN 384 THEN encode(sha384(convert_to($1, 'UTF8')), 'hex')
        WHEN 512 THEN encode(sha512(convert_to($1, 'UTF8')), 'hex')
    END;

-- JSON_QUOTE('a"b') => "a\"b" ----------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION json_quote(text) RETURNS text LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
    RETURN to_json($1)::text;

-- A JSON document built as text can be written to a jsonb column (UPDATE t SET json_column = CONCAT('[', ..., ']')) --------
DO $$ BEGIN
    CREATE CAST (text AS jsonb) WITH INOUT AS ASSIGNMENT;
EXCEPTION WHEN duplicate_object THEN NULL; END $$;

-- UPDATE t SET bool_column = 0 ---------------------------------------------------------------------------------------------
-- The built in integer to boolean cast is explicit only and a second cast for the same pair can not be created.
-- Changing the catalog needs a superuser and is not possible on most managed services, proof of concept only.
UPDATE pg_cast SET castcontext = 'a'
WHERE castsource = 'integer'::regtype AND casttarget = 'boolean'::regtype AND castcontext = 'e';
