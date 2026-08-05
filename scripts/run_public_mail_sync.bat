@echo off
rem ============================================================
rem 파일 경로: C:\www\cpms\scripts\run_public_mail_sync.bat
rem 네이버 메일 PHP 백그라운드 동기화 실행 파일
rem ============================================================
setlocal EnableExtensions

set "SCRIPT_DIR=%~dp0"
set "SYNC_SCRIPT=%SCRIPT_DIR%public_mail_sync.php"
set "PHP_EXE="

rem PHP 경로를 알고 있다면 아래 빈 값에 직접 넣어도 됩니다.
rem 예: set "CPMS_PHP_EXE=C:\php\php.exe"
if not defined CPMS_PHP_EXE set "CPMS_PHP_EXE="

if not "%CPMS_PHP_EXE%"=="" if exist "%CPMS_PHP_EXE%" set "PHP_EXE=%CPMS_PHP_EXE%"

if not defined PHP_EXE if exist "C:\php\php.exe" set "PHP_EXE=C:\php\php.exe"
if not defined PHP_EXE if exist "C:\PHP\php.exe" set "PHP_EXE=C:\PHP\php.exe"
if not defined PHP_EXE if exist "C:\xampp\php\php.exe" set "PHP_EXE=C:\xampp\php\php.exe"
if not defined PHP_EXE if exist "C:\wamp\bin\php\php5.6.40\php.exe" set "PHP_EXE=C:\wamp\bin\php\php5.6.40\php.exe"
if not defined PHP_EXE if exist "C:\wamp64\bin\php\php5.6.40\php.exe" set "PHP_EXE=C:\wamp64\bin\php\php5.6.40\php.exe"
if not defined PHP_EXE if exist "C:\Program Files\PHP\v5.6\php.exe" set "PHP_EXE=C:\Program Files\PHP\v5.6\php.exe"
if not defined PHP_EXE if exist "C:\Program Files\PHP\php.exe" set "PHP_EXE=C:\Program Files\PHP\php.exe"
if not defined PHP_EXE if exist "C:\Program Files (x86)\PHP\v5.6\php.exe" set "PHP_EXE=C:\Program Files (x86)\PHP\v5.6\php.exe"
if not defined PHP_EXE if exist "C:\Program Files (x86)\PHP\php.exe" set "PHP_EXE=C:\Program Files (x86)\PHP\php.exe"

if not defined PHP_EXE (
    for /f "delims=" %%P in ('where php.exe 2^>nul') do (
        if not defined PHP_EXE set "PHP_EXE=%%P"
    )
)

if not exist "%SYNC_SCRIPT%" (
    call :write_error "동기화 PHP 파일을 찾을 수 없습니다: %SYNC_SCRIPT%"
    exit /b 3
)

if not defined PHP_EXE (
    call :write_error "php.exe를 찾지 못했습니다. run_public_mail_sync.bat의 CPMS_PHP_EXE 값에 PHP 5.6 php.exe 전체 경로를 입력하세요."
    exit /b 2
)

"%PHP_EXE%" "%SYNC_SCRIPT%"
set "RESULT=%ERRORLEVEL%"
exit /b %RESULT%

:write_error
set "LOG_DIR=%SCRIPT_DIR%..\storage\public_mail"
if not exist "%LOG_DIR%" mkdir "%LOG_DIR%" >nul 2>&1
>>"%LOG_DIR%\background_task_error.log" echo [%date% %time%] %~1
exit /b 0
