<?php
/**
 * ============================================================
 *  系统救援工具箱  Rescue Toolkit  v1.0
 * ------------------------------------------------------------
 *  适用：云主机售卖系统 (ThinkPHP5)
 *  用途：服务器终端一键救援。无需登录后台，直接修复 / 重置后台关键配置。
 *
 *  用法（在网站根目录执行，即与 app/ frame/ public/ 同级）：
 *      php rescue.php                    交互式菜单（推荐）
 *      php rescue.php info               查看系统信息
 *      php rescue.php admin-pass 新密码   重置后台登录密码
 *      php rescue.php admin-user 新账号   修改后台登录账号
 *      php rescue.php entrance manage    修改后台登录入口
 *      php rescue.php reset safe         恢复默认设置（安全模式）
 *      php rescue.php help               查看全部命令
 *
 *  加 -y / --yes 可跳过二次确认（用于脚本化）。
 *
 *  安全说明：
 *    - 仅允许命令行 (CLI) 运行，浏览器访问直接拒绝（403）。
 *    - 所有写操作前自动备份到 runtime/rescue_backup/，并记录到 runtime/rescue_log/。
 *    - 使用完毕后建议删除本文件（或改名）。
 * ============================================================
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("403 Forbidden：救援工具箱仅允许在服务器终端（命令行）中运行。\n");
}

@set_time_limit(0);
@ini_set('display_errors', '1');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

define('RESCUE_ROOT', __DIR__);
define('RESCUE_VERSION', '1.0.0');
define('RESCUE_BACKUP_DIR', RESCUE_ROOT . '/runtime/rescue_backup');
define('RESCUE_LOG_DIR', RESCUE_ROOT . '/runtime/rescue_log');

// ── 终端颜色支持检测 ──
$rescueColor = true;
if (DIRECTORY_SEPARATOR === '\\') {
    $rescueColor = (bool) (getenv('ANSICON') || getenv('WT_SESSION') || getenv('ConEmuANSI') === 'ON');
}
if (getenv('NO_COLOR') !== false) {
    $rescueColor = false;
}
define('RESCUE_COLOR', $rescueColor);

/* ============================================================
 *  一、终端输出
 * ============================================================ */

function c($text, $code)
{
    return RESCUE_COLOR ? "\033[{$code}m{$text}\033[0m" : $text;
}

function out($s = '')
{
    fwrite(STDOUT, $s . PHP_EOL);
}

function out_raw($s)
{
    fwrite(STDOUT, $s);
}

function ok($s)
{
    out(c('  [√] ', '32') . $s);
}

function warn($s)
{
    out(c('  [!] ', '33') . $s);
}

function err($s)
{
    out(c('  [×] ', '31') . $s);
}

function info($s)
{
    out(c('  · ', '36') . $s);
}

function hr($ch = '-', $len = 62)
{
    out(c(str_repeat($ch, $len), '90'));
}

function title($s)
{
    out();
    hr('=');
    out(c('  ' . $s, '1;36'));
    hr('=');
}

function kv($k, $v, $width = 18)
{
    $pad = $width - (int) (mb_strlen($k, 'UTF-8') + 2);
    if ($pad < 1) {
        $pad = 1;
    }
    out('  ' . c($k . '：', '90') . str_repeat(' ', $pad) . $v);
}

/* ============================================================
 *  二、数据库连接
 * ============================================================ */

function db_config()
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }
    $file = RESCUE_ROOT . '/app/database.php';
    if (!is_file($file)) {
        err('找不到数据库配置文件：app/database.php');
        out('  请确认 rescue.php 已放在网站根目录（与 app/ frame/ public/ 同级）。');
        exit(1);
    }
    $cfg = include $file;
    if (!is_array($cfg) || empty($cfg['database'])) {
        err('数据库配置无效，站点可能尚未完成安装。');
        out('  请先访问站点完成安装向导，或手工检查 app/database.php。');
        exit(1);
    }
    return $cfg;
}

function P()
{
    $cfg = db_config();
    return isset($cfg['prefix']) ? (string) $cfg['prefix'] : '';
}

function db()
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $cfg = db_config();
    $host = !empty($cfg['hostname']) ? $cfg['hostname'] : '127.0.0.1';
    $port = !empty($cfg['hostport']) ? $cfg['hostport'] : '3306';
    $charset = !empty($cfg['charset']) ? $cfg['charset'] : 'utf8mb4';
    // 注意：DSN 必须带 dbname，否则会连到默认库导致「表不存在」误判
    $dsn = "mysql:host={$host};port={$port};dbname={$cfg['database']};charset={$charset}";
    try {
        $pdo = new PDO($dsn, isset($cfg['username']) ? $cfg['username'] : '', isset($cfg['password']) ? $cfg['password'] : '', [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT            => 8,
        ]);
    } catch (PDOException $e) {
        err('数据库连接失败：' . $e->getMessage());
        out();
        out('  请检查 app/database.php 里的 hostname / database / username / password 是否正确，');
        out('  以及数据库服务是否已启动、账号是否有该库权限。');
        exit(1);
    }
    return $pdo;
}

function q($sql, $params = [])
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function rows($sql, $params = [])
{
    return q($sql, $params)->fetchAll();
}

function row($sql, $params = [])
{
    $r = q($sql, $params)->fetch();
    return $r === false ? null : $r;
}

function val($sql, $params = [])
{
    $r = q($sql, $params)->fetchColumn();
    return $r === false ? null : $r;
}

function exec_sql($sql, $params = [])
{
    return q($sql, $params)->rowCount();
}

/** 带前缀的安全表名 */
function T($name)
{
    return '`' . P() . $name . '`';
}

function table_exists($name)
{
    try {
        $st = db()->prepare('SHOW TABLES LIKE ?');
        $st->execute([P() . $name]);
        return $st->fetchColumn() !== false;
    } catch (Throwable $e) {
        return false;
    }
}

function columns($table)
{
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }
    $cols = [];
    try {
        foreach (rows("SHOW COLUMNS FROM `{$table}`") as $r) {
            $cols[] = $r['Field'];
        }
    } catch (Throwable $e) {
    }
    $cache[$table] = $cols;
    return $cols;
}

/* ============================================================
 *  三、站点配置读写
 * ============================================================ */

function web_row($refresh = false)
{
    static $row = null;
    if ($row === null || $refresh) {
        $row = row('SELECT * FROM ' . T('web') . ' ORDER BY id ASC LIMIT 1');
    }
    return $row;
}

function web_get($key, $default = '')
{
    $w = web_row();
    return ($w && array_key_exists($key, $w)) ? $w[$key] : $default;
}

/**
 * 安全写 web 表（自动过滤不存在的列），返回受影响行数
 */
function web_save(array $data)
{
    if (empty($data)) {
        return 0;
    }
    $w = web_row();
    if (!$w) {
        err('web 表没有站点配置记录，无法写入。可先执行「数据库救援」补齐。');
        return 0;
    }
    $cols = columns(P() . 'web');
    $sets = [];
    $params = [];
    foreach ($data as $k => $v) {
        if (!in_array($k, $cols, true)) {
            continue;
        }
        $sets[] = "`{$k}` = ?";
        $params[] = $v;
    }
    if (empty($sets)) {
        return 0;
    }
    $params[] = (int) $w['id'];
    $n = exec_sql('UPDATE ' . T('web') . ' SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
    // 刷新内存缓存，保证后续读取到最新配置（例如刚改完后台入口后拼登录地址）
    web_row(true);
    return $n;
}

/* ============================================================
 *  四、备份与日志
 * ============================================================ */

function ensure_dir($dir)
{
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return is_dir($dir);
}

function backup_snapshot($tag)
{
    if (!ensure_dir(RESCUE_BACKUP_DIR)) {
        warn('备份目录创建失败，已跳过备份：' . RESCUE_BACKUP_DIR);
        return '';
    }
    $data = [
        'time'   => date('Y-m-d H:i:s'),
        'tag'    => $tag,
        'web'    => web_row(),
        'admins' => table_exists('admin')
            ? rows('SELECT id, name, user, mail, qq, role_id, is_super, status FROM ' . T('admin'))
            : [],
    ];
    $safeTag = preg_replace('/[^A-Za-z0-9_\-]/', '_', $tag);
    $file = RESCUE_BACKUP_DIR . '/' . date('Ymd_His') . '_' . $safeTag . '.json';
    if (@file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false) {
        warn('备份写入失败（目录可能不可写）：' . $file);
        return '';
    }
    return $file;
}

function rescue_log($action, $detail = '')
{
    ensure_dir(RESCUE_LOG_DIR);
    $line = sprintf("[%s] %s\t%s\n", date('Y-m-d H:i:s'), $action, $detail);
    @file_put_contents(RESCUE_LOG_DIR . '/' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
}

function bytes_human($bytes)
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    $bytes = (float) $bytes;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 2) . $units[$i];
}

/* ============================================================
 *  五、交互输入
 * ============================================================ */

