#!/bin/bash
# Job for NAAN: ark_16081
# Scheduled date: 2026-06-19
# Delay: 7200 seconds from midnight

export TZ='America/Fortaleza'

ARK_DIR="/home/u753420024/domains/revistacarnaubais.com.br/public_html/ark-telemetry"
JOBS_DIR="/home/u753420024/domains/revistacarnaubais.com.br/public_html/ark-telemetry/jobs"
JOB_NAME="job_ark_16081_20260619"
LOG_FILE="$ARK_DIR/logs/pull_ark_16081_20260619.log"

mkdir -p "$ARK_DIR/logs"

# Create running marker
touch "$JOBS_DIR/${JOB_NAME}.running"

echo "$(date +%Y-%m-%d_%H:%M:%S) - Job started for ark_16081" >> $LOG_FILE

# Calculate remaining sleep time
CURRENT_EPOCH=$(date +%s)
MIDNIGHT_EPOCH=$(date -d "$(date +%Y-%m-%d) 00:00:00" +%s)
ELAPSED=$((CURRENT_EPOCH - MIDNIGHT_EPOCH))
REMAINING=$((7200 - ELAPSED))

if [ $REMAINING -gt 0 ]; then
    echo "$(date +%Y-%m-%d_%H:%M:%S) - Sleeping for $REMAINING seconds" >> $LOG_FILE
    sleep $REMAINING
fi

echo "$(date +%Y-%m-%d_%H:%M:%S) - Executing pull for ark_16081" >> $LOG_FILE

# Chamar o novo coletor mensal
/usr/bin/php $ARK_DIR/monthly_collector.php "naan=ark_16081" "force=1" >> $LOG_FILE 2>&1

if [ $? -eq 0 ]; then
    echo "$(date +%Y-%m-%d_%H:%M:%S) - Success for ark_16081" >> $LOG_FILE
    # Create completion marker
    touch "$JOBS_DIR/${JOB_NAME}"
else
    echo "$(date +%Y-%m-%d_%H:%M:%S) - Failed for ark_16081" >> $LOG_FILE
fi

# Remove running marker
rm -f "$JOBS_DIR/${JOB_NAME}.running"
