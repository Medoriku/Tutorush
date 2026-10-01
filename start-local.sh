#!/bin/zsh
set -e

cd "${0:A:h}"

read -s "TUTORUSH_SMTP_PASSWORD?Hostinger mailbox password: "
echo
export TUTORUSH_SMTP_HOST="smtp.hostinger.com"
export TUTORUSH_SMTP_PORT="465"
export TUTORUSH_SMTP_USERNAME="info@tutorush.com"
export TUTORUSH_SMTP_PASSWORD
export TUTORUSH_MAIL_FROM="info@tutorush.com"
export TUTORUSH_MAIL_FROM_NAME="TutorRush"

if lsof -nP -iTCP:8080 -sTCP:LISTEN >/dev/null 2>&1; then
  echo "Port 8080 is already in use. Stop the existing server, then run this script again."
  exit 1
fi

exec php -S localhost:8080 -t .