function ask($prompt, $default = '')
{
    $hint = $default !== '' ? c(' [' . $default . ']', '90') : '';
    out_raw('  ' . $prompt . $hint . '：');
    $v = fgets(STDIN);
    if ($v === false) {
        out();
        return $default;
    }
    $v = trim(str_replace(["\r", "\n"], '', $v));
    return $v === '' ? $default : $v;
}

function ask_secret($prompt)
{
    out_raw('  ' . $prompt . '：');
    $hidden = false;
    if (DIRECTORY_SEPARATOR !== '\\' && function_exists('shell_exec')) {
        @shell_exec('stty -echo 2>/dev/null');
        $hidden = true;
    }
    $v = fgets(STDIN);
    if ($hidden) {
        @shell_exec('stty echo 2>/dev/null');
        out();
    }
    if ($v === false) {
        return '';
    }
    return trim(str_replace(["\r", "\n"], '', $v));
}

function pause()
{
    out();
    out_raw(c('  按回车键继续...', '90'));
    @fgets(STDIN);
}

function confirm($prompt, $force = false)
{
    out();
    out('  ' . c('⚠  ' . $prompt, '33'));
    if ($force) {
        out('  ' . c('（已通过 -y 自动确认）', '90'));
        return true;
    }
    out_raw('  ' . c('确认请输入 YES（其它任意键取消）：', '33'));
    $a = fgets(STDIN);
    if ($a === false) {
        out();
        return false;
    }
    return strtoupper(trim($a)) === 'YES';
}

function choose($prompt, $options, $defaultKey = '')
{
    out();
    foreach ($options as $k => $label) {
        out('   ' . c('[' . $k . ']', '36') . ' ' . $label);
    }
    $a = ask($prompt, $defaultKey);
    return isset($options[$a]) ? $a : $defaultKey;
}

/* ============================================================
 *  六、校验工具
 * ============================================================ */

function is_super_admin($admin)
{
    return intval($admin['is_super']) === 1;
}

/**
 * 密码强度检查：返回 [是否达标, 提示]
 */
function check_password_strength($pwd)
{
    if (mb_strlen($pwd, 'UTF-8') < 6) {
        return [false, '密码长度至少 6 位'];
    }
    $hasUpper = preg_match('/[A-Z]/', $pwd) === 1;
    $hasLower = preg_match('/[a-z]/', $pwd) === 1;
    $hasDigit = preg_match('/[0-9]/', $pwd) === 1;
    if ($hasUpper && $hasLower && $hasDigit) {
        return [true, ''];
    }
    return [true, '⚠ 该密码未同时包含大写字母、小写字母和数字，安全性偏低（站点注册策略要求三者齐全）'];
}

/** 后台入口路径校验：返回 [是否合法, 提示] */
function check_entrance_path($path)
{
    if ($path === '') {
        return [true, ''];
    }
    // 允许显式恢复为默认入口 admin
    if (strtolower($path) === 'admin') {
        return [true, ''];
    }
    if (!preg_match('/^[a-zA-Z0-9]+$/', $path)) {
        return [false, '后台入口只能包含字母和数字（不含斜杠、横线、下划线）'];
    }
    $reserved = ['admin', 'user', 'login', 'register', 'pay', 'api', 'install', 'index', 'product', 'order',
        'ticket', 'cart', 'captcha', 'oauth', 'cron', 'sq', 'live2d', 'rankings', 'help', 'announcement',
        'announcements', 'pwreset', 'datav', 'server', 'domain', 'aff', 'qrlogin', 'verify_email',
        'static', 'upload', 'favicon', 'robots'];
    if (in_array(strtolower($path), $reserved, true)) {
        return [false, '「' . $path . '」与站点已有功能冲突，请换一个（如 manage、myadmin、backend01）'];
    }
    return [true, ''];
}

/** 清除后台入口缓存，让新入口立即生效 */
function clear_entrance_cache()
{
    $files = [
        RESCUE_ROOT . '/runtime/admin_entrance.json',
        RESCUE_ROOT . '/runtime/admin_entrance.php',
    ];
    $n = 0;
    foreach ($files as $f) {
        if (is_file($f)) {
            if (@unlink($f)) {
                $n++;
            }
        }
    }
    return $n;
}

function admin_login_url_hint()
{
    $path = trim((string) web_get('admin_path', 'admin'), '/');
    if ($path === '') {
        $path = 'admin';
    }
    return '/' . $path . '/login';
}

/* ============================================================
 *  七、功能：系统信息
 * ============================================================ */

function cmd_info()
{
    title('系统信息总览');

    $cfg = db_config();
    kv('工具箱版本', RESCUE_VERSION . '  （脚本路径：' . RESCUE_ROOT . '/rescue.php）');
    kv('PHP 版本', PHP_VERSION . '  /  ' . PHP_SAPI);
    kv('操作系统', PHP_OS . '  ' . php_uname('m'));
    kv('PHP 时间', date('Y-m-d H:i:s') . '  （PHP 时区：' . date_default_timezone_get() . '）');
    kv('站点根目录', RESCUE_ROOT);

    out();
    hr();
    out(c('  数据库', '1'));
    kv('连接地址', $cfg['hostname'] . ':' . (isset($cfg['hostport']) ? $cfg['hostport'] : '3306'));
    kv('数据库名', $cfg['database']);
    kv('账号', isset($cfg['username']) ? $cfg['username'] : '');
    kv('表前缀', P() === '' ? '(无)' : P());
    try {
        $ver = val('SELECT VERSION()');
        $conn = rows('SELECT DATABASE() AS db');
        kv('连接状态', c('正常', '32') . '  MySQL ' . $ver . '  当前库：' . $conn[0]['db']);
    } catch (Throwable $e) {
        kv('连接状态', c('异常：' . $e->getMessage(), '31'));
    }

    out();
    hr();
    out(c('  站点与后台', '1'));
    if (web_row()) {
        kv('站点名称', (string) web_get('name'));
        kv('站点描述', mb_substr((string) web_get('description'), 0, 40, 'UTF-8'));
        kv('前台模板', web_get('template', 'default') . '   后台模板：' . web_get('admintemplate', 'default'));
        kv('后台入口', c(trim((string) web_get('admin_path', 'admin'), '/'), '33')
            . '   →  登录地址 ' . c(admin_login_url_hint(), '1;32'));
        $wh = (string) web_get('wh', '0') === '1';
        kv('维护模式', $wh ? c('已开启（前台显示维护页）', '31') : c('关闭', '32'));
        $entFile = RESCUE_ROOT . '/runtime/admin_entrance.json';
        kv('入口缓存', is_file($entFile)
            ? c('存在', '33') . '（修改入口后若有异常可清缓存）'
            : c('不存在（正常）', '32'));
    } else {
        warn('web 表没有站点配置记录，站点可能未安装完成。可执行「数据库救援」补齐。');
    }

    out();
    hr();
    out(c('  管理员账号', '1'));
    if (table_exists('admin')) {
        $admins = rows('SELECT id, name, user, mail, is_super, role_id, status FROM ' . T('admin') . ' ORDER BY id ASC');
        if ($admins) {
            out('   ' . sprintf('%-4s %-18s %-12s %-10s %s', 'ID', '账号', '昵称', '状态', '身份'));
            foreach ($admins as $a) {
                out('   ' . sprintf(
                    '%-4s %-18s %-12s %-10s %s',
                    $a['id'],
                    $a['user'],
                    mb_substr((string) $a['name'], 0, 6, 'UTF-8'),
                    intval($a['status']) === 1 ? c('正常', '32') : c('禁用', '31'),
                    is_super_admin($a) ? '超级管理员' : ('角色#' . intval($a['role_id']))
                ));
            }
        } else {
            err('管理员表为空！请用「管理员账号管理 → 新增管理员」创建一个可用账号。');
        }
    } else {
        err('管理员表 ' . T('admin') . ' 不存在，请执行「数据库救援」。');
    }

    out();
    hr();
    out(c('  业务数据概览', '1'));
    $statTables = [
        'user'    => '注册用户',
        'cart'    => '产品/套餐',
        'order'   => '订单',
        'server'  => '主机服务器',
        'host'    => '已开通主机',
        'ticket'  => '工单',
        'cdkey'   => '卡密',
        'transaction' => '资金流水',
    ];
    foreach ($statTables as $t => $label) {
        $name = P() . $t;
        if (table_exists($t)) {
            try {
                $n = (int) val("SELECT COUNT(*) FROM `{$name}`");
                kv($label, number_format($n) . '  ' . c('(' . $name . ')', '90'));
            } catch (Throwable $e) {
                kv($label, c('读取失败', '31'));
            }
        } else {
            kv($label, c('表不存在', '31') . '  ' . c('(' . $name . ')', '90'));
        }
    }
    out();
    info('提示：缺失的业务表会在访问站点首页时自动补齐（自愈机制）。');
}

/* ============================================================
 *  八、功能：修改后台登录密码
 * ============================================================ */

