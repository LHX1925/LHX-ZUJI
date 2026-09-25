<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2016 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------
// [ 应用入口文件 ]
// 定义应用目录
define('APP_PATH', __DIR__ . '/../app/');
define('PATH', __DIR__ . '/../');

// ---------------------------------------------------------------------------
// 兼容未正确传递 PATH_INFO 的服务器伪静态写法
// 例如 nginx: try_files $uri $uri/ /index.php?$query_string;
// 这种写法下 PHP 拿不到 PATH_INFO / $_GET['s']，ThinkPHP 的 Request::path() 会
// 退化成 "/"，于是所有地址（含 /install）都会命中路由 "/" -> index/index/index，
// 表现为「输 install 却进首页 / 报数据库错」。这里从 REQUEST_URI 反推 PATH_INFO。
// ---------------------------------------------------------------------------
if (PHP_SAPI != 'cli') {
    $mnbtScript = isset($_SERVER['SCRIPT_NAME'])
        ? str_replace('\\', '/', (string) $_SERVER['SCRIPT_NAME']) : '';
    $mnbtBaseDir = rtrim(str_replace('\\', '/', dirname($mnbtScript)), '/');
    if ($mnbtBaseDir === '/' || $mnbtBaseDir === '.') {
        $mnbtBaseDir = '';
    }

    // 把候选路径还原成「相对站点根目录」的实际请求路径
    $mnbtResolve = function ($raw) use ($mnbtScript, $mnbtBaseDir) {
        $p = (string) parse_url((string) $raw, PHP_URL_PATH);
        if ($p === '') {
            return '';
        }
        $p = str_replace('\\', '/', $p);
        // 兼容 /index.php/install/xxx 形式
        if ($mnbtScript !== '' && stripos($p, $mnbtScript) === 0) {
            $p = substr($p, strlen($mnbtScript));
        } elseif ($mnbtBaseDir !== '' && stripos($p, $mnbtBaseDir) === 0) {
            // 兼容子目录部署
            $p = substr($p, strlen($mnbtBaseDir));
        }
        $p = trim($p, '/');
        if ($p === '' || ($mnbtScript !== '' && $p === basename($mnbtScript))) {
            return '';
        }
        return $p;
    };

    // 路径来源候选（重写组件常见做法）
    $mnbtCandidates = [];
    if (!empty($_SERVER['HTTP_X_REWRITE_URL'])) {
        $mnbtCandidates[] = (string) $_SERVER['HTTP_X_REWRITE_URL'];
    }
    if (!empty($_SERVER['REQUEST_URI'])) {
        $mnbtCandidates[] = (string) $_SERVER['REQUEST_URI'];
    }

    $mnbtRel = '';
    foreach ($mnbtCandidates as $mnbtRaw) {
        $mnbtRel = $mnbtResolve($mnbtRaw);
        if ($mnbtRel !== '') {
            break;
        }
    }

    if ($mnbtRel !== '') {
        $mnbtUri = '/' . $mnbtRel;
        $varPathinfo = 's';

        if (empty($_SERVER['PATH_INFO']) && empty($_GET[$varPathinfo])) {
            $_SERVER['PATH_INFO'] = $mnbtUri;
            if (empty($_SERVER['ORIG_PATH_INFO'])) {
                $_SERVER['ORIG_PATH_INFO'] = $mnbtUri;
            }
        }

        // 部分重写方式下 REQUEST_URI 仍是 /index.php，这里补齐，
        // 保证后续安装检测 / 控制器里基于 REQUEST_URI 的判断拿到真实路径
        $mnbtCur = isset($_SERVER['REQUEST_URI'])
            ? (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) : '';
        $mnbtCurIsRoot = ($mnbtCur === '' || $mnbtCur === '/'
            || ($mnbtScript !== '' && rtrim($mnbtCur, '/') === rtrim($mnbtScript, '/')));
        if ($mnbtCurIsRoot) {
            $mnbtQuery = isset($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : '';
            $_SERVER['REQUEST_URI'] = $mnbtUri . ($mnbtQuery !== '' ? '?' . $mnbtQuery : '');
        }
    }

    unset($mnbtScript, $mnbtBaseDir, $mnbtResolve, $mnbtCandidates, $mnbtRaw,
        $mnbtRel, $mnbtUri, $mnbtCur, $mnbtCurIsRoot, $mnbtQuery, $varPathinfo);
}

/**
 * 判断当前访客是否已登录后台（仅用于「隐藏默认后台入口」时的放行判断，失败时返回 false 会误拦，
 * 因此仅在能明确读到会话信息时返回 true；无法读取会话的异常情况由调用处兜底放行逻辑处理）。
 */
function mnbt_admin_has_session()
{
    static $checked = null;
    if ($checked !== null) {
        return $checked;
    }
    $checked = false;
    if (PHP_SAPI === 'cli') {
        return $checked;
    }
    try {
        // 无会话 Cookie 一定未登录，无需启动会话
        $sessionName = function_exists('session_name') ? session_name() : 'PHPSESSID';
        if (empty($sessionName)) { $sessionName = 'PHPSESSID'; }
        if (empty($_COOKIE[$sessionName])) {
            return $checked;
        }
        if (PHP_SESSION_ACTIVE !== session_status()) {
            @session_start();
        }
        if (!empty($_SESSION['think']['adminid'])) {
            $checked = true;
        }
    } catch (\Throwable $e) {
        // 读取会话异常时放行，避免把管理员锁在门外
        $checked = true;
    }
    return $checked;
}

// 检测是否已安装：无数据库配置 / 数据库连接失败 / 数据库无已安装标记 → 自动进入安装向导
if (PHP_SAPI != 'cli') {
    require_once PATH . 'app/install_check.php';

    // 如果当前不在安装模块，则跳转
    $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
    // 去掉查询字符串，避免 /?xxx 之类的干扰
    $path = parse_url($uri, PHP_URL_PATH);
    if ($path === null || $path === false || $path === '') {
        $path = '/';
    }
    // 统一为正斜杠，兼容 Windows 路径分隔符
    $path = str_replace('\\', '/', $path);
    // 自动检测子目录路径，避免在非根目录部署时跳转失败
    $scriptName = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/index.php';
    $baseDir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
    if ($baseDir === '' || $baseDir === '/') {
        $baseDir = '';
    }

    // 仅当当前访问路径不是安装路径时才跳转（防止死循环）
    if (!mnbt_is_install_uri($path, $baseDir)) {
        $state = mnbt_install_state();
        if (!$state['ok']) {
            // mnbt_install_goto 内含自跳防御与循环熔断，失败时直接输出诊断页
            mnbt_install_goto($baseDir . '/install');
            exit;
        }
    }
}

// ---------------------------------------------------------------------------
// 后台登录入口自定义（隐藏默认 /admin/login）
//   1) 访问自定义入口（如 /manage/login）→ 内部重写为 /admin/login
//   2) 访问默认 /admin/login → 直接 404，不暴露自定义入口
//   3) 其余 /admin/xxx 仍可正常路由（后台内部链接/已登录状态不受影响）
// 读取 admin_path：优先 runtime/admin_entrance.json 缓存（后台保存设置时会失效，10 分钟自动过期），
// 缓存不存在或过期时再连库读取并回写缓存，避免每次请求都连库。
// ---------------------------------------------------------------------------
if (PHP_SAPI != 'cli') {
    $mnbtAdminPath = '';
    $mnbtAdminPathKnown = false;
    try {
        $mnbtCacheFile = PATH . 'runtime/admin_entrance.json';
        $mnbtCacheRaw = is_file($mnbtCacheFile) ? @file_get_contents($mnbtCacheFile) : '';
        $mnbtCacheData = $mnbtCacheRaw ? @json_decode($mnbtCacheRaw, true) : null;
        if (is_array($mnbtCacheData) && array_key_exists('path', $mnbtCacheData)
            && isset($mnbtCacheData['t']) && (time() - intval($mnbtCacheData['t'])) < 600) {
            $mnbtAdminPath = trim((string) $mnbtCacheData['path'], '/');
            $mnbtAdminPathKnown = true;
        }
        if (!$mnbtAdminPathKnown) {
            $mnbtDbConfig = is_file(PATH . 'app/database.php') ? include PATH . 'app/database.php' : [];
            if (is_array($mnbtDbConfig) && !empty($mnbtDbConfig['hostname']) && !empty($mnbtDbConfig['database'])) {
                $mnbtDsn = 'mysql:host=' . $mnbtDbConfig['hostname']
                    . ';port=' . (isset($mnbtDbConfig['hostport']) && $mnbtDbConfig['hostport'] !== '' ? $mnbtDbConfig['hostport'] : '3306')
                    . ';dbname=' . $mnbtDbConfig['database']
                    . ';charset=utf8mb4';
                $mnbtPdo = new \PDO($mnbtDsn, $mnbtDbConfig['username'], $mnbtDbConfig['password'], [\PDO::ATTR_TIMEOUT => 3]);
                $mnbtPrefix = isset($mnbtDbConfig['prefix']) ? $mnbtDbConfig['prefix'] : '';
                $mnbtStmt = $mnbtPdo->prepare("SELECT admin_path FROM `{$mnbtPrefix}web` WHERE id = 1 LIMIT 1");
                $mnbtStmt->execute();
                $mnbtVal = $mnbtStmt->fetchColumn();
                $mnbtAdminPath = $mnbtVal === false ? '' : trim((string) $mnbtVal, '/');
                $mnbtPdo = null;
                $mnbtAdminPathKnown = true;
                // 读取成功才写缓存（读取失败说明数据库异常，不能把错误值缓存下来）
                $mnbtRuntimeDir = PATH . 'runtime/';
                if (!is_dir($mnbtRuntimeDir)) {
                    @mkdir($mnbtRuntimeDir, 0755, true);
                }
                @file_put_contents(
                    $mnbtCacheFile,
                    json_encode(['path' => $mnbtAdminPath, 't' => time()]),
                    LOCK_EX
                );
            }
        }
    } catch (\Throwable $e) {
        $mnbtAdminPath = '';
    }

    if ($mnbtAdminPath !== '' && $mnbtAdminPath !== 'admin') {
        // 还原「相对站点根目录」的请求路径（兼容子目录部署与 /index.php/xxx 形式）
        $mnbtEntrancePath = (string) parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH);
        if ($mnbtEntrancePath === '') { $mnbtEntrancePath = '/'; }
        $mnbtScriptName = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', (string) $_SERVER['SCRIPT_NAME']) : '/index.php';
        $mnbtBase = rtrim(str_replace('\\', '/', dirname($mnbtScriptName)), '/');
        if ($mnbtBase === '/' || $mnbtBase === '.') { $mnbtBase = ''; }
        if ($mnbtScriptName !== '' && stripos($mnbtEntrancePath, $mnbtScriptName) === 0) {
            $mnbtEntrancePath = substr($mnbtEntrancePath, strlen($mnbtScriptName));
        } elseif ($mnbtBase !== '' && stripos($mnbtEntrancePath, $mnbtBase) === 0) {
            $mnbtEntrancePath = substr($mnbtEntrancePath, strlen($mnbtBase));
        }
        if ($mnbtEntrancePath === '' || $mnbtEntrancePath[0] !== '/') { $mnbtEntrancePath = '/' . ltrim($mnbtEntrancePath, '/'); }

        $mnbtPrefixPath = '/' . $mnbtAdminPath;
        if ($mnbtEntrancePath === $mnbtPrefixPath || strpos($mnbtEntrancePath, $mnbtPrefixPath . '/') === 0) {
            // 自定义入口 → 内部重写为 /admin
            $mnbtNewPath = '/admin' . substr($mnbtEntrancePath, strlen($mnbtPrefixPath));
            $_SERVER['PATH_INFO']      = $mnbtNewPath;
            $_SERVER['ORIG_PATH_INFO'] = $mnbtNewPath;
            $_SERVER['REDIRECT_URL']   = $mnbtNewPath;
            if (isset($_GET['s'])) { $_GET['s'] = $mnbtNewPath; }
            $mnbtEntranceQuery = parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '', PHP_URL_QUERY);
            $_SERVER['REQUEST_URI'] = $mnbtBase . $mnbtNewPath . ($mnbtEntranceQuery ? '?' . $mnbtEntranceQuery : '');
            // 标记：本次请求是经自定义入口进入的（后台以此生成入口地址）
            $_SERVER['MNBT_ADMIN_ENTRANCE_OK'] = '1';
        } elseif ($mnbtEntrancePath === '/admin/login' || strpos($mnbtEntrancePath, '/admin/login/') === 0
            || ($mnbtEntrancePath === '/admin' && !mnbt_admin_has_session())) {
            // 1) 默认后台登录入口已隐藏
            // 2) 未登录时直接访问 /admin 同样视为不存在（已登录管理员仍可正常打开后台）
            http_response_code(404);
            header('Content-Type: text/html; charset=utf-8');
            echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>404 Not Found</title><style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;background:#f6f8fb;color:#64748b;margin:0}.box{text-align:center;padding:40px}.code{font-size:88px;font-weight:900;color:#e2e8f0;line-height:1}.msg{font-size:15px;margin-top:14px}</style></head><body><div class="box"><div class="code">404</div><div class="msg">您访问的页面不存在</div></div></body></html>';
            exit;
        }
    }

    unset($mnbtAdminPath, $mnbtAdminPathKnown, $mnbtCacheFile, $mnbtCacheRaw, $mnbtCacheData, $mnbtDbConfig,
        $mnbtDsn, $mnbtPdo, $mnbtPrefix, $mnbtStmt, $mnbtVal, $mnbtRuntimeDir,
        $mnbtEntrancePath, $mnbtScriptName, $mnbtBase, $mnbtPrefixPath, $mnbtNewPath, $mnbtEntranceQuery);
}

// 加载框架引导文件
require __DIR__ . '/../frame/start.php';
