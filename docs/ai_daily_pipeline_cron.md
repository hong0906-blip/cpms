# AI 일일 자동 파이프라인 크론 등록

`tools/ai_daily_pipeline_job.php` 하나가 다음 단계를 순서대로 실행합니다.

1. 일일 스냅샷
2. 입력완료 패턴
3. V2 투입비 예측
4. 실제 마감 및 예측 정확도
5. 입력 신뢰도
6. 이상징후
7. 투영 위험 및 손익 위험
8. CEO Index V2
9. GPT 경영 요약

PHP CLI와 크론 모두 한국시간을 사용해야 합니다. PHP 코드는 PHP 5.6 문법으로 작성되어 있습니다.

## 1. PHP CLI와 저장 경로 확인

```sh
command -v php
php -v
cd /실제/배포경로/cpms
test -w storage/logs
```

`php -v` 결과가 5.6인지 확인합니다. 아래 예시의 `/usr/bin/php`가 `command -v php` 결과와 다르면 실제 경로로 바꿉니다.

## 2. 전체 구조 설치/확인

```sh
cd /실제/배포경로/cpms
/usr/bin/php tools/ai_daily_pipeline_job.php --setup-only=1
```

`Status: SUCCESS`가 출력되어야 합니다. 크론 본 실행도 구조가 누락된 경우 자동으로 설치/보완을 먼저 시도합니다.

## 3. 매일 19:00 크론 등록

파이프라인을 실행할 리눅스 사용자로 다음 명령을 실행합니다.

```sh
crontab -e
```

다음 두 줄을 추가합니다. 경로는 실제 배포경로로 변경합니다.

```cron
CRON_TZ=Asia/Seoul
0 19 * * * cd /실제/배포경로/cpms && mkdir -p storage/logs && /usr/bin/php tools/ai_daily_pipeline_job.php >> /실제/배포경로/cpms/storage/logs/ai_daily_pipeline_cron.log 2>&1
```

`0 19 * * *`에는 요일 제한이 없으므로 주말과 공휴일을 포함해 매일 실행됩니다. 서버의 cron 구현이 `CRON_TZ`를 지원하지 않으면 서버 시간대를 `Asia/Seoul`로 맞추거나 호스팅 예약작업 화면에서 시간대를 한국시간으로 선택합니다.

기존에 `scripts/ai_daily_snapshot.php`만 실행하는 별도 크론이 있다면 제거합니다. 전체 파이프라인의 첫 단계가 같은 일일 스냅샷을 생성합니다.

등록 결과를 확인합니다.

```sh
crontab -l
```

## 4. 실행 확인

필요하면 등록 직후 한 번 직접 실행합니다. 이미 오늘 정상 완료된 경우에는 `SKIPPED`가 정상입니다.

```sh
cd /실제/배포경로/cpms
/usr/bin/php tools/ai_daily_pipeline_job.php
tail -n 100 storage/logs/ai_daily_pipeline_cron.log
```

관리 화면의 `AI 자동 분석 실행 이력`에서도 단계별 결과를 확인할 수 있습니다.

- 종료코드 `0`: 정상 완료 또는 오늘 실행 완료로 건너뜀
- 종료코드 `1`: 핵심 단계 실패
- 종료코드 `2`: 선택 단계가 일부 실패한 부분 완료

같은 작업이 겹치면 CLI 파일 잠금과 MySQL 잠금이 중복 실행을 막습니다. 정상 완료된 날짜는 `--force=1`을 주지 않는 한 다시 계산하지 않습니다.