function pick_admin()
{
    if (!table_exists('admin')) {
        err('管理员表不存在，请先执行「数据库救援」。');
        return null;
    }
    $admins = rows('SELECT id, name, user, mail, is_super, role_id, status FROM ' . T('admin') . ' ORDER BY id ASC');
    if (empty($admins)) {
        err('管理员表为空，请先用「新增管理员」创建一个账号。');
        return null;
    }
    if (count($admins) === 1) {
        info('仅有一个管理员，自动选择：[ID ' . $admins[0]['id'] . '] ' . $admins[0]['user']);
        return $admins[0];
    }
    out();
    out('   ' . sprintf('%-4s %-18s %-12s %-8s %s', 'ID', '账号', '昵称', '状态', '身份'));
    foreach ($admins as $a) {
        out('   ' . sprintf(
            '%-4s %-18s %-12s %-8s %s',
            $a['id'],
            $a['user'],
            mb_substr((string) $a['name'], 0, 6, 'UTF-8'),
            intval($a['status']) === 1 ? '正常' : '禁用',
            is_super_admin($a) ? '超级管理员' : ('角色#' . intval($a['role_id']))
        ));
    }
    $defId = (string) $admins[0]['id'];
    $id = (int) ask('请输入要操作的管理员 ID', $defId);
    foreach ($admins as $a) {
        if ((int) $a['id'] === $id) {
            return $a;
        }
    }
    err('未找到 ID 为 ' . $id . ' 的管理员。');
    return null;
}

function cmd_admin_pass($args = [], $autoYes = false)
{
    title('修改后台登录密码');

    $admin = pick_admin();
    if (!$admin) {
        return;
    }

    $pwd = isset($args[0]) ? $args[0] : '';
    if ($pwd === '') {
        out();
        info('留空可直接回车取消。密码长度至少 6 位。');
        $pwd = ask_secret('请输入新密码');
        if ($pwd === '') {
            warn('未输入密码，已取消。');
            return;
        }
        $pwd2 = ask_secret('请再次输入新密码');
        if ($pwd !== $pwd2) {
            err('两次输入的密码不一致，已取消。');
            return;
        }
    }

    list($pass, $msg) = check_password_strength($pwd);
    if (!$pass) {
        err($msg);
        return;
    }
    if ($msg !== '') {
        warn($msg);
        if (!confirm('是否仍要用这个密码继续？（建议使用 大小写字母+数字 的强密码）', $autoYes)) {
            warn('已取消。');
            return;
        }
    }

    if (!confirm('即将把管理员「' . $admin['user'] . '」(ID ' . $admin['id'] . ') 的登录密码重置为新密码，是否继续？', $autoYes)) {
        warn('已取消。');
        return;
    }

    $backup = backup_snapshot('admin_pass');
    $hash = password_hash($pwd, PASSWORD_DEFAULT);
    exec_sql('UPDATE ' . T('admin') . ' SET `password` = ? WHERE id = ?', [$hash, (int) $admin['id']]);

    // 顺带清除该账号的登录失败锁定，避免重置后仍被锁定
    $cleared = clear_login_lock(true);

    out();
    ok('密码已重置成功！');
    kv('管理员', $admin['user'] . '  (ID ' . $admin['id'] . ')');
    kv('新密码', c($pwd, '1;32'));
    kv('登录地址', c(admin_login_url_hint(), '1;33'));
    if ($backup !== '') {
        kv('备份文件', $backup);
    }
    if ($cleared > 0) {
        info('同时清理了 ' . $cleared . ' 条登录失败/限流记录，账号锁定已解除。');
    }
    rescue_log('admin_pass', 'adminId=' . $admin['id'] . ' user=' . $admin['user']);
}

/* ============================================================
 *  九、功能：修改后台登录账号
 * ============================================================ */

function cmd_admin_user($args = [], $autoYes = false)
{
    title('修改后台登录账号');

    $admin = pick_admin();
    if (!$admin) {
        return;
    }

    $newUser = isset($args[0]) ? trim($args[0]) : '';
    if ($newUser === '') {
        out();
        info('当前账号：' . $admin['user'] . '（留空回车取消）');
        $newUser = ask('请输入新的登录账号');
        if ($newUser === '') {
            warn('未输入，已取消。');
            return;
        }
    }

    if (!preg_match('/^[A-Za-z0-9_@.\-]{3,30}$/', $newUser)) {
        err('账号格式不合法：3-30 位，仅支持字母、数字及 _ @ . - 符号。');
        return;
    }
    if ($newUser === $admin['user']) {
        warn('新账号与当前账号相同，无需修改。');
        return;
    }
    $exists = (int) val('SELECT COUNT(*) FROM ' . T('admin') . ' WHERE `user` = ?', [$newUser]);
    if ($exists > 0) {
        err('账号「' . $newUser . '」已被其它管理员占用，请换一个。');
        return;
    }

    if (!confirm('即将把管理员 ID ' . $admin['id'] . ' 的登录账号由「' . $admin['user'] . '」改为「' . $newUser . '」，是否继续？', $autoYes)) {
        warn('已取消。');
        return;
    }

    $backup = backup_snapshot('admin_user');
    exec_sql('UPDATE ' . T('admin') . ' SET `user` = ? WHERE id = ?', [$newUser, (int) $admin['id']]);

    out();
    ok('登录账号已修改！');
    kv('新账号', c($newUser, '1;32'));
    kv('原账号', $admin['user']);
    kv('登录地址', c(admin_login_url_hint(), '1;33'));
    if ($backup !== '') {
        kv('备份文件', $backup);
    }
    rescue_log('admin_user', 'adminId=' . $admin['id'] . ' ' . $admin['user'] . ' -> ' . $newUser);
}

/* ============================================================
 *  十、功能：后台登录入口
 * ============================================================ */

function cmd_entrance($args = [], $autoYes = false)
{
    title('后台登录入口管理');

    $current = trim((string) web_get('admin_path', 'admin'), '/');
    if ($current === '') {
        $current = 'admin';
    }
    kv('当前入口', c($current, '33'));
    kv('当前登录地址', c('/' . $current . '/login', '1;32'));
    out();

    $newPath = isset($args[0]) ? trim($args[0], '/') : '';
    if ($newPath === '') {
        info('留空回车可取消。填写 admin 表示恢复为默认入口。');
        $newPath = trim((string) ask('请输入新的后台入口（仅字母数字）', $current), '/');
        if ($newPath === '') {
            warn('未输入，已取消。');
            return;
        }
    }

    list($okPath, $msg) = check_entrance_path($newPath);
    if (!$okPath) {
        err($msg);
        return;
    }
    if ($newPath === $current) {
        warn('新入口与当前入口相同，无需修改。');
        return;
    }

    out();
    if ($newPath === 'admin') {
        warn('你将把后台入口恢复为默认的 /admin，安全性会降低（/admin/login 会重新对外可用）。');
    } else {
        info('修改后：/' . $current . '/login 将立即失效（返回 404），请改用 /' . $newPath . '/login 登录。');
    }
    $tag = $newPath === 'admin' ? '重新启用默认入口' : '自定义入口';
    if (!confirm('确认将后台登录入口从「' . $current . '」改为「' . $newPath . '」（' . $tag . '）？', $autoYes)) {
        warn('已取消。');
        return;
    }

    $backup = backup_snapshot('entrance');
    web_save(['admin_path' => $newPath]);
    $cleared = clear_entrance_cache();

    out();
    ok('后台登录入口已修改！');
    kv('新登录地址', c('/' . $newPath . '/login', '1;32'));
    kv('入口缓存', $cleared > 0 ? '已清理 ' . $cleared . ' 个缓存文件（立即生效）' : '无需清理');
    if ($backup !== '') {
        kv('备份文件', $backup);
    }
    out();
    warn('请务必牢记新入口。忘记时：再次运行本工具箱 → 选择「修改后台登录入口」→ 输入 admin 即可恢复默认。');
    rescue_log('entrance', $current . ' -> ' . $newPath);
}

/* ============================================================
 *  十一、功能：恢复默认设置
 * ============================================================ */

