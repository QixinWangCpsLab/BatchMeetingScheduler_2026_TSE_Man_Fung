@echo off
setlocal EnableExtensions DisableDelayedExpansion

cd /d "%~dp0"

set "DEFAULT_PHP_CONTAINER_NAME=batch-meeting-scheduler-php"
set "DEFAULT_DB_CONTAINER_NAME=batch-meeting-scheduler-db"
set "DEFAULT_DB_NAME=testwsqlnew"
set "DEFAULT_DB_USER=appuser"
set "DEFAULT_DB_PASS=change_me_app_password"
set "DEFAULT_DB_ROOT_PASSWORD=change_me_root_password"
set "DEFAULT_DB_HOST=localhost"
set "DEFAULT_DB_DOCKER_HOST=db"
set "DEFAULT_DB_PORT=3306"
set "DEFAULT_DB_CHARSET=utf8mb4"
set "DEFAULT_DB_COLLATION=utf8mb4_general_ci"
set "DEFAULT_PHP_PORT=8080"
set "DEFAULT_TZ=Asia/Hong_Kong"
set "DEFAULT_MAIL_HOST=smtp.gmail.com"
set "DEFAULT_MAIL_PORT=465"
set "DEFAULT_MAIL_SECURE=ssl"
set "DEFAULT_MAIL_USER="
set "DEFAULT_MAIL_PASS="
set "DEFAULT_MAIL_FROM="
set "DEFAULT_MAIL_FROM_NAME=do-not-reply"
set "DEFAULT_ROOT_PATH=/testwsqlnew"

echo ==========================================
echo Batch Meeting Scheduler Docker Manager
echo ==========================================
echo.
echo [C] Create containers and write a new .env
echo [E] Create containers from the current .env
echo [S] Start existing containers
echo [T] Stop containers
echo [L] Show container logs
echo [D] Delete containers but keep the database volume
echo [X] Delete containers and the database volume
echo.

:GET_ACTION
set "ACTION="
set /p "ACTION=Select an action [C/E/S(default)/T/L/D/X]: "
if not defined ACTION set "ACTION=S"

if /i "%ACTION%"=="C" goto GET_ENV_VALUES
if /i "%ACTION%"=="E" goto LOAD_ENV_STRICT
if /i "%ACTION%"=="S" goto LOAD_ENV
if /i "%ACTION%"=="T" goto LOAD_ENV
if /i "%ACTION%"=="L" goto LOAD_ENV
if /i "%ACTION%"=="D" goto LOAD_ENV
if /i "%ACTION%"=="X" goto LOAD_ENV

echo.
echo ERROR: Please enter C, E, S, T, L, D, or X.
echo.
goto GET_ACTION

:GET_ENV_VALUES
echo.
echo Enter Docker settings. Press Enter to use the value in brackets.
echo Passwords are written to the local .env file.
echo.

