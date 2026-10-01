#!/usr/bin/env bash
# Đồng bộ database production -> local (chỉ ĐỌC từ production).
#
#   scripts/sync-from-prod.sh                 # tải bản sao lưu đêm gần nhất, nạp vào DB local flashship_local
#   scripts/sync-from-prod.sh --fresh         # dump mới ngay bây giờ (single-transaction, không khoá bảng)
#
# DB đích (mặc định flashship_local, đổi bằng TARGET_DB=...) được tạo lại từ đầu; nếu đã tồn tại
# thì tự sao lưu vào ~/flashship-backups trước khi xoá.
# Sau khi nạp luôn xoá fcm_token/player_id và token đăng nhập để local KHÔNG gửi push nhầm
# cho người dùng thật. Nhớ kiểm tra .env local không dùng khoá Zalo/SMS/PayOS/Firebase của production.
set -euo pipefail

SSH_HOST="${SSH_HOST:-flashship-vps}"
REMOTE_BACKUP_DIR="/home/deploy/backups"
LOCAL_ROOT_ARGS=(-h127.0.0.1 -uroot)
WORK_DIR="${HOME}/flashship-backups"
TARGET_DB="${TARGET_DB:-flashship_local}"
FRESH=0

for arg in "$@"; do
  case "$arg" in
    --fresh) FRESH=1 ;;
    -h|--help) sed -n '2,11p' "$0"; exit 0 ;;
    *) echo "Tuỳ chọn không hợp lệ: $arg" >&2; exit 1 ;;
  esac
done

mkdir -p "$WORK_DIR"
STAMP=$(date +%Y%m%d_%H%M%S)
DUMP="${WORK_DIR}/prod_${STAMP}.sql.gz"

echo "▶ Lấy dữ liệu từ ${SSH_HOST} ..."
if [[ $FRESH -eq 1 ]]; then
  ssh "$SSH_HOST" "mysqldump --single-transaction --no-tablespaces --routines --triggers flashship | gzip" > "$DUMP"
else
  LATEST=$(ssh "$SSH_HOST" "ls -1t ${REMOTE_BACKUP_DIR}/flashship_*.sql.gz | head -1")
  echo "  bản sao lưu: $(basename "$LATEST")"
  scp -q "${SSH_HOST}:${LATEST}" "$DUMP"
fi
gzip -t "$DUMP"
echo "  đã tải $(du -h "$DUMP" | cut -f1) -> $DUMP"

if [[ -n "$(mysql "${LOCAL_ROOT_ARGS[@]}" -N -e "SHOW DATABASES LIKE '${TARGET_DB}'")" ]]; then
  LOCAL_BAK="${WORK_DIR}/${TARGET_DB}_before_sync_${STAMP}.sql.gz"
  echo "▶ Sao lưu DB local ${TARGET_DB} -> $LOCAL_BAK"
  mysqldump "${LOCAL_ROOT_ARGS[@]}" --single-transaction --routines --triggers "$TARGET_DB" 2>/dev/null | gzip > "$LOCAL_BAK"
  gzip -t "$LOCAL_BAK"
fi

echo "▶ Nạp vào database local: ${TARGET_DB}"
mysql "${LOCAL_ROOT_ARGS[@]}" -e "DROP DATABASE IF EXISTS \`${TARGET_DB}\`; CREATE DATABASE \`${TARGET_DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON \`${TARGET_DB}\`.* TO 'flashship'@'localhost';"
# Bỏ DEFINER để nạp được dưới user local.
gunzip -c "$DUMP" | sed -E 's/DEFINER=`[^`]+`@`[^`]+`//g' | mysql "${LOCAL_ROOT_ARGS[@]}" "$TARGET_DB"

echo "▶ Xoá token thông báo & token đăng nhập (tránh gửi nhầm cho người thật)"
# Chỉ xoá cột có thật trong DB (schema production có thể khác code mới).
for col in fcm_token player_id; do
  if [[ -n "$(mysql "${LOCAL_ROOT_ARGS[@]}" -N -e "SELECT 1 FROM information_schema.columns WHERE table_schema='${TARGET_DB}' AND table_name='users' AND column_name='${col}'")" ]]; then
    mysql "${LOCAL_ROOT_ARGS[@]}" "$TARGET_DB" -e "UPDATE users SET \`${col}\` = NULL"
  fi
done
mysql "${LOCAL_ROOT_ARGS[@]}" "$TARGET_DB" -e "DELETE FROM personal_access_tokens"

echo "▶ Chạy migration còn thiếu"
DB_DATABASE="$TARGET_DB" php artisan migrate --force

cat <<MSG

✔ Xong. Database: ${TARGET_DB}
  Dùng thử:   DB_DATABASE=${TARGET_DB} php artisan serve
  Hoặc sửa DB_DATABASE trong .env thành ${TARGET_DB}.
  Đừng quên: .env local không được dùng khoá Zalo/SMS/PayOS/Firebase của production.
MSG