function setting_groups()
{
    return [
        'basic' => [
            'label' => '基础信息（站点名称/描述/关键词/备案/客服QQ群）',
            'safe'  => true,
            'items' => [
                'name'              => '我的主机站',
                'description'       => '',
                'keywords'          => '',
                'icp'               => '',
                'business_license'  => '',
                'police_beian'      => '',
                'telecom_license'   => '',
                'qq_group'          => '',
                'service_email'     => '',
                'run_start_time'    => '',
            ],
        ],
        'theme' => [
            'label' => '外观与主题（LOGO/背景图/液态玻璃/加载动画/模板）',
            'safe'  => true,
            'items' => [
                'favicon'             => '/favicon.ico',
                'logo'                => '',
                'logo_icon'           => 'fas fa-server',
                'template'            => 'default',
                'admintemplate'       => 'default',
                'bg_image'            => '',
                'bg_type'             => 'image',
                'bg_video_loop'       => '1',
                'bg_video_muted'      => '1',
                'bg_blur'             => '0',
                'bg_gradient'         => 'default',
                'bg_images'           => '',
                'bg_switch_interval'  => '0',
                'glass_enabled'       => '1',
                'glass_opacity'       => '72',
                'glass_theme'         => 'default',
                'loading_enabled'     => '1',
                'loading_logo'        => '',
                'loading_brand'       => '',
                'loading_text'        => '',
                'loading_subtext'     => '',
                'bg_music_url'        => '',
                'bg_music_enabled'    => '0',
                'bg_music_volume'     => '50',
            ],
        ],
        'live2d' => [
            'label' => '看板娘（模型/位置/颜色/AI 对话）',
            'safe'  => true,
            'items' => [
                'live2d_enabled'        => '1',
                'live2d_model'          => 'shufulei',
                'live2d_scale'          => '0.1',
                'live2d_primary_color'  => '#38B0DE',
                'live2d_pos_x'          => '70',
                'live2d_pos_y'          => '70',
                'live2d_ai_enabled'     => '1',
                'live2d_ai_model'       => 'deepseek-v4-flash',
                'live2d_ai_api_url'     => '',
                'live2d_ai_api_key'     => '',
                'live2d_ai_persona'     => '',
            ],
        ],
        'maintain' => [
            'label' => '维护模式与弹窗公告',
            'safe'  => true,
            'items' => [
                'wh'             => '0',
                'whxx'           => '',
                'popup_notice'   => '0',
                'popup_title'    => '',
                'popup_content'  => '',
            ],
        ],
        'mail' => [
            'label' => '邮件配置（SMTP 服务器 / 账号 / 密码）',
            'safe'  => false,
            'items' => [
                'email'        => '0',
                'emailchar'    => '',
                'emailsecure'  => '',
                'emailport'    => '',
                'emailhost'    => '',
                'emailname'    => '',
                'emailpass'    => '',
                'emailauth'    => '',
            ],
        ],
        'oauth' => [
            'label' => '登录对接（QQ 登录 / 邮箱登录 / 注册验证）',
            'safe'  => false,
            'items' => [
                'oauth_enabled'  => '0',
                'oauth_appid'    => '',
                'oauth_appkey'   => '',
                'oauth_callback' => '',
                'yxdl'           => '0',
                'zcyxyz'         => '0',
            ],
        ],
        'realname' => [
            'label' => '实名认证与身份证 OCR（接口密钥 / 收费 / 限制）',
            'safe'  => false,
            'items' => [
                'realname_mode'            => '0',
                'realname_api_type'        => '1',
                'realname_api_appid'       => '',
                'realname_api_appkey'      => '',
                'realname_secret_id'       => '',
                'realname_secret_key'      => '',
                'realname_charge_amount'   => '0',
                'realname_method_idcard'   => '1',
                'realname_method_phone'    => '1',
                'realname_method_manual'   => '1',
                'realname_limit_pay'       => '0',
                'realname_limit_buy'       => '0',
                'realname_limit_ticket'    => '0',
                'realname_limit_renew'     => '0',
                'realname_limit_transfer'  => '0',
                'realname_first_free'      => '0',
                'idcard_ocr_provider'      => '0',
                'idcard_ocr_appcode'       => '',
                'idcard_ocr_secret_id'     => '',
                'idcard_ocr_secret_key'    => '',
                'idcard_ocr_api_url'       => '',
            ],
        ],
        'security' => [
            'label' => '安全与验证码（滑块/极验 / 防临时邮箱 / 强制加群）',
            'safe'  => false,
            'items' => [
                'captcha_type'             => '0',
                'geetest_id'               => '',
                'geetest_key'              => '',
                'geetest4_id'              => '',
                'geetest4_key'             => '',
                'disposable_email_block'   => '0',
                'force_qq_group'           => '0',
                'force_qq_group_key'       => '',
                'force_qq_group_link'      => '',
                'force_qq_group_number'    => '',
                'force_qq_group_reason'    => '',
            ],
        ],
        'cron' => [
            'label' => '计划任务与自动开通（定时开关 / 自动开通主机）',
            'safe'  => false,
            'items' => [
                'cronzz'                 => '',
                'cronsc'                 => '',
                'paycron'                => '',
                'tickcron'               => '',
                'host_auto_create'       => '0',
                'host_auto_create_delay' => '0',
            ],
        ],
        'aff' => [
            'label' => '推广分佣（折扣 / 提现设置）',
            'safe'  => false,
            'items' => [
                'affdiscount'    => '',
                'affwithdrawal'  => '',
            ],
        ],
        'misc' => [
            'label' => '其它第三方（高德地图 Key / 域名商城 / 全球数据中心）',
            'safe'  => false,
            'items' => [
                'amap_key'               => '',
                'domain_market_enabled'  => '0',
                'global_datacenters'     => '[]',
            ],
        ],
    ];
}

function short_val($v, $len = 30)
{
    $v = (string) $v;
    $v = str_replace(["\r", "\n", "\t"], ' ', $v);
    if (mb_strlen($v, 'UTF-8') > $len) {
        $v = mb_substr($v, 0, $len, 'UTF-8') . '…';
    }
    return $v === '' ? '(空)' : $v;
}

/**
 * 恢复默认设置的主流程
 * @param string $selector safe|all|分组key逗号分隔
 */
function cmd_reset($args = [], $autoYes = false)
{
    title('恢复后台设置为默认');

    $groups = setting_groups();
    $webCols = columns(P() . 'web');
    $w = web_row();
    if (!$w) {
        err('web 表没有站点配置记录，请先执行「数据库救援」。');
        return;
    }

    out('  ' . c('说明：本操作只重置 web 设置表中的配置项，', '33'));
    out('  ' . c('      不会删除/修改用户、订单、主机、卡密等任何业务数据。', '33'));
    out('  ' . c('      执行前会自动备份当前配置到 runtime/rescue_backup/。', '90'));
    out();

    // 组装可选列表
    out('  ' . c('可重置的分组：', '1'));
    $idx = 0;
    $map = [];
    foreach ($groups as $key => $g) {
        $existsItems = array_intersect(array_keys($g['items']), $webCols);
        if (empty($existsItems)) {
            continue;
        }
        $idx++;
        $map[(string) $idx] = $key;
        out('   ' . c('[' . $idx . ']', '36') . ' ' . str_pad($g['label'], 10)
            . ($g['safe'] ? c(' 建议', '32') : c(' 谨慎（含已填密钥/接口配置）', '31')));
    }
    out();
    out('   ' . c('快捷方式：', '1'));
    out('   ' . c('[S]', '36') . ' 安全模式（仅重置展示类：基础信息 + 外观主题 + 看板娘 + 维护公告）—— ' . c('推荐', '32'));
    out('   ' . c('[A]', '36') . ' 全部重置（含邮箱/登录对接/实名/验证码/定时/分佣等所有配置）—— ' . c('谨慎', '31'));
    out('   ' . c('[0]', '36') . ' 取消返回');
    out();

    $sel = isset($args[0]) && $args[0] !== '' ? strtoupper(trim($args[0])) : '';
    if ($sel === '') {
        $sel = strtoupper(trim((string) ask('请输入选择（如 S / A / 1,2,3）', 'S')));
    }

    $chosen = [];
    if ($sel === 'S' || $sel === 'SAFE') {
        foreach ($groups as $key => $g) {
            if (!empty($g['safe'])) {
                $chosen[] = $key;
            }
        }
        info('已选择：安全模式');
    } elseif ($sel === 'A' || $sel === 'ALL') {
        $chosen = array_keys($groups);
        warn('已选择：全部重置');
    } elseif ($sel === '0' || $sel === '') {
        warn('已取消。');
        return;
    } else {
        foreach (preg_split('/[\s,，]+/', $sel) as $p) {
            $p = trim($p);
            if ($p === '') {
                continue;
            }
            if (isset($map[$p])) {
                $chosen[] = $map[$p];
            }
        }
        if (empty($chosen)) {
            err('未识别任何有效的分组选择，已取消。');
            return;
        }
    }

    $chosen = array_values(array_unique($chosen));

    // 收集将要变更的字段（跳过不存在的列 + 跳过值未变化的）
    $changes = [];
    foreach ($chosen as $key) {
        foreach ($groups[$key]['items'] as $field => $def) {
            if (!in_array($field, $webCols, true)) {
                continue;
            }
            $old = array_key_exists($field, $w) ? (string) $w[$field] : '';
            $new = (string) $def;
            if ($old === $new) {
                continue;
            }
            $changes[$field] = ['old' => $old, 'new' => $new, 'group' => $groups[$key]['label']];
        }
    }

    out();
    hr();
    out(c('  即将执行的变更预览（' . count($chosen) . ' 个分组，' . count($changes) . ' 个字段）', '1'));
    hr();
    if (empty($changes)) {
        warn('所选分组中的字段当前已经全部是默认值，无需修改。');
        return;
    }
    foreach ($changes as $field => $ch) {
        out('   ' . c(str_pad($field, 26), '36')
            . c(short_val($ch['old'], 22), '31') . c('  →  ', '90') . c(short_val($ch['new'], 22), '32'));
    }
    hr();

    $danger = false;
    foreach ($chosen as $key) {
        if (empty($groups[$key]['safe'])) {
            $danger = true;
            break;
        }
    }
    if ($danger) {
        out();
        warn('所选分组中包含「密钥 / 接口 / 邮箱等已填写配置」，重置后需要重新填写！');
        out('  ' . c('  （用户数据、订单、主机、卡密等业务数据不受任何影响）', '90'));
    }

    if (!confirm('确认按上述预览重置这些设置？', $autoYes)) {
        warn('已取消，未做任何修改。');
        return;
    }
    if ($danger && !confirm('再次确认：将清空上表列出的接口密钥等配置，确定继续？', $autoYes)) {
        warn('已取消，未做任何修改。');
        return;
    }

    $backup = backup_snapshot('reset_' . implode('-', $chosen));
    $data = [];
    foreach ($changes as $field => $ch) {
        $data[$field] = $ch['new'];
    }
    $affected = web_save($data);
    clear_entrance_cache();

    out();
    ok('设置已恢复默认，共写入 ' . $affected . ' 项。');
    kv('涉及分组', implode('、', array_map(function ($k) use ($groups) {
        return $groups[$k]['label'];
    }, $chosen)));
    if ($backup !== '') {
        kv('备份文件', $backup);
        info('如需还原：菜单选择「备份与还原 → 还原配置」，选择该文件即可。');
    }
    rescue_log('reset', 'groups=' . implode(',', $chosen) . ' fields=' . count($data));
}

