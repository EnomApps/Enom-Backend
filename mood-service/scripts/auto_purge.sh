#!/bin/bash
# Auto-purge mood entries older than 365 days
# Run daily via cron: 0 3 * * * /var/www/enom/mood-service/scripts/auto_purge.sh

LOG_FILE="/var/log/mood-service-purge.log"

echo "[$(date)] Starting auto-purge of mood entries older than 365 days" >> "$LOG_FILE"

cd /var/www/enom/mood-service
source venv/bin/activate

python -c "
from app.database import purge_old_entries
result = purge_old_entries(365)
print(f'Purged {result[\"purged_count\"]} entries older than {result[\"retention_days\"]} days')
" >> "$LOG_FILE" 2>&1

echo "[$(date)] Purge complete" >> "$LOG_FILE"