call :PROMPT_VALUE PHP_CONTAINER_NAME "PHP container name" "%DEFAULT_PHP_CONTAINER_NAME%"
call :PROMPT_VALUE DB_CONTAINER_NAME "Database container name" "%DEFAULT_DB_CONTAINER_NAME%"
call :PROMPT_VALUE DB_NAME "Database name" "%DEFAULT_DB_NAME%"
call :PROMPT_VALUE DB_USER "Database user" "%DEFAULT_DB_USER%"
call :PROMPT_VALUE DB_PASS "Database password" "%DEFAULT_DB_PASS%"
call :PROMPT_VALUE DB_ROOT_PASSWORD "MariaDB root password" "%DEFAULT_DB_ROOT_PASSWORD%"
call :PROMPT_VALUE DB_HOST "Database host used by PHP" "%DEFAULT_DB_HOST%"
call :PROMPT_PORT DB_PORT "Database host port" "%DEFAULT_DB_PORT%"
call :PROMPT_PORT PHP_PORT "PHP host port" "%DEFAULT_PHP_PORT%"
call :PROMPT_VALUE TZ "Timezone" "%DEFAULT_TZ%"
echo.
echo Mail and site settings. Press Enter to keep the bracket value.
echo Mail can stay blank until email is connected.
call :PROMPT_VALUE MAIL_HOST "SMTP host" "%DEFAULT_MAIL_HOST%"
call :PROMPT_PORT MAIL_PORT "SMTP port" "%DEFAULT_MAIL_PORT%"
call :PROMPT_VALUE MAIL_SECURE "SMTP security (ssl or tls)" "%DEFAULT_MAIL_SECURE%"
call :PROMPT_VALUE MAIL_USER "Mail username" "%DEFAULT_MAIL_USER%"
call :PROMPT_VALUE MAIL_PASS "Mail password" "%DEFAULT_MAIL_PASS%"
call :PROMPT_VALUE MAIL_FROM "Mail from address" "%DEFAULT_MAIL_FROM%"
call :PROMPT_VALUE MAIL_FROM_NAME "Mail from name" "%DEFAULT_MAIL_FROM_NAME%"
set "DEFAULT_ROOT_URL=http://localhost:%PHP_PORT%"
call :PROMPT_VALUE ROOT_URL "Public site URL" "%DEFAULT_ROOT_URL%"
call :PROMPT_VALUE ROOT_PATH "App base path" "%DEFAULT_ROOT_PATH%"
call :PROMPT_VALUE DB_CHARSET "Database charset" "%DEFAULT_DB_CHARSET%"
call :PROMPT_VALUE DB_COLLATION "Database collation" "%DEFAULT_DB_COLLATION%"
set "DB_DOCKER_HOST=%DEFAULT_DB_DOCKER_HOST%"

call :VALIDATE_ENV
if errorlevel 1 goto FAILED
call :WRITE_ENV
if errorlevel 1 goto FAILED
goto SHOW_CONFIGURATION

:LOAD_ENV_STRICT
if not exist ".env" (
    echo.
    echo ERROR: .env does not exist.
    echo Copy .env.example to .env, or select C to create one.
    goto FAILED
)

for /f "usebackq eol=# tokens=1,* delims==" %%A in (".env") do (
    if not "%%A"=="" set "%%A=%%B"
)

echo.
echo Checking .env and project files...
call :VALIDATE_ENV
if errorlevel 1 goto FAILED
goto SHOW_CONFIGURATION

:LOAD_ENV
if not exist ".env" (
    echo.
    echo ERROR: .env does not exist.
    echo Select C or E after creating .env.
    goto FAILED
)

for /f "usebackq eol=# tokens=1,* delims==" %%A in (".env") do (
    if not "%%A"=="" set "%%A=%%B"
)

if not defined PHP_CONTAINER_NAME set "PHP_CONTAINER_NAME=%DEFAULT_PHP_CONTAINER_NAME%"
if not defined DB_CONTAINER_NAME set "DB_CONTAINER_NAME=%DEFAULT_DB_CONTAINER_NAME%"
if not defined DB_HOST set "DB_HOST=%DEFAULT_DB_HOST%"
if not defined DB_DOCKER_HOST set "DB_DOCKER_HOST=%DEFAULT_DB_DOCKER_HOST%"
if not defined DB_PORT set "DB_PORT=%DEFAULT_DB_PORT%"
if not defined DB_CHARSET set "DB_CHARSET=%DEFAULT_DB_CHARSET%"
if not defined DB_COLLATION set "DB_COLLATION=%DEFAULT_DB_COLLATION%"
if not defined PHP_PORT set "PHP_PORT=%DEFAULT_PHP_PORT%"
if not defined TZ set "TZ=%DEFAULT_TZ%"
if not defined MAIL_HOST set "MAIL_HOST=%DEFAULT_MAIL_HOST%"
if not defined MAIL_PORT set "MAIL_PORT=%DEFAULT_MAIL_PORT%"
if not defined MAIL_SECURE set "MAIL_SECURE=%DEFAULT_MAIL_SECURE%"
if not defined MAIL_USER set "MAIL_USER=%DEFAULT_MAIL_USER%"
if not defined MAIL_PASS set "MAIL_PASS=%DEFAULT_MAIL_PASS%"
if not defined MAIL_FROM set "MAIL_FROM=%DEFAULT_MAIL_FROM%"
if not defined MAIL_FROM_NAME set "MAIL_FROM_NAME=%DEFAULT_MAIL_FROM_NAME%"
if not defined ROOT_URL set "ROOT_URL=http://localhost:%PHP_PORT%"
if not defined ROOT_PATH set "ROOT_PATH=%DEFAULT_ROOT_PATH%"
goto SHOW_CONFIGURATION

