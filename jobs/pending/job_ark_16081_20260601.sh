#!/bin/bash
# Job for NAAN: ark_16081
# Scheduled time: 2026-06-30 16:44:53
# Delay: 2565893 seconds from midnight

export TZ='America/Fortaleza'

ARK_DIR="/home/u753420024/domains/revistacarnaubais.com.br/public_html/ark-telemetry"
JOBS_DIR="/home/u753420024/domains/revistacarnaubais.com.br/public_html/ark-telemetry/jobs"
JOB_NAME="job_ark_16081_20260601"
LOG_FILE="$ARK_DIR/logs/pull_ark_16081_20260601.log"

# Create running marker
touch "$JOBS_DIR/${JOB_NAME}.running"

echo "$(date +%Y-%m-%d_%H:%M:%S) - Job started for ark_16081" >> $LOG_FILE

# Calculate remaining sleep time
CURRENT_EPOCH=$(date +%s)
MIDNIGHT_EPOCH=$(date -d "$(date +%Y-%m-%d) 00:00:00" +%s)
ELAPSED=$((CURRENT_EPOCH - MIDNIGHT_EPOCH))
REMAINING=$((2565893 - ELAPSED))

if [ $REMAINING -gt 0 ]; then
    echo "$(date +%Y-%m-%d_%H:%M:%S) - Sleeping for $REMAINING seconds" >> $LOG_FILE
    sleep $REMAINING
fi

echo "$(date +%Y-%m-%d_%H:%M:%S) - Executing pull for ark_16081" >> $LOG_FILE

/usr/bin/php $ARK_DIR/pull_single.php "naan=ark_16081" "token=33d7737612a839d89624ef08157ee0cedce4cb010a56d99559446c4db93bfc4f" "api_endpoint=https://revistacarnaubais.com.br/index.php/ojs/ark-api/telemetry" >> $LOG_FILE 2>&1

if [ $? -eq 0 ]; then
    echo "$(date +%Y-%m-%d_%H:%M:%S) - Success for ark_16081" >> $LOG_FILE
    # Create completion marker (NO extension)
    touch "$JOBS_DIR/${JOB_NAME}"
else
    echo "$(date +%Y-%m-%d_%H:%M:%S) - Failed for ark_16081" >> $LOG_FILE
fi

# Remove running marker
rm -f "$JOBS_DIR/${JOB_NAME}.running"
