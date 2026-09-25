#!/bin/sh
# ============================================================
#  系统救援工具箱 · 启动脚本
# ------------------------------------------------------------
#  用法（在网站根目录执行）：
#      sh rescue.sh              # 交互式菜单
#      sh rescue.sh info         # 查看系统信息
#      sh rescue.sh help         # 查看全部命令
#
#  说明：本脚本只负责找到 PHP 解释器并调用 rescue.php，
#        宝塔环境下即使 php 未加入 PATH 也能自动找到。
# ============================================================

DIR=$(cd "$(dirname "$0")" && pwd)

# ── 寻找 PHP 解释器 ──
PHP=""
if [ -n "$PHP_BIN" ] && command -v "$PHP_BIN" >/dev/null 2>&1; then
    PHP="$PHP_BIN"
elif command -v php >/dev/null 2>&1; then
    PHP="php"
else
    # 宝塔常见路径，取版本号最大且可执行的一个
    for p in $(ls -1d /www/server/php/*/bin/php 2>/dev/null | sort -V -r); do
        if [ -x "$p" ]; then
            PHP="$p"
            break
        fi
    done
fi

if [ -z "$PHP" ]; then
    echo "============================================================"
    echo " 未找到 PHP 命令行解释器（php 不在 PATH 中）"
    echo "------------------------------------------------------------"
    echo " 请用绝对路径执行，例如："
    echo "   /www/server/php/74/bin/php $DIR/rescue.php"
    echo " 或先指定解释器："
    echo "   PHP_BIN=/www/server/php/74/bin/php sh $DIR/rescue.sh"
    echo "============================================================"
    exit 127
fi

if [ ! -f "$DIR/rescue.php" ]; then
    echo "错误：未找到 $DIR/rescue.php，请确认 rescue.sh 与 rescue.php 在同一目录。"
    exit 1
fi

exec "$PHP" "$DIR/rescue.php" "$@"
