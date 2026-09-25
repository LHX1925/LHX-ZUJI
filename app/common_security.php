<?php
// Security helper functions

// Cookie安全属性（TP5不支持samesite config，通过ini_set实现）
if (!defined('SESSION_COOKIE_SECURED')) {
    define('SESSION_COOKIE_SECURED', true);
    // 仅在HTTPS环境下设置Secure
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
    if ($isHttps) {
        ini_set('session.cookie_secure', '1');
    }
    ini_set('session.cookie_httponly', '1');
    // PHP 7.3+ 支持 session.cookie_samesite
    // 使用 Lax：允许第三方 OAuth（如 QQ 聚合登录）跨站回调时携带 Session Cookie，
    // 同时仍能阻止跨站 POST 型 CSRF。Strict 会导致从 mapay.cn 跳回 /oauth/callback 时 Session 丢失，
    // 进而使 QQ 登录与绑定失效。
    if (version_compare(PHP_VERSION, '7.3.0', '>=')) {
        ini_set('session.cookie_samesite', 'Lax');
    }
}

/**
 * XSS clean - sanitize output
 */
function xss_clean($data) {
    if (is_array($data)) {
        foreach ($data as $key => $value) {
            $data[$key] = xss_clean($value);
        }
        return $data;
    }
    return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
}

/**
 * 全局输入安全过滤 - 检测路径遍历、SQL注入关键字等攻击载荷
 * 返回true表示通过，false表示检测到攻击
 */
function check_attack_payload($value) {
    if (is_array($value)) {
        foreach ($value as $v) {
            if (!check_attack_payload($v)) return false;
        }
        return true;
    }
    if (!is_string($value)) return true;

    // 路径遍历攻击
    if (preg_match('#\.\./|\.\.\\\\|%2e%2e|%252e|\./\.\.#i', $value)) {
        security_log('path_traversal_blocked', '检测到路径遍历攻击: ' . mb_substr($value, 0, 100));
        return false;
    }

    // NULL字节注入
    if (strpos($value, "\0") !== false) {
        security_log('null_byte_blocked', '检测到NULL字节注入');
        return false;
    }

    // 常见攻击载荷模式（仅拦截明确的攻击特征）
    $scanPatterns = [
        '/etc/passwd', 'win.ini', 'boot.ini', 'web.xml', 'web.config',
        'php://input', 'php://filter', 'data://text', 'expect://',
        '/proc/self', 'C:\Windows\System32',
        '../', '..\\',
    ];
    foreach ($scanPatterns as $pattern) {
        if (stripos($value, $pattern) !== false) {
            security_log('scan_pattern_blocked', '检测到攻击载荷: ' . $pattern);
            return false;
        }
    }

    return true;
}

/**
 * 全局请求安全过滤 - 在请求处理前调用
 */
function global_input_filter() {
    // 检查GET参数
    if (!empty($_GET)) {
        foreach ($_GET as $key => $val) {
            if (!check_attack_payload($val)) {
                http_response_code(403);
                die('Forbidden');
            }
        }
    }
    // 后台模块 POST 数据跳过过滤：后台管理员已通过登录鉴权，且需要保存 URL/HTML/JSON 等任意内容，
    // 若按 ../、php:// 等模式过滤会误拦截合法内容，导致后台保存提示"提交失败"
    $uriPath = isset($_SERVER['REQUEST_URI']) ? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) : '';
    if (is_string($uriPath) && preg_match('#(^|/)admin(/|$)#', $uriPath)) {
        return;
    }
    // 检查POST参数
    if (!empty($_POST)) {
        foreach ($_POST as $key => $val) {
            if (!check_attack_payload($val)) {
                http_response_code(403);
                die('Forbidden');
            }
        }
    }
}

/**
 * 检测是否来自扫描器的请求（频繁404、探测路径等）
 * 如果是扫描器，自动封锁IP 24小时
 */