:SHOW_CONFIGURATION
echo.
echo ==========================================
echo Configuration
echo ==========================================
echo Action:             %ACTION%
echo PHP container:      %PHP_CONTAINER_NAME%
echo Database container: %DB_CONTAINER_NAME%
echo Database name:      %DB_NAME%
echo Database user:      %DB_USER%
echo Database password:  [hidden]
echo Root password:      [hidden]
echo PHP URL:            http://localhost:%PHP_PORT%
echo Database host:      %DB_HOST% (Docker PHP host: %DB_DOCKER_HOST%)
echo Database port:      localhost:%DB_PORT%
echo Database charset:   %DB_CHARSET% / %DB_COLLATION%
echo Timezone:           %TZ%
echo Mail:               %MAIL_USER% via %MAIL_HOST%:%MAIL_PORT% (%MAIL_SECURE%)
echo Mail password:      [hidden]
echo Mail from:          %MAIL_FROM_NAME% %MAIL_FROM%
echo Public URL:         %ROOT_URL%
echo App base path:      %ROOT_PATH%
echo ==========================================
echo.

call :REQUIRE_DOCKER
if errorlevel 1 goto FAILED

if /i "%ACTION%"=="T" goto STOP_CONTAINERS
if /i "%ACTION%"=="L" goto SHOW_LOGS
if /i "%ACTION%"=="D" goto DELETE_CONTAINERS
if /i "%ACTION%"=="X" goto DELETE_ALL

docker compose config >nul 2>&1
if errorlevel 1 (
    echo ERROR: Docker Compose configuration is invalid.
    goto FAILED
)

if /i "%ACTION%"=="S" goto START_CONTAINERS

echo Building the PHP and database images...
docker compose up -d --build
if errorlevel 1 (
    echo.
    echo ERROR: Failed to create the containers.
    goto FAILED
)
goto WAIT_FOR_SERVICES

:START_CONTAINERS
echo Starting the containers...
docker compose start
if errorlevel 1 (
    echo.
    echo ERROR: Failed to start the containers.
    goto FAILED
)
goto SHOW_SUCCESS

:STOP_CONTAINERS
echo Stopping the containers...
docker compose stop
if errorlevel 1 (
    echo.
    echo ERROR: Failed to stop the containers.
    goto FAILED
)
echo Containers stopped successfully.
goto END

:SHOW_LOGS
echo Showing logs. Press Ctrl+C to stop viewing logs.
docker compose logs --tail=100 -f
goto END

:DELETE_CONTAINERS
echo Removing containers. The mariadb-data volume will be kept.
docker compose down
if errorlevel 1 (
    echo.
    echo ERROR: Failed to remove the containers.
    goto FAILED
)
echo Containers removed successfully.
goto END

:DELETE_ALL
echo WARNING: This permanently deletes the database volume and all database data.
set "CONFIRM="
set /p "CONFIRM=Type DELETE to continue: "
if /i not "%CONFIRM%"=="DELETE" (
    echo Operation cancelled.
    goto END
)
docker compose down -v
if errorlevel 1 (
    echo.
    echo ERROR: Failed to remove the containers and volume.
    goto FAILED
)
echo Containers and database volume removed successfully.
goto END