/* ============================================================
 *  十二、功能：管理员账号管理
 * ============================================================ */

function cmd_admins($args = [], $autoYes = false)
{
    title('管理员账号管理');

    if (!table_exists('admin')) {
        err('管理员表不存在，请先执行「数据库救援」。');
        return;
    }

    while (true) {
        $admins = rows('SELECT id, name, user, mail, qq, is_super, role_id, status FROM ' . T('admin') . ' ORDER BY id ASC');
        out();
        out('   ' . sprintf('%-4s %-18s %-12s %-8s %-12s %s', 'ID', '账号', '昵称', '状态', '身份', '邮箱'));
        if (empty($admins)) {
            err('当前没有任何管理员账号！');
        }
        foreach ($admins as $a) {
            out('   ' . sprintf(
                '%-4s %-18s %-12s %-8s %-12s %s',
                $a['id'],
                $a['user'],
                mb_substr((string) $a['name'], 0, 6, 'UTF-8'),
                intval($a['status']) === 1 ? c('正常', '32') : c('禁用', '31'),
                is_super_admin($a) ? c('超级管理员', '33') : ('角色#' . intval($a['role_id'])),
                (string) $a['mail']
            ));
        }
        out();
        out('   ' . c('[1]', '36') . ' 新增管理员');
        out('   ' . c('[2]', '36') . ' 启用 / 禁用');
        out('   [3] 设为超级管理员（提权）');
        out('   ' . c('[4]', '31') . ' 删除管理员');
        out('   [0] 返回上级菜单');
        $op = ask('请选择操作', '0');

        if ($op === '1') {
            out();
            $user = trim((string) ask('新管理员登录账号'));
            if ($user === '') {
                warn('账号不能为空，已取消。');
                continue;
            }
            if (!preg_match('/^[A-Za-z0-9_@.\-]{3,30}$/', $user)) {
                err('账号格式不合法：3-30 位，仅支持字母、数字及 _ @ . - 符号。');
                continue;
            }
            if ((int) val('SELECT COUNT(*) FROM ' . T('admin') . ' WHERE `user` = ?', [$user]) > 0) {
                err('账号已存在，请换一个。');
                continue;
            }
            $pwd = ask_secret('登录密码（至少 6 位）');
            if ($pwd === '') {
                warn('密码不能为空，已取消。');
                continue;
            }
            list($passOk, $pwdMsg) = check_password_strength($pwd);
            if (!$passOk) {
                err($pwdMsg);
                continue;
            }
            $name = trim((string) ask('昵称', $user));
            $mail = trim((string) ask('邮箱（可留空）'));
            $qq   = trim((string) ask('QQ（可留空）'));
            $roleId = intval(ask('角色ID（1=超级管理员，2=普通管理员）', '1'));
            if (!in_array($roleId, [1, 2], true)) {
                $roleId = 1;
            }
            if ($roleId === 1 && in_array('is_super', columns(P() . 'admin'), true)) {
                // 超级管理员
            }

            $backup = backup_snapshot('admin_add');
            $cols = columns(P() . 'admin');
            $data = ['user' => $user, 'password' => password_hash($pwd, PASSWORD_DEFAULT), 'name' => $name];
            if (in_array('mail', $cols, true))       { $data['mail'] = $mail; }
            if (in_array('qq', $cols, true))         { $data['qq'] = $qq; }
            if (in_array('role_id', $cols, true))    { $data['role_id'] = $roleId; }
            if (in_array('is_super', $cols, true))   { $data['is_super'] = $roleId === 1 ? 1 : 0; }
            if (in_array('status', $cols, true))     { $data['status'] = 1; }
            if (in_array('created_at', $cols, true)) { $data['created_at'] = time(); }

            $fields = array_keys($data);
            $sql = 'INSERT INTO ' . T('admin') . ' (`' . implode('`,`', $fields) . '`) VALUES ('
                . implode(',', array_fill(0, count($fields), '?')) . ')';
            exec_sql($sql, array_values($data));

            // 确保角色记录存在
            if ($roleId === 1 && table_exists('admin_role')) {
                $hasRole = (int) val('SELECT COUNT(*) FROM ' . T('admin_role') . ' WHERE id = 1');
                if ($hasRole === 0) {
                    exec_sql('INSERT INTO ' . T('admin_role') . ' (`id`,`name`,`permissions`,`description`,`created_at`) VALUES (1,?,?,?,?)',
                        ['超级管理员', json_encode(['all']), '拥有所有权限', time()]);
                }
            }

            out();
            ok('管理员创建成功！');
            kv('账号', c($user, '1;32'));
            kv('密码', c($pwd, '1;32'));
            kv('身份', $roleId === 1 ? '超级管理员' : '普通管理员');
            kv('登录地址', c(admin_login_url_hint(), '1;33'));
            if ($backup !== '') {
                kv('备份文件', $backup);
            }
            rescue_log('admin_add', 'user=' . $user . ' role=' . $roleId);
            pause();
        } elseif ($op === '2') {
            $id = (int) ask('请输入要启用/禁用的管理员 ID');
            $t = null;
            foreach ($admins as $a) {
                if ((int) $a['id'] === $id) {
                    $t = $a;
                }
            }
            if (!$t) {
                err('未找到该管理员。');
                continue;
            }
            $newStatus = intval($t['status']) === 1 ? 0 : 1;
            if (!confirm('确认将管理员「' . $t['user'] . '」' . ($newStatus === 1 ? '启用' : '禁用') . '？', $autoYes)) {
                warn('已取消。');
                continue;
            }
            backup_snapshot('admin_status');
            exec_sql('UPDATE ' . T('admin') . ' SET `status` = ? WHERE id = ?', [$newStatus, $id]);
            ok('操作完成：' . $t['user'] . ' 已' . ($newStatus === 1 ? '启用' : '禁用'));
            rescue_log('admin_status', 'id=' . $id . ' status=' . $newStatus);
        } elseif ($op === '3') {
            $id = (int) ask('请输入要提权为超级管理员的管理员 ID');
            $t = null;
            foreach ($admins as $a) {
                if ((int) $a['id'] === $id) {
                    $t = $a;
                }
            }
            if (!$t) {
                err('未找到该管理员。');
                continue;
            }
            if (is_super_admin($t)) {
                warn('该管理员已经是超级管理员。');
                continue;
            }
            if (!confirm('确认将「' . $t['user'] . '」提升为超级管理员（拥有全部权限）？', $autoYes)) {
                warn('已取消。');
                continue;
            }
            backup_snapshot('admin_promote');
            $set = [];
            if (in_array('is_super', columns(P() . 'admin'), true)) {
                $set['is_super'] = 1;
            }
            if (in_array('role_id', columns(P() . 'admin'), true)) {
                $set['role_id'] = 1;
            }
            if (in_array('status', columns(P() . 'admin'), true)) {
                $set['status'] = 1;
            }
            if ($set) {
                $sets = [];
                $params = [];
                foreach ($set as $k => $v) {
                    $sets[] = "`{$k}` = ?";
                    $params[] = $v;
                }
                $params[] = $id;
                exec_sql('UPDATE ' . T('admin') . ' SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
            }
            ok('提权完成：' . $t['user'] . ' 现为超级管理员');
            rescue_log('admin_promote', 'id=' . $id);
        } elseif ($op === '4') {
            $id = (int) ask('请输入要删除的管理员 ID');
            $t = null;
            foreach ($admins as $a) {
                if ((int) $a['id'] === $id) {
                    $t = $a;
                }
            }
            if (!$t) {
                err('未找到该管理员。');
                continue;
            }
            $superCount = 0;
            foreach ($admins as $a) {
                if (is_super_admin($a) && intval($a['status']) === 1) {
                    $superCount++;
                }
            }
            if (is_super_admin($t) && $superCount <= 1) {
                err('这是最后一个可用的超级管理员，删除后将无法登录后台，已阻止该操作。');
                continue;
            }
            if (!$t) {
                continue;
            }
            out();
            warn('删除后该管理员将立即无法登录后台，操作不可撤销！');
            if (!confirm('确认删除管理员「' . $t['user'] . '」(ID ' . $t['id'] . ')？', $autoYes)) {
                warn('已取消。');
                continue;
            }
            backup_snapshot('admin_delete');
            exec_sql('DELETE FROM ' . T('admin') . ' WHERE id = ?', [$id]);
            ok('已删除管理员：' . $t['user']);
            rescue_log('admin_delete', 'id=' . $id . ' user=' . $t['user']);
        } else {
            return;
        }
    }
}

/* ============================================================
 *  十三、功能：解除后台登录锁定
 * ============================================================ */

function clear_login_lock($silent = false)
{
    $count = 0;

    // 1) 清理限流文件
    $rateDir = RESCUE_ROOT . '/runtime/log/rate_limit';
    if (is_dir($rateDir)) {
        $files = glob($rateDir . '/*');
        if ($files) {
            foreach ($files as $f) {
                if (is_file($f) && @unlink($f)) {
                    $count++;
                }
            }
        }
    }

    // 2) 清理登录失败记录（保留成功记录）
    if (table_exists('admin_login_log')) {
        try {
            $cols = columns(P() . 'admin_login_log');
            if (in_array('status', $cols, true)) {
                $n = exec_sql('DELETE FROM ' . T('admin_login_log') . ' WHERE `status` = 0');
                $count += $n;
            }
        } catch (Throwable $e) {
        }
    }

    // 3) 同步清理系统记录中的登录失败（可选，失败忽略）
    if (table_exists('sys_record')) {
        try {
            $cols = columns(P() . 'sys_record');
            if (in_array('type', $cols, true)) {
                exec_sql('DELETE FROM ' . T('sys_record') . " WHERE `type` = 'admin_login' AND `summary` LIKE '登录失败%'");
            }
        } catch (Throwable $e) {
        }
    }

    return $count;
}

function cmd_unlock($args = [], $autoYes = false)
{
    title('解除后台登录锁定');

    out('  用途：后台提示「账号已被锁定」「登录尝试过于频繁」「当前网络登录失败次数过多」时使用。');
    out();

    $logCount = 0;
    if (table_exists('admin_login_log')) {
        $logCount = (int) val('SELECT COUNT(*) FROM ' . T('admin_login_log') . ' WHERE `status` = 0');
    }
    kv('登录失败记录', $logCount . ' 条');
    $rateDir = RESCUE_ROOT . '/runtime/log/rate_limit';
    $rateFiles = is_dir($rateDir) ? count((array) glob($rateDir . '/*')) : 0;
    kv('限流计数文件', $rateFiles . ' 个');

    if ($logCount === 0 && $rateFiles === 0) {
        out();
        ok('当前没有锁定记录，无需清理。');
        return;
    }
    if (!confirm('确认清除以上锁定记录？（管理员账号本身不受影响）', $autoYes)) {
        warn('已取消。');
        return;
    }
    $n = clear_login_lock();
    out();
    ok('已清除 ' . $n . ' 条锁定 / 限流记录，现在可以正常登录后台了。');
    kv('登录地址', c(admin_login_url_hint(), '1;33'));
    rescue_log('unlock', 'cleared=' . $n);
}

/* ============================================================
 *  十四、功能：站点维护模式
 * ============================================================ */

function cmd_maintenance($args = [], $autoYes = false)
{
    title('站点维护模式');

    $cur = (string) web_get('wh', '0') === '1';
    kv('当前状态', $cur ? c('维护中（前台显示维护页）', '31') : c('正常运行', '32'));

    $act = isset($args[0]) ? strtolower(trim($args[0])) : '';
    if ($act === '') {
        $sel = choose('请选择操作', ['1' => '开启维护模式（前台暂停访问）', '2' => '关闭维护模式（恢复访问）', '0' => '取消'], '0');
        if ($sel === '1') {
            $act = 'on';
        } elseif ($sel === '2') {
            $act = 'off';
        } else {
            warn('已取消。');
            return;
        }
    }

    $on = in_array($act, ['on', '1', 'open', 'enable'], true);
    $data = ['wh' => $on ? '1' : '0'];
    if ($on) {
        out();
        $notice = ask('维护提示文字（可留空使用默认）');
        if ($notice !== '') {
            $data['whxx'] = $notice;
        }
    }

    if (!confirm('确认' . ($on ? '开启' : '关闭') . '维护模式？', $autoYes)) {
        warn('已取消。');
        return;
    }

    backup_snapshot('maintenance');
    web_save($data);
    out();
    ok('维护模式已' . ($on ? '开启' : '关闭') . '。');
    rescue_log('maintenance', $on ? 'on' : 'off');
}

/* ============================================================
 *  十五、功能：清理缓存
 * ============================================================ */

function dir_size($dir)
{
    $size = 0;
    if (!is_dir($dir)) {
        return 0;
    }
    $it = @new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $size += $f->isFile() ? $f->getSize() : 0;
    }
    return $size;
}