function check_scanner_activity() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $uri = $_SERVER['REQUEST_URI'] ?? '';

    // 常见扫描器探测路径列表 - 直接403
    $blockPaths = [
        '/phpmyadmin', '/pma', '/mysql', '/adminer',
        '/wp-admin', '/wp-login', '/wp-content',
        '/xmlrpc.php', '/.env', '/config.php', '/config.yaml',
        '/.git/', '/.svn/', '/.hg/', '/.DS_Store',
        '/api/.env', '/admin/.env', '/backend/.env',
        '/console', '/admin/login.asp', '/manage',
        '/left.php', '/public/static/',
        '/actuator', '/swagger', '/api-docs',
        '/shell', '/cmd', '/upload.', '/uploads/',
        '/tmp/', '/temp/', '/test.php', '/info.php',
    ];

    $uriLower = strtolower($uri);
    foreach ($blockPaths as $path) {
        if (strpos($uriLower, strtolower($path)) !== false) {
            // 安全日志路径（兜底到runtime/log/）
            $logDir = (defined('LOG_PATH') ? LOG_PATH : (__DIR__ . '/../runtime/log/')) . 'security/';
            if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
            $log = [
                'time' => date('Y-m-d H:i:s'),
                'ip' => $ip,
                'uri' => $uri,
                'ua' => isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr($_SERVER['HTTP_USER_AGENT'], 0, 200) : '',
                'action' => 'blocked_scan_path',
            ];
            file_put_contents($logDir . 'scanner_' . date('Y-m-d') . '.log',
                json_encode($log, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);

            // 自动封禁已禁用（IP封禁为手动管理），仅记录日志和返回403
            // ban_ip($ip, '扫描敏感路径: ' . mb_substr($uri, 0, 100), 86400 * 7, 'auto');
            security_log('blocked_scan_path', '拦截扫描路径: ' . $ip . ' -> ' . $uri);

            http_response_code(403);
            die('Forbidden');
        }
    }
}

/**
 * Rate limiter for sensitive operations (email sending, login attempts)
 */
function rate_limit($key, $maxAttempts = 5, $decaySeconds = 60) {
    $sessionKey = 'rate_limit_' . $key;
    $attempts = session($sessionKey);
    
    if (!$attempts) {
        session($sessionKey, ['count' => 1, 'time' => time()]);
        return true;
    }
    
    if (time() - $attempts['time'] > $decaySeconds) {
        session($sessionKey, ['count' => 1, 'time' => time()]);
        return true;
    }
    
    if ($attempts['count'] >= $maxAttempts) {
        return false;
    }
    
    $attempts['count']++;
    session($sessionKey, $attempts);
    return true;
}

/**
 * Generate CSRF token
 */
function csrf_token() {
    $token = session('csrf_token');
    if (!$token) {
        $token = bin2hex(random_bytes(32));
        session('csrf_token', $token);
    }
    return $token;
}

/**
 * Verify CSRF token
 * 兼容：1) 表单字段 __token__；2) 请求头 X-CSRF-Token（AJAX 统一走请求头，避免 Token 混入表单字段）
 */