:WAIT_FOR_SERVICES
echo Waiting for the database healthcheck and the PHP container...
set "WAIT_COUNT=0"

:WAIT_LOOP
set /a WAIT_COUNT+=1
if %WAIT_COUNT% GTR 36 (
    echo ERROR: Containers did not become ready within 3 minutes.
    docker compose ps
    docker compose logs --tail=50
    goto FAILED
)
set "DB_HEALTH="
set "PHP_STATE="
for /f "delims=" %%H in ('docker inspect --format "{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}" "%DB_CONTAINER_NAME%" 2^>nul') do set "DB_HEALTH=%%H"
for /f "delims=" %%H in ('docker inspect --format "{{.State.Status}}" "%PHP_CONTAINER_NAME%" 2^>nul') do set "PHP_STATE=%%H"
if /i "%DB_HEALTH%"=="unhealthy" (
    echo ERROR: Database container is unhealthy.
    docker compose logs --tail=50 db
    goto FAILED
)
if /i "%DB_HEALTH%"=="healthy" if /i "%PHP_STATE%"=="running" goto CHECK_SCHEMA
ping -n 6 127.0.0.1 >nul
goto WAIT_LOOP

:CHECK_SCHEMA
echo Checking that %DB_NAME% contains the exam table...
set "SCHEMA_OUT=%TEMP%\bms-schema-check.txt"
set "SCHEMA_ERR=%TEMP%\bms-schema-check.err"
docker exec -e MYSQL_PWD="%DB_PASS%" "%DB_CONTAINER_NAME%" mariadb --protocol=tcp -h127.0.0.1 -u"%DB_USER%" -N -B "%DB_NAME%" -e "SHOW TABLES LIKE 'exam'" > "%SCHEMA_OUT%" 2> "%SCHEMA_ERR%"
if errorlevel 1 (
    echo ERROR: Could not query the database with DB_USER.
    type "%SCHEMA_ERR%"
    del "%SCHEMA_OUT%" "%SCHEMA_ERR%" 2>nul
    goto FAILED
)
findstr /i /x "exam" "%SCHEMA_OUT%" >nul
if errorlevel 1 (
    echo ERROR: The database is up, but the exam table is missing.
    echo Schema import runs only when the database volume is first created.
    echo Select X to delete the volume, then select E again.
    del "%SCHEMA_OUT%" "%SCHEMA_ERR%" 2>nul
    goto FAILED
)
del "%SCHEMA_OUT%" "%SCHEMA_ERR%" 2>nul
goto SHOW_SUCCESS

:SHOW_SUCCESS
echo.
echo ==========================================
echo Services are running
echo ==========================================
echo PHP application: http://localhost:%PHP_PORT%
echo Database host:   localhost:%DB_PORT%
echo ==========================================
docker compose ps
start "" "http://127.0.0.1:%PHP_PORT%/"
goto END

:REQUIRE_DOCKER
docker info >nul 2>&1
if errorlevel 1 (
    echo ERROR: Docker is not running or is not available on PATH.
    exit /b 1
)
exit /b 0