function rm_contents($dir, &$removed)
{
    if (!is_dir($dir)) {
        return 0;
    }
    $size = dir_size($dir);
    $it = @new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $p = $f->getPathname();
        if ($f->isDir()) {
            @rmdir($p);
        } else {
            if (@unlink($p)) {
                $removed++;
            }
        }
    }
    return $size;
}

function cmd_clear_cache($args = [], $autoYes = false)
{
    title('清理缓存');

    $targets = [
        RESCUE_ROOT . '/runtime/cache'  => '模板 / 数据缓存',
        RESCUE_ROOT . '/runtime/temp'   => '临时文件',
    ];
    $totalSize = 0;
    foreach ($targets as $dir => $label) {
        $size = dir_size($dir);
        $totalSize += $size;
        kv($label, (is_dir($dir) ? '存在' : '不存在') . '  ' . bytes_human($size) . '  ' . c('(' . $dir . ')', '90'));
    }
    $entFile = RESCUE_ROOT . '/runtime/admin_entrance.json';
    kv('后台入口缓存', is_file($entFile) ? '存在（建议清理，清理后入口立即生效）' : '不存在');
    $lockFile = RESCUE_ROOT . '/runtime/log/tables_ensured.lock';
    kv('自愈建表标记', is_file($lockFile) ? '存在（清理后下次访问会重新校验并补齐缺失表）' : '不存在');
    $rateDir = RESCUE_ROOT . '/runtime/log/rate_limit';
    $rateFiles = is_dir($rateDir) ? count((array) glob($rateDir . '/*')) : 0;
    kv('登录限流文件', $rateFiles . ' 个');

    $total = $totalSize + ($rateFiles * 32) + ((int) is_file($entFile) * 32) + ((int) is_file($lockFile) * 32);
    out();
    kv('预计可清理', bytes_human($total));

    if (!confirm('确认清理以上缓存？（只会删除缓存，不会影响数据库数据与用户上传文件）', $autoYes)) {
        warn('已取消。');
        return;
    }

    $removed = 0;
    $freed = 0;
    foreach ($targets as $dir => $label) {
        $freed += rm_contents($dir, $removed);
    }
    foreach ([$entFile, $lockFile] as $f) {
        if (is_file($f) && @unlink($f)) {
            $removed++;
            $freed += 32;
        }
    }
    if ($rateFiles > 0) {
        $removed += clear_login_lock(true);
    }

    out();
    ok('缓存清理完成。');
    kv('删除文件', $removed . ' 个');
    kv('释放空间', bytes_human($freed));
    rescue_log('clear_cache', 'files=' . $removed . ' freed=' . $freed);
}

/* ============================================================
 *  十六、功能：数据库救援
 * ============================================================ */

