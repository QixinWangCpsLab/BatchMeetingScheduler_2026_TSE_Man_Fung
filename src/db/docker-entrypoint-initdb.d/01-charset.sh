#!/bin/sh
# Apply DB_CHARSET and DB_COLLATION from run.bat to the database named by DB_NAME.
# MariaDB creates that database from MARIADB_DATABASE before this script runs.
set -eu

charset="${DB_CHARSET:-utf8mb4}"
collation="${DB_COLLATION:-utf8mb4_general_ci}"
database="${MARIADB_DATABASE:-}"

case "$charset" in
  *[!A-Za-z0-9_]*)
    echo "DB_CHARSET must contain only letters, digits, and underscores." >&2
    exit 1
    ;;
esac

case "$collation" in
  *[!A-Za-z0-9_]*)
    echo "DB_COLLATION must contain only letters, digits, and underscores." >&2
    exit 1
    ;;
esac

case "$database" in
  ""|*[!A-Za-z0-9_]*)
    echo "DB_NAME must contain only letters, digits, and underscores." >&2
    exit 1
    ;;
esac

MYSQL_PWD="$MARIADB_ROOT_PASSWORD" mariadb \
  --protocol=socket \
  -uroot \
  -hlocalhost \
  --database="$database" \
  --comments \
  -e "ALTER DATABASE \`$database\` CHARACTER SET $charset COLLATE $collation;"
