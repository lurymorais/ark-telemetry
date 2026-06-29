#!/bin/bash
export TZ='America/Fortaleza'
ARK_DIR="/home/u753420024/domains/revistacarnaubais.com.br/public_html/ark-telemetry"
JOB_NAME="job_TEST_IMMEDIATE_20260530"
LOG_FILE="$ARK_DIR/logs/pull_TEST_IMMEDIATE_20260530.log"

touch "$ARK_DIR/jobs/${JOB_NAME}.running"
echo "$(date) - Job started" >> $LOG_FILE

# Delay curto para teste (10 segundos)
sleep 10

echo "$(date) - Executing pull" >> $LOG_FILE
/usr/bin/php $ARK_DIR/pull_single.php "naan=TEST_00001" "token=f73ec217f1c4107224a5b9bb8763cb205131c3d93202c28c3a14b7157a74dbac" "api_endpoint=https://revistacarnaubais.com.br/index.php/ojs/ark-api/telemetry" >> $LOG_FILE 2>&1

if [ $? -eq 0 ]; then
    touch "$ARK_DIR/jobs/${JOB_NAME}"
    echo "$(date) - Success" >> $LOG_FILE
else
    echo "$(date) - Failed" >> $LOG_FILE
fi
rm -f "$ARK_DIR/jobs/${JOB_NAME}.running"