function cmd_repair_db($args = [], $autoYes = false)
{
    title('数据库救援（补齐核心记录）');

    out('  本功能只做三件事，均不涉及删除数据：');
    out('   ① 站点配置记录（web 表 id=1）缺失时补一条默认的');
    out('   ② 超级管理员角色（admin_role 表）缺失时补一条');
    out('   ③ 清掉自愈建表标记，让站点下次访问自动补齐缺失的业务表');
    out();

    $needFix = [];
    if (!table_exists('web')) {
        warn('web 表不存在，无法补齐站点配置。请先访问站点完成安装向导。');
    } else {
        $w = row('SELECT id FROM ' . T('web') . ' ORDER BY id ASC LIMIT 1');
        if (!$w) {
            $needFix[] = '站点配置记录（web）缺失 → 将写入一条默认配置';
        } else {
            ok('站点配置记录正常（id=' . $w['id'] . '）');
        }
    }

    if (table_exists('admin_role')) {
        $r = (int) val('SELECT COUNT(*) FROM ' . T('admin_role') . ' WHERE id = 1');
        if ($r === 0) {
            $needFix[] = '超级管理员角色（admin_role id=1）缺失 → 将创建';
        } else {
            ok('超级管理员角色正常');
        }
    } else {
        warn('admin_role 表不存在（不影响登录，可忽略）。');
    }

    if (table_exists('admin')) {
        $n = (int) val('SELECT COUNT(*) FROM ' . T('admin'));
        $usable = (int) val('SELECT COUNT(*) FROM ' . T('admin') . ' WHERE `status` = 1');
        if ($n === 0) {
            warn('管理员表为空 → 请用「管理员账号管理 → 新增管理员」创建账号');
        } elseif ($usable === 0) {
            warn('所有管理员均被禁用 → 请用「管理员账号管理 → 启用/禁用」恢复');
        } else {
            ok('管理员账号正常（共 ' . $n . ' 个，可用 ' . $usable . ' 个）');
        }
    }

    $lockFile = RESCUE_ROOT . '/runtime/log/tables_ensured.lock';
    if (is_file($lockFile)) {
        $needFix[] = '自愈建表标记存在 → 将清除，下次访问站点自动校验并补齐缺失表';
    }

    out();
    hr();
    out(c('  检查结果', '1'));
    if (empty($needFix)) {
        ok('未发现需要修复的问题。');
        out();
        info('若前台/后台仍报表不存在（如 1146 错误），请直接用浏览器访问一次站点首页触发自愈，');
        info('或删除 runtime/log/tables_ensured.lock 后再次访问。');
        return;
    }
    foreach ($needFix as $i => $msg) {
        out('   ' . c(($i + 1) . '. ', '36') . $msg);
    }
    out();
    if (!confirm('确认执行上述修复？', $autoYes)) {
        warn('已取消。');
        return;
    }

    $backup = backup_snapshot('repair_db');
    $done = [];

    if (table_exists('web') && !row('SELECT id FROM ' . T('web') . ' ORDER BY id ASC LIMIT 1')) {
        $cols = columns(P() . 'web');
        $data = ['id' => 1];
        if (in_array('name', $cols, true))              { $data['name'] = '我的主机站'; }
        if (in_array('favicon', $cols, true))           { $data['favicon'] = '/favicon.ico'; }
        if (in_array('template', $cols, true))          { $data['template'] = 'default'; }
        if (in_array('admintemplate', $cols, true))     { $data['admintemplate'] = 'default'; }
        if (in_array('global_datacenters', $cols, true)) { $data['global_datacenters'] = '[]'; }
        if (in_array('templateset', $cols, true))       { $data['templateset'] = '[]'; }
        if (in_array('wh', $cols, true))                { $data['wh'] = '0'; }
        if (in_array('admin_path', $cols, true) && !isset($data['admin_path'])) { $data['admin_path'] = 'admin'; }
        $keys = array_keys($data);
        $sql = 'INSERT INTO ' . T('web') . ' (`' . implode('`,`', $keys) . '`) VALUES ('
            . implode(',', array_fill(0, count($keys), '?')) . ')';
        exec_sql($sql, array_values($data));
        $done[] = '已写入默认站点配置';
    }

    if (table_exists('admin_role') && (int) val('SELECT COUNT(*) FROM ' . T('admin_role') . ' WHERE id = 1') === 0) {
        exec_sql('INSERT INTO ' . T('admin_role') . ' (`id`,`name`,`permissions`,`description`,`created_at`) VALUES (1,?,?,?,?)',
            ['超级管理员', json_encode(['all']), '拥有所有权限', time()]);
        $done[] = '已创建超级管理员角色';
    }

    if (is_file($lockFile) && @unlink($lockFile)) {
        $done[] = '已清除自愈建表标记（下次访问站点会自动补齐缺失表）';
    }

    out();
    if ($done) {
        foreach ($done as $d) {
            ok($d);
        }
        if ($backup !== '') {
            kv('备份文件', $backup);
        }
        out();
        info('建议立即用浏览器访问一次站点首页，让系统自动补齐其余业务表。');
        rescue_log('repair_db', implode('; ', $done));
    } else {
        warn('没有可执行的修复项。');
    }
}

/* ============================================================
 *  十七、功能：解封 IP
 * ============================================================ */

function cmd_unban_ip($args = [], $autoYes = false)
{
    title('解封被自动封禁的 IP');

    if (!table_exists('ip_ban')) {
        warn('ip_ban 表不存在，无封禁记录。');
        return;
    }
    $cols = columns(P() . 'ip_ban');
    $hasType = in_array('ban_type', $cols, true);
    $hasStatus = in_array('status', $cols, true);

    $where = [];
    if ($hasType) {
        $where[] = "`ban_type` = 'auto'";
    }
    if ($hasStatus) {
        $where[] = '`status` = 1';
    }
    $whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

    $list = rows('SELECT * FROM ' . T('ip_ban') . $whereSql . ' ORDER BY id DESC LIMIT 50');
    $autoTotal = (int) val('SELECT COUNT(*) FROM ' . T('ip_ban') . $whereSql);
    $allTotal  = (int) val('SELECT COUNT(*) FROM ' . T('ip_ban'));

    kv('封禁记录总数', $allTotal . ' 条');
    kv('自动封禁（启用中）', $autoTotal . ' 条');
    if ($list) {
        out();
        out('   ' . sprintf('%-6s %-18s %-22s %s', 'ID', 'IP', '原因', '时间'));
        foreach (array_slice($list, 0, 20) as $r) {
            out('   ' . sprintf(
                '%-6s %-18s %-22s %s',
                $r['id'],
                isset($r['ip']) ? $r['ip'] : '',
                mb_substr(isset($r['reason']) ? (string) $r['reason'] : '', 0, 10, 'UTF-8'),
                isset($r['time']) ? $r['time'] : (isset($r['create_time']) ? date('Y-m-d H:i', (int) $r['create_time']) : '')
            ));
        }
        if (count($list) > 20) {
            out('   ' . c('  ...（仅显示最近 20 条）', '90'));
        }
    }

    if ($autoTotal === 0) {
        out();
        ok('当前没有生效中的自动封禁记录。');
        return;
    }
    if (!confirm('确认解除全部 ' . $autoTotal . ' 条自动封禁记录？（手动封禁的记录不会被解除）', $autoYes)) {
        warn('已取消。');
        return;
    }

    backup_snapshot('unban_ip');
    if ($hasStatus) {
        exec_sql('UPDATE ' . T('ip_ban') . ' SET `status` = 0' . $whereSql);
    } else {
        exec_sql('DELETE FROM ' . T('ip_ban') . $whereSql);
    }
    out();
    ok('已解除 ' . $autoTotal . ' 条自动封禁记录。');
    rescue_log('unban_ip', 'count=' . $autoTotal);
}

/* ============================================================
 *  十八、功能：备份与还原
 * ============================================================ */

function cmd_backup($args = [], $autoYes = false)
{
    title('备份站点配置');
    $file = backup_snapshot('manual');
    if ($file !== '') {
        ok('备份完成：' . $file);
        kv('包含内容', 'web 站点配置 + 管理员账号清单（不含密码明文，含哈希）');
        rescue_log('backup', $file);
    }
}

function cmd_restore($args = [], $autoYes = false)
{
    title('还原站点配置');

    if (!is_dir(RESCUE_BACKUP_DIR)) {
        warn('还没有任何备份（目录不存在）。');
        return;
    }
    $files = (array) glob(RESCUE_BACKUP_DIR . '/*.json');
    if (empty($files)) {
        warn('还没有任何备份文件。');
        return;
    }
    rsort($files);
    $files = array_slice($files, 0, 20);

    out();
    out('   ' . sprintf('%-4s %-22s %s', '序号', '备份时间', '文件'));
    $map = [];
    foreach ($files as $i => $f) {
        $idx = $i + 1;
        $map[(string) $idx] = $f;
        $json = json_decode((string) @file_get_contents($f), true);
        $t = (is_array($json) && isset($json['time'])) ? $json['time'] : date('Y-m-d H:i:s', filemtime($f));
        $tag = (is_array($json) && isset($json['tag'])) ? $json['tag'] : '';
        out('   ' . sprintf('%-4s %-22s %s', '[' . $idx . ']', $t, basename($f) . ($tag ? '  (' . $tag . ')' : '')));
    }
    out();
    out('   [0] 取消返回');

    $sel = isset($args[0]) ? trim($args[0]) : '';
    if ($sel === '') {
        $sel = trim((string) ask('请输入要还原的备份序号', '0'));
    }
    if ($sel === '0' || $sel === '' || !isset($map[$sel])) {
        warn('已取消。');
        return;
    }

    $file = $map[$sel];
    $json = json_decode((string) @file_get_contents($file), true);
    if (!is_array($json) || empty($json['web']) || !is_array($json['web'])) {
        err('备份文件内容无效或已损坏。');
        return;
    }

    $webData = $json['web'];
    unset($webData['id']);
    $cols = columns(P() . 'web');
    $webData = array_intersect_key($webData, array_flip($cols));

    out();
    warn('还原会用备份里的配置覆盖当前站点配置（含后台入口、LOGO、背景等）。');
    info('备份时间：' . (isset($json['time']) ? $json['time'] : '未知') . '，共 ' . count($webData) . ' 个字段');
    if (!confirm('确认还原该备份？', $autoYes)) {
        warn('已取消。');
        return;
    }

    backup_snapshot('before_restore');
    $n = web_save($webData);
    clear_entrance_cache();

    out();
    ok('还原完成，共写入 ' . $n . ' 项配置。');
    kv('当前登录地址', c(admin_login_url_hint(), '1;33'));
    rescue_log('restore', $file);
}

