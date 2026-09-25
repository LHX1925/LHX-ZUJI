<?php
/**
 * 安装流程诊断脚本（临时工具，绕过 ThinkPHP 框架直接运行）
 *
 * 用法：放在 public/ 目录，浏览器访问 https://你的域名/install_debug.php
 * 排查完成后请务必删除本文件。
 */
define('PATH', __DIR__ . '/../');
require_once PATH . 'app/install_check.php';

header('Content-Type: text/html; charset=utf-8');

function hd($v)
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function maskSecret($v)
{
    $v = (string) $v;
    if ($v === '') {
        return '(空)';
    }
    return '***（长度 ' . strlen($v) . '，首字符 ' . mb_substr($v, 0, 1) . '）';
}

function yesno($b)
{
    return $b
        ? '<span style="color:#16a34a;font-weight:700">✓ 是</span>'
        : '<span style="color:#dc2626;font-weight:700">✗ 否</span>';
}

// ---- 基础信息 ----
$reqUri    = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '-';
$scriptName = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/index.php';
$reqPath   = parse_url($reqUri, PHP_URL_PATH);
$baseDir   = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
if ($baseDir === '' || $baseDir === '/') {
    $baseDir = '';
}

// 模拟访问 /install 时的判定
$installPath = $baseDir . '/install';
$isInstallUri = mnbt_is_install_uri($installPath, $baseDir);
$state        = mnbt_install_state(false);
$cfg          = mnbt_db_config();

