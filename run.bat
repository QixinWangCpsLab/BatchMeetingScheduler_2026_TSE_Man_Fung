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
set "DEFAULT_PHP_PORT=8080"
set "DEFAULT_TZ=Asia/Hong_Kong"

echo ==========================================
echo Batch Meeting Scheduler Docker Manager
echo ==========================================
echo.
echo [C] Create or recreate the PHP and database containers
echo [S] Start existing containers
echo [T] Stop containers
echo [L] Show container logs
echo [D] Delete containers but keep the database volume
echo [X] Delete containers and the database volume
echo.

:GET_ACTION
set "ACTION="
set /p "ACTION=Select an action [C/S(default)/T/L/D/X]: "
if not defined ACTION set "ACTION=S"

if /i "%ACTION%"=="C" goto GET_ENV_VALUES
if /i "%ACTION%"=="S" goto LOAD_ENV
if /i "%ACTION%"=="T" goto LOAD_ENV
if /i "%ACTION%"=="L" goto LOAD_ENV
if /i "%ACTION%"=="D" goto LOAD_ENV
if /i "%ACTION%"=="X" goto LOAD_ENV

echo.
echo ERROR: Please enter C, S, T, L, D, or X.
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
set "DB_DOCKER_HOST=%DEFAULT_DB_DOCKER_HOST%"

call :WRITE_ENV
if errorlevel 1 goto FAILED
goto SHOW_CONFIGURATION

:LOAD_ENV
if not exist ".env" (
    echo.
    echo ERROR: .env does not exist.
    echo Select C first to create it.
    goto FAILED
)

for /f "usebackq tokens=1,* delims==" %%A in (".env") do (
    if not "%%A"=="" set "%%A=%%B"
)

if not defined PHP_CONTAINER_NAME set "PHP_CONTAINER_NAME=%DEFAULT_PHP_CONTAINER_NAME%"
if not defined DB_CONTAINER_NAME set "DB_CONTAINER_NAME=%DEFAULT_DB_CONTAINER_NAME%"
if not defined DB_HOST set "DB_HOST=%DEFAULT_DB_HOST%"
if not defined DB_DOCKER_HOST set "DB_DOCKER_HOST=%DEFAULT_DB_DOCKER_HOST%"
if not defined DB_PORT set "DB_PORT=%DEFAULT_DB_PORT%"
if not defined PHP_PORT set "PHP_PORT=%DEFAULT_PHP_PORT%"
if not defined TZ set "TZ=%DEFAULT_TZ%"
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
echo Timezone:           %TZ%
echo ==========================================
echo.

if /i "%ACTION%"=="T" goto STOP_CONTAINERS
if /i "%ACTION%"=="L" goto SHOW_LOGS
if /i "%ACTION%"=="D" goto DELETE_CONTAINERS
if /i "%ACTION%"=="X" goto DELETE_ALL

docker compose config >nul 2>&1
if errorlevel 1 (
    echo ERROR: Docker Compose configuration is invalid or Docker is unavailable.
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
goto SHOW_SUCCESS

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
    echo PHP_PORT=%PHP_PORT%
    echo TZ=%TZ%
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
