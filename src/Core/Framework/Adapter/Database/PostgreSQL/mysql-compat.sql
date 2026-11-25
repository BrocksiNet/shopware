-- MySQL compatibility objects for PostgreSQL.
-- Functions and operators that MySQL flavoured SQL in the core relies on and that can be provided inside the database.
-- Idempotent, apply with: psql -v ON_ERROR_STOP=1 -f mysql-compat.sql

-- Collation ---------------------------------------------------------------------------------------------------------
-- utf8mb4_unicode_ci: case and accent insensitive comparison, DdlRewriter maps every such string column to COLLATE "ci"
CREATE COLLATION IF NOT EXISTS ci (provider = icu, locale = 'und-u-ks-level1', deterministic = false);

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
-- GROUP_CONCAT(DISTINCT JSON_OBJECT(...)) (SqlHelper::objectArray, OpenSearch product indexing) aggregates jsonb values.
CREATE OR REPLACE FUNCTION mysql_compat_group_concat_step(text, jsonb, text) RETURNS text LANGUAGE sql IMMUTABLE PARALLEL SAFE
    RETURN mysql_compat_group_concat_step($1, $2::text, $3);
CREATE OR REPLACE FUNCTION mysql_compat_group_concat_step(text, jsonb) RETURNS text LANGUAGE sql IMMUTABLE PARALLEL SAFE
    RETURN mysql_compat_group_concat_step($1, $2::text, ',');
CREATE OR REPLACE AGGREGATE group_concat(jsonb) (SFUNC = mysql_compat_group_concat_step, STYPE = text);
CREATE OR REPLACE AGGREGATE group_concat(jsonb, text) (SFUNC = mysql_compat_group_concat_step, STYPE = text);

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

-- JSON_CONTAINS(doc, candidate) --------------------------------------------------------------------------------------------
-- Only the two argument form (containment), the path argument is not supported.
CREATE OR REPLACE FUNCTION json_contains(jsonb, jsonb) RETURNS boolean LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
    AS $$ SELECT $1 @> $2 $$;
CREATE OR REPLACE FUNCTION json_contains(jsonb, json) RETURNS boolean LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
    AS $$ SELECT $1 @> $2::jsonb $$;
CREATE OR REPLACE FUNCTION json_contains(text, text) RETURNS boolean LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
    AS $$ SELECT $1::jsonb @> $2::jsonb $$;

-- DATE_FORMAT(date, format) ------------------------------------------------------------------------------------------------
-- Translates the common MySQL specifiers to to_char patterns, unknown specifiers are kept as literal text.
CREATE OR REPLACE FUNCTION date_format(timestamp, text) RETURNS text LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
    AS $$
    SELECT to_char($1, string_agg(
        CASE WHEN t[1] IS NOT NULL THEN
            CASE t[1] WHEN 'Y' THEN 'YYYY' WHEN 'y' THEN 'YY' WHEN 'm' THEN 'MM' WHEN 'c' THEN 'FMMM' WHEN 'd' THEN 'DD'
                      WHEN 'e' THEN 'FMDD' WHEN 'H' THEN 'HH24' WHEN 'k' THEN 'FMHH24' WHEN 'h' THEN 'HH12' WHEN 'I' THEN 'HH12'
                      WHEN 'l' THEN 'FMHH12' WHEN 'i' THEN 'MI' WHEN 's' THEN 'SS' WHEN 'S' THEN 'SS' WHEN 'f' THEN 'US'
                      WHEN 'M' THEN 'FMMonth' WHEN 'b' THEN 'Mon' WHEN 'W' THEN 'FMDay' WHEN 'a' THEN 'Dy' WHEN 'j' THEN 'DDD'
                      WHEN 'p' THEN 'AM' WHEN 'T' THEN 'HH24:MI:SS' WHEN 'r' THEN 'HH12:MI:SS AM' WHEN 'U' THEN 'WW'
                      WHEN 'v' THEN 'IW' WHEN 'x' THEN 'IYYY' WHEN '%' THEN '%'
                      ELSE '"%' || t[1] || '"' END
        ELSE '"' || replace(t[2], '"', '') || '"' END, '') )
    FROM regexp_matches($2, '%(.)|([^%]+)', 'g') AS t
    $$;
