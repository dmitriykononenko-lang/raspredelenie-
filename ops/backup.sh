#!/bin/bash
# Ежедневный бэкап данных «Распределения»: /storage (правила, графики, очереди, токены,
# подписки, логи) + .env. Шифруется gpg (пароль в /root/.backup_pass), хранится 14 дней.
set -e
TS=$(date +%Y%m%d-%H%M); DST=/root/backups; SRC=/var/lib/docker/volumes/raspredelenie_storage/_data
TMP=$(mktemp -d)
cp -a "$SRC" "$TMP/storage"; rm -rf "$TMP/storage/dl" "$TMP/storage/locks"
cp /opt/raspredelenie/server/.env "$TMP/env"
tar -C "$TMP" -czf - . | gpg --batch --yes --symmetric --cipher-algo AES256 --passphrase-file /root/.backup_pass -o "$DST/raspredelenie-$TS.tar.gz.gpg"
rm -rf "$TMP"
find "$DST" -name 'raspredelenie-*.gpg' -mtime +14 -delete
echo "$(date -Is) ok $(du -h $DST/raspredelenie-$TS.tar.gz.gpg | cut -f1)" >> /var/log/dd-backup.log