// ---- 数据库连接与标记表 ----
$dbConnect = false;
$dbError   = '';
$tables    = [];
$markerInstall = false;
$markerWeb = false;
if ($cfg !== null) {
    try {
        $port = isset($cfg['hostport']) && $cfg['hostport'] !== '' ? $cfg['hostport'] : '3306';
        $dsn  = 'mysql:host=' . $cfg['hostname'] . ';port=' . $port . ';charset=utf8';
        $pdo  = new PDO($dsn, (string) $cfg['username'], (string) $cfg['password'], [
            PDO::ATTR_TIMEOUT => 3,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $dbConnect = true;
        $prefix = isset($cfg['prefix']) ? (string) $cfg['prefix'] : '';
        // 不指定 dbname 时先看看能否切库
        try {
            $pdo->exec('USE `' . str_replace('`', '``', $cfg['database']) . '`');
        } catch (Throwable $e2) {
            $dbError = '切换数据库失败: ' . $e2->getMessage();
        }
        try {
            $stmt = $pdo->query('SHOW TABLES');
            $tables = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
        } catch (Throwable $e3) {
            $tables = [];
        }
        $markerInstall = in_array($prefix . 'install', $tables, true);
        $markerWeb     = in_array($prefix . 'web', $tables, true);
    } catch (Throwable $e) {
        $dbError = $e->getMessage();
    }
}

// ---- 行为加载检查 ----
$behaviorFile = PATH . 'app/behavior/InstallCheck.php';
$tagsFile     = PATH . 'app/tags.php';
$tagsContent  = is_file($tagsFile) ? (string) file_get_contents($tagsFile) : '';
$behaviorRegistered = strpos($tagsContent, 'InstallCheck') !== false;

// ---- 运行时目录 ----
$runtimeOk = is_dir(PATH . 'runtime') && is_writable(PATH . 'runtime');

// ---- 跳转日志 ----
$logFile = PATH . 'runtime/log/install_redirect.log';
$logTail = is_file($logFile) ? implode("\n", array_slice(file($logFile), -20)) : '(暂无跳转记录)';

// ---- route.php 中 install 路由 ----
$routeFile = PATH . 'app/route.php';
$routeContent = is_file($routeFile) ? (string) file_get_contents($routeFile) : '';
$installRouteCount = preg_match_all('#"/install[^"]*"\s*=>#i', $routeContent);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>安装流程诊断 · LHX-YunHost-Panel</title>
<style>
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;background:#f1f5f9;color:#1e293b;margin:0;padding:28px}
.box{max-width:900px;margin:0 auto;background:#fff;border-radius:16px;padding:26px 28px;box-shadow:0 10px 40px rgba(15,23,42,.08);margin-bottom:18px}
h1{font-size:20px;margin:0 0 4px}
h2{font-size:15px;margin:0 0 12px;color:#334155;border-left:4px solid #6366f1;padding-left:10px}
.sub{color:#64748b;font-size:13px;margin-bottom:18px}
table{width:100%;border-collapse:collapse;font-size:13px}
td{padding:8px 10px;border-bottom:1px solid #eef2f7;vertical-align:top}
td:first-child{width:210px;color:#64748b;white-space:nowrap}
code{background:#f1f5f9;padding:2px 6px;border-radius:5px;font-size:12px;word-break:break-all}
.warn{margin:16px 0;padding:14px 16px;background:#fef2f2;border-left:4px solid #ef4444;border-radius:8px;font-size:13px;line-height:1.7}
.ok{margin:16px 0;padding:14px 16px;background:#f0fdf4;border-left:4px solid #22c55e;border-radius:8px;font-size:13px;line-height:1.7}
pre{background:#0f172a;color:#e2e8f0;padding:14px;border-radius:10px;font-size:12px;overflow:auto;line-height:1.6}
</style>
</head>
<body>

<div class="box">
  <h1>安装流程诊断</h1>
  <div class="sub">生成时间：<?php echo date('Y-m-d H:i:s'); ?> &nbsp;|&nbsp; PHP <?php echo PHP_VERSION; ?> &nbsp;|&nbsp; 站点根目录：<code><?php echo hd(dirname(__DIR__)); ?></code></div>

  <?php if ($state['ok']): ?>
    <div class="ok"><b>安装状态：已完成安装</b>（reason=<?php echo hd($state['reason']); ?>）。<br>
    此时访问 <code>/install</code> 会被安装控制器重定向回首页面——这是正常表现。若想重新走向导，请先把 <code>{prefix}install</code> 标记表删除。</div>
  <?php else: ?>
    <div class="warn"><b>安装状态：未完成</b>（reason=<code><?php echo hd($state['reason']); ?></code>）。<br>
    各 reason 含义：<code>no_config/empty_config</code>=缺少或空数据库配置；<code>db_error</code>=连不上数据库；<code>no_marker</code>=能连库但库中无 <code>{prefix}install</code> 与 <code>{prefix}web</code> 标记表。</div>
  <?php endif; ?>
</div>

<div class="box">
  <h2>1. 请求变量（决定 /install 是否被识别）</h2>
  <table>
    <tr><td>REQUEST_URI</td><td><code><?php echo hd($reqUri); ?></code></td></tr>
    <tr><td>SCRIPT_NAME</td><td><code><?php echo hd($scriptName); ?></code></td></tr>
    <tr><td>推导出的 baseDir</td><td><code><?php echo hd($baseDir === '' ? '(空 · 根目录部署)' : $baseDir); ?></code></td></tr>
    <tr><td>PATH_INFO</td><td><code><?php echo hd(isset($_SERVER['PATH_INFO']) ? $_SERVER['PATH_INFO'] : '(无)'); ?></code></td></tr>
    <tr><td>ORIG_PATH_INFO</td><td><code><?php echo hd(isset($_SERVER['ORIG_PATH_INFO']) ? $_SERVER['ORIG_PATH_INFO'] : '(无)'); ?></code></td></tr>
    <tr><td>REDIRECT_URL</td><td><code><?php echo hd(isset($_SERVER['REDIRECT_URL']) ? $_SERVER['REDIRECT_URL'] : '(无)'); ?></code></td></tr>
    <tr><td>HTTP_X_REWRITE_URL</td><td><code><?php echo hd(isset($_SERVER['HTTP_X_REWRITE_URL']) ? $_SERVER['HTTP_X_REWRITE_URL'] : '(无)'); ?></code></td></tr>
    <tr><td>GET['s']</td><td><code><?php echo hd(isset($_GET['s']) ? $_GET['s'] : '(无)'); ?></code></td></tr>
    <tr><td>入口推导的实际路径</td><td><code><?php
        // 与 public/index.php 中的推导逻辑一致，用来判断框架拿到的 pathinfo 是否正确
        $dbgRel = '';
        foreach ([isset($_SERVER['HTTP_X_REWRITE_URL']) ? $_SERVER['HTTP_X_REWRITE_URL'] : '', $reqUri] as $dbgRaw) {
            $dbgPath = (string) parse_url((string) $dbgRaw, PHP_URL_PATH);
            if ($dbgPath === '') { continue; }
            if (stripos($dbgPath, $scriptName) === 0) {
                $dbgPath = substr($dbgPath, strlen($scriptName));
            } elseif ($baseDir !== '' && stripos($dbgPath, $baseDir) === 0) {
                $dbgPath = substr($dbgPath, strlen($baseDir));
            }
            $dbgPath = trim($dbgPath, '/');
            if ($dbgPath !== '' && $dbgPath !== basename($scriptName)) { $dbgRel = '/' . $dbgPath; break; }
        }
        echo hd($dbgRel === '' ? '(未能推导 · 说明服务器连 PATH_INFO 都没给，入口会自动补齐)' : $dbgRel);
    ?></code></td></tr>
    <tr><td>HTTP_HOST</td><td><code><?php echo hd(isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '-'); ?></code></td></tr>
    <tr><td>安装路径模拟</td><td><code><?php echo hd($installPath); ?></code> → mnbt_is_install_uri() 结果：<?php echo yesno($isInstallUri); ?></td></tr>
  </table>
  <?php if (!$isInstallUri): ?>
    <div class="warn"><b>异常：</b>函数认为 <code><?php echo hd($installPath); ?></code> 不是安装路径，这会导致 /install 自己 302 跳自己（无限重定向）。请把本页内容截图反馈。</div>
  <?php endif; ?>
</div>

<div class="box">
  <h2>2. 数据库配置与连接</h2>
  <table>
    <tr><td>app/database.php</td><td><?php echo is_file(PATH . 'app/database.php') ? '<span style="color:#16a34a">存在</span>（可写：' . (is_writable(PATH . 'app/database.php') ? '是' : '否') . '）' : '<span style="color:#dc2626;font-weight:700">不存在</span>'; ?></td></tr>
    <?php if ($cfg === null): ?>
      <tr><td>配置读取结果</td><td><span style="color:#dc2626;font-weight:700">无效/为空</span>（缺少 hostname、database 或 username）</td></tr>
    <?php else: ?>
      <tr><td>hostname : port</td><td><code><?php echo hd($cfg['hostname'] . ' : ' . ($cfg['hostport'] ?: '3306')); ?></code></td></tr>
      <tr><td>database（库名）</td><td><code><?php echo hd($cfg['database']); ?></code></td></tr>
      <tr><td>username（用户名）</td><td><code><?php echo $cfg['username'] === '' ? '<span style="color:#dc2626;font-weight:700">空 ← 这就是 1045 报错的原因</span>' : hd($cfg['username']); ?></code></td></tr>
      <tr><td>password</td><td><code><?php echo maskSecret($cfg['password']); ?></code></td></tr>
      <tr><td>prefix</td><td><code><?php echo hd(isset($cfg['prefix']) ? $cfg['prefix'] : ''); ?></code></td></tr>
      <tr><td>连接测试</td><td><?php echo $dbConnect ? '<span style="color:#16a34a;font-weight:700">✓ 成功</span>' : '<span style="color:#dc2626;font-weight:700">✗ 失败</span>'; ?></td></tr>
      <?php if ($dbError !== ''): ?><tr><td>错误信息</td><td><code><?php echo hd($dbError); ?></code></td></tr><?php endif; ?>
      <tr><td>{prefix}install 标记表</td><td><?php echo yesno($markerInstall); ?></td></tr>
      <tr><td>{prefix}web 表</td><td><?php echo yesno($markerWeb); ?></td></tr>
      <tr><td>库中表总数</td><td><?php echo count($tables); ?> <?php echo $tables ? '（前 15 张：<code>' . hd(implode(', ', array_slice($tables, 0, 15))) . '</code>）' : ''; ?></td></tr>
    <?php endif; ?>
  </table>
</div>

<div class="box">
  <h2>3. 代码加载状态</h2>
  <table>
    <tr><td>app/behavior/InstallCheck.php</td><td><?php echo yesno(is_file($behaviorFile)); ?><br><code><?php echo hd($behaviorFile); ?></code></td></tr>
    <tr><td>tags.php 是否注册 InstallCheck</td><td><?php echo yesno($behaviorRegistered); ?></td></tr>
    <tr><td>app/behavior/InstallDispatch.php</td><td><?php echo yesno(is_file(PATH . 'app/behavior/InstallDispatch.php')); ?></td></tr>
    <tr><td>tags.php 是否注册 InstallDispatch</td><td><?php echo yesno(strpos($tagsContent, 'InstallDispatch') !== false); ?></td></tr>
    <tr><td>app/install_check.php</td><td><?php echo yesno(is_file(PATH . 'app/install_check.php')); ?></td></tr>
    <tr><td>route.php 中 install 路由条数</td><td><?php echo (int) $installRouteCount; ?></td></tr>
    <tr><td>runtime 目录可写</td><td><?php echo yesno($runtimeOk); ?></td></tr>
    <tr><td>app_debug</td><td>
      <?php
      $cf = PATH . 'app/config.php';
      if (is_file($cf)) {
          $c = @include $cf;
          echo is_array($c) && isset($c['app_debug']) ? ($c['app_debug'] ? 'true（会显示详细错误页）' : 'false') : '未设置';
      } else {
          echo '配置文件不存在';
      }
      ?>
    </td></tr>
  </table>
</div>

<div class="box">
  <h2>4. 最近的安装跳转记录（runtime/log/install_redirect.log）</h2>
  <pre><?php echo hd($logTail); ?></pre>
  <div class="sub">出现 <code>self_redirect_blocked</code> 表示 /install 跳向了自己；出现 <code>loop_break</code> 表示跳转次数过多已熔断。</div>
</div>

<div class="box">
  <div class="warn"><b>安全提示：</b>本脚本会暴露服务器路径与数据库配置信息，排查完成后请立即删除 <code>public/install_debug.php</code>。</div>
</div>

</body>
</html>
