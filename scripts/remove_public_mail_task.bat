@echo off
rem ============================================================
rem 파일 경로: C:\www\cpms\scripts\remove_public_mail_task.bat
rem 등록된 24시간 네이버 메일 자동수집 작업만 제거합니다.
rem 메일 설정과 이미 가져온 데이터는 삭제하지 않습니다.
rem ============================================================
setlocal EnableExtensions

set "TASK_NAME=CPMS Naver Mail Sync"

net session >nul 2>&1
if errorlevel 1 (
    echo.
    echo [실패] 관리자 권한이 필요합니다.
    echo 이 파일을 마우스 오른쪽 버튼으로 누른 뒤 '관리자 권한으로 실행'을 선택하세요.
    echo.
    pause
    exit /b 1
)

schtasks /Query /TN "%TASK_NAME%" >nul 2>&1
if errorlevel 1 (
    echo.
    echo 등록된 자동수집 작업이 없습니다.
    echo.
    pause
    exit /b 0
)

schtasks /Delete /TN "%TASK_NAME%" /F >nul
if errorlevel 1 (
    echo.
    echo [실패] 자동수집 작업 제거에 실패했습니다.
    echo.
    pause
    exit /b 2
)

echo.
echo [완료] 24시간 네이버 메일 자동수집 작업을 제거했습니다.
echo CPMS에 저장된 메일과 설정은 그대로 유지됩니다.
echo.
pause
exit /b 0
