<?php
/**
 * 安装状态检测（独立文件，不依赖框架，入口 public/index.php 与安装器共用）
 *
 * 判定规则（任一不满足即视为未安装，自动进入安装向导）：
 *   1. 未添加数据库连接信息（app/database.php 缺失 / 内容为空 / 关键项为空）
 *   2. 数据库连接失败
 *   3. 数据库中没有识别到专属的已安装标记
 *
 * 已安装标记：优先识别专用标记表 `{prefix}install`；
 * 兼容旧版本：核心表 `{prefix}web` 存在同样视为已安装。
 */

if (!function_exists('mnbt_db_config')) {
    /**
     * 读取数据库配置
     * @return array|null 配置数组，缺失/无效时返回 null
     */
    function mnbt_db_config()
    {
        $file = PATH . 'app/database.php';
        if (!is_file($file)) {
            return null;
        }
        $cfg = @include $file;
        if (!is_array($cfg)) {
            return null;
        }
        if (empty($cfg['hostname']) || empty($cfg['database']) || empty($cfg['username'])) {
            return null;
        }
        return $cfg;
    }
}

if (!function_exists('mnbt_install_state')) {
    /**
     * 检测安装状态
     * @param bool $useCache 是否使用 60 秒正向缓存（减少每请求连库开销）
     * @return array ['ok'=>bool, 'reason'=>string]
     *               reason: no_config / empty_config / db_error / no_marker / ok / cache
     */
    function mnbt_install_state($useCache = true)
    {
        $cfg = mnbt_db_config();
        if ($cfg === null) {
            return ['ok' => false, 'reason' => is_file(PATH . 'app/database.php') ? 'empty_config' : 'no_config'];
        }

        // 正向结果短期缓存（仅缓存"已安装"，未安装状态不缓存，方便修好后即时生效）
        $cacheFile = PATH . 'runtime/install_state.cache';
        if ($useCache && is_file($cacheFile) && (time() - filemtime($cacheFile) < 60)
            && trim((string) @file_get_contents($cacheFile)) === '1') {
            return ['ok' => true, 'reason' => 'cache'];
        }

        if (!class_exists('PDO') || !in_array('mysql', PDO::getAvailableDrivers(), true)) {
            return ['ok' => false, 'reason' => 'db_error'];
        }

        try {
            $port  = isset($cfg['hostport']) && $cfg['hostport'] !== '' ? $cfg['hostport'] : '3306';
            // 必须带上 dbname（或建连后 USE），否则 SHOW TABLES 在「未选择数据库」状态下
            // 会抛 1046 或查不到已安装标记表，导致已安装却判定为未安装 → 安装完成页/前台被踢回安装向导第一步
            $dsn   = 'mysql:host=' . $cfg['hostname'] . ';port=' . $port
                . ';dbname=' . (string) $cfg['database'] . ';charset=utf8';
            $pdo   = new PDO($dsn, (string) $cfg['username'], (string) $cfg['password'], [
                PDO::ATTR_TIMEOUT    => 2,
                PDO::ATTR_ERRMODE    => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_PERSISTENT => false,
            ]);

            $prefix = isset($cfg['prefix']) ? (string) $cfg['prefix'] : '';

            // 专属已安装标记：{prefix}install 表；兼容旧版本：{prefix}web 表
            $found = false;
            foreach ([$prefix . 'install', $prefix . 'web'] as $table) {
                $like = str_replace(["\\", "'"], ["\\\\", "\\'"], $table);
                $stmt = $pdo->query("SHOW TABLES LIKE '" . $like . "'");
                if ($stmt && $stmt->fetch()) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return ['ok' => false, 'reason' => 'no_marker'];
            }

            // 写入正向缓存
            if (!is_dir(PATH . 'runtime')) {
                @mkdir(PATH . 'runtime', 0755, true);
            }
            @file_put_contents($cacheFile, '1');

            return ['ok' => true, 'reason' => 'ok'];
        } catch (Throwable $e) {
            return ['ok' => false, 'reason' => 'db_error'];
        }
    }
}

if (!function_exists('mnbt_db_installed')) {
    /**
     * 是否已安装（数据库存在专属标记）
     * @return bool
     */
    function mnbt_db_installed()
    {
        return mnbt_install_state()['ok'] === true;
    }
}

if (!function_exists('mnbt_install_log')) {
    /**
     * 记录安装跳转诊断日志（runtime/log/install_redirect.log）
     */
    function mnbt_install_log($from, $to, $reason, $action)
    {
        $dir = PATH . 'runtime/log/';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '-';
        @file_put_contents(
            $dir . 'install_redirect.log',
            date('Y-m-d H:i:s') . " action={$action} from={$from} to={$to} reason={$reason} ip={$ip}\n",
            FILE_APPEND
        );
    }
}