:VALIDATE_ENV
set "ENV_ERROR=0"
if not exist "docker-compose.yml" (
    echo ERROR: docker-compose.yml is missing.
    set "ENV_ERROR=1"
)
if not exist "src\web\docker\Dockerfile" (
    echo ERROR: src\web\docker\Dockerfile is missing.
    set "ENV_ERROR=1"
)
if not exist "src\db\Dockerfile" (
    echo ERROR: src\db\Dockerfile is missing.
    set "ENV_ERROR=1"
)
if not exist "src\db\capstone_project.sql" (
    echo ERROR: src\db\capstone_project.sql is missing.
    set "ENV_ERROR=1"
)
if not exist "src\db\docker-entrypoint-initdb.d\01-charset.sh" (
    echo ERROR: src\db\docker-entrypoint-initdb.d\01-charset.sh is missing.
    set "ENV_ERROR=1"
)
call :CHECK_NAME PHP_CONTAINER_NAME "PHP_CONTAINER_NAME"
call :CHECK_NAME DB_CONTAINER_NAME "DB_CONTAINER_NAME"
call :CHECK_IDENT DB_NAME "DB_NAME"
call :CHECK_IDENT DB_USER "DB_USER"
call :CHECK_IDENT DB_CHARSET "DB_CHARSET"
call :CHECK_IDENT DB_COLLATION "DB_COLLATION"
call :CHECK_TOKEN DB_HOST "DB_HOST"
call :CHECK_TOKEN DB_DOCKER_HOST "DB_DOCKER_HOST"
call :CHECK_TOKEN TZ "TZ"
call :CHECK_TOKEN ROOT_PATH "ROOT_PATH"
call :CHECK_PORT DB_PORT "DB_PORT"
call :CHECK_PORT PHP_PORT "PHP_PORT"
call :CHECK_PASSWORD DB_PASS "DB_PASS"
call :CHECK_PASSWORD DB_ROOT_PASSWORD "DB_ROOT_PASSWORD"
if defined MAIL_PORT call :CHECK_PORT MAIL_PORT "MAIL_PORT"
if defined MAIL_SECURE call :CHECK_MAIL_SECURE
if /i "%DB_USER%"=="root" (
    echo ERROR: DB_USER cannot be root. Use DB_ROOT_PASSWORD for the root account.
    set "ENV_ERROR=1"
)
if defined DB_PORT if defined PHP_PORT if "%DB_PORT%"=="%PHP_PORT%" (
    echo ERROR: DB_PORT and PHP_PORT must be different.
    set "ENV_ERROR=1"
)
if /i not "%PHP_CONTAINER_NAME%"=="" if /i "%PHP_CONTAINER_NAME%"=="%DB_CONTAINER_NAME%" (
    echo ERROR: PHP_CONTAINER_NAME and DB_CONTAINER_NAME must be different.
    set "ENV_ERROR=1"
)
if /i not "%DB_DOCKER_HOST%"=="db" (
    echo ERROR: DB_DOCKER_HOST must be db, the Compose service name.
    set "ENV_ERROR=1"
)
if "%ENV_ERROR%"=="1" exit /b 1
exit /b 0

