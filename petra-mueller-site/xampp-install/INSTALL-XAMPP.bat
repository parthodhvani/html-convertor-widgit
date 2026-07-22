@echo off
setlocal EnableExtensions EnableDelayedExpansion
title Petra Mueller - XAMPP WordPress Installer
color 0A

:: =====================================================================
:: Creates a full WordPress site at:
::   D:\xampp\htdocs\petra-mueller
:: Creates / recreates MySQL database:
::   wp_petra_mueller
:: Target URL (Apache on port 8082, matching phpMyAdmin):
::   http://localhost:8082/petra-mueller/
:: =====================================================================

set "XAMPP=D:\xampp"
set "HTDOCS=%XAMPP%\htdocs"
set "TARGET=%HTDOCS%\petra-mueller"
set "MYSQL=%XAMPP%\mysql\bin\mysql.exe"
set "MYSQLDUMP=%XAMPP%\mysql\bin\mysqldump.exe"
set "DBNAME=wp_petra_mueller"
set "SITEURL=http://localhost:8082/petra-mueller"
set "SCRIPT_DIR=%~dp0"

echo.
echo ============================================================
echo   Petra Mueller WordPress - XAMPP Installer
echo ============================================================
echo.

if not exist "%XAMPP%\xampp-control.exe" if not exist "%MYSQL%" (
  echo [ERROR] XAMPP not found at %XAMPP%
  echo Edit XAMPP path at the top of this script if needed.
  pause
  exit /b 1
)

if not exist "%MYSQL%" (
  echo [ERROR] MySQL not found: %MYSQL%
  echo Start MySQL from XAMPP Control Panel, then re-run.
  pause
  exit /b 1
)

echo [1/6] Checking MySQL connection (XAMPP root, empty password)...
"%MYSQL%" -u root -e "SELECT 1;" >nul 2>&1
if errorlevel 1 (
  echo [ERROR] Cannot connect to MySQL as root with empty password.
  echo Open phpMyAdmin: http://localhost:8082/phpmyadmin/
  echo and confirm root login works. If root has a password, edit
  echo MYSQL_OPTS below in this script.
  pause
  exit /b 1
)
echo       MySQL OK.

echo [2/6] Recreating database %DBNAME% ...
"%MYSQL%" -u root -e "DROP DATABASE IF EXISTS `%DBNAME%`; CREATE DATABASE `%DBNAME%` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
if errorlevel 1 (
  echo [ERROR] Could not create database.
  pause
  exit /b 1
)
echo       Database created.

echo [3/6] Preparing folder %TARGET% ...
if not exist "%HTDOCS%" (
  echo [ERROR] htdocs not found: %HTDOCS%
  pause
  exit /b 1
)

:: Prefer full bundled site if present
if exist "%SCRIPT_DIR%petra-mueller\wp-config.php" (
  echo       Using bundled full WordPress copy...
  if exist "%TARGET%" (
    echo       Removing existing %TARGET% ...
    rmdir /s /q "%TARGET%"
  )
  mkdir "%TARGET%" >nul 2>&1
  xcopy "%SCRIPT_DIR%petra-mueller\*" "%TARGET%\" /E /I /H /Y /Q >nul
  if errorlevel 1 (
    echo [ERROR] Failed to copy site files.
    pause
    exit /b 1
  )
) else (
  echo       Bundled full site not found - downloading WordPress...
  if exist "%TARGET%" rmdir /s /q "%TARGET%"
  mkdir "%TARGET%"
  powershell -NoProfile -ExecutionPolicy Bypass -Command ^
    "$ProgressPreference='SilentlyContinue'; ^
    Invoke-WebRequest -Uri 'https://wordpress.org/latest.zip' -OutFile '%TEMP%\wp-latest.zip'; ^
    Expand-Archive -Path '%TEMP%\wp-latest.zip' -DestinationPath '%TEMP%\wp-extract' -Force; ^
    Copy-Item -Path '%TEMP%\wp-extract\wordpress\*' -Destination '%TARGET%' -Recurse -Force"
  if errorlevel 1 (
    echo [ERROR] WordPress download/extract failed. Check internet access.
    pause
    exit /b 1
  )
  if exist "%SCRIPT_DIR%payload\wp-content" (
    echo       Copying theme + uploads payload...
    xcopy "%SCRIPT_DIR%payload\wp-content\*" "%TARGET%\wp-content\" /E /I /H /Y /Q >nul
  )
  :: Write wp-config from sample
  if exist "%TARGET%\wp-config-sample.php" (
    copy /Y "%TARGET%\wp-config-sample.php" "%TARGET%\wp-config.php" >nul
    powershell -NoProfile -ExecutionPolicy Bypass -File "%SCRIPT_DIR%write-wp-config.ps1" -Target "%TARGET%"
  )
)

echo [4/6] Importing database dump...
if not exist "%SCRIPT_DIR%wp_petra_mueller.sql" (
  echo [ERROR] Missing %SCRIPT_DIR%wp_petra_mueller.sql
  pause
  exit /b 1
)
"%MYSQL%" -u root --default-character-set=utf8mb4 "%DBNAME%" < "%SCRIPT_DIR%wp_petra_mueller.sql"
if errorlevel 1 (
  echo [WARN] Import with database name arg failed, trying USE + import...
  "%MYSQL%" -u root --default-character-set=utf8mb4 < "%SCRIPT_DIR%wp_petra_mueller.sql"
)
if errorlevel 1 (
  echo [ERROR] SQL import failed.
  echo You can import manually in phpMyAdmin:
  echo   1^) Open http://localhost:8082/phpmyadmin/
  echo   2^) Import file: %SCRIPT_DIR%wp_petra_mueller.sql
  pause
  exit /b 1
)
echo       SQL imported.

echo [5/6] Updating site URL in database to %SITEURL% ...
"%MYSQL%" -u root "%DBNAME%" -e "UPDATE wp_options SET option_value='%SITEURL%' WHERE option_name IN ('siteurl','home');"
:: Also fix serialized-ish theme asset URLs if any leftover 127.0.0.1:8080
"%MYSQL%" -u root "%DBNAME%" -e "UPDATE wp_posts SET post_content = REPLACE(post_content,'http://127.0.0.1:8080','%SITEURL%');"
"%MYSQL%" -u root "%DBNAME%" -e "UPDATE wp_posts SET post_content = REPLACE(post_content,'http://localhost:8082/petra-mueller','%SITEURL%');"

echo [6/6] Ensuring wp-config.php matches XAMPP...
powershell -NoProfile -ExecutionPolicy Bypass -File "%SCRIPT_DIR%write-wp-config.ps1" -Target "%TARGET%"

:: Activate theme safety (if wp-cli available skip; otherwise DB already has stylesheet)
"%MYSQL%" -u root "%DBNAME%" -e "UPDATE wp_options SET option_value='ai-starter' WHERE option_name IN ('stylesheet','template');"

echo.
echo ============================================================
echo   INSTALL COMPLETE
echo ============================================================
echo   Folder:   %TARGET%
echo   Database: %DBNAME%
echo   Site:     %SITEURL%/
echo   Admin:    %SITEURL%/wp-admin/
echo   User:     nimesh
echo   Pass:     nimesh@123
echo.
echo   phpMyAdmin: http://localhost:8082/phpmyadmin/
echo   Make sure Apache + MySQL are running in XAMPP Control Panel.
echo ============================================================
echo.
start "" "%SITEURL%/"
pause
endlocal
