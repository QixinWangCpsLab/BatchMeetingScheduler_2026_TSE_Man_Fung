#!/usr/bin/env bash
# Docker manager for Batch Meeting Scheduler.
# Same actions as run.bat: C, E, S, T, L, D, X, I, and U.
set -u
set +H
unset CDPATH

case "$(uname -s 2>/dev/null || true)" in
  MINGW*|MSYS*|CYGWIN*)
    # Git Bash rewrites /tmp and /var arguments before docker.exe sees them.
    export MSYS_NO_PATHCONV=1
    export MSYS2_ARG_CONV_EXCL='*'
    ;;
esac

DEFAULT_PHP_CONTAINER_NAME=batch-meeting-scheduler-php
DEFAULT_DB_CONTAINER_NAME=batch-meeting-scheduler-db
DEFAULT_DB_NAME=testwsqlnew
DEFAULT_DB_USER=appuser
DEFAULT_DB_PASS=change_me_app_password
DEFAULT_DB_ROOT_PASSWORD=change_me_root_password
DEFAULT_DB_HOST=localhost
DEFAULT_DB_DOCKER_HOST=db
DEFAULT_DB_PORT=3306
DEFAULT_DB_CHARSET=utf8mb4
DEFAULT_DB_COLLATION=utf8mb4_general_ci
DEFAULT_PHP_PORT=8080
DEFAULT_TZ=Asia/Hong_Kong
DEFAULT_MAIL_HOST=smtp.gmail.com
DEFAULT_MAIL_PORT=465
DEFAULT_MAIL_SECURE=ssl
DEFAULT_MAIL_USER=
DEFAULT_MAIL_PASS=
DEFAULT_MAIL_FROM=
DEFAULT_MAIL_FROM_NAME=do-not-reply
DEFAULT_ROOT_PATH=/testwsqlnew

ACTION=
ENV_ERROR=0

lower() {
  printf '%s' "$1" | tr '[:upper:]' '[:lower:]'
}

eq_ci() {
  [[ "$(lower "$1")" == "$(lower "$2")" ]]
}

