@echo off
rem ============================================================
rem 파일 경로: C:\www\cpms\scripts\install_public_mail_task.bat
rem Windows 작업 스케줄러에 1분 간격 자동수집 작업을 등록합니다.
rem 반드시 마우스 오른쪽 버튼의 '관리자 권한으로 실행'을 사용하세요.
rem ============================================================
setlocal EnableExtensions

set "TASK_NAME=CPMS Naver Mail Sync"
set "SCRIPT_DIR=%~dp0"
set "RUN_FILE=%SCRIPT_DIR%run_public_mail_sync.bat"

net session >nul 2>&1
if errorlevel 1 (
    echo.
    echo [실패] 관리자 권한이 필요합니다.
    echo 이 파일을 마우스 오른쪽 버튼으로 누른 뒤 '관리자 권한으로 실행'을 선택하세요.
    echo.
    pause
    exit /b 1
)

if not exist "%RUN_FILE%" (
    echo.
    echo [실패] 실행 파일을 찾을 수 없습니다.
    echo %RUN_FILE%
    echo.
    pause
    exit /b 2
)

echo.
echo 1단계: PHP 실행 가능 여부를 확인합니다.
call "%RUN_FILE%"
set "TEST_RESULT=%ERRORLEVEL%"
if "%TEST_RESULT%"=="2" (
    echo.
    echo [실패] php.exe 경로를 찾지 못했습니다.
    echo run_public_mail_sync.bat 파일을 메모장으로 열고 CPMS_PHP_EXE에 php.exe 경로를 입력하세요.
    echo 예: set "CPMS_PHP_EXE=C:\php\php.exe"
    echo.
    pause
    exit /b 3
)
if "%TEST_RESULT%"=="3" (
    echo.
    echo [실패] public_mail_sync.php 파일을 찾지 못했습니다.
    echo.
    pause
    exit /b 4
)

echo.
echo 2단계: Windows 작업 스케줄러에 등록합니다.
schtasks /Create /TN "%TASK_NAME%" /TR "%RUN_FILE%" /SC MINUTE /MO 1 /RU SYSTEM /RL HIGHEST /F >nul
if errorlevel 1 (
    echo.
    echo [실패] 작업 스케줄러 등록에 실패했습니다.
    echo 관리자 권한으로 실행했는지 확인하세요.
    echo.
    pause
    exit /b 5
)

schtasks /Run /TN "%TASK_NAME%" >nul 2>&1

echo.
echo ============================================================
echo [완료] 24시간 네이버 메일 자동수집을 등록했습니다.
echo 작업 이름: %TASK_NAME%
echo 실행 간격: 1분
echo 실행 계정: Windows SYSTEM
echo ============================================================
echo.
echo CPMS 네이버 메일 연동 설정이 '사용' 상태여야 실제로 메일을 가져옵니다.
echo 실행 기록: C:\www\cpms\storage\public_mail\background_sync.log
echo.
pause
exit /b 0
