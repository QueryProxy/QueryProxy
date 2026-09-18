---
paths:
  - 'app/Services/Sql/**'
---

# Sql

## SQL guard checks read token values, not normalize() text
The phpmyadmin/sql-parser Lexer is MySQL-dialect-first and two of its habits have already produced guard bypasses. Verify any new rule empirically against the lexer before trusting a regex.

1. A double-quoted PostgreSQL identifier lexes as a String, so normalize() blanks it to '?'. CREATE EXTENSION "plpythonu" and SET GLOBAL "general_log" are invisible in the normalized text. Read names from $token->value (it already strips `x`, "x" and 'x' quoting), never from normalize() output. Also strip a trailing ':' — "x:=1" lexes the name as a Label token "x:".

2. The lexer does not understand dollar quoting, so every ';' inside a $$ ... $$ body was reported as a statement delimiter and shredded plpgsql DO blocks. SqlInspector::dollarQuotedRanges() scans for those regions and splitStatements() skips delimiters inside them; normalize() masks them to '?'. The DO body scan deliberately turns that masking off (normalize($body, maskDollarQuoted: false)) because there the quoted text is the subject of the check.

The denylist is defence in depth, not the authorization boundary — DBA approval is. The DO body scan in particular is best-effort: it cannot see SQL a body computes at run time (EXECUTE format(...), EXECUTE a_variable).