CREATE OR REPLACE FUNCTION date_format(timestamptz, text) RETURNS text LANGUAGE sql STABLE STRICT PARALLEL SAFE
    AS $$ SELECT date_format($1::timestamp, $2) $$;
CREATE OR REPLACE FUNCTION date_format(text, text) RETURNS text LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
    AS $$ SELECT date_format($1::timestamp, $2) $$;

-- LENGTH(datetime_column), used by the natural sorting of the DAL -----------------------------------------------------------
CREATE OR REPLACE FUNCTION length(timestamp) RETURNS integer LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
    AS $$ SELECT length($1::text) $$;
CREATE OR REPLACE FUNCTION length(integer) RETURNS integer LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
    AS $$ SELECT length($1::text) $$;
CREATE OR REPLACE FUNCTION length(bigint) RETURNS integer LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
    AS $$ SELECT length($1::text) $$;
CREATE OR REPLACE FUNCTION length(numeric) RETURNS integer LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
    AS $$ SELECT length($1::text) $$;

-- JSON_TYPE(doc) -----------------------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION json_type(jsonb) RETURNS text LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
    AS $$ SELECT CASE jsonb_typeof($1)
        WHEN 'number' THEN CASE WHEN $1::text ~ '[.eE]' THEN 'DOUBLE' ELSE 'INTEGER' END
        ELSE upper(jsonb_typeof($1)) END $$;
CREATE OR REPLACE FUNCTION json_type(text) RETURNS text LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
    AS $$ SELECT json_type($1::jsonb) $$;

-- NOW(6) ------------------------------------------------------------------------------------------------------------
-- MySQL accepts a fractional seconds precision, the session time zone is UTC (SessionMiddleware)
CREATE OR REPLACE FUNCTION now(integer) RETURNS timestamp LANGUAGE sql STABLE STRICT PARALLEL SAFE
    RETURN localtimestamp;

-- DATABASE() is the database name, information_schema.table_schema is rewritten to the database name as well (InformationSchemaRewriter)
CREATE OR REPLACE FUNCTION database() RETURNS text LANGUAGE sql STABLE PARALLEL SAFE
    AS $$ SELECT current_database()::text $$;

-- LIKE on a JSON column compares the text form, like on MySQL
CREATE OR REPLACE FUNCTION jsonb_like(jsonb, text) RETURNS boolean LANGUAGE sql IMMUTABLE PARALLEL SAFE
    AS $$ SELECT $1::text LIKE $2 $$;
CREATE OR REPLACE FUNCTION jsonb_not_like(jsonb, text) RETURNS boolean LANGUAGE sql IMMUTABLE PARALLEL SAFE
    AS $$ SELECT $1::text NOT LIKE $2 $$;
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_operator WHERE oprname = '~~' AND oprleft = 'jsonb'::regtype AND oprright = 'text'::regtype) THEN
        CREATE OPERATOR ~~ (LEFTARG = jsonb, RIGHTARG = text, FUNCTION = jsonb_like);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_operator WHERE oprname = '!~~' AND oprleft = 'jsonb'::regtype AND oprright = 'text'::regtype) THEN
        CREATE OPERATOR !~~ (LEFTARG = jsonb, RIGHTARG = text, FUNCTION = jsonb_not_like);
    END IF;
END
$$;

-- REPLACE on a JSON column works on the text form
CREATE OR REPLACE FUNCTION replace(jsonb, text, text) RETURNS jsonb LANGUAGE sql IMMUTABLE PARALLEL SAFE
    AS $$ SELECT replace($1::text, $2, $3)::jsonb $$;