if (!function_exists('mnbt_install_diag_page')) {
    /**
     * 输出安装跳转诊断页（用于自跳/循环熔断时，替代无限重定向）
     */
    function mnbt_install_diag_page($path, $target, $reason, $action)
    {
        $cfg = mnbt_db_config();
        $cfgText = $cfg === null
            ? '未读取到有效数据库配置（app/database.php 缺失或关键项为空）'
            : sprintf(
                'hostname=%s / database=%s / username=%s / prefix=%s',
                $cfg['hostname'], $cfg['database'],
                $cfg['username'] === '' ? '(空)' : $cfg['username'],
                isset($cfg['prefix']) ? $cfg['prefix'] : ''
            );
        $html = '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>安装跳转诊断</title><style>'
            . 'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;background:#f1f5f9;color:#1e293b;margin:0;padding:32px}'
            . '.box{max-width:760px;margin:0 auto;background:#fff;border-radius:16px;padding:28px;box-shadow:0 10px 40px rgba(15,23,42,.08)}'
            . 'h1{font-size:20px;margin:0 0 6px}.sub{color:#64748b;font-size:13px;margin-bottom:20px}'
            . 'table{width:100%;border-collapse:collapse;font-size:13px}td{padding:9px 10px;border-bottom:1px solid #e2e8f0;vertical-align:top}td:first-child{width:150px;color:#64748b;white-space:nowrap}'
            . 'code{background:#f1f5f9;padding:2px 6px;border-radius:5px;font-size:12px}'
            . '.bad{color:#dc2626;font-weight:600}.ok{color:#16a34a;font-weight:600}'
            . '.hint{margin-top:18px;padding:14px 16px;background:#fefce8;border-left:4px solid #eab308;border-radius:8px;font-size:13px;line-height:1.7}'
            . '</style></head><body><div class="box">'
            . '<h1>安装跳转诊断</h1><div class="sub">系统检测到安装跳转出现异常，已停止继续重定向以避免循环。<b>本页面不影响安装，排查完成后可删除诊断脚本。</b></div>'
            . '<table>'
            . '<tr><td>拦截动作</td><td><code>' . htmlspecialchars($action) . '</code></td></tr>'
            . '<tr><td>安装状态判定</td><td><span class="bad">' . htmlspecialchars($reason) . '</span></td></tr>'
            . '<tr><td>当前请求路径</td><td><code>' . htmlspecialchars($path) . '</code></td></tr>'
            . '<tr><td>打算跳转到</td><td><code>' . htmlspecialchars($target) . '</code></td></tr>'
            . '<tr><td>数据库配置</td><td><code>' . htmlspecialchars($cfgText) . '</code></td></tr>'
            . '<tr><td>REQUEST_URI</td><td><code>' . htmlspecialchars(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '-') . '</code></td></tr>'
            . '<tr><td>SCRIPT_NAME</td><td><code>' . htmlspecialchars(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '-') . '</code></td></tr>'
            . '<tr><td>PATH_INFO</td><td><code>' . htmlspecialchars(isset($_SERVER['PATH_INFO']) ? $_SERVER['PATH_INFO'] : '-') . '</code></td></tr>'
            . '</table>'
            . '<div class="hint">请在服务器上访问 <code>/install_debug.php</code> 获取完整诊断信息（REQUEST_URI / 数据库连接 / 已安装标记表 / 行为是否加载）。'
            . '同时可查看 <code>runtime/log/install_redirect.log</code> 中的跳转记录。</div>'
            . '</div></body></html>';
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
            http_response_code(500);
        }
        echo $html;
    }
}

if (!function_exists('mnbt_install_goto')) {
    /**
     * 统一跳转到安装向导（带自跳防御 + 循环熔断）
     *
     * @param string $target 目标 URL（如 /install）
     * @return bool 是否已发出跳转
     */
    function mnbt_install_goto($target)
    {
        $uri  = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
        $path = parse_url($uri, PHP_URL_PATH);
        if ($path === null || $path === false || $path === '') {
            $path = '/';
        }
        $path  = str_replace('\\', '/', $path);
        $state = mnbt_install_state();

        // 1) 自跳防御：目标与当前路径一致时若继续跳转会形成死循环
        if (rtrim($path, '/') === rtrim((string) $target, '/')) {
            mnbt_install_log($path, $target, $state['reason'], 'self_redirect_blocked');
            mnbt_install_diag_page($path, $target, $state['reason'], 'self_redirect_blocked');
            return false;
        }

        // 2) 循环熔断：60 秒内跳转 > 6 次则停止并显示诊断页
        $flagFile = PATH . 'runtime/install_redirect.json';
        $flag = @json_decode((string) @file_get_contents($flagFile), true);
        if (!is_array($flag) || !isset($flag['t']) || (time() - $flag['t']) > 60) {
            $flag = ['t' => time(), 'n' => 0];
        }
        $flag['n']++;
        @file_put_contents($flagFile, json_encode($flag));
        if ($flag['n'] > 6) {
            mnbt_install_log($path, $target, $state['reason'], 'loop_break_' . $flag['n']);
            mnbt_install_diag_page($path, $target, $state['reason'], 'loop_break');
            return false;
        }

        mnbt_install_log($path, $target, $state['reason'], 'redirect');
        if (!headers_sent()) {
            header('Location: ' . $target, true, 302);
        } else {
            echo '<meta http-equiv="refresh" content="0;url=' . htmlspecialchars($target) . '">';
        }
        return true;
    }
}

if (!function_exists('mnbt_is_install_uri')) {
    /**
     * 当前请求是否访问安装向导（避免跳转死循环）
     * @param string $path    当前请求路径
     * @param string $baseDir 站点子目录前缀（根目录部署时为空）
     * @return bool
     */
    function mnbt_is_install_uri($path, $baseDir = '')
    {
        $path = str_replace('\\', '/', (string) $path);
        if ($baseDir !== '' && strpos($path, $baseDir) === 0) {
            $path = substr($path, strlen($baseDir));
        }
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }
        return preg_match('#^/install(/|$|\?)#i', $path) === 1;
    }
}