/* ============================================================
 *  十九、交互式菜单
 * ============================================================ */

function show_menu()
{
    $entrance = trim((string) web_get('admin_path', 'admin'), '/');
    if ($entrance === '') {
        $entrance = 'admin';
    }
    $wh = (string) web_get('wh', '0') === '1';

    out();
    hr('=', 62);
    out(c('   系统救援工具箱  Rescue Toolkit  v' . RESCUE_VERSION, '1;36'));
    out(c('   站点：' . mb_substr((string) web_get('name', '(未配置)'), 0, 20, 'UTF-8')
        . '   登录入口：/' . $entrance . '/login'
        . ($wh ? '   [维护中]' : ''), '90'));
    hr('=', 62);
    out('   ' . c('[1]', '36') . ' 系统信息总览');
    out('   ' . c('[2]', '33') . ' 修改后台登录密码');
    out('   ' . c('[3]', '33') . ' 修改后台登录账号');
    out('   ' . c('[4]', '33') . ' 查看 / 修改后台登录入口');
    out('   ' . c('[5]', '33') . ' 恢复后台设置为默认（不动用户数据）');
    out('   ' . c('[6]', '36') . ' 管理员账号管理（新增/启用/禁用/提权/删除）');
    out('   ' . c('[7]', '36') . ' 解除后台登录锁定');
    out('   ' . c('[8]', '36') . ' 站点维护模式 开 / 关');
    out('   ' . c('[9]', '36') . ' 清理缓存');
    out('   ' . c('[10]', '36') . ' 数据库救援（补齐核心记录）');
    out('   ' . c('[11]', '36') . ' 解封被自动封禁的 IP');
    out('   ' . c('[12]', '36') . ' 备份 / 还原站点配置');
    out('   ' . c('[0]', '90') . ' 退出');
    hr('-', 62);
}

function menu()
{
    title('系统救援工具箱');
    out('  提示：所有写操作前都会自动备份到 runtime/rescue_backup/，');
    out('        并记录到 runtime/rescue_log/。使用完毕后建议删除 rescue.php。');

    while (true) {
        show_menu();
        $op = ask('请输入操作编号', '0');
        switch ($op) {
            case '1':
                cmd_info();
                break;
            case '2':
                cmd_admin_pass();
                break;
            case '3':
                cmd_admin_user();
                break;
            case '4':
                cmd_entrance();
                break;
            case '5':
                cmd_reset();
                break;
            case '6':
                cmd_admins();
                break;
            case '7':
                cmd_unlock();
                break;
            case '8':
                cmd_maintenance();
                break;
            case '9':
                cmd_clear_cache();
                break;
            case '10':
                cmd_repair_db();
                break;
            case '11':
                cmd_unban_ip();
                break;
            case '12':
                out();
                out('   ' . c('[1]', '36') . ' 立即备份当前配置');
                out('   ' . c('[2]', '36') . ' 从备份还原配置');
                $s = ask('请选择', '0');
                if ($s === '1') {
                    cmd_backup();
                } elseif ($s === '2') {
                    cmd_restore();
                }
                break;
            case '0':
            case 'exit':
            case 'q':
                out();
                out('  已退出救援工具箱。建议删除 rescue.php 以保证站点安全。');
                out();
                return;
            default:
                err('无效的编号，请重新输入。');
                continue;
        }
        pause();
    }
}

/* ============================================================
 *  二十、命令行入口
 * ============================================================ */

function show_help()
{
    title('救援工具箱使用说明');
    out('  用法：php rescue.php [命令] [参数] [-y]');
    out();
    out('  ' . c('命令列表', '1'));
    out('   ' . c('info', '36') . '                      查看系统信息（站点/数据库/管理员/数据概览）');
    out('   ' . c('admin-pass', '36') . ' [新密码]          重置后台登录密码');
    out('   ' . c('admin-user', '36') . ' [新账号]          修改后台登录账号');
    out('   ' . c('entrance', '36') . ' [新入口|admin]     查看或修改后台登录入口');
    out('   ' . c('reset', '36') . ' [safe|all|1,2,3]     恢复后台设置为默认（safe=只重置展示类）');
    out('   ' . c('admins', '36') . '                    管理员账号管理（进入子菜单）');
    out('   ' . c('unlock', '36') . '                    解除后台登录锁定');
    out('   ' . c('maintenance', '36') . ' [on|off]         开关站点维护模式');
    out('   ' . c('clear-cache', '36') . '                 清理缓存');
    out('   ' . c('repair-db', '36') . '                   数据库救援（补齐核心记录）');
    out('   ' . c('unban-ip', '36') . '                    解封被自动封禁的 IP');
    out('   ' . c('backup', '36') . '                      立即备份站点配置');
    out('   ' . c('restore', '36') . ' [备份序号]           从备份还原配置');
    out('   ' . c('help', '36') . '                        显示本帮助');
    out();
    out('  ' . c('全局参数', '1'));
    out('   ' . c('-y, --yes', '36') . '                   跳过二次确认（脚本化调用时使用）');
    out();
    out('  ' . c('示例', '1'));
    out('   php rescue.php                       ' . c('# 交互式菜单（推荐）', '90'));
    out('   php rescue.php info                  ' . c('# 查看系统信息', '90'));
    out('   php rescue.php admin-pass Abc12345   ' . c('# 重置密码为 Abc12345', '90'));
    out('   php rescue.php admin-user newadmin   ' . c('# 改登录账号为 newadmin', '90'));
    out('   php rescue.php entrance manage       ' . c('# 后台入口改为 /manage/login', '90'));
    out('   php rescue.php reset safe -y         ' . c('# 安全模式恢复默认设置', '90'));
    out();
    out('  ' . c('安全提醒', '33'));
    out('   · 本脚本仅能在服务器终端运行，浏览器访问返回 403。');
    out('   · 使用完毕后请立即删除 rescue.php 或改名隐藏。');
    out('   · 所有写操作都会备份到 runtime/rescue_backup/，可随时还原。');
}

function main($argv)
{
    $args = array_slice($argv, 1);

    // 解析全局参数
    $autoYes = false;
    $rest = [];
    foreach ($args as $a) {
        if ($a === '-y' || $a === '--yes') {
            $autoYes = true;
            continue;
        }
        if ($a === '--no-color') {
            continue;
        }
        $rest[] = $a;
    }
    $cmd = isset($rest[0]) ? strtolower(trim($rest[0])) : '';
    $params = array_slice($rest, 1);

    if ($cmd === '') {
        menu();
        return 0;
    }

    switch ($cmd) {
        case 'info':
        case 'status':
            cmd_info();
            break;
        case 'admin-pass':
        case 'passwd':
        case 'password':
            cmd_admin_pass($params, $autoYes);
            break;
        case 'admin-user':
        case 'username':
            cmd_admin_user($params, $autoYes);
            break;
        case 'entrance':
        case 'admin-path':
        case 'adminpath':
            cmd_entrance($params, $autoYes);
            break;
        case 'reset':
        case 'restore-default':
            cmd_reset($params, $autoYes);
            break;
        case 'admins':
        case 'admin':
            cmd_admins($params, $autoYes);
            break;
        case 'unlock':
            cmd_unlock($params, $autoYes);
            break;
        case 'maintenance':
        case 'wh':
            cmd_maintenance($params, $autoYes);
            break;
        case 'clear-cache':
        case 'cache':
            cmd_clear_cache($params, $autoYes);
            break;
        case 'repair-db':
        case 'repair':
            cmd_repair_db($params, $autoYes);
            break;
        case 'unban-ip':
        case 'unban':
            cmd_unban_ip($params, $autoYes);
            break;
        case 'backup':
            cmd_backup($params, $autoYes);
            break;
        case 'restore':
            cmd_restore($params, $autoYes);
            break;
        case 'help':
        case '-h':
        case '--help':
            show_help();
            break;
        case 'version':
        case '-v':
            out('rescue.php v' . RESCUE_VERSION . '  (PHP ' . PHP_VERSION . ')');
            break;
        default:
            err('未知命令：' . $cmd);
            out('  输入 php rescue.php help 查看全部命令。');
            out('  或直接运行 php rescue.php 进入交互式菜单。');
            return 1;
    }
    return 0;
}

exit(main($argv));