function csrf_verify($token) {
    $stored = session('csrf_token');
    if (!$stored) return false;
    if ($token && is_string($token) && hash_equals($stored, $token)) return true;
    if (!empty($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'];
        if (is_string($headerToken) && hash_equals($stored, $headerToken)) return true;
    }
    return false;
}

/**
 * Log security events
 */
function security_log($event, $details = '') {
    $log = [
        'time' => date('Y-m-d H:i:s'),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'event' => $event,
        'details' => $details,
    ];
    $logDir = __DIR__ . '/../runtime/log/security/';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    $logFile = $logDir . date('Y-m-d') . '.log';
    file_put_contents($logFile, json_encode($log, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
}

/**
 * Send security HTTP headers
 * Call this at the beginning of every request to set security headers
 */
function send_security_headers() {
    // 防止点击劫持
    header('X-Frame-Options: SAMEORIGIN');
    // 防止MIME类型嗅探
    header('X-Content-Type-Options: nosniff');
    // 启用浏览器XSS过滤器
    header('X-XSS-Protection: 1; mode=block');
    // 引用策略
    header('Referrer-Policy: strict-origin-when-cross-origin');
    // 移除PHP版本信息
    header_remove('X-Powered-By');
}

// ==================== IP封禁管理系统 ====================

/**
 * 确保IP封禁表存在
 */
function ensure_ip_ban_table() {
    static $ensured = false;
    if ($ensured) return;
    $ensured = true;
    try {
        $prefix = \think\Db::getConfig('prefix');
        $prefix = $prefix ?: '';
        $table = "{$prefix}ip_ban";
        $exists = \think\Db::query("SHOW TABLES LIKE '{$table}'");
        if (empty($exists)) {
            \think\Db::execute("CREATE TABLE `{$table}` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `ip` varchar(50) NOT NULL DEFAULT '' COMMENT '被封禁IP',
                `reason` varchar(255) DEFAULT '' COMMENT '封禁原因',
                `trigger_count` int(11) DEFAULT 1 COMMENT '触发次数',
                `first_seen` int(11) DEFAULT 0 COMMENT '首次触发时间',
                `last_seen` int(11) DEFAULT 0 COMMENT '最近触发时间',
                `ban_type` varchar(30) DEFAULT 'auto' COMMENT '封禁类型: auto=自动 timeout=定时 permanent=永久',
                `status` tinyint(1) DEFAULT 1 COMMENT '1=生效中 0=已解除',
                `banned_at` int(11) DEFAULT 0 COMMENT '封禁时间',
                `unban_at` int(11) DEFAULT 0 COMMENT '自动解封时间(0=永久)',
                `note` varchar(255) DEFAULT '' COMMENT '管理员备注',
                PRIMARY KEY (`id`),
                KEY `idx_ip` (`ip`),
                KEY `idx_status` (`status`),
                KEY `idx_banned_at` (`banned_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
        }
    } catch (\Exception $e) {}
}

/**
 * 检查IP是否已被封禁
 * @param string $ip
 * @return bool true=已封禁
 */
function is_ip_banned($ip) {
    try {
        ensure_ip_ban_table();
        $record = \think\Db::name('ip_ban')
            ->where('ip', $ip)
            ->where('status', 1)
            ->where('ban_type', '<>', 'auto')
            ->where(function ($query) {
                $query->where('unban_at', 0)
                      ->whereOr('unban_at', '>', time());
            })
            ->find();
        return !empty($record);
    } catch (\Exception $e) {
        return false;
    }
}

/**
 * 封禁IP
 * @param string $ip
 * @param string $reason 封禁原因
 * @param int $duration 时长(秒), 0=永久
 * @param string $banType auto|timeout|permanent
 */
function ban_ip($ip, $reason = '', $duration = 86400, $banType = 'auto') {
    try {
        ensure_ip_ban_table();
        $now = time();
        $existing = \think\Db::name('ip_ban')->where('ip', $ip)->find();
        if ($existing) {
            // 已有记录：累加触发次数，更新时间和原因
            $updateData = [
                'trigger_count' => $existing['trigger_count'] + 1,
                'last_seen'     => $now,
                'reason'        => $reason ?: $existing['reason'],
            ];
            if ($existing['status'] == 0) {
                $updateData['status'] = 1;
                $updateData['banned_at'] = $now;
                $updateData['ban_type'] = $banType;
                $updateData['unban_at'] = $duration > 0 ? ($now + $duration) : 0;
            }
            \think\Db::name('ip_ban')->where('id', $existing['id'])->update($updateData);
            return;
        }
        \think\Db::name('ip_ban')->insert([
            'ip'            => $ip,
            'reason'        => $reason,
            'trigger_count' => 1,
            'first_seen'    => $now,
            'last_seen'     => $now,
            'ban_type'      => $banType,
            'status'        => 1,
            'banned_at'     => $now,
            'unban_at'      => $duration > 0 ? ($now + $duration) : 0,
        ]);
    } catch (\Exception $e) {}
}

/**
 * 解除IP封禁
 * @param int $id 记录ID
 */
function unban_ip($id) {
    try {
        ensure_ip_ban_table();
        \think\Db::name('ip_ban')->where('id', $id)->update([
            'status' => 0,
        ]);
    } catch (\Exception $e) {}
}

/**
 * 获取被封禁IP的请求路径列表 (从安全日志聚合)
 */
function get_banned_ip_paths($ip, $limit = 10) {
    try {
        $logDir = (defined('LOG_PATH') ? LOG_PATH : (__DIR__ . '/../runtime/log/')) . 'security/';
        $today = date('Y-m-d');
        $paths = [];

        // 扫描今天的扫描器日志
        $scannerFile = $logDir . 'scanner_' . $today . '.log';
        if (file_exists($scannerFile)) {
            $lines = file($scannerFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $log = json_decode($line, true);
                if ($log && isset($log['ip']) && $log['ip'] === $ip && isset($log['uri'])) {
                    $paths[] = $log['uri'];
                    if (count($paths) >= $limit) break;
                }
            }
        }

        // 也检查昨天的
        if (count($paths) < $limit) {
            $yesterday = date('Y-m-d', strtotime('-1 day'));
            $yesterdayFile = $logDir . 'scanner_' . $yesterday . '.log';
            if (file_exists($yesterdayFile)) {
                $lines = file($yesterdayFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($lines as $line) {
                    $log = json_decode($line, true);
                    if ($log && isset($log['ip']) && $log['ip'] === $ip && isset($log['uri'])) {
                        $paths[] = $log['uri'];
                        if (count($paths) >= $limit) break;
                    }
                }
            }
        }
        return array_unique($paths);
    } catch (\Exception $e) {
        return [];
    }
}

// ==================== 访客访问日志系统 ====================

/**
 * 确保访客日志表存在
 */
function ensure_visitor_log_table() {
    static $ensured = false;
    if ($ensured) return;
    try {
        $prefix = \think\Db::getConfig('prefix');
        $prefix = $prefix ?: '';
        $table = "{$prefix}visitor_log";
        $exists = \think\Db::query("SHOW TABLES LIKE '{$table}'");
        if (empty($exists)) {
            \think\Db::execute("CREATE TABLE `{$table}` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `ip` varchar(50) NOT NULL DEFAULT '' COMMENT 'IP地址',
                `uri` varchar(500) NOT NULL DEFAULT '' COMMENT '请求路径',
                `url` varchar(500) NOT NULL DEFAULT '' COMMENT '访问URL',
                `user_agent` varchar(500) NOT NULL DEFAULT '' COMMENT '浏览器UA',
                `referer` varchar(500) NOT NULL DEFAULT '' COMMENT '来源页',
                `user_id` int(11) NOT NULL DEFAULT 0 COMMENT '用户ID(0=访客)',
                `request_method` varchar(10) NOT NULL DEFAULT 'GET' COMMENT '请求方法',
                `visit_time` int(11) NOT NULL DEFAULT 0 COMMENT '访问时间戳',
                `date` varchar(10) NOT NULL DEFAULT '' COMMENT '日期',
                `hour` int(2) NOT NULL DEFAULT 0 COMMENT '小时0-23',
                `is_bot` tinyint(1) NOT NULL DEFAULT 0 COMMENT '疑似机器人:0否 1是',
                `bot_reason` varchar(255) NOT NULL DEFAULT '' COMMENT '机器人判定原因',
                PRIMARY KEY (`id`),
                KEY `idx_ip` (`ip`),
                KEY `idx_user_id` (`user_id`),
                KEY `idx_visit_time` (`visit_time`),
                KEY `idx_date` (`date`),
                KEY `idx_hour` (`date`,`hour`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='访客访问日志'");
        } else {
            // 表已存在：补齐缺失列，兼容 record_visitor 与 log_visitor/visitorStats 两种字段
            // （历史版本可能仅创建了 url/date/hour 或仅 uri/is_bot 的旧结构）
            $existingCols = array_column(\think\Db::query("SHOW COLUMNS FROM `{$table}`"), 'Field');
            $columns = [
                'uri'            => "ALTER TABLE `{$table}` ADD COLUMN `uri` varchar(500) NOT NULL DEFAULT '' COMMENT '请求路径'",
                'url'            => "ALTER TABLE `{$table}` ADD COLUMN `url` varchar(500) NOT NULL DEFAULT '' COMMENT '访问URL'",
                'user_id'        => "ALTER TABLE `{$table}` ADD COLUMN `user_id` int(11) NOT NULL DEFAULT 0 COMMENT '用户ID(0=访客)'",
                'request_method' => "ALTER TABLE `{$table}` ADD COLUMN `request_method` varchar(10) NOT NULL DEFAULT 'GET' COMMENT '请求方法'",
                'date'           => "ALTER TABLE `{$table}` ADD COLUMN `date` varchar(10) NOT NULL DEFAULT '' COMMENT '日期'",
                'hour'           => "ALTER TABLE `{$table}` ADD COLUMN `hour` int(2) NOT NULL DEFAULT 0 COMMENT '小时0-23'",
                'is_bot'         => "ALTER TABLE `{$table}` ADD COLUMN `is_bot` tinyint(1) NOT NULL DEFAULT 0 COMMENT '疑似机器人:0否 1是'",
                'bot_reason'     => "ALTER TABLE `{$table}` ADD COLUMN `bot_reason` varchar(255) NOT NULL DEFAULT '' COMMENT '机器人判定原因'",
            ];
            foreach ($columns as $colName => $alterSql) {
                if (!in_array($colName, $existingCols, true)) {
                    \think\Db::execute($alterSql);
                }
            }
        }
        $ensured = true;  // 仅在成功后才标记
    } catch (\Throwable $e) {}
}

/**
 * 记录访客访问日志
 */
function record_visitor($ip, $uri, $userAgent, $userId, $method, $referer) {
    try {
        ensure_visitor_log_table();

        // 检测疑似机器人
        $isBot = 0;
        $botReason = '';

        $uaLower = mb_strtolower($userAgent);
        $botKeywords = ['bot', 'crawler', 'spider', 'scanner', 'python', 'java', 'curl', 'wget', 'go-http', 'axios', 'fetch'];
        if (empty($userAgent)) {
            $isBot = 1;
            $botReason = 'UA为空';
        } else {
            foreach ($botKeywords as $kw) {
                if (strpos($uaLower, $kw) !== false) {
                    $isBot = 1;
                    $botReason = 'UA包含关键字: ' . $kw;
                    break;
                }
            }
        }

        // 同一IP在10秒内请求超过5次
        $recentCount = \think\Db::name('visitor_log')
            ->where('ip', $ip)
            ->where('visit_time', '>', time() - 10)
            ->count();
        if ($recentCount >= 5) {
            $isBot = 1;
            $botReason = $botReason ? ($botReason . '; 高频请求') : '高频请求(10秒>' . $recentCount . '次)';
        }

        $now = time();
        \think\Db::name('visitor_log')->insert([
            'ip'             => mb_substr($ip, 0, 50),
            'uri'            => mb_substr($uri, 0, 500),
            'url'            => mb_substr($uri, 0, 500),
            'user_agent'     => mb_substr($userAgent, 0, 500),
            'user_id'        => intval($userId),
            'request_method' => mb_substr($method, 0, 10),
            'referer'        => mb_substr($referer, 0, 500),
            'visit_time'     => $now,
            'date'           => date('Y-m-d', $now),
            'hour'           => (int)date('H', $now),
            'is_bot'         => $isBot,
            'bot_reason'     => $botReason,
        ]);

        // 每次最多保留30天日志，超过30天自动清理（概率清理，约1%概率触发）
        if (mt_rand(1, 100) === 1) {
            $threshold = time() - 30 * 86400;
            \think\Db::name('visitor_log')->where('visit_time', '<', $threshold)->delete();
        }
    } catch (\Exception $e) {}
}