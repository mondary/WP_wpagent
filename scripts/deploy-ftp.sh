#!/bin/bash
HOST="${FTP_HOST:-}"
USER="${FTP_USER:-}"
PASS="${FTP_PASS:-}"
REMOTE_DIR="/www/pk/wpagent"
LOCAL_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if [ -z "$HOST" ] || [ -z "$USER" ] || [ -z "$PASS" ]; then
  echo "Missing FTP credentials. Set FTP_HOST, FTP_USER, FTP_PASS."
  exit 1
fi

echo "🚀 Déploiement FTP en cours..."
lftp -u "$USER","$PASS" "$HOST" <<EOF_LFTP
set ssl:verify-certificate no
set ftp:passive-mode on

lcd "$LOCAL_DIR"
cd "$REMOTE_DIR"

# Mirror project files while keeping runtime data on the server
mirror -R . . \
  --exclude '.DS_Store' \
  --exclude-glob 'data/' \
  --exclude-glob '-pk/' \
  --exclude-glob '-test/' \
  --exclude-glob '.*' # Exclude hidden files and directories

# Get the current date and time
UPLOAD_TIME=$(date +"%Y-%m-%d %H:%M:%S")

echo "✅ Déploiement terminé à $UPLOAD_TIME."

# Deployment completed successfully
echo "Deployment completed successfully"
EOF_LFTP