:CHECK_IDENT
call set "CHECK_VALUE=%%%~1%%"
if not defined CHECK_VALUE (
    echo ERROR: %~2 is missing from .env.
    set "ENV_ERROR=1"
    exit /b 0
)
echo(%CHECK_VALUE%| %SystemRoot%\System32\findstr.exe /r /x "[A-Za-z0-9_][A-Za-z0-9_]*" >nul
if errorlevel 1 (
    echo ERROR: %~2 must contain only letters, digits, and underscores.
    set "ENV_ERROR=1"
)
exit /b 0

:CHECK_NAME
call set "CHECK_VALUE=%%%~1%%"
if not defined CHECK_VALUE (
    echo ERROR: %~2 is missing from .env.
    set "ENV_ERROR=1"
    exit /b 0
)
echo(%CHECK_VALUE%| %SystemRoot%\System32\findstr.exe /r /x "[A-Za-z0-9][A-Za-z0-9_.-]*" >nul
if errorlevel 1 (
    echo ERROR: %~2 must be a Docker container name.
    set "ENV_ERROR=1"
)
exit /b 0

:CHECK_TOKEN
call set "CHECK_VALUE=%%%~1%%"
if not defined CHECK_VALUE (
    echo ERROR: %~2 is missing from .env.
    set "ENV_ERROR=1"
    exit /b 0
)
echo(%CHECK_VALUE%| %SystemRoot%\System32\findstr.exe /r /x "[A-Za-z0-9_./:+-][A-Za-z0-9_./:+-]*" >nul
if errorlevel 1 (
    echo ERROR: %~2 contains an unsupported character.
    set "ENV_ERROR=1"
)
exit /b 0

:CHECK_PORT
call set "PORT_VALUE=%%%~1%%"
if not defined PORT_VALUE (
    echo ERROR: %~2 is missing from .env.
    set "ENV_ERROR=1"
    exit /b 0
)
echo(%PORT_VALUE%| %SystemRoot%\System32\findstr.exe /r /x "[1-9][0-9]*" >nul
if errorlevel 1 (
    echo ERROR: %~2 must be a number from 1 to 65535.
    set "ENV_ERROR=1"
    exit /b 0
)
if %PORT_VALUE% GTR 65535 (
    echo ERROR: %~2 must be a number from 1 to 65535.
    set "ENV_ERROR=1"
)
exit /b 0

:CHECK_MAIL_SECURE
if /i "%MAIL_SECURE%"=="ssl" exit /b 0
if /i "%MAIL_SECURE%"=="tls" exit /b 0
echo ERROR: MAIL_SECURE must be ssl or tls.
set "ENV_ERROR=1"
exit /b 0

:CHECK_PASSWORD
call set "PASS_VALUE=%%%~1%%"
if not defined PASS_VALUE (
    echo ERROR: %~2 is missing from .env.
    set "ENV_ERROR=1"
    exit /b 0
)
powershell -NoProfile -Command "if ($env:%~1.Contains([char]34) -or $env:%~1 -match '[&|!^%%<> ]') { exit 1 } else { exit 0 }" >nul
if errorlevel 1 (
    echo ERROR: %~2 contains a space or one of these characters: ^& ^| ^^ ! %% " ^< ^>
    set "ENV_ERROR=1"
)
exit /b 0

:PROMPT_VALUE
set "%~1="
set /p "%~1=%~2 [%~3]: "
if not defined %~1 set "%~1=%~3"
exit /b 0

:PROMPT_PORT
call :PROMPT_VALUE "%~1" "%~2" "%~3"
call set "PORT_VALUE=%%%~1%%"
echo(%PORT_VALUE%| %SystemRoot%\System32\findstr.exe /r /x "[0-9][0-9]*" >nul
if errorlevel 1 (
    echo ERROR: %~2 must contain digits only.
    goto PROMPT_PORT
)
if %PORT_VALUE% LSS 1 goto PROMPT_PORT
if %PORT_VALUE% GTR 65535 goto PROMPT_PORT
exit /b 0

:WRITE_ENV
> ".env" (
    echo PHP_CONTAINER_NAME=%PHP_CONTAINER_NAME%
    echo DB_CONTAINER_NAME=%DB_CONTAINER_NAME%
    echo DB_NAME=%DB_NAME%
    echo DB_USER=%DB_USER%
    echo DB_PASS=%DB_PASS%
    echo DB_ROOT_PASSWORD=%DB_ROOT_PASSWORD%
    echo DB_HOST=%DB_HOST%
    echo DB_DOCKER_HOST=%DB_DOCKER_HOST%
    echo DB_PORT=%DB_PORT%
    echo DB_CHARSET=%DB_CHARSET%
    echo DB_COLLATION=%DB_COLLATION%
    echo PHP_PORT=%PHP_PORT%
    echo TZ=%TZ%
    echo MAIL_HOST=%MAIL_HOST%
    echo MAIL_PORT=%MAIL_PORT%
    echo MAIL_SECURE=%MAIL_SECURE%
    echo MAIL_USER=%MAIL_USER%
    echo MAIL_PASS=%MAIL_PASS%
    echo MAIL_FROM=%MAIL_FROM%
    echo MAIL_FROM_NAME=%MAIL_FROM_NAME%
    echo ROOT_URL=%ROOT_URL%
    echo ROOT_PATH=%ROOT_PATH%
)
if errorlevel 1 (
    echo ERROR: Could not write .env.
    exit /b 1
)
exit /b 0

:FAILED
echo.
pause
endlocal
exit /b 1

:END
echo.
pause
endlocal
exit /b 0