enter_script_dir() {
  local source="${BASH_SOURCE[0]}"
  local dir
  while [[ -h "$source" ]]; do
    dir=$(cd -P "$(dirname "$source")" && pwd) || exit 1
    source=$(readlink "$source")
    [[ "$source" != /* ]] && source="${dir}/${source}"
  done
  cd -P "$(dirname "$source")" || exit 1
}

pause() {
  echo
  if [[ -t 0 ]]; then
    read -r -n 1 -s -p "Press any key to continue . . . " || true
    echo
  fi
}

fail() {
  echo
  pause
  exit 1
}

finish() {
  echo
  pause
  exit 0
}

# Compose prefers exported shell variables over the .env file.
# cmd "set" does the same thing for run.bat.
export_config() {
  local name
  for name in \
    PHP_CONTAINER_NAME DB_CONTAINER_NAME DB_NAME DB_USER DB_PASS DB_ROOT_PASSWORD \
    DB_HOST DB_DOCKER_HOST DB_PORT DB_CHARSET DB_COLLATION PHP_PORT TZ \
    MAIL_HOST MAIL_PORT MAIL_SECURE MAIL_USER MAIL_PASS MAIL_FROM MAIL_FROM_NAME \
    ROOT_URL ROOT_PATH
  do
    if [[ -n "${!name+x}" ]]; then
      export "$name"
    fi
  done
}

open_browser() {
  local url="$1"
  local uname_s
  uname_s=$(uname -s 2>/dev/null || true)
  case "$uname_s" in
    MINGW*|MSYS*|CYGWIN*)
      if command -v powershell.exe >/dev/null 2>&1; then
        powershell.exe -NoProfile -Command "Start-Process '$url'" >/dev/null 2>&1 || true
        return 0
      fi
      ;;
  esac
  if [[ -n "${WSL_DISTRO_NAME-}" ]] && command -v powershell.exe >/dev/null 2>&1; then
    powershell.exe -NoProfile -Command "Start-Process '$url'" >/dev/null 2>&1 || true
    return 0
  fi
  if command -v xdg-open >/dev/null 2>&1; then
    xdg-open "$url" >/dev/null 2>&1 || true
  elif command -v open >/dev/null 2>&1; then
    open "$url" >/dev/null 2>&1 || true
  fi
}

prompt_value() {
  local __name="$1"
  local __label="$2"
  local __default="$3"
  local __value=""
  if ! IFS= read -r -p "${__label} [${__default}]: " __value; then
    echo
    echo "ERROR: Could not read a value."
    return 1
  fi
  if [[ -z "$__value" ]]; then
    __value=$__default
  fi
  printf -v "$__name" '%s' "$__value"
}

prompt_secret() {
  local __name="$1"
  local __label="$2"
  local __default="$3"
  local __value=""
  printf '%s' "${__label} (hidden, Enter keeps the default): "
  if ! IFS= read -r -s __value; then
    printf '\n'
    echo "ERROR: Could not read a hidden value."
    return 1
  fi
  printf '\n'
  if [[ -z "$__value" ]]; then
    __value=$__default
  fi
  printf -v "$__name" '%s' "$__value"
}

prompt_port() {
  local __name="$1"
  local __label="$2"
  local __default="$3"
  local __value=""
  while true; do
    prompt_value "$__name" "$__label" "$__default" || return 1
    __value=${!__name}
    if [[ ! "$__value" =~ ^[0-9]+$ ]]; then
      echo "ERROR: ${__label} must contain digits only."
      continue
    fi
    if [[ "$__value" == 0* || ${#__value} -gt 5 || "$((10#$__value))" -lt 1 || "$((10#$__value))" -gt 65535 ]]; then
      echo "ERROR: ${__label} must be a number from 1 to 65535."
      continue
    fi
    return 0
  done
}

require_file() {
  local path="$1"
  if [[ ! -f "$path" ]]; then
    echo "ERROR: ${path} is missing."
    ENV_ERROR=1
  fi
}

check_ident() {
  local var_name="$1"
  local label="$2"
  local value="${!var_name-}"
  if [[ -z "$value" ]]; then
    echo "ERROR: ${label} is missing from .env."
    ENV_ERROR=1
    return 0
  fi
  if [[ ! "$value" =~ ^[A-Za-z0-9_][A-Za-z0-9_]*$ ]]; then
    echo "ERROR: ${label} must contain only letters, digits, and underscores."
    ENV_ERROR=1
  fi
}

check_name() {
  local var_name="$1"
  local label="$2"
  local value="${!var_name-}"
  if [[ -z "$value" ]]; then
    echo "ERROR: ${label} is missing from .env."
    ENV_ERROR=1
    return 0
  fi
  if [[ ! "$value" =~ ^[A-Za-z0-9][A-Za-z0-9_.-]*$ ]]; then
    echo "ERROR: ${label} must be a Docker container name."
    ENV_ERROR=1
  fi
}

check_token() {
  local var_name="$1"
  local label="$2"
  local value="${!var_name-}"
  if [[ -z "$value" ]]; then
    echo "ERROR: ${label} is missing from .env."
    ENV_ERROR=1
    return 0
  fi
  if [[ ! "$value" =~ ^[A-Za-z0-9_./:+-][A-Za-z0-9_./:+-]*$ ]]; then
    echo "ERROR: ${label} contains an unsupported character."
    ENV_ERROR=1
  fi
}

check_port() {
  local var_name="$1"
  local label="$2"
  local value="${!var_name-}"
  if [[ -z "$value" ]]; then
    echo "ERROR: ${label} is missing from .env."
    ENV_ERROR=1
    return 0
  fi
  if [[ ! "$value" =~ ^[1-9][0-9]*$ || ${#value} -gt 5 || "$((10#$value))" -gt 65535 ]]; then
    echo "ERROR: ${label} must be a number from 1 to 65535."
    ENV_ERROR=1
  fi
}

secret_has_forbidden() {
  local value="$1"
  local forbidden=' &|!^%"<>'
  local i char
  for ((i = 0; i < ${#value}; i++)); do
    char=${value:i:1}
    if [[ "$forbidden" == *"$char"* ]]; then
      return 0
    fi
  done
  return 1
}

check_password() {
  local var_name="$1"
  local label="$2"
  local value="${!var_name-}"
  if [[ -z "$value" ]]; then
    echo "ERROR: ${label} is missing from .env."
    ENV_ERROR=1
    return 0
  fi
  if secret_has_forbidden "$value"; then
    echo "ERROR: ${label} contains a space or one of these characters: & | ^ ! % \" < >"
    ENV_ERROR=1
  fi
}

check_mail_secure() {
  if eq_ci "${MAIL_SECURE-}" "ssl" || eq_ci "${MAIL_SECURE-}" "tls"; then
    return 0
  fi
  echo "ERROR: MAIL_SECURE must be ssl or tls."
  ENV_ERROR=1
}

validate_env() {
  ENV_ERROR=0
  require_file "docker-compose.yml"
  require_file "src/web/docker/Dockerfile"
  require_file "src/db/Dockerfile"
  require_file "src/db/capstone_project.sql"
  require_file "src/db/docker-entrypoint-initdb.d/01-charset.sh"
  check_name PHP_CONTAINER_NAME "PHP_CONTAINER_NAME"
  check_name DB_CONTAINER_NAME "DB_CONTAINER_NAME"
  check_ident DB_NAME "DB_NAME"
  check_ident DB_USER "DB_USER"
  check_ident DB_CHARSET "DB_CHARSET"
  check_ident DB_COLLATION "DB_COLLATION"
  check_token DB_HOST "DB_HOST"
  check_token DB_DOCKER_HOST "DB_DOCKER_HOST"
  check_token TZ "TZ"
  check_token ROOT_PATH "ROOT_PATH"
  check_port DB_PORT "DB_PORT"
  check_port PHP_PORT "PHP_PORT"
  check_password DB_PASS "DB_PASS"
  check_password DB_ROOT_PASSWORD "DB_ROOT_PASSWORD"
  if [[ -n "${MAIL_USER-}" ]]; then
    check_password MAIL_USER "MAIL_USER"
  fi
  if [[ -n "${MAIL_PASS-}" ]]; then
    check_password MAIL_PASS "MAIL_PASS"
  fi
  if [[ -n "${MAIL_PORT-}" ]]; then
    check_port MAIL_PORT "MAIL_PORT"
  fi
  if [[ -n "${MAIL_SECURE-}" ]]; then
    check_mail_secure
  fi
  if eq_ci "${DB_USER-}" "root"; then
    echo "ERROR: DB_USER cannot be root. Use DB_ROOT_PASSWORD for the root account."
    ENV_ERROR=1
  fi
  if [[ -n "${DB_PORT-}" && -n "${PHP_PORT-}" && "$DB_PORT" == "$PHP_PORT" ]]; then
    echo "ERROR: DB_PORT and PHP_PORT must be different."
    ENV_ERROR=1
  fi
  if [[ -n "${PHP_CONTAINER_NAME-}" ]] && eq_ci "$PHP_CONTAINER_NAME" "${DB_CONTAINER_NAME-}"; then
    echo "ERROR: PHP_CONTAINER_NAME and DB_CONTAINER_NAME must be different."
    ENV_ERROR=1
  fi
  if ! eq_ci "${DB_DOCKER_HOST-}" "db"; then
    echo "ERROR: DB_DOCKER_HOST must be db, the Compose service name."
    ENV_ERROR=1
  fi
  [[ "$ENV_ERROR" -eq 0 ]]
}

load_env_file() {
  local line key value
  while IFS= read -r line || [[ -n "$line" ]]; do
    line=${line#$'\ufeff'}
    line=${line%$'\r'}
    [[ -z "$line" ]] && continue
    [[ "$line" == \#* ]] && continue
    [[ "$line" == *=* ]] || continue
    key=${line%%=*}
    value=${line#*=}
    [[ -z "$key" ]] && continue
    [[ "$key" =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]] || continue
    printf -v "$key" '%s' "$value"
  done < .env
}

fill_default() {
  local name="$1"
  local value="$2"
  if [[ -z "${!name+x}" ]]; then
    printf -v "$name" '%s' "$value"
  fi
}

fill_defaults() {
  fill_default PHP_CONTAINER_NAME "$DEFAULT_PHP_CONTAINER_NAME"
  fill_default DB_CONTAINER_NAME "$DEFAULT_DB_CONTAINER_NAME"
  fill_default DB_HOST "$DEFAULT_DB_HOST"
  fill_default DB_DOCKER_HOST "$DEFAULT_DB_DOCKER_HOST"
  fill_default DB_PORT "$DEFAULT_DB_PORT"
  fill_default DB_CHARSET "$DEFAULT_DB_CHARSET"
  fill_default DB_COLLATION "$DEFAULT_DB_COLLATION"
  fill_default PHP_PORT "$DEFAULT_PHP_PORT"
  fill_default TZ "$DEFAULT_TZ"
  fill_default MAIL_HOST "$DEFAULT_MAIL_HOST"
  fill_default MAIL_PORT "$DEFAULT_MAIL_PORT"
  fill_default MAIL_SECURE "$DEFAULT_MAIL_SECURE"
  fill_default MAIL_USER "$DEFAULT_MAIL_USER"
  fill_default MAIL_PASS "$DEFAULT_MAIL_PASS"
  fill_default MAIL_FROM "$DEFAULT_MAIL_FROM"
  fill_default MAIL_FROM_NAME "$DEFAULT_MAIL_FROM_NAME"
  fill_default ROOT_URL "http://localhost:${PHP_PORT-}"
  fill_default ROOT_PATH "$DEFAULT_ROOT_PATH"
}

write_env() {
  local tmp
  tmp=$(mktemp "${TMPDIR:-/tmp}/bms-env.XXXXXX") || {
    echo "ERROR: Could not write .env."
    return 1
  }
  {
    printf 'PHP_CONTAINER_NAME=%s\n' "$PHP_CONTAINER_NAME"
    printf 'DB_CONTAINER_NAME=%s\n' "$DB_CONTAINER_NAME"
    printf 'DB_NAME=%s\n' "$DB_NAME"
    printf 'DB_USER=%s\n' "$DB_USER"
    printf 'DB_PASS=%s\n' "$DB_PASS"
    printf 'DB_ROOT_PASSWORD=%s\n' "$DB_ROOT_PASSWORD"
    printf 'DB_HOST=%s\n' "$DB_HOST"
    printf 'DB_DOCKER_HOST=%s\n' "$DB_DOCKER_HOST"
    printf 'DB_PORT=%s\n' "$DB_PORT"
    printf 'DB_CHARSET=%s\n' "$DB_CHARSET"
    printf 'DB_COLLATION=%s\n' "$DB_COLLATION"
    printf 'PHP_PORT=%s\n' "$PHP_PORT"
    printf 'TZ=%s\n' "$TZ"
    printf 'MAIL_HOST=%s\n' "$MAIL_HOST"
    printf 'MAIL_PORT=%s\n' "$MAIL_PORT"
    printf 'MAIL_SECURE=%s\n' "$MAIL_SECURE"
    printf 'MAIL_USER=%s\n' "$MAIL_USER"
    printf 'MAIL_PASS=%s\n' "$MAIL_PASS"
    printf 'MAIL_FROM=%s\n' "$MAIL_FROM"
    printf 'MAIL_FROM_NAME=%s\n' "$MAIL_FROM_NAME"
    printf 'ROOT_URL=%s\n' "$ROOT_URL"
    printf 'ROOT_PATH=%s\n' "$ROOT_PATH"
  } >"$tmp" || {
    rm -f "$tmp"
    echo "ERROR: Could not write .env."
    return 1
  }
  mv -f "$tmp" .env || {
    rm -f "$tmp"
    echo "ERROR: Could not write .env."
    return 1
  }
}

require_docker() {
  if ! docker info >/dev/null 2>&1; then
    echo "ERROR: Docker is not running or is not available on PATH."
    return 1
  fi
}

container_health() {
  local name="$1"
  local health
  health=$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$name" 2>/dev/null || true)
  health=${health%$'\r'}
  printf '%s' "$health"
}

container_status() {
  local name="$1"
  local status
  status=$(docker inspect --format '{{.State.Status}}' "$name" 2>/dev/null || true)
  status=${status%$'\r'}
  printf '%s' "$status"
}

print_configuration() {
  echo
  echo "=========================================="
  echo "Configuration"
  echo "=========================================="
  printf '%-19s %s\n' "Action:" "$ACTION"
  printf '%-19s %s\n' "PHP container:" "${PHP_CONTAINER_NAME-}"
  printf '%-19s %s\n' "Database container:" "${DB_CONTAINER_NAME-}"
  printf '%-19s %s\n' "Database name:" "${DB_NAME-}"
  printf '%-19s %s\n' "Database user:" "${DB_USER-}"
  printf '%-19s %s\n' "Database password:" "[hidden]"
  printf '%-19s %s\n' "Root password:" "[hidden]"
  printf '%-19s %s\n' "PHP URL:" "http://localhost:${PHP_PORT-}"
  printf '%-19s %s\n' "Database host:" "${DB_HOST-} (Docker PHP host: ${DB_DOCKER_HOST-})"
  printf '%-19s %s\n' "Database port:" "localhost:${DB_PORT-}"
  printf '%-19s %s\n' "Database charset:" "${DB_CHARSET-} / ${DB_COLLATION-}"
  printf '%-19s %s\n' "Timezone:" "${TZ-}"
  printf '%-19s %s\n' "Mail host:" "[hidden]"
  printf '%-19s %s\n' "Mail username:" "[hidden]"
  printf '%-19s %s\n' "Mail password:" "[hidden]"
  printf '%-19s %s\n' "Mail from:" "${MAIL_FROM_NAME-} ${MAIL_FROM-}"
  printf '%-19s %s\n' "Public URL:" "${ROOT_URL-}"
  printf '%-19s %s\n' "App base path:" "${ROOT_PATH-}"
  echo "=========================================="
  echo
}

show_success() {
  echo
  echo "=========================================="
  echo "Services are running"
  echo "=========================================="
  printf 'PHP application: http://localhost:%s\n' "$PHP_PORT"
  printf 'Database host:   localhost:%s\n' "$DB_PORT"
  echo "=========================================="
  docker compose ps || true
  open_browser "http://127.0.0.1:${PHP_PORT}/"
  finish
}

run_test_data() {
  if [[ ! -f "src/db/test_data.sql" ]]; then
    echo "ERROR: src/db/test_data.sql is missing."
    fail
  fi
  echo "Inserting test data..."
  if ! docker exec -i -e MYSQL_PWD="$DB_PASS" "$DB_CONTAINER_NAME" mariadb --protocol=tcp -h127.0.0.1 -u"$DB_USER" "$DB_NAME" < "src/db/test_data.sql"; then
    echo
    echo "ERROR: Test data was not inserted."
    fail
  fi
  echo
  echo "Test data inserted."
  echo "Teacher login: prof.demo, teacher.demo, or ta.demo"
  echo "Student login: 22000001d, then choose COMP4431 or COMP4432"
  echo "Password:      Test1234"
  echo "old.demo and student 22000011d are disabled."
  show_success
}

maybe_test_data() {
  local insert_test_data=""
  echo
  echo "Demo exams COMP4431, COMP4432, and COMP4433 can be loaded from src/db/test_data.sql."
  echo "Enter skips this. y replaces those exam codes and the demo logins."
  if ! IFS= read -r -p "Insert test data? [y/N]: " insert_test_data; then
    echo
    echo "Skipped test data."
    show_success
  fi
  if eq_ci "$insert_test_data" "y" || eq_ci "$insert_test_data" "yes"; then
    run_test_data
  fi
  echo "Skipped test data."
  show_success
}

check_schema() {
  local out err hit
  echo "Checking that ${DB_NAME} contains the exam table..."
  out=$(mktemp "${TMPDIR:-/tmp}/bms-schema-check.XXXXXX") || fail
  err=$(mktemp "${TMPDIR:-/tmp}/bms-schema-check.XXXXXX") || fail
  if ! docker exec -e MYSQL_PWD="$DB_PASS" "$DB_CONTAINER_NAME" mariadb --protocol=tcp -h127.0.0.1 -u"$DB_USER" -N -B "$DB_NAME" -e "SHOW TABLES LIKE 'exam'" >"$out" 2>"$err"; then
    echo "ERROR: Could not query the database with DB_USER."
    cat "$err"
    rm -f "$out" "$err"
    fail
  fi
  hit=""
  IFS= read -r hit <"$out" || true
  hit=${hit%$'\r'}
  rm -f "$out" "$err"
  if ! eq_ci "$hit" "exam"; then
    echo "ERROR: The database is up, but the exam table is missing."
    echo "Schema import runs only when the database volume is first created."
    echo "Select X to delete the volume, then select E again."
    fail
  fi
  maybe_test_data
}

wait_for_services() {
  local wait_count=0 db_health php_state
  echo "Waiting for the database healthcheck and the PHP container..."
  while true; do
    wait_count=$((wait_count + 1))
    if [[ "$wait_count" -gt 36 ]]; then
      echo "ERROR: Containers did not become ready within 3 minutes."
      docker compose ps || true
      docker compose logs --tail=50 || true
      fail
    fi
    db_health=$(container_health "$DB_CONTAINER_NAME")
    php_state=$(container_status "$PHP_CONTAINER_NAME")
    if eq_ci "$db_health" "unhealthy"; then
      echo "ERROR: Database container is unhealthy."
      docker compose logs --tail=50 db || true
      fail
    fi
    if eq_ci "$db_health" "healthy" && eq_ci "$php_state" "running"; then
      check_schema
    fi
    sleep 5
  done
}

start_containers() {
  echo "Starting the containers..."
  if ! docker compose start; then
    echo
    echo "ERROR: Failed to start the containers."
    fail
  fi
  wait_for_services
}

stop_containers() {
  echo "Stopping the containers..."
  if ! docker compose stop; then
    echo
    echo "ERROR: Failed to stop the containers."
    fail
  fi
  echo "Containers stopped successfully."
  finish
}

show_logs() {
  echo "Showing logs. Press Ctrl+C to stop viewing logs."
  trap 'echo' INT
  docker compose logs --tail=100 -f || true
  trap - INT
  finish
}

delete_containers() {
  echo "Removing containers. The mariadb-data volume will be kept."
  if ! docker compose down; then
    echo
    echo "ERROR: Failed to remove the containers."
    fail
  fi
  echo "Containers removed successfully."
  finish
}

delete_all() {
  local confirm=""
  echo "WARNING: This permanently deletes the database volume and all database data."
  if ! IFS= read -r -p "Type DELETE to continue: " confirm; then
    echo
    echo "Operation cancelled."
    finish
  fi
  if ! eq_ci "$confirm" "DELETE"; then
    echo "Operation cancelled."
    finish
  fi
  if ! docker compose down -v; then
    echo
    echo "ERROR: Failed to remove the containers and volume."
    fail
  fi
  echo "Containers and database volume removed successfully."
  finish
}

show_configuration() {
  print_configuration
  require_docker || fail
  case "$ACTION" in
    T) stop_containers ;;
    L) show_logs ;;
    D) delete_containers ;;
    X) delete_all ;;
  esac
  if ! docker compose config >/dev/null 2>&1; then
    echo "ERROR: Docker Compose configuration is invalid."
    fail
  fi
  if [[ "$ACTION" == "S" ]]; then
    start_containers
  fi
  echo "Building the PHP and database images..."
  if ! docker compose up -d --build; then
    echo
    echo "ERROR: Failed to create the containers."
    fail
  fi
  wait_for_services
}

update_php() {
  local php_state
  require_docker || fail
  if [[ ! -f "src/web/index.php" ]]; then
    echo "ERROR: src/web/index.php is missing."
    fail
  fi
  php_state=$(container_status "$PHP_CONTAINER_NAME")
  if ! eq_ci "$php_state" "running"; then
    echo "ERROR: The PHP container is not running. Select S to start it, then select U again."
    fail
  fi
  echo "Copying src/web into ${PHP_CONTAINER_NAME}..."
  echo "The running container is kept."
  if ! docker exec "$PHP_CONTAINER_NAME" rm -rf /tmp/bms-web-update; then
    echo
    echo "ERROR: Could not prepare the PHP container for the update."
    fail
  fi
  # The destination must not exist. docker cp then places the contents of src/web there.
  if ! docker cp "src/web" "${PHP_CONTAINER_NAME}:/tmp/bms-web-update"; then
    echo
    echo "ERROR: Could not copy src/web into the PHP container."
    fail
  fi
  if ! docker exec "$PHP_CONTAINER_NAME" sh -c 'find /tmp/bms-web-update -type f \( -name .env -o -name .env.local -o -name .env.development.local \) -delete && rm -rf /var/www/html && mv /tmp/bms-web-update /var/www/html && chown -R www-data:www-data /var/www/html && test -f /var/www/html/index.php && test ! -e /var/www/html/.env'; then
    echo
    echo "ERROR: The PHP files were not published into /var/www/html."
    fail
  fi
  if ! docker exec "$PHP_CONTAINER_NAME" apache2ctl graceful; then
    echo
    echo "ERROR: The files were copied, but Apache did not reload."
    fail
  fi
  echo
  echo "PHP files in the running container now match src/web."
  echo "Refresh the browser."
  echo "Run U again after later edits. C and E build a new container from the image, so run U again after those if src/web is newer."
  finish
}

insert_test_data_only() {
  local db_health
  require_docker || fail
  db_health=$(container_health "$DB_CONTAINER_NAME")
  if ! eq_ci "$db_health" "healthy"; then
    echo "ERROR: The database is not running. Select S to start it, then select I again."
    fail
  fi
  maybe_test_data
}

get_env_values() {
  echo
  echo "Enter Docker settings. Press Enter to use the value in brackets."
  echo "Database passwords are hidden while you type. Press Enter to keep the default."
  echo "Passwords are written to the local .env file."
  echo
  prompt_value PHP_CONTAINER_NAME "PHP container name" "$DEFAULT_PHP_CONTAINER_NAME" || return 1
  prompt_value DB_CONTAINER_NAME "Database container name" "$DEFAULT_DB_CONTAINER_NAME" || return 1
  prompt_value DB_NAME "Database name" "$DEFAULT_DB_NAME" || return 1
  prompt_value DB_USER "Database user" "$DEFAULT_DB_USER" || return 1
  prompt_secret DB_PASS "Database password" "$DEFAULT_DB_PASS" || return 1
  prompt_secret DB_ROOT_PASSWORD "MariaDB root password" "$DEFAULT_DB_ROOT_PASSWORD" || return 1
  prompt_value DB_HOST "Database host used by PHP" "$DEFAULT_DB_HOST" || return 1
  prompt_port DB_PORT "Database host port" "$DEFAULT_DB_PORT" || return 1
  prompt_port PHP_PORT "PHP host port" "$DEFAULT_PHP_PORT" || return 1
  prompt_value TZ "Timezone" "$DEFAULT_TZ" || return 1
  echo
  echo "Mail and site settings. Press Enter to keep the bracket value."
  echo "Mail can stay blank until email is connected."
  echo "The mail username and mail password are hidden while you type."
  prompt_value MAIL_HOST "SMTP host" "$DEFAULT_MAIL_HOST" || return 1
  prompt_port MAIL_PORT "SMTP port" "$DEFAULT_MAIL_PORT" || return 1
  prompt_value MAIL_SECURE "SMTP security (ssl or tls)" "$DEFAULT_MAIL_SECURE" || return 1
  prompt_secret MAIL_USER "Mail username" "$DEFAULT_MAIL_USER" || return 1
  prompt_secret MAIL_PASS "Mail password" "$DEFAULT_MAIL_PASS" || return 1
  prompt_value MAIL_FROM "Mail from address" "$DEFAULT_MAIL_FROM" || return 1
  prompt_value MAIL_FROM_NAME "Mail from name" "$DEFAULT_MAIL_FROM_NAME" || return 1
  prompt_value ROOT_URL "Public site URL" "http://localhost:${PHP_PORT}" || return 1
  prompt_value ROOT_PATH "App base path" "$DEFAULT_ROOT_PATH" || return 1
  prompt_value DB_CHARSET "Database charset" "$DEFAULT_DB_CHARSET" || return 1
  prompt_value DB_COLLATION "Database collation" "$DEFAULT_DB_COLLATION" || return 1
  DB_DOCKER_HOST=$DEFAULT_DB_DOCKER_HOST
  validate_env || return 1
  write_env || return 1
  export_config
  show_configuration
}

load_env_strict() {
  if [[ ! -f .env ]]; then
    echo
    echo "ERROR: .env does not exist."
    echo "Copy .env.example to .env, or select C to create one."
    return 1
  fi
  load_env_file
  echo
  echo "Checking .env and project files..."
  validate_env || return 1
  export_config
  show_configuration
}

load_env() {
  if [[ ! -f .env ]]; then
    echo
    echo "ERROR: .env does not exist."
    echo "Select C or E after creating .env."
    return 1
  fi
  load_env_file
  fill_defaults
  export_config
  if [[ "$ACTION" == "I" ]]; then
    insert_test_data_only
  elif [[ "$ACTION" == "U" ]]; then
    update_php
  else
    show_configuration
  fi
}

print_banner() {
  echo "=========================================="
  echo "Batch Meeting Scheduler Docker Manager"
  echo "=========================================="
  echo
  echo "[C] Create containers and write a new .env"
  echo "[E] Create containers from the current .env"
  echo "[S] Start existing containers"
  echo "[T] Stop containers"
  echo "[L] Show container logs"
  echo "[D] Delete containers but keep the database volume"
  echo "[X] Delete containers and the database volume"
  echo "[I] Insert test data into the running database"
  echo "[U] Update PHP files in the running container"
  echo
  echo "Test data is not inserted unless you choose I, or answer y after C, E, or S."
  echo "Enter skips the question. The default is no."
  echo "U copies src/web into the running PHP container and reloads Apache."
  echo "The container stays in place. Dockerfile changes still need E."
  echo
}

get_action() {
  local choice=""
  while true; do
    if ! IFS= read -r -p "Select an action [C/E/S(default)/T/L/D/X/I/U]: " choice; then
      echo
      echo "ERROR: Could not read an action."
      return 1
    fi
    if [[ -z "$choice" ]]; then
      ACTION=S
      return 0
    fi
    ACTION=$(printf '%s' "$choice" | tr '[:lower:]' '[:upper:]')
    case "$ACTION" in
      C|E|S|T|L|D|X|I|U) return 0 ;;
    esac
    echo
    echo "ERROR: Please enter C, E, S, T, L, D, X, I, or U."
    echo
  done
}

main() {
  enter_script_dir
  print_banner
  get_action || fail
  case "$ACTION" in
    C) get_env_values || fail ;;
    E) load_env_strict || fail ;;
    S|T|L|D|X|I|U) load_env || fail ;;
  esac
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  main "$@"
fi