-- MySQL converts the arguments of IFNULL to a common type, PostgreSQL does not unify text and numbers
CREATE OR REPLACE FUNCTION ifnull(text, bigint) RETURNS text LANGUAGE sql IMMUTABLE PARALLEL SAFE
    AS $$ SELECT COALESCE($1, $2::text) $$;

-- JSON_SET(doc, '$.a.b[0]', value) for plain keys and array indexes, a missing last key is created like on MySQL
CREATE OR REPLACE FUNCTION mysql_compat_json_path(text) RETURNS text[] LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
    AS $$ SELECT string_to_array(trim(both '.' from regexp_replace(regexp_replace($1, '^\$', ''), '\[(\d+)\]', '.\1', 'g')), '.') $$;
CREATE OR REPLACE FUNCTION json_set(jsonb, text, jsonb) RETURNS jsonb LANGUAGE sql IMMUTABLE PARALLEL SAFE
    AS $$ SELECT jsonb_set($1, mysql_compat_json_path($2), $3, true) $$;
CREATE OR REPLACE FUNCTION json_set(jsonb, text, text) RETURNS jsonb LANGUAGE sql IMMUTABLE PARALLEL SAFE
    AS $$ SELECT jsonb_set($1, mysql_compat_json_path($2), to_jsonb($3), true) $$;
CREATE OR REPLACE FUNCTION json_set(jsonb, text, numeric) RETURNS jsonb LANGUAGE sql IMMUTABLE PARALLEL SAFE
    AS $$ SELECT jsonb_set($1, mysql_compat_json_path($2), to_jsonb($3), true) $$;

-- SUBSTRING_INDEX(str, delim, count): everything before the count-th delimiter, from the end for a negative count
CREATE OR REPLACE FUNCTION substring_index(text, text, integer) RETURNS text LANGUAGE sql IMMUTABLE PARALLEL SAFE
    AS $$
        SELECT CASE
            WHEN $3 = 0 OR $2 = '' THEN ''
            WHEN $3 > 0 THEN array_to_string((string_to_array($1, $2))[1:$3], $2)
            ELSE array_to_string((string_to_array($1, $2))[GREATEST(cardinality(string_to_array($1, $2)) + $3 + 1, 1):], $2)
        END
    $$;

-- JSON_CONTAINS_PATH(doc, 'one'|'all', path, ...)
CREATE OR REPLACE FUNCTION json_contains_path(jsonb, text, VARIADIC text[]) RETURNS boolean LANGUAGE sql IMMUTABLE PARALLEL SAFE
    AS $$
        SELECT CASE lower($2)
            WHEN 'all' THEN NOT EXISTS (SELECT 1 FROM unnest($3) p WHERE $1 #> mysql_compat_json_path(p) IS NULL)
            ELSE EXISTS (SELECT 1 FROM unnest($3) p WHERE $1 #> mysql_compat_json_path(p) IS NOT NULL)
        END
    $$;

-- JSON_EXTRACT(...) IN (strings), the translator uses this variant so the right hand side is compared as text
CREATE OR REPLACE FUNCTION json_extract_text(jsonb, text) RETURNS text LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
    AS $$ SELECT json_extract($1, $2) #>> '{}' $$;
CREATE OR REPLACE FUNCTION json_extract_text(text, text) RETURNS text LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
    AS $$ SELECT json_extract($1, $2) #>> '{}' $$;

-- JSON_REMOVE(doc, path)
CREATE OR REPLACE FUNCTION json_remove(jsonb, text) RETURNS jsonb LANGUAGE sql IMMUTABLE PARALLEL SAFE
    AS $$ SELECT $1 #- mysql_compat_json_path($2) $$;

-- JSON_LENGTH(doc): number of members of an object or elements of an array, 1 for a scalar
CREATE OR REPLACE FUNCTION json_length(jsonb) RETURNS integer LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
    AS $$ SELECT CASE jsonb_typeof($1) WHEN 'object' THEN (SELECT count(*) FROM jsonb_object_keys($1))::integer WHEN 'array' THEN jsonb_array_length($1) ELSE 1 END $$;

-- JSON_EXTRACT(...) = true / 5, MySQL compares the JSON value with the scalar
CREATE OR REPLACE FUNCTION jsonb_eq_scalar(jsonb, boolean) RETURNS boolean LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$ SELECT $1 = to_jsonb($2) $$;
CREATE OR REPLACE FUNCTION jsonb_ne_scalar(jsonb, boolean) RETURNS boolean LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$ SELECT $1 <> to_jsonb($2) $$;
CREATE OR REPLACE FUNCTION jsonb_eq_scalar(jsonb, numeric) RETURNS boolean LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$ SELECT $1 = to_jsonb($2) $$;
CREATE OR REPLACE FUNCTION jsonb_ne_scalar(jsonb, numeric) RETURNS boolean LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$ SELECT $1 <> to_jsonb($2) $$;
DO $$ BEGIN
    CREATE OPERATOR = (LEFTARG = jsonb, RIGHTARG = boolean, FUNCTION = jsonb_eq_scalar);
    CREATE OPERATOR <> (LEFTARG = jsonb, RIGHTARG = boolean, FUNCTION = jsonb_ne_scalar);
    CREATE OPERATOR = (LEFTARG = jsonb, RIGHTARG = numeric, FUNCTION = jsonb_eq_scalar);
    CREATE OPERATOR <> (LEFTARG = jsonb, RIGHTARG = numeric, FUNCTION = jsonb_ne_scalar);
EXCEPTION WHEN duplicate_function THEN NULL;
END $$;

-- UUID() returns the textual form, like MySQL
CREATE OR REPLACE FUNCTION uuid() RETURNS text LANGUAGE sql VOLATILE PARALLEL SAFE AS $$ SELECT gen_random_uuid()::text $$;

-- RAND() without seed, ORDER BY RAND() in the demo data generator
CREATE OR REPLACE FUNCTION rand() RETURNS double precision LANGUAGE sql VOLATILE PARALLEL RESTRICTED AS $$ SELECT random() $$;

-- JSON_VALID(): a jsonb value is valid by construction, text is checked
CREATE OR REPLACE FUNCTION json_valid(jsonb) RETURNS boolean LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$ SELECT $1 IS NOT NULL $$;
CREATE OR REPLACE FUNCTION json_valid(text) RETURNS boolean LANGUAGE plpgsql IMMUTABLE PARALLEL SAFE AS $$
BEGIN
    IF $1 IS NULL THEN RETURN NULL; END IF;
    PERFORM $1::jsonb;
    RETURN true;
EXCEPTION WHEN others THEN RETURN false;
END $$;

-- JSON_ARRAY_APPEND(doc, path, value), an unknown path leaves the document unchanged, a scalar becomes an array
CREATE OR REPLACE FUNCTION json_array_append(jsonb, text, text) RETURNS jsonb LANGUAGE sql IMMUTABLE PARALLEL SAFE
    AS $$
        SELECT CASE
            WHEN $1 IS NULL THEN NULL
            -- root path '$': jsonb_set() with an empty path returns the document unchanged
            WHEN cardinality(mysql_compat_json_path($2)) = 0 AND jsonb_typeof($1) = 'array' THEN $1 || jsonb_build_array($3)
            WHEN cardinality(mysql_compat_json_path($2)) = 0 THEN jsonb_build_array($1, $3)
            WHEN $1 #> mysql_compat_json_path($2) IS NULL THEN $1
            WHEN jsonb_typeof($1 #> mysql_compat_json_path($2)) = 'array'
                THEN jsonb_set($1, mysql_compat_json_path($2), ($1 #> mysql_compat_json_path($2)) || jsonb_build_array($3))
            ELSE jsonb_set($1, mysql_compat_json_path($2), jsonb_build_array($1 #> mysql_compat_json_path($2), $3))
        END
    $$;

-- Assigning text to a bytea column (a serialized text column copied into a binary column), MySQL stores the bytes of the string
CREATE OR REPLACE FUNCTION mysql_compat_text_to_bytea(text) RETURNS bytea LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
    AS $$ SELECT convert_to($1, 'UTF8') $$;
DO $$ BEGIN
    CREATE CAST (text AS bytea) WITH FUNCTION mysql_compat_text_to_bytea(text) AS ASSIGNMENT;
EXCEPTION WHEN duplicate_object THEN NULL;
END $$;
DO $$ BEGIN
    CREATE CAST (character varying AS bytea) WITH FUNCTION mysql_compat_text_to_bytea(text) AS ASSIGNMENT;
EXCEPTION WHEN duplicate_object THEN NULL;
END $$;

-- JSON path with quoted keys ($."key", used for customer ids by PromotionRedemptionUpdater), replaces the plain version above
CREATE OR REPLACE FUNCTION mysql_compat_json_path(text) RETURNS text[] LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
    AS $$ SELECT COALESCE(array_agg(COALESCE(m[1], m[2], m[3]) ORDER BY n), ARRAY[]::text[])
          FROM regexp_matches(regexp_replace($1, '^\$', ''), '\."([^"]*)"|\[(\d+)\]|\.?([^.\[]+)', 'g') WITH ORDINALITY AS t(m, n) $$;

-- Integer written to a boolean column (MySQL BOOL is TINYINT(1)), BooleanAssignmentRewriter wraps such values
-- The text variant covers untyped parameters, PDO sends PHP false as '' and true as '1'
CREATE OR REPLACE FUNCTION mysql_compat_to_bool(boolean) RETURNS boolean LANGUAGE sql IMMUTABLE PARALLEL SAFE
    RETURN $1;
CREATE OR REPLACE FUNCTION mysql_compat_to_bool(integer) RETURNS boolean LANGUAGE sql IMMUTABLE PARALLEL SAFE
    RETURN $1 <> 0;
CREATE OR REPLACE FUNCTION mysql_compat_to_bool(bigint) RETURNS boolean LANGUAGE sql IMMUTABLE PARALLEL SAFE
    RETURN $1 <> 0;
CREATE OR REPLACE FUNCTION mysql_compat_to_bool(numeric) RETURNS boolean LANGUAGE sql IMMUTABLE PARALLEL SAFE
    RETURN $1 <> 0;
CREATE OR REPLACE FUNCTION mysql_compat_to_bool(text) RETURNS boolean LANGUAGE sql IMMUTABLE PARALLEL SAFE
    RETURN CASE WHEN $1 = '' THEN false WHEN $1 ~ '^-?[0-9]+$' THEN $1::numeric <> 0 ELSE $1::boolean END;

-- ROUND(double, n) and QUARTER(ts)
CREATE OR REPLACE FUNCTION round(double precision, integer) RETURNS double precision LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
    RETURN round($1::numeric, $2)::double precision;
CREATE OR REPLACE FUNCTION quarter(timestamp) RETURNS integer LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
    RETURN extract(quarter FROM $1)::integer;
-- the double overload would win over numeric for integer arguments (double is the preferred numeric type), keep numeric for them
CREATE OR REPLACE FUNCTION round(integer, integer) RETURNS numeric LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
    RETURN round($1::numeric, $2);
CREATE OR REPLACE FUNCTION round(bigint, integer) RETURNS numeric LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
    RETURN round($1::numeric, $2);

-- DATE_ADD(ts, INTERVAL n UNIT) and DATE_SUB, the translator turns the interval into `n * INTERVAL '1 unit'`
CREATE OR REPLACE FUNCTION date_add(timestamp, interval) RETURNS timestamp LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
    RETURN $1 + $2;
CREATE OR REPLACE FUNCTION date_sub(timestamp, interval) RETURNS timestamp LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
    RETURN $1 - $2;
