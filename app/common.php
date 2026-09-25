<?php
include_once __DIR__ . '/common_security.php';
include_once __DIR__ . '/common_bt.php';
include_once __DIR__ . '/common_record.php';
include_once __DIR__ . '/common_music.php';

// 发送安全HTTP头（必须在任何输出之前调用）
send_security_headers();

// ===== 后台入口自定义（隐藏默认 /admin/login，支持自定义路径访问） =====
// 实现已迁移到 public/index.php 顶部（框架启动前执行，确保重写/拦截一定生效）。
// 那里会在命中自定义入口时设置 $_SERVER['MNBT_ADMIN_ENTRANCE_OK'] = 1。
// 这里保留两个辅助函数：
//   admin_entry_path()  —— 读取当前后台入口路径（默认 admin）
//   admin_login_url()   —— 生成后台登录地址（兼容自定义入口与子目录部署）

/**
 * 读取当前后台入口路径（默认 admin）
 * @return string 形如 admin / manage
 */
function admin_entry_path() {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $cached = 'admin';
    try {
        $w = function_exists('web_config') ? web_config() : null;
        if (is_array($w) && !empty($w['admin_path'])) {
            $p = trim((string)$w['admin_path'], '/');
            if ($p !== '') { $cached = $p; }
        }
    } catch (\Throwable $e) {}
    return $cached;
}

/**
 * 生成后台登录地址（自动适配自定义入口）
 * @param string $query 附加查询串，如 '?out=1'
 * @return string
 */
function admin_login_url($query = '') {
    $root = '';
    try { $root = (string)request()->root(); } catch (\Throwable $e) {}
    // 已配置自定义入口时，一律使用自定义入口地址（默认 /admin/login 已被隐藏为 404）
    $path = admin_entry_path();
    return rtrim($root, '/') . '/' . trim($path, '/') . '/login' . $query;
}

/**
 * 生成后台首页地址（自动适配自定义入口）
 * @return string
 */
function admin_index_url() {
    $root = '';
    try { $root = (string)request()->root(); } catch (\Throwable $e) {}
    $path = admin_entry_path();
    return rtrim($root, '/') . '/' . trim($path, '/') . '/index';
}

// 记录访客日志（使用 shutdown 函数延迟到框架初始化完成后执行，确保 \think\Db / session 可用）
$visitorUri = $_SERVER['REQUEST_URI'] ?? '';
$visitorUa = $_SERVER['HTTP_USER_AGENT'] ?? '';
$visitorMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$visitorReferer = $_SERVER['HTTP_REFERER'] ?? '';
register_shutdown_function(function() use ($visitorUri, $visitorUa, $visitorMethod, $visitorReferer) {
    if (!function_exists('record_visitor')) return;
    try {
        // 真实 IP（兼容 CDN / 反向代理，避免全部记录成 CDN 节点 IP）
        $visitorIp = function_exists('get_client_ip') ? get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        // Session 此时已由框架初始化完成，再读取用户 ID（session 前缀为 think，需用 session() 读取）
        $visitorUserId = 0;
        if (function_exists('session')) {
            $uid = session('userid');
            if ($uid !== null && $uid !== '') $visitorUserId = intval($uid);
        }
        if ($visitorUserId <= 0 && isset($_SESSION['think']['userid'])) {
            $visitorUserId = intval($_SESSION['think']['userid']);
        }
        record_visitor($visitorIp, $visitorUri, $visitorUa, $visitorUserId, $visitorMethod, $visitorReferer);
    } catch (\Throwable $e) {}
});

// 扫描器检测：拦截常见探测路径（/phpmyadmin、/.env、/.git/ 等）
check_scanner_activity();

// 已封禁IP检查：封禁列表中的IP直接403
// 注意：此时框架 helper.php 尚未加载，不可使用 request() 辅助函数
$clientIp = $_SERVER['REMOTE_ADDR'] ?? ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '127.0.0.1');
if (is_ip_banned($clientIp)) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    die('<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><title>403 Forbidden</title><style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;background:#fef2f2;color:#dc2626;margin:0}.box{text-align:center;padding:40px}.code{font-size:100px;font-weight:900;color:#fecaca}.msg{font-size:16px;margin-top:12px}</style></head><body><div class="box"><div class="code">403</div><div class="msg">您已被限制访问此站点</div></div></body></html>');
}

// 全局输入过滤：拦截路径遍历等攻击载荷
global_input_filter();

function random($length = 8,$chars = null){
  if(empty($chars)){
    $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
  }
  $count = strlen($chars) - 1;
  $code = '';
  while( strlen($code) < $length){
    $code .= substr($chars,rand(0,$count),1);
  }
  return $code;
}

function userrandom(){
$rand="a".rand(100000,999999);
return $rand;
}

// 手机端模板使用的规格格式化辅助函数（与 Index.php 私有方法保持逻辑一致）
if (!function_exists('formatSizeM')) {
    function formatSizeM($value) {
        $value = trim((string)$value);
        if ($value === '' || !is_numeric($value)) return '-';
        $num = floatval($value);
        if ($num >= 1024) {
            $gb = round($num / 1024, 1);
            return strpos((string)$gb, '.0') !== false ? intval($gb) . 'GB' : $gb . 'GB';
        }
        return intval($num) . 'MB';
    }
}
if (!function_exists('formatTraffic')) {
    function formatTraffic($value) {
        $value = trim((string)$value);
        if ($value === '' || !is_numeric($value)) return '不限';
        $num = floatval($value);
        if ($num >= 1024) {
            $tb = round($num / 1024, 1);
            return strpos((string)$tb, '.0') !== false ? intval($tb) . 'TB' : $tb . 'TB';
        }
        return intval($num) . 'GB';
    }
}
if (!function_exists('formatCount')) {
    function formatCount($value) {
        $value = trim((string)$value);
        if ($value === '' || !is_numeric($value)) return '不限';
        return intval($value) . ' 个';
    }
}

// 邮箱格式验证（支持所有合法TLD，包括 .com .cn .net .org 等）
function is_valid_email($email) {
    if (empty($email)) return false;
    // 先使用PHP内置过滤器，兼容性好
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) return true;
    // 备用正则：兼容PHP旧版本对部分TLD验证失败的问题
    return preg_match('/^[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$/', $email) === 1;
}

// 国内邮箱白名单验证 — 仅允许国内主流邮箱服务商（杜绝临时邮箱/海外邮箱）
function is_allowed_email($email) {
    if (empty($email)) return false;
    $domain = strtolower(substr(strrchr($email, '@'), 1));
    if (!$domain) return false;
    $allowedDomains = [
        // 腾讯系
        'qq.com',
        // 网易系
        '163.com', '126.com', 'yeah.net',
        // 新浪系
        'sina.com', 'sina.cn',
        // 搜狐系
        'sohu.com',
        // 阿里系
        'aliyun.com',
        // 腾讯企业
        'foxmail.com',
        // 运营商
        '139.com', '189.cn', 'wo.cn',
    ];
    return in_array($domain, $allowedDomains);
}

//获取目录下的子目录
function my_dir($dir) {
    $files = array();
    if(@$handle = opendir($dir)) { //注意这里要加一个@，不然会有warning错误提示：）
        while(($file = readdir($handle)) !== false) {
            if($file != ".." && $file != ".") { //排除根目录；
               $files[] = $file; 
            }
        }
        closedir($handle);
        return $files;
    }
}

function generateRand($m, $n)
{
    if ($m > $n) {
        $numMax = $m;
        $numMin = $n;
    } else {
        $numMax = $n;
        $numMin = $m;
    }
    /**
     * 生成$numMin和$numMax之间的随机浮点数，保留2位小数
     */
    $rand = $numMin + mt_rand() / mt_getrandmax() * ($numMax - $numMin);
    return floatval(number_format($rand,2));
}

//判断是否是HTTPS
function isHTTPS()
{
    if (defined('HTTPS') && HTTPS) return true;
    if (!isset($_SERVER)) return FALSE;
    if (!isset($_SERVER['HTTPS'])) return FALSE;
    if ($_SERVER['HTTPS'] === 1) {  //Apache
        return TRUE;
    } elseif ($_SERVER['HTTPS'] === 'on') { //IIS
        return TRUE;
    } elseif ($_SERVER['SERVER_PORT'] == 443) { //其他
        return TRUE;
    }
    return FALSE;
}


function judge($a,$b){
    if(in_array($b,$a)){
     return "1";
    }else{
return "2";
}
}

function getLen($num)
{
         $arr = explode('.',$num);
     $str=array_pop($arr);
if($str==$num){
$len="0";
}else{
     $len=strlen($str);
}
         return $len;
}

/**
 * 获取网站配置 (单次请求内静态缓存, 避免控制器 _initialize 与 email 函数重复查询)
 * @param string|null $key 配置字段名, 不传则返回全部配置数组
 * @return mixed|null
 */
function web_config($key = null){
    static $web = null;
    static $migrated = false;
    if($web === null){
        $web = \think\Db::name('web')->where('id', 1)->find();
    }
    // 自动迁移新字段（仅单次请求执行一次）
    if(!$migrated){
        $migrated = true;
        try {
            $webTable = \think\Db::name('web')->getTable();
            $orderTable = \think\Db::name('order')->getTable();
            $cols = \think\Db::query("SHOW COLUMNS FROM `{$webTable}` LIKE 'host_auto_create'");
            if(empty($cols)){
                \think\Db::execute("ALTER TABLE `{$webTable}` ADD COLUMN `host_auto_create` varchar(10) DEFAULT '0' COMMENT '0=manual 1=auto'");
            }
            $cols = \think\Db::query("SHOW COLUMNS FROM `{$webTable}` LIKE 'host_auto_create_delay'");
            if(empty($cols)){
                \think\Db::execute("ALTER TABLE `{$webTable}` ADD COLUMN `host_auto_create_delay` varchar(50) DEFAULT '0' COMMENT 'minutes'");
            }
            $cols = \think\Db::query("SHOW COLUMNS FROM `{$orderTable}` LIKE 'auto_create_at'");
            if(empty($cols)){
                \think\Db::execute("ALTER TABLE `{$orderTable}` ADD COLUMN `auto_create_at` varchar(50) DEFAULT '0' COMMENT '自动开通时间戳'");
            }
            // ── 主机退款（用户自助退款，需邮箱验证）──
            $refundWebCols = [
                'refund_enabled'     => "varchar(10) DEFAULT '1' COMMENT '是否允许用户自助退款 1=允许 0=关闭'",
                'refund_fee_percent' => "decimal(5,2) DEFAULT '0.00' COMMENT '退款手续费百分比(0-100)'",
                'refund_min_amount'  => "decimal(10,2) DEFAULT '0.00' COMMENT '最低退款金额，低于此值不退'",
            ];
            foreach ($refundWebCols as $rcName => $rcDdl) {
                $rc = \think\Db::query("SHOW COLUMNS FROM `{$webTable}` LIKE '{$rcName}'");
                if (empty($rc)) {
                    \think\Db::execute("ALTER TABLE `{$webTable}` ADD COLUMN `{$rcName}` {$rcDdl}");
                }
            }
            $refundOrderCols = [
                'paid_amount'     => "decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '该订单累计已支付金额(用于退款计算)'",
                'refund_amount'   => "decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '已退款金额'",
                'refund_at'       => "int(11) NOT NULL DEFAULT '0' COMMENT '退款时间戳'",
            ];
            foreach ($refundOrderCols as $roName => $roDdl) {
                $ro = \think\Db::query("SHOW COLUMNS FROM `{$orderTable}` LIKE '{$roName}'");
                if (empty($ro)) {
                    \think\Db::execute("ALTER TABLE `{$orderTable}` ADD COLUMN `{$roName}` {$roDdl}");
                }
            }
            // ── Docker 容器开通（产品控制台自助开通，需支付）──
            $dockerWebCols = [
                'docker_enabled'      => "varchar(10) DEFAULT '0' COMMENT 'Docker 开通总开关 1=开启 0=关闭'",
                'docker_price'        => "decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT 'Docker 容器开通价(全局统一价，元)'",
                'docker_mode'         => "varchar(20) DEFAULT 'manual' COMMENT '开通方式 auto=自动开通 manual=人工开通'",
                'docker_pay_balance'  => "varchar(10) DEFAULT '1' COMMENT '允许余额支付 1=允许 0=关闭'",
                'docker_pay_online'   => "varchar(10) DEFAULT '0' COMMENT '允许在线支付 1=允许 0=关闭'",
                'docker_intro'        => "text COMMENT 'Docker 开通说明(前台弹窗展示)'",
                'docker_allow_close'  => "varchar(10) DEFAULT '1' COMMENT '允许用户自助关闭 Docker 1=允许 0=关闭'",
            ];
            foreach ($dockerWebCols as $dwName => $dwDdl) {
                $dw = \think\Db::query("SHOW COLUMNS FROM `{$webTable}` LIKE '{$dwName}'");
                if (empty($dw)) {
                    \think\Db::execute("ALTER TABLE `{$webTable}` ADD COLUMN `{$dwName}` {$dwDdl}");
                }
            }
            // cart：每个产品是否开放 Docker 开通（价格取全局，故只放开关）
            try {
                $cartTableD = \think\Db::name('cart')->getTable();
                $cd = \think\Db::query("SHOW COLUMNS FROM `{$cartTableD}` LIKE 'docker_enabled'");
                if (empty($cd)) {
                    \think\Db::execute("ALTER TABLE `{$cartTableD}` ADD COLUMN `docker_enabled` varchar(10) NOT NULL DEFAULT '0' COMMENT '该产品是否开放 Docker 开通 1=开放 0=不开放'");
                }
            } catch (\Throwable $e) {
            }
            ensure_docker_order_table();
            $cols = \think\Db::query("SHOW COLUMNS FROM `{$webTable}` LIKE 'realname_mode'");
            if(empty($cols)){
                \think\Db::execute("ALTER TABLE `{$webTable}` ADD COLUMN `realname_mode` varchar(10) DEFAULT '0' COMMENT '实名模式:0关闭 1API 2人工 3阿里云'");
            }
            $cols = \think\Db::query("SHOW COLUMNS FROM `{$webTable}` LIKE 'realname_api_uname'");
            if(empty($cols)){
                \think\Db::execute("ALTER TABLE `{$webTable}` ADD COLUMN `realname_api_uname` varchar(100) DEFAULT '' COMMENT '实名API账号'");
            }
            $cols = \think\Db::query("SHOW COLUMNS FROM `{$webTable}` LIKE 'realname_api_password'");
            if(empty($cols)){
                \think\Db::execute("ALTER TABLE `{$webTable}` ADD COLUMN `realname_api_password` varchar(100) DEFAULT '' COMMENT '实名API密码'");
            }
            $cols = \think\Db::query("SHOW COLUMNS FROM `{$webTable}` LIKE 'realname_limit_pay'");
            if(empty($cols)){
                \think\Db::execute("ALTER TABLE `{$webTable}` ADD COLUMN `realname_limit_pay` varchar(10) DEFAULT '0' COMMENT '实名限制充值'");
            }
            $cols = \think\Db::query("SHOW COLUMNS FROM `{$webTable}` LIKE 'realname_limit_buy'");
            if(empty($cols)){
                \think\Db::execute("ALTER TABLE `{$webTable}` ADD COLUMN `realname_limit_buy` varchar(10) DEFAULT '0' COMMENT '实名限制购买主机'");
            }
            $cols = \think\Db::query("SHOW COLUMNS FROM `{$webTable}` LIKE 'realname_limit_ticket'");
            if(empty($cols)){
                \think\Db::execute("ALTER TABLE `{$webTable}` ADD COLUMN `realname_limit_ticket` varchar(10) DEFAULT '0' COMMENT '实名限制提交工单'");
            }
            $cols = \think\Db::query("SHOW COLUMNS FROM `{$webTable}` LIKE 'realname_limit_renew'");
            if(empty($cols)){
                \think\Db::execute("ALTER TABLE `{$webTable}` ADD COLUMN `realname_limit_renew` varchar(10) DEFAULT '0' COMMENT '实名限制续费主机'");
            }
            $cols = \think\Db::query("SHOW COLUMNS FROM `{$webTable}` LIKE 'realname_limit_transfer'");
            if(empty($cols)){
                \think\Db::execute("ALTER TABLE `{$webTable}` ADD COLUMN `realname_limit_transfer` varchar(10) DEFAULT '1' COMMENT '实名限制转让主机'");
            }
            $cols = \think\Db::query("SHOW COLUMNS FROM `{$webTable}` LIKE 'realname_api_appid'");
            if(empty($cols)){
                \think\Db::execute("ALTER TABLE `{$webTable}` ADD COLUMN `realname_api_appid` varchar(100) DEFAULT '' COMMENT '花迹数据API AppID'");
            }
            $cols = \think\Db::query("SHOW COLUMNS FROM `{$webTable}` LIKE 'realname_api_appkey'");
            if(empty($cols)){
                \think\Db::execute("ALTER TABLE `{$webTable}` ADD COLUMN `realname_api_appkey` varchar(100) DEFAULT '' COMMENT '花迹数据API AppKey'");
            }
            $cols = \think\Db::query("SHOW COLUMNS FROM `{$webTable}` LIKE 'realname_first_free'");
            if(empty($cols)){
                \think\Db::execute("ALTER TABLE `{$webTable}` ADD COLUMN `realname_first_free` varchar(10) DEFAULT '1' COMMENT '首次实名认证免费:0关闭 1开启'");
            }
            $cols = \think\Db::query("SHOW COLUMNS FROM `{$webTable}` LIKE 'realname_charge_amount'");
            if(empty($cols)){
                \think\Db::execute("ALTER TABLE `{$webTable}` ADD COLUMN `realname_charge_amount` varchar(20) DEFAULT '0' COMMENT '实名认证每次收费金额(元)'");
            }
            $cols = \think\Db::query("SHOW COLUMNS FROM `{$webTable}` LIKE 'realname_api_type'");
            if(empty($cols)){
                \think\Db::execute("ALTER TABLE `{$webTable}` ADD COLUMN `realname_api_type` varchar(10) DEFAULT '1' COMMENT '实名API类型:1花迹数据 2云市场'");
            }
            $cols = \think\Db::query("SHOW COLUMNS FROM `{$webTable}` LIKE 'realname_secret_id'");
            if(empty($cols)){
                \think\Db::execute("ALTER TABLE `{$webTable}` ADD COLUMN `realname_secret_id` varchar(100) DEFAULT '' COMMENT '云市场API SecretId'");
            }
            $cols = \think\Db::query("SHOW COLUMNS FROM `{$webTable}` LIKE 'realname_secret_key'");
            if(empty($cols)){
                \think\Db::execute("ALTER TABLE `{$webTable}` ADD COLUMN `realname_secret_key` varchar(100) DEFAULT '' COMMENT '云市场API SecretKey'");
            }
            $cols = \think\Db::query("SHOW COLUMNS FROM `{$webTable}` LIKE 'realname_api_url'");
            if(empty($cols)){
                \think\Db::execute("ALTER TABLE `{$webTable}` ADD COLUMN `realname_api_url` varchar(255) DEFAULT '' COMMENT '云市场实名API接口地址(留空用默认)'");
            }
            $cols = \think\Db::query("SHOW COLUMNS FROM `{$webTable}` LIKE 'disposable_email_block'");
            if(empty($cols)){
                \think\Db::execute("ALTER TABLE `{$webTable}` ADD COLUMN `disposable_email_block` tinyint(1) NOT NULL DEFAULT '0' COMMENT '防临时邮箱注册'");
            }
            // 液态玻璃总开关
            $cols = \think\Db::query("SHOW COLUMNS FROM `{$webTable}` LIKE 'glass_enabled'");
            if(empty($cols)){
                \think\Db::execute("ALTER TABLE `{$webTable}` ADD COLUMN `glass_enabled` tinyint(1) NOT NULL DEFAULT '1' COMMENT '是否启用液态玻璃主题:0关闭 1开启'");
            }
            // 强制QQ群加群相关字段
            $cols = \think\Db::query("SHOW COLUMNS FROM `{$webTable}` LIKE 'force_qq_group'");
            if(empty($cols)){
                \think\Db::execute("ALTER TABLE `{$webTable}` ADD COLUMN `force_qq_group` tinyint(1) NOT NULL DEFAULT '0' COMMENT '强制加入QQ群:0关闭 1开启'");
            }
            $cols = \think\Db::query("SHOW COLUMNS FROM `{$webTable}` LIKE 'force_qq_group_key'");
            if(empty($cols)){
                \think\Db::execute("ALTER TABLE `{$webTable}` ADD COLUMN `force_qq_group_key` varchar(100) NOT NULL DEFAULT '' COMMENT '强制加群卡密'");
            }
            $cols = \think\Db::query("SHOW COLUMNS FROM `{$webTable}` LIKE 'force_qq_group_reason'");
            if(empty($cols)){
                \think\Db::execute("ALTER TABLE `{$webTable}` ADD COLUMN `force_qq_group_reason` text COMMENT '强制加群原因说明'");
            }
            $cols = \think\Db::query("SHOW COLUMNS FROM `{$webTable}` LIKE 'force_qq_group_number'");
            if(empty($cols)){
                \think\Db::execute("ALTER TABLE `{$webTable}` ADD COLUMN `force_qq_group_number` varchar(50) NOT NULL DEFAULT '' COMMENT 'QQ群号码'");
            }
            $cols = \think\Db::query("SHOW COLUMNS FROM `{$webTable}` LIKE 'force_qq_group_link'");
            if(empty($cols)){
                \think\Db::execute("ALTER TABLE `{$webTable}` ADD COLUMN `force_qq_group_link` varchar(500) NOT NULL DEFAULT '' COMMENT 'QQ群加群链接'");
            }
            // 确保背景图相关字段存在
            ensure_web_bg_column();
            // 确保主机转让表存在
            ensure_host_transfer_table();
            // 集中确保业务自愈表全部存在（会员等级/积分/公告/卡密等，内部有24h标记文件，非首次请求近零开销）
            ensure_all_business_tables();
            // 聚合登录配置字段
            $cols = \think\Db::query("SHOW COLUMNS FROM `{$webTable}` LIKE 'oauth_enabled'");
            if(empty($cols)){
                \think\Db::execute("ALTER TABLE `{$webTable}` ADD COLUMN `oauth_enabled` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否启用聚合登录'");
            }
            $cols = \think\Db::query("SHOW COLUMNS FROM `{$webTable}` LIKE 'oauth_appid'");
            if(empty($cols)){
                \think\Db::execute("ALTER TABLE `{$webTable}` ADD COLUMN `oauth_appid` varchar(100) DEFAULT '' COMMENT 'API应用ID'");
            }
            $cols = \think\Db::query("SHOW COLUMNS FROM `{$webTable}` LIKE 'oauth_appkey'");
            if(empty($cols)){
                \think\Db::execute("ALTER TABLE `{$webTable}` ADD COLUMN `oauth_appkey` varchar(100) DEFAULT '' COMMENT 'API应用密钥'");
            }
            $cols = \think\Db::query("SHOW COLUMNS FROM `{$webTable}` LIKE 'oauth_callback'");
            if(empty($cols)){
                \think\Db::execute("ALTER TABLE `{$webTable}` ADD COLUMN `oauth_callback` varchar(255) DEFAULT '' COMMENT '回调URL'");
            }
            // Live2D 看板娘配置字段
            $cols = \think\Db::query("SHOW COLUMNS FROM `{$webTable}` LIKE 'live2d_model'");
            if(empty($cols)){
                \think\Db::execute("ALTER TABLE `{$webTable}` ADD COLUMN `live2d_model` varchar(50) DEFAULT 'shufulei' COMMENT 'Live2D模型选择'");
            }
            $cols = \think\Db::query("SHOW COLUMNS FROM `{$webTable}` LIKE 'live2d_scale'");
            if(empty($cols)){
                \think\Db::execute("ALTER TABLE `{$webTable}` ADD COLUMN `live2d_scale` varchar(10) DEFAULT '0.015' COMMENT 'Live2D缩放比例'");
            }
            // 仅当模型值为空或不在已知模型列表时才回退到本地舒芙蕾（保留用户已选的 senko 等远程模型）
            try {
                $curLive2dModel = \think\Db::name('web')->where('id', 1)->value('live2d_model');
                $knownModels = ['shufulei', 'youxiaomiao', 'shizuku', 'shizuku_pajama', 'pio', 'senko', 'hk416', 'cat_black'];
                if (empty($curLive2dModel) || !in_array($curLive2dModel, $knownModels)) {
                    \think\Db::name('web')->where('id', 1)->update(['live2d_model' => 'shufulei', 'live2d_scale' => '0.015']);
                }
            } catch (\Exception $e) {
                // 忽略数据迁移异常
            }
            $cols = \think\Db::query("SHOW COLUMNS FROM `{$webTable}` LIKE 'live2d_pos_x'");
            if(empty($cols)){
                \think\Db::execute("ALTER TABLE `{$webTable}` ADD COLUMN `live2d_pos_x` varchar(10) DEFAULT '70' COMMENT 'Live2D水平位置'");
            }
            $cols = \think\Db::query("SHOW COLUMNS FROM `{$webTable}` LIKE 'live2d_pos_y'");
            if(empty($cols)){
                \think\Db::execute("ALTER TABLE `{$webTable}` ADD COLUMN `live2d_pos_y` varchar(10) DEFAULT '70' COMMENT 'Live2D垂直位置'");
            }
            $cols = \think\Db::query("SHOW COLUMNS FROM `{$webTable}` LIKE 'live2d_primary_color'");
            if(empty($cols)){
                \think\Db::execute("ALTER TABLE `{$webTable}` ADD COLUMN `live2d_primary_color` varchar(20) DEFAULT '#38B0DE' COMMENT 'Live2D主题色'");
            }
            // Live2D AI 聊天相关字段
            $cols = \think\Db::query("SHOW COLUMNS FROM `{$webTable}` LIKE 'live2d_ai_enabled'");
            if(empty($cols)){
                \think\Db::execute("ALTER TABLE `{$webTable}` ADD COLUMN `live2d_ai_enabled` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'AI聊天开关'");
            }
            $cols = \think\Db::query("SHOW COLUMNS FROM `{$webTable}` LIKE 'live2d_ai_api_url'");
            if(empty($cols)){
                \think\Db::execute("ALTER TABLE `{$webTable}` ADD COLUMN `live2d_ai_api_url` varchar(500) NOT NULL DEFAULT '' COMMENT 'AI API地址'");
            }
            $cols = \think\Db::query("SHOW COLUMNS FROM `{$webTable}` LIKE 'live2d_ai_api_key'");
            if(empty($cols)){
                \think\Db::execute("ALTER TABLE `{$webTable}` ADD COLUMN `live2d_ai_api_key` varchar(500) NOT NULL DEFAULT '' COMMENT 'AI API密钥'");
            }
            $cols = \think\Db::query("SHOW COLUMNS FROM `{$webTable}` LIKE 'live2d_ai_model'");
            if(empty($cols)){
                \think\Db::execute("ALTER TABLE `{$webTable}` ADD COLUMN `live2d_ai_model` varchar(100) NOT NULL DEFAULT 'deepseek-v4-flash' COMMENT 'AI模型'");
            }
            $cols = \think\Db::query("SHOW COLUMNS FROM `{$webTable}` LIKE 'live2d_ai_persona'");
            if(empty($cols)){
                \think\Db::execute("ALTER TABLE `{$webTable}` ADD COLUMN `live2d_ai_persona` TEXT NULL COMMENT 'AI人设（自定义）'");
            }

            // 开发者 API 接口控制字段
            $cols = \think\Db::query("SHOW COLUMNS FROM `{$webTable}` LIKE 'api_enabled'");
            if(empty($cols)){
                \think\Db::execute("ALTER TABLE `{$webTable}` ADD COLUMN `api_enabled` tinyint(1) NOT NULL DEFAULT '1' COMMENT '开发者API总开关:0关闭 1开启'");
            }
            $cols = \think\Db::query("SHOW COLUMNS FROM `{$webTable}` LIKE 'api_rate'");
            if(empty($cols)){
                \think\Db::execute("ALTER TABLE `{$webTable}` ADD COLUMN `api_rate` varchar(20) NOT NULL DEFAULT '0' COMMENT 'API每分钟最大调用次数(次/分钟/IP与密钥独立,0不限)'");
            }
            $cols = \think\Db::query("SHOW COLUMNS FROM `{$webTable}` LIKE 'api_rate_ban'");
            if(empty($cols)){
                \think\Db::execute("ALTER TABLE `{$webTable}` ADD COLUMN `api_rate_ban` varchar(20) NOT NULL DEFAULT '3600' COMMENT 'API超限自动封禁时长(秒,0永久)'");
            }
            $cols = \think\Db::query("SHOW COLUMNS FROM `{$webTable}` LIKE 'api_function_status'");
            if(empty($cols)){
                \think\Db::execute("ALTER TABLE `{$webTable}` ADD COLUMN `api_function_status` TEXT NULL COMMENT 'API功能开关JSON(action=>0关闭)'");
            }
            $cols = \think\Db::query("SHOW COLUMNS FROM `{$webTable}` LIKE 'realname_encrypt'");
            if(empty($cols)){
                \think\Db::execute("ALTER TABLE `{$webTable}` ADD COLUMN `realname_encrypt` tinyint(1) NOT NULL DEFAULT '1' COMMENT '实名信息前端加密传输:0关闭 1开启'");
            }
            $cols = \think\Db::query("SHOW COLUMNS FROM `{$webTable}` LIKE 'admin_path'");
            if(empty($cols)){
                \think\Db::execute("ALTER TABLE `{$webTable}` ADD COLUMN `admin_path` varchar(50) NOT NULL DEFAULT 'admin' COMMENT '后台入口路径(默认admin，可自定义)'");
            }

            // 三种实名认证方式开关
            $cols = \think\Db::query("SHOW COLUMNS FROM `{$webTable}` LIKE 'realname_method_idcard'");
            if(empty($cols)){
                \think\Db::execute("ALTER TABLE `{$webTable}` ADD COLUMN `realname_method_idcard` varchar(10) NOT NULL DEFAULT '1' COMMENT '身份证上传认证开关'");
            }
            $cols = \think\Db::query("SHOW COLUMNS FROM `{$webTable}` LIKE 'realname_method_phone'");
            if(empty($cols)){
                \think\Db::execute("ALTER TABLE `{$webTable}` ADD COLUMN `realname_method_phone` varchar(10) NOT NULL DEFAULT '1' COMMENT '手机三要素认证开关'");
            }
            $cols = \think\Db::query("SHOW COLUMNS FROM `{$webTable}` LIKE 'realname_method_manual'");
            if(empty($cols)){
                \think\Db::execute("ALTER TABLE `{$webTable}` ADD COLUMN `realname_method_manual` varchar(10) NOT NULL DEFAULT '1' COMMENT '人工审核认证开关'");
            }
            // 身份证 OCR 配置
            $cols = \think\Db::query("SHOW COLUMNS FROM `{$webTable}` LIKE 'idcard_ocr_provider'");
            if(empty($cols)){
                \think\Db::execute("ALTER TABLE `{$webTable}` ADD COLUMN `idcard_ocr_provider` varchar(20) NOT NULL DEFAULT '0' COMMENT '身份证OCR服务:0关闭 aliyun阿里云 tencent腾讯云'");
            }
            $cols = \think\Db::query("SHOW COLUMNS FROM `{$webTable}` LIKE 'idcard_ocr_appcode'");
            if(empty($cols)){
                \think\Db::execute("ALTER TABLE `{$webTable}` ADD COLUMN `idcard_ocr_appcode` varchar(200) NOT NULL DEFAULT '' COMMENT '阿里云OCR AppCode'");
            }
            $cols = \think\Db::query("SHOW COLUMNS FROM `{$webTable}` LIKE 'idcard_ocr_secret_id'");
            if(empty($cols)){
                \think\Db::execute("ALTER TABLE `{$webTable}` ADD COLUMN `idcard_ocr_secret_id` varchar(200) NOT NULL DEFAULT '' COMMENT '腾讯云OCR SecretId'");
            }
            $cols = \think\Db::query("SHOW COLUMNS FROM `{$webTable}` LIKE 'idcard_ocr_secret_key'");
            if(empty($cols)){
                \think\Db::execute("ALTER TABLE `{$webTable}` ADD COLUMN `idcard_ocr_secret_key` varchar(200) NOT NULL DEFAULT '' COMMENT '腾讯云OCR SecretKey'");
            }
            $cols = \think\Db::query("SHOW COLUMNS FROM `{$webTable}` LIKE 'idcard_ocr_api_url'");
            if(empty($cols)){
                \think\Db::execute("ALTER TABLE `{$webTable}` ADD COLUMN `idcard_ocr_api_url` varchar(500) NOT NULL DEFAULT '' COMMENT 'OCR接口地址(可自定义覆盖)'");
            }
            // 背景音乐配置
            $cols = \think\Db::query("SHOW COLUMNS FROM `{$webTable}` LIKE 'bg_music_enabled'");
            if(empty($cols)){
                \think\Db::execute("ALTER TABLE `{$webTable}` ADD COLUMN `bg_music_enabled` tinyint(1) NOT NULL DEFAULT '0' COMMENT '背景音乐开关'");
            }
            $cols = \think\Db::query("SHOW COLUMNS FROM `{$webTable}` LIKE 'bg_music_url'");
            if(empty($cols)){
                \think\Db::execute("ALTER TABLE `{$webTable}` ADD COLUMN `bg_music_url` varchar(500) NOT NULL DEFAULT '' COMMENT '背景音乐地址'");
            }
            $cols = \think\Db::query("SHOW COLUMNS FROM `{$webTable}` LIKE 'bg_music_volume'");
            if(empty($cols)){
                \think\Db::execute("ALTER TABLE `{$webTable}` ADD COLUMN `bg_music_volume` int(11) NOT NULL DEFAULT '50' COMMENT '背景音乐音量0-100'");
            }
            // 高德地图定位 Key
            $cols = \think\Db::query("SHOW COLUMNS FROM `{$webTable}` LIKE 'amap_key'");
            if(empty($cols)){
                \think\Db::execute("ALTER TABLE `{$webTable}` ADD COLUMN `amap_key` varchar(200) NOT NULL DEFAULT '' COMMENT '高德地图Web服务Key'");
            }
            // 域名商城开关
            $cols = \think\Db::query("SHOW COLUMNS FROM `{$webTable}` LIKE 'domain_market_enabled'");
            if(empty($cols)){
                \think\Db::execute("ALTER TABLE `{$webTable}` ADD COLUMN `domain_market_enabled` tinyint(1) NOT NULL DEFAULT '0' COMMENT '域名商城开关'");
            }

            // 重新读取配置以包含新增字段
            $web = \think\Db::name('web')->where('id', 1)->find();
        } catch (\Exception $e) {
            // 迁移失败不影响后续流程
        }
    }
    if($key === null){
        return $web;
    }
    return isset($web[$key]) ? $web[$key] : null;
}

/**
 * Build enterprise HTML email template
 */
function build_email_html($title, $content, $webname = '') {
    $web = web_config();
    if (!$webname) {
        $webname = $web['name'] ?? 'MNBT-SALE';
    }
    $year = date('Y');

    // Build enterprise logo URL
    $logoHtml = '';
    if (!empty($web['logo'])) {
        $logo = $web['logo'];
        if (strpos($logo, 'http') !== 0) {
            $logo = ltrim($logo, '/');
            try {
                $domain = request()->domain();
                $logo = rtrim($domain, '/') . '/' . $logo;
            } catch (\Exception $e) {
                $logo = '/' . $logo;
            }
        }
        $logoHtml = '<img src="' . htmlspecialchars($logo) . '" alt="' . htmlspecialchars($webname) . '" style="max-height:42px;display:block;margin:0 auto 12px;">';
    }

    $html = <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{$title}</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f7fb;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,'PingFang SC','Microsoft YaHei',sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f7fb;padding:48px 16px;">
<tr>
<td align="center">
<table width="560" cellpadding="0" cellspacing="0" style="background-color:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 8px 32px rgba(30,58,95,0.08);border:1px solid #e8eef5;">
<!-- Header -->
<tr>
<td style="background:#ffffff;padding:28px 40px 12px;text-align:center;border-bottom:1px solid #eef2f7;">
{$logoHtml}
<h1 style="color:#1e293b;font-size:20px;font-weight:700;margin:0;letter-spacing:0.02em;">{$webname}</h1>
<p style="color:#64748b;font-size:12px;margin:6px 0 0;letter-spacing:0.04em;">SECURE CLOUD SERVICE</p>
</td>
</tr>
<!-- Title -->
<tr>
<td style="padding:36px 48px 8px;">
<h2 style="color:#0f172a;font-size:18px;font-weight:600;margin:0;">{$title}</h2>
</td>
</tr>
<!-- Content -->
<tr>
<td style="padding:12px 48px 36px;color:#475569;font-size:14px;line-height:1.85;">
{$content}
</td>
</tr>
<!-- Divider -->
<tr>
<td style="padding:0 48px;">
<div style="border-top:1px solid #e2e8f0;"></div>
</td>
</tr>
<!-- Footer -->
<tr>
<td style="padding:24px 48px 32px;text-align:center;">
<p style="color:#94a3b8;font-size:12px;margin:0 0 4px;line-height:1.6;">此邮件由系统自动发送，请勿直接回复</p>
<p style="color:#94a3b8;font-size:12px;margin:0;">&copy; {$year} {$webname} All Rights Reserved.</p>
</td>
</tr>
</table>
</td>
</tr>
</table>
</body>
</html>
HTML;
    return $html;
}

/**
 * 确保订单表包含订单号字段
 */
function ensure_order_ordernumber_column() {
    try {
        $prefix = \think\Db::getConfig('prefix');
        $prefix = $prefix ?: '';
        $table = "{$prefix}order";
        $columns = \think\Db::query("SHOW COLUMNS FROM `{$table}`");
        $columnNames = array_column($columns, 'Field');
        if (!in_array('ordernumber', $columnNames)) {
            \think\Db::execute("ALTER TABLE `{$table}` ADD COLUMN `ordernumber` varchar(32) NOT NULL DEFAULT '' COMMENT '订单号' AFTER `id`");
        }
        // 为已有订单补充订单号
        $emptyCount = \think\Db::name('order')->where('ordernumber', '')->count();
        if ($emptyCount > 0) {
            $orders = \think\Db::name('order')->where('ordernumber', '')->select();
            foreach ($orders as $order) {
                $ordernumber = 'YH' . date('Ymd', intval($order['atime'] ?: time())) . str_pad($order['id'], 6, '0', STR_PAD_LEFT);
                \think\Db::name('order')->where('id', $order['id'])->update(['ordernumber' => $ordernumber]);
            }
        }
    } catch (\Exception $e) {
        // 忽略
    }
}

/**
 * 生成订单号
 */
function generate_order_number($orderId) {
    return 'YH' . date('Ymd') . str_pad($orderId, 6, '0', STR_PAD_LEFT);
}

/**
 * 确保用户表包含最后登录相关字段（登录时间、IP、地区）
 * 供前台用户中心和后台总览共用
 */
function ensure_user_columns() {
    try {
        $prefix = \think\Db::getConfig('prefix');
        $prefix = $prefix ?: '';
        $table = "{$prefix}user";
        $columns = \think\Db::query("SHOW COLUMNS FROM `{$table}`");
        $columnNames = array_column($columns, 'Field');
        if (!in_array('last_login_time', $columnNames)) {
            \think\Db::execute("ALTER TABLE `{$table}` ADD COLUMN `last_login_time` int(11) NOT NULL DEFAULT 0 COMMENT '最后登录时间戳'");
        }
        if (!in_array('last_login_ip', $columnNames)) {
            \think\Db::execute("ALTER TABLE `{$table}` ADD COLUMN `last_login_ip` varchar(255) NOT NULL DEFAULT '' COMMENT '最后登录IP'");
        }
        if (!in_array('last_login_region', $columnNames)) {
            \think\Db::execute("ALTER TABLE `{$table}` ADD COLUMN `last_login_region` varchar(50) NOT NULL DEFAULT '' COMMENT '最后登录地区（省份）'");
        }
        if (!in_array('realname', $columnNames)) {
            \think\Db::execute("ALTER TABLE `{$table}` ADD COLUMN `realname` varchar(100) NOT NULL DEFAULT '' COMMENT '真实姓名'");
        }
        if (!in_array('idcard', $columnNames)) {
            \think\Db::execute("ALTER TABLE `{$table}` ADD COLUMN `idcard` varchar(20) NOT NULL DEFAULT '' COMMENT '身份证号'");
        }
        if (!in_array('realname_status', $columnNames)) {
            \think\Db::execute("ALTER TABLE `{$table}` ADD COLUMN `realname_status` tinyint(1) NOT NULL DEFAULT 0 COMMENT '实名状态:0未认证 1已认证 2已驳回 3待审核'");
        }
        if (!in_array('realname_attempts', $columnNames)) {
            \think\Db::execute("ALTER TABLE `{$table}` ADD COLUMN `realname_attempts` int(11) NOT NULL DEFAULT 0 COMMENT '实名认证已尝试次数'");
        }
        if (!in_array('points', $columnNames)) {
            \think\Db::execute("ALTER TABLE `{$table}` ADD COLUMN `points` int(11) NOT NULL DEFAULT 0 COMMENT '用户积分'");
        }
        if (!in_array('last_checkin_time', $columnNames)) {
            \think\Db::execute("ALTER TABLE `{$table}` ADD COLUMN `last_checkin_time` int(11) NOT NULL DEFAULT 0 COMMENT '最后签到时间戳'");
        }
        if (!in_array('total_recharge', $columnNames)) {
            \think\Db::execute("ALTER TABLE `{$table}` ADD COLUMN `total_recharge` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '累计充值消费金额'");
        }
        if (!in_array('membership_level', $columnNames)) {
            \think\Db::execute("ALTER TABLE `{$table}` ADD COLUMN `membership_level` int(11) NOT NULL DEFAULT 0 COMMENT '会员等级:0普通用户 1-6对应VIP等级'");
        }
        if (!in_array('oauth_qq', $columnNames)) {
            \think\Db::execute("ALTER TABLE `{$table}` ADD COLUMN `oauth_qq` varchar(100) NOT NULL DEFAULT '' COMMENT 'QQ聚合登录social_uid'");
        }
        if (!in_array('oauth_wechat', $columnNames)) {
            \think\Db::execute("ALTER TABLE `{$table}` ADD COLUMN `oauth_wechat` varchar(100) NOT NULL DEFAULT '' COMMENT '微信聚合登录social_uid'");
        }
        if (!in_array('force_qq_group_verified', $columnNames)) {
            \think\Db::execute("ALTER TABLE `{$table}` ADD COLUMN `force_qq_group_verified` tinyint(1) NOT NULL DEFAULT 0 COMMENT '强制QQ群卡密已验证:0未验证 1已验证'");
        }
        if (!in_array('theme_mode', $columnNames)) {
            \think\Db::execute("ALTER TABLE `{$table}` ADD COLUMN `theme_mode` varchar(20) NOT NULL DEFAULT '' COMMENT '用户自定义主题深浅:空跟随默认 light浅色 dark深色'");
        }
        if (!in_array('theme_glass', $columnNames)) {
            \think\Db::execute("ALTER TABLE `{$table}` ADD COLUMN `theme_glass` varchar(20) NOT NULL DEFAULT '' COMMENT '用户自定义液态玻璃:空跟随默认 1开启 0关闭'");
        }
        if (!in_array('theme_bg_type', $columnNames)) {
            \think\Db::execute("ALTER TABLE `{$table}` ADD COLUMN `theme_bg_type` varchar(20) NOT NULL DEFAULT '' COMMENT '用户自定义背景类型:空跟随默认 image图片 video视频 gif动图 none无背景'");
        }
        if (!in_array('theme_bg_image', $columnNames)) {
            \think\Db::execute("ALTER TABLE `{$table}` ADD COLUMN `theme_bg_image` varchar(500) NOT NULL DEFAULT '' COMMENT '用户自定义背景图/视频URL'");
        }
        if (!in_array('avatar', $columnNames)) {
            \think\Db::execute("ALTER TABLE `{$table}` ADD COLUMN `avatar` varchar(500) NOT NULL DEFAULT '' COMMENT '用户头像URL'");
        }
        if (!in_array('api_disabled', $columnNames)) {
            \think\Db::execute("ALTER TABLE `{$table}` ADD COLUMN `api_disabled` tinyint(1) NOT NULL DEFAULT 0 COMMENT '开发者API禁用:0正常 1禁用'");
        }
        if (!in_array('last_logout_time', $columnNames)) {
            \think\Db::execute("ALTER TABLE `{$table}` ADD COLUMN `last_logout_time` int(11) NOT NULL DEFAULT 0 COMMENT '最近退出时间戳'");
        }
        if (!in_array('music_enabled', $columnNames)) {
            \think\Db::execute("ALTER TABLE `{$table}` ADD COLUMN `music_enabled` tinyint(1) NOT NULL DEFAULT 1 COMMENT '背景音乐开关:1开启 0关闭'");
        }
        if (!in_array('custom_bg_image', $columnNames)) {
            \think\Db::execute("ALTER TABLE `{$table}` ADD COLUMN `custom_bg_image` varchar(500) NOT NULL DEFAULT '' COMMENT '用户自定义背景图URL(上传)'");
        }
    } catch (\Exception $e) {
        // 忽略字段已存在等异常
    }
}

/**
 * 校验密码强度：密码需长度≥8，且同时包含大写字母、小写字母和数字。
 * 账号不做任何额外限制（用户要求：只改密码规则）。
 * @param string $user 账号（保留形参以兼容既有调用，不参与校验）
 * @param string $password 密码（长度≥8 且同时含大写、小写、数字）
 * @return string 错误信息；空字符串表示通过
 */
function validate_user_credential($user, $password) {
    $password = (string) $password;
    if (mb_strlen($password) < 8) {
        return '密码长度至少 8 位！';
    }
    if (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/\d/', $password)) {
        return '密码必须同时包含大写字母、小写字母和数字！';
    }
    return '';
}

/**
 * 获取用户头像 URL：优先使用 QQ 聚合登录返回的头像，其次按 QQ 号拼 qlogo，最后返回内置占位头像。
 * @param array $user 用户记录（含 avatar / qq 字段）
 * @return string
 */
function get_user_avatar($user) {
    if (!is_array($user)) return '';
    if (!empty($user['avatar'])) {
        $av = trim($user['avatar']);
        // 绝对地址（http/https/data/协议相对）直接返回；相对路径拼根路径前缀，避免在 /admin 等子路径下 404
        if (preg_match('/^(https?:|data:|\/\/)/i', $av)) return $av;
        $root = '';
        try { $root = (string) request()->root(); } catch (\Exception $e) {}
        return rtrim($root, '/') . '/' . ltrim($av, '/');
    }
    $qq = isset($user['qq']) ? trim($user['qq']) : '';
    if ($qq !== '' && preg_match('/^\d{4,12}$/', $qq)) {
        return 'https://q1.qlogo.cn/g?b=qq&nk=' . $qq . '&s=100';
    }
    return 'data:image/svg+xml;utf8,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100"><rect width="100" height="100" fill="#e2e8f0"/><circle cx="50" cy="40" r="18" fill="#94a3b8"/><path d="M20 90c0-16 13-26 30-26s30 10 30 26" fill="#94a3b8"/></svg>');
}

/**
 * 获取会员等级名称
 * @param int $level 等级 0-6
 * @return string
 */
function get_membership_name($level) {
    $map = [
        0 => '普通用户',
        1 => '青铜VIP',
        2 => '白银VIP',
        3 => '黄金VIP',
        4 => '紫金VIP',
        5 => '钻石VIP',
        6 => '至尊VIP',
    ];
    $level = intval($level);
    return isset($map[$level]) ? $map[$level] : $map[0];
}

/**
 * 归一化 AI 大模型接口地址（兼容 OpenAI 协议的中转站）
 *
 * 支持后台只填 Base URL，例如：
 *   https://api.deepseek.com            -> https://api.deepseek.com/chat/completions
 *   https://api.openai.com              -> https://api.openai.com/v1/chat/completions
 *   https://your-relay.com/v1           -> https://your-relay.com/v1/chat/completions
 *   https://your-relay.com/v1/chat/completions -> 原样返回
 *
 * @param string $url 后台填写的接口地址或中转站 Base URL
 * @return string 可直接请求的 chat/completions 地址；输入为空时返回 ''
 */
function live2d_ai_endpoint($url) {
    $url = trim((string)$url);
    if ($url === '') {
        return '';
    }
    $url = rtrim($url, '/');
    // 已带 chat/completions（官方或中转站的完整地址）
    if (preg_match('#/chat/completions$#i', $url)) {
        return $url;
    }
    // 已带版本号：https://xxx/v1 -> https://xxx/v1/chat/completions
    if (preg_match('#/v\d+$#i', $url)) {
        return $url . '/chat/completions';
    }
    // 其它带路径的地址（如 https://xxx/api/openai），视为中转站前缀
    if (preg_match('#/v\d+/[a-z0-9]#i', $url)) {
        return $url;
    }
    // DeepSeek 官方两种写法都支持，优先使用更短的官方地址
    if (preg_match('#^https?://api\.deepseek\.com$#i', $url)) {
        return $url . '/chat/completions';
    }
    return $url . '/v1/chat/completions';
}

/**
 * 获取用户真实 QQ 号：优先使用 qq 字段，若 qq 字段是 OAuth openid 令牌，
 * 则从 avatar URL 的 nk= 参数中提取真实 QQ 号。
 * @param array $user 用户记录
 * @return string
 */
function get_user_qq($user) {
    if (!is_array($user)) return '';
    $qq = isset($user['qq']) ? trim($user['qq']) : '';
    if ($qq !== '' && preg_match('/^\d{4,12}$/', $qq)) {
        return $qq;
    }
    $avatar = isset($user['avatar']) ? trim($user['avatar']) : '';
    if ($avatar && preg_match('/[?&]nk=(\d{4,12})/', $avatar, $m)) {
        return $m[1];
    }
    // qq 字段可能是 OAuth openid 令牌，禁止回显，返回空由用户手动填写
    return '';
}

/**
 * 将当前登录用户的个性化主题配置合并到后台默认配置之上。
 * 用户未自定义（字段为空）时保持后台默认；自定义时覆盖对应项。
 * 返回合并后的 $web 配置数组。
 */
function apply_user_theme($web) {
    if (!is_array($web)) $web = [];
    $uid = 0;
    if (isset($_SESSION['userid'])) {
        $uid = intval($_SESSION['userid']);
    } elseif (function_exists('session')) {
        $v = session('userid');
        if ($v !== null && $v !== '') $uid = intval($v);
    }
    if ($uid <= 0) return $web;
    try {
        $u = \think\Db::name('user')->where('id', $uid)->find();
        if (!$u) return $web;
        // 深浅色：light / dark，空则跟随后台默认
        if (!empty($u['theme_mode']) && in_array($u['theme_mode'], ['light', 'dark'], true)) {
            $web['theme_mode'] = $u['theme_mode'];
        }
        // 液态玻璃：1 开启 / 0 关闭，空则跟随后台默认
        if (isset($u['theme_glass']) && $u['theme_glass'] !== '' && $u['theme_glass'] !== null) {
            $web['glass_enabled'] = ($u['theme_glass'] === '1' || $u['theme_glass'] === 1) ? '1' : '0';
        }
        // 背景类型：image/video/gif/none，空则跟随后台默认
        if (!empty($u['theme_bg_type']) && in_array($u['theme_bg_type'], ['image', 'video', 'gif', 'none'], true)) {
            $web['bg_type'] = $u['theme_bg_type'];
        }
        // 背景图/视频 URL：有则覆盖；若用户选择"无背景"则清空
        if (!empty($u['theme_bg_image'])) {
            $web['bg_image'] = $u['theme_bg_image'];
        } elseif (!empty($u['theme_bg_type']) && $u['theme_bg_type'] === 'none') {
            $web['bg_image'] = '';
        }
    } catch (\Exception $e) {
        // 忽略用户表字段缺失等异常
    }
    return $web;
}

/**
 * 确保积分商城产品表存在
 */
function ensure_points_products_table() {
    try {
        $prefix = \think\Db::getConfig('prefix');
        $prefix = $prefix ?: '';
        $table = "{$prefix}points_products";
        $tables = \think\Db::query("SHOW TABLES LIKE '{$table}'");
        if (empty($tables)) {
            \think\Db::execute("CREATE TABLE `{$table}` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `name` varchar(255) NOT NULL COMMENT '产品名称',
                `type` varchar(50) NOT NULL DEFAULT 'balance' COMMENT '类型:balance余额 host主机 renew续费',
                `points` int(11) NOT NULL DEFAULT 0 COMMENT '所需积分',
                `value` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '兑换价值(余额金额/主机cartid/续费天数)',
                `stock` int(11) NOT NULL DEFAULT -1 COMMENT '库存 -1无限',
                `description` text COMMENT '产品描述',
                `image` varchar(500) DEFAULT '' COMMENT '产品图片',
                `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态:0下架 1上架',
                `sort` int(11) NOT NULL DEFAULT 0 COMMENT '排序',
                `created_at` int(11) NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    } catch (\Exception $e) {}
}

/**
 * 集中确保业务自愈表全部存在（会员等级/积分/公告/卡密/购物车/转让/API等）
 * 带 24 小时标记文件：命中标记则跳过全量检查，避免每请求多次 SHOW TABLES；
 * 仅当核心表存在（系统已安装）时才写标记，防止安装阶段误写导致 24 小时不自愈。
 */
function ensure_all_business_tables($force = false) {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $marker = (defined('LOG_PATH') ? LOG_PATH : (PATH . 'runtime/log/')) . 'tables_ensured.lock';
        if (!$force && is_file($marker) && (time() - filemtime($marker)) < 86400) {
            return;
        }
        ensure_points_products_table();
        ensure_membership_levels_table();
        ensure_points_log_table();
        ensure_email_verify_table();
        ensure_realname_record_table();
        ensure_cdkey_table();
        ensure_cdkey_usage_log_table();
        ensure_announcements_table();
        ensure_shopping_cart_table();
        ensure_cart_table();
        ensure_host_transfer_table();
        ensure_host_transfer_message_table();
        ensure_api_keys_table();
        ensure_api_call_log_table();
        ensure_api_rate_table();
        ensure_qr_login_table();
        // 核心表存在（已安装成功）才写标记，避免安装期误写
        $prefix = \think\Db::getConfig('prefix');
        $prefix = $prefix ?: '';
        $core = \think\Db::query("SHOW TABLES LIKE '{$prefix}user'");
        if (!empty($core)) {
            @file_put_contents($marker, date('Y-m-d H:i:s'), LOCK_EX);
        }
    } catch (\Exception $e) {}
}

/**
 * 确保会员等级表存在
 */
function ensure_membership_levels_table() {
    try {
        $prefix = \think\Db::getConfig('prefix');
        $prefix = $prefix ?: '';
        $table = "{$prefix}membership_levels";
        $tables = \think\Db::query("SHOW TABLES LIKE '{$table}'");
        if (empty($tables)) {
            \think\Db::execute("CREATE TABLE `{$table}` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `level` int(11) NOT NULL DEFAULT 1 COMMENT '等级 1-6',
                `name` varchar(50) NOT NULL COMMENT '等级名称',
                `icon` varchar(500) DEFAULT '' COMMENT '等级图标',
                `min_recharge` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '升级所需累计充值',
                `discount` decimal(3,2) NOT NULL DEFAULT 1.00 COMMENT '折扣比例 0.95=95折',
                `renew_discount` decimal(3,2) NOT NULL DEFAULT 1.00 COMMENT '续费折扣比例',
                `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态:0禁用 1启用',
                `created_at` int(11) NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            // 插入默认会员等级
            $defaultLevels = [
                ['level' => 1, 'name' => '青铜VIP', 'icon' => '创享青铜vip1.svg', 'min_recharge' => 0, 'discount' => 0.98, 'renew_discount' => 0.98, 'status' => 1, 'created_at' => time()],
                ['level' => 2, 'name' => '白银VIP', 'icon' => '创享白银vip2.svg', 'min_recharge' => 100, 'discount' => 0.95, 'renew_discount' => 0.95, 'status' => 1, 'created_at' => time()],
                ['level' => 3, 'name' => '黄金VIP', 'icon' => '创享黄金vip3.svg', 'min_recharge' => 500, 'discount' => 0.90, 'renew_discount' => 0.90, 'status' => 1, 'created_at' => time()],
                ['level' => 4, 'name' => '紫金VIP', 'icon' => '创享紫金vip4.svg', 'min_recharge' => 1000, 'discount' => 0.85, 'renew_discount' => 0.85, 'status' => 1, 'created_at' => time()],
                ['level' => 5, 'name' => '钻石VIP', 'icon' => '创享钻石vip5.svg', 'min_recharge' => 2000, 'discount' => 0.80, 'renew_discount' => 0.80, 'status' => 1, 'created_at' => time()],
                ['level' => 6, 'name' => '至尊VIP', 'icon' => '创享至尊vip6.svg', 'min_recharge' => 5000, 'discount' => 0.75, 'renew_discount' => 0.75, 'status' => 1, 'created_at' => time()],
            ];
            foreach ($defaultLevels as $lvl) {
                \think\Db::name('membership_levels')->insert($lvl);
            }
        }
    } catch (\Exception $e) {}
}

/**
 * 确保积分兑换记录表存在
 */
function ensure_points_log_table() {
    try {
        $prefix = \think\Db::getConfig('prefix');
        $prefix = $prefix ?: '';
        $table = "{$prefix}points_log";
        $tables = \think\Db::query("SHOW TABLES LIKE '{$table}'");
        if (empty($tables)) {
            \think\Db::execute("CREATE TABLE `{$table}` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `userid` int(11) NOT NULL,
                `type` varchar(50) NOT NULL DEFAULT 'checkin' COMMENT '类型:checkin签到 exchange兑换',
                `points` int(11) NOT NULL DEFAULT 0 COMMENT '积分变动(正数增加负数减少)',
                `content` varchar(500) DEFAULT '' COMMENT '描述',
                `created_at` int(11) NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                KEY `idx_userid` (`userid`),
                KEY `idx_type` (`type`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    } catch (\Exception $e) {}
}

/**
 * 更新用户会员等级（根据累计充值金额）
 */
function update_user_membership($userid) {
    try {
        $user = \think\Db::name('user')->where('id', $userid)->find();
        if (!$user) return 0;
        $totalRecharge = floatval($user['total_recharge'] ?? 0);
        $levels = \think\Db::name('membership_levels')
            ->where('status', 1)
            ->where('min_recharge', '<=', $totalRecharge)
            ->order('level desc')
            ->select();
        $newLevel = 0;
        if (!empty($levels)) {
            $newLevel = intval($levels[0]['level']);
        }
        if (intval($user['membership_level'] ?? 0) != $newLevel) {
            \think\Db::name('user')->where('id', $userid)->update(['membership_level' => $newLevel]);
        }
        return $newLevel;
    } catch (\Exception $e) {
        return 0;
    }
}

/**
 * 获取会员折扣
 */
function get_membership_discount($userid, $type = 'buy') {
    try {
        $user = \think\Db::name('user')->where('id', $userid)->find();
        if (!$user || intval($user['membership_level'] ?? 0) == 0) return 1.00;
        $level = \think\Db::name('membership_levels')
            ->where('level', intval($user['membership_level']))
            ->where('status', 1)
            ->find();
        if (!$level) return 1.00;
        if ($type == 'renew') {
            return floatval($level['renew_discount'] ?? 1.00);
        }
        return floatval($level['discount'] ?? 1.00);
    } catch (\Exception $e) {
        return 1.00;
    }
}

/**
 * 确保邮箱验证记录表存在（用于跨控制器验证状态共享）
 */
function ensure_email_verify_table() {
    try {
        $prefix = \think\Db::getConfig('prefix');
        $prefix = $prefix ?: '';
        $table = "{$prefix}email_verify";
        $tables = \think\Db::query("SHOW TABLES LIKE '{$table}'");
        if (empty($tables)) {
            \think\Db::execute("CREATE TABLE `{$table}` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `mail` varchar(255) NOT NULL DEFAULT '' COMMENT '邮箱地址',
                `token` varchar(255) NOT NULL DEFAULT '' COMMENT '验证令牌',
                `code` varchar(10) NOT NULL DEFAULT '' COMMENT '备用验证码',
                `verified` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否已验证:0未验证 1已验证',
                `create_time` int(11) NOT NULL DEFAULT 0 COMMENT '创建时间戳',
                `expire_time` int(11) NOT NULL DEFAULT 0 COMMENT '过期时间戳',
                PRIMARY KEY (`id`),
                KEY `idx_mail` (`mail`),
                KEY `idx_token` (`token`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='邮箱验证记录'");
        } else {
            $cols = \think\Db::query("SHOW COLUMNS FROM `{$table}` LIKE 'code'");
            if (empty($cols)) {
                \think\Db::execute("ALTER TABLE `{$table}` ADD COLUMN `code` varchar(10) NOT NULL DEFAULT '' COMMENT '备用验证码' AFTER `token`");
            }
        }
    } catch (\Throwable $e) {
        // 创建失败不影响后续流程
    }
}

/**
 * 确保管理员表包含权限相关字段
 */
function ensure_admin_columns() {
    try {
        $prefix = \think\Db::getConfig('prefix');
        $prefix = $prefix ?: '';
        $table = "{$prefix}admin";
        $columns = \think\Db::query("SHOW COLUMNS FROM `{$table}`");
        $columnNames = array_column($columns, 'Field');
        if (!in_array('role_id', $columnNames)) {
            \think\Db::execute("ALTER TABLE `{$table}` ADD COLUMN `role_id` int(11) DEFAULT 0 COMMENT '角色ID'");
        }
        if (!in_array('is_super', $columnNames)) {
            \think\Db::execute("ALTER TABLE `{$table}` ADD COLUMN `is_super` tinyint(1) DEFAULT 0 COMMENT '是否超级管理员'");
        }
        if (!in_array('status', $columnNames)) {
            \think\Db::execute("ALTER TABLE `{$table}` ADD COLUMN `status` tinyint(1) DEFAULT 1 COMMENT '状态'");
        }
        if (!in_array('created_at', $columnNames)) {
            \think\Db::execute("ALTER TABLE `{$table}` ADD COLUMN `created_at` int(11) DEFAULT 0 COMMENT '创建时间'");
        }
        if (!in_array('permissions', $columnNames)) {
            \think\Db::execute("ALTER TABLE `{$table}` ADD COLUMN `permissions` text NULL COMMENT '管理员自定义权限JSON（普通管理员单个勾选）'");
        }
    } catch (\Exception $e) {
        // 忽略字段已存在等异常
    }
}

/**
 * 确保管理员角色表存在，并初始化两角色体系（超级管理员 / 普通管理员）
 * 兼容旧三角色体系（站长/超级管理员/普通管理员）的一次性自动迁移
 */
function ensure_admin_role_table() {
    try {
        $prefix = \think\Db::getConfig('prefix');
        $prefix = $prefix ?: '';
        $sql = "CREATE TABLE IF NOT EXISTS `{$prefix}admin_role` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `name` varchar(50) NOT NULL COMMENT '角色名称',
          `permissions` text COMMENT '权限JSON',
          `description` varchar(255) DEFAULT '' COMMENT '角色描述',
          `created_at` int(11) DEFAULT 0,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        \think\Db::execute($sql);

        // 检测旧三角色体系特征（存在"站长"角色、id>2 的角色，或 admin.role_id>2）
        $hasLegacyRole = \think\Db::name('admin_role')->where(function ($q) {
            $q->where('name', '站长')->whereOr('id', '>', 2);
        })->find();
        $hasLegacyAdmin = \think\Db::name('admin')->where('role_id', '>', 2)->find();

        if (!empty($hasLegacyRole) || !empty($hasLegacyAdmin)) {
            // 一次性迁移：旧体系 → 两角色体系
            // admin.role_id 映射：旧站长(1)/旧超级管理员(2) → 1(超级管理员)，其余 → 2(普通管理员)
            $admins = \think\Db::name('admin')->field('id,is_super,role_id')->select();
            foreach ($admins as $a) {
                $newRole = 2;
                if (intval($a['is_super']) == 1 || in_array(intval($a['role_id']), [1, 2])) {
                    $newRole = 1;
                }
                if (intval($a['role_id']) != $newRole) {
                    \think\Db::name('admin')->where('id', $a['id'])->update(['role_id' => $newRole]);
                }
            }
            // 重建两个标准角色
            \think\Db::name('admin_role')->where('id', '>', 0)->delete();
            \think\Db::name('admin_role')->insertAll([
                ['id' => 1, 'name' => '超级管理员', 'permissions' => json_encode(['all']), 'description' => '拥有全部管理权限', 'created_at' => time()],
                ['id' => 2, 'name' => '普通管理员', 'permissions' => json_encode(['user']), 'description' => '基础权限，可单独勾选', 'created_at' => time()],
            ]);
        } else {
            // 已是两角色体系：确保两个标准角色存在且名称正确
            $r1 = \think\Db::name('admin_role')->where('id', 1)->find();
            if (!$r1) {
                \think\Db::name('admin_role')->insert(['id' => 1, 'name' => '超级管理员', 'permissions' => json_encode(['all']), 'description' => '拥有全部管理权限', 'created_at' => time()]);
            } elseif ($r1['name'] !== '超级管理员') {
                \think\Db::name('admin_role')->where('id', 1)->update(['name' => '超级管理员', 'description' => '拥有全部管理权限']);
            }
            $r2 = \think\Db::name('admin_role')->where('id', 2)->find();
            if (!$r2) {
                \think\Db::name('admin_role')->insert(['id' => 2, 'name' => '普通管理员', 'permissions' => json_encode(['user']), 'description' => '基础权限，可单独勾选', 'created_at' => time()]);
            } elseif ($r2['name'] !== '普通管理员') {
                \think\Db::name('admin_role')->where('id', 2)->update(['name' => '普通管理员', 'description' => '基础权限，可单独勾选']);
            }
        }
    } catch (\Exception $e) {
        // 忽略表已存在等异常
    }
}

/**
 * 获取管理员的权限列表（统一权限判断）
 * 超级管理员（is_super=1 或 role_id=1）返回完整权限；
 * 普通管理员优先读 admin.permissions（单个勾选），为空时回退角色权限。
 * @param array $admin 管理员记录
 * @return array
 */
function get_admin_permissions($admin) {
    if (isset($admin['is_super']) && intval($admin['is_super']) == 1) {
        return ['all', 'user', 'product', 'classification', 'server', 'order', 'ticket', 'announcement', 'pay', 'pays', 'aff', 'set', 'admin_manager', 'sq', 'transaction', 'transferrecord', 'op_log'];
    }
    if (isset($admin['role_id']) && intval($admin['role_id']) == 1) {
        return ['all', 'user', 'product', 'classification', 'server', 'order', 'ticket', 'announcement', 'pay', 'pays', 'aff', 'set', 'admin_manager', 'sq', 'transaction', 'transferrecord', 'op_log'];
    }
    // 普通管理员：优先读单个管理员自定义权限
    if (isset($admin['permissions']) && $admin['permissions'] !== '' && $admin['permissions'] !== null) {
        $perms = json_decode($admin['permissions'], true);
        if (is_array($perms)) {
            return $perms;
        }
    }
    // 回退角色权限
    if (!empty($admin['role_id'])) {
        try {
            $role = \think\Db::name('admin_role')->where('id', intval($admin['role_id']))->find();
            if ($role) {
                $perms = json_decode($role['permissions'], true);
                if (is_array($perms)) {
                    return $perms;
                }
            }
        } catch (\Exception $e) {}
    }
    return [];
}

/**
 * 根据已有 IP 批量补全用户地区字段（用于地域分布图首次统计）
 * 每页仅处理少量用户，避免外部 API 超时
 */
function refresh_user_regions($limit = 50) {
    try {
        $prefix = \think\Db::getConfig('prefix');
        $prefix = $prefix ?: '';
        $table = "{$prefix}user";
        $rows = \think\Db::name('user')
            ->where('last_login_region', 'in', ['', '未知', '本地'])
            ->where('last_login_ip', 'not in', ['', '0.0.0.0', '127.0.0.1', 'localhost', 'unknown'])
            ->field('id,last_login_ip')
            ->limit($limit)
            ->select();
        if (empty($rows)) {
            return 0;
        }
        foreach ($rows as $row) {
            $region = get_ip_region($row['last_login_ip']);
            if ($region && !in_array($region, ['', '未知', '本地'])) {
                \think\Db::name('user')->where('id', $row['id'])->update([
                    'last_login_region' => $region,
                ]);
            }
        }
        return count($rows);
    } catch (\Exception $e) {
        return 0;
    }
}

/**
 * 确保实名认证记录表存在
 */
function ensure_realname_record_table() {
    try {
        $prefix = \think\Db::getConfig('prefix');
        $prefix = $prefix ?: '';
        $sql = "CREATE TABLE IF NOT EXISTS `{$prefix}realname_record` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `user_id` int(11) NOT NULL COMMENT '用户ID',
          `realname` varchar(100) NOT NULL DEFAULT '' COMMENT '真实姓名',
          `idcard` varchar(20) NOT NULL DEFAULT '' COMMENT '身份证号',
          `status` tinyint(1) NOT NULL DEFAULT 0 COMMENT '0未认证 1已通过 2已驳回 3待审核',
          `apply_time` int(11) NOT NULL DEFAULT 0 COMMENT '申请时间',
          `review_time` int(11) NOT NULL DEFAULT 0 COMMENT '审核时间',
          `reviewer_id` int(11) NOT NULL DEFAULT 0 COMMENT '审核人ID',
          `reviewer_name` varchar(50) NOT NULL DEFAULT '' COMMENT '审核人账号',
          `remark` varchar(255) NOT NULL DEFAULT '' COMMENT '审核备注',
          PRIMARY KEY (`id`),
          KEY `idx_user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        \think\Db::execute($sql);
        // 确保表编码为 utf8mb4
        \think\Db::execute("ALTER TABLE `{$prefix}realname_record` DEFAULT CHARSET=utf8mb4");
    } catch (\Exception $e) {
        // 忽略表已存在等异常
    }
}

/**
 * 确保卡密表存在
 */
function ensure_cdkey_table() {
    try {
        $prefix = \think\Db::getConfig('prefix');
        $prefix = $prefix ?: '';
        $sql = "CREATE TABLE IF NOT EXISTS `{$prefix}cdkey` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `cdkey` varchar(64) NOT NULL DEFAULT '' COMMENT '卡密',
          `type` varchar(10) NOT NULL DEFAULT 'balance' COMMENT '类型: balance=余额充值, host=购买主机, points=积分',
          `money` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '余额充值金额',
          `points` int(11) NOT NULL DEFAULT '0' COMMENT '积分卡密的积分数额',
          `cartid` int(11) NOT NULL DEFAULT '0' COMMENT '关联产品ID(host类型)',
          `status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0未使用 1已使用 2已停用/回收',
          `created_at` int(11) NOT NULL DEFAULT '0' COMMENT '创建时间',
          `used_at` int(11) NOT NULL DEFAULT '0' COMMENT '使用时间',
          `used_userid` int(11) NOT NULL DEFAULT '0' COMMENT '使用用户ID',
          `remark` varchar(255) NOT NULL DEFAULT '' COMMENT '备注',
          `repeatable` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0不可重复使用 1可重复使用',
          `restrict_type` varchar(20) NOT NULL DEFAULT 'all' COMMENT '限制类型: all=通用, single=单用户, multi=多用户, once_per_user=全站每人一次',
          `restrict_users` text COMMENT '限制用户ID列表(逗号分隔, single/multi时生效)',
          `exclude_users` text COMMENT '排除用户ID列表(逗号分隔, 这些用户不可使用)',
          `max_uses` int(11) NOT NULL DEFAULT '0' COMMENT '可兑换人数上限(0=不限, 配合全站通用卡密使用)',
          PRIMARY KEY (`id`),
          UNIQUE KEY `uk_cdkey` (`cdkey`),
          KEY `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        \think\Db::execute($sql);
        // 兼容旧表：添加新字段
        $columns = \think\Db::query("SHOW COLUMNS FROM `{$prefix}cdkey`");
        $colNames = array_column($columns, 'Field');
        if (!in_array('repeatable', $colNames)) {
            \think\Db::execute("ALTER TABLE `{$prefix}cdkey` ADD COLUMN `repeatable` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0不可重复使用 1可重复使用'");
        }
        if (!in_array('restrict_type', $colNames)) {
            \think\Db::execute("ALTER TABLE `{$prefix}cdkey` ADD COLUMN `restrict_type` varchar(20) NOT NULL DEFAULT 'all' COMMENT '限制类型: all=通用, single=单用户, multi=多用户, once_per_user=全站每人一次'");
        } else {
            // 兼容旧表：扩展 restrict_type 长度以支持 once_per_user
            $colInfo = \think\Db::query("SHOW COLUMNS FROM `{$prefix}cdkey` LIKE 'restrict_type'");
            if (!empty($colInfo) && isset($colInfo[0]['Type'])) {
                $colType = $colInfo[0]['Type'];
                if (stripos($colType, 'varchar(10)') !== false) {
                    \think\Db::execute("ALTER TABLE `{$prefix}cdkey` MODIFY COLUMN `restrict_type` varchar(20) NOT NULL DEFAULT 'all' COMMENT '限制类型: all=通用, single=单用户, multi=多用户, once_per_user=全站每人一次'");
                }
            }
        }
        if (!in_array('restrict_users', $colNames)) {
            \think\Db::execute("ALTER TABLE `{$prefix}cdkey` ADD COLUMN `restrict_users` text COMMENT '限制用户ID列表(逗号分隔)'");
        }
        if (!in_array('exclude_users', $colNames)) {
            \think\Db::execute("ALTER TABLE `{$prefix}cdkey` ADD COLUMN `exclude_users` text COMMENT '排除用户ID列表(逗号分隔, 这些用户不可使用)'");
        }
        // 积分卡密：新增积分数额字段
        if (!in_array('points', $colNames)) {
            \think\Db::execute("ALTER TABLE `{$prefix}cdkey` ADD COLUMN `points` int(11) NOT NULL DEFAULT '0' COMMENT '积分卡密的积分数额'");
        }
        // 全站通用卡密：可兑换人数上限
        if (!in_array('max_uses', $colNames)) {
            \think\Db::execute("ALTER TABLE `{$prefix}cdkey` ADD COLUMN `max_uses` int(11) NOT NULL DEFAULT '0' COMMENT '可兑换人数上限(0=不限)'");
        }
        // 兼容旧表：type 字段原本可能过短（'points' 需要至少 10 位即可，这里保持 10）
    } catch (\Exception $e) {
        // 忽略表已存在等异常
    }
}

function ensure_cdkey_usage_log_table() {
    try {
        $prefix = \think\Db::getConfig('prefix');
        $prefix = $prefix ?: '';
        $sql = "CREATE TABLE IF NOT EXISTS `{$prefix}cdkey_usage_log` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `cdkey` varchar(64) NOT NULL COMMENT '卡密',
          `userid` int(11) NOT NULL COMMENT '使用用户ID',
          `used_at` int(11) NOT NULL COMMENT '使用时间',
          PRIMARY KEY (`id`),
          UNIQUE KEY `uk_cdkey_user` (`cdkey`, `userid`),
          KEY `idx_cdkey` (`cdkey`),
          KEY `idx_userid` (`userid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        \think\Db::execute($sql);

        // 修正历史表：确保唯一索引为 (cdkey, userid) 复合索引，而非 userid 单独唯一。
        // 旧表若对 userid 建了唯一索引，会导致"同一用户兑换两张不同卡密"时第二条插入失败（不同卡密不能共存）。
        try {
            $indexes = \think\Db::query("SHOW INDEX FROM `{$prefix}cdkey_usage_log`");
            $uniqueKeys = []; // Key_name => [columns]
            foreach ($indexes as $idx) {
                if (isset($idx['Non_unique']) && intval($idx['Non_unique']) === 0) {
                    $uniqueKeys[$idx['Key_name']][] = $idx['Column_name'];
                }
            }
            $hasCompositeUnique = false;
            foreach ($uniqueKeys as $cols) {
                sort($cols);
                if (in_array('cdkey', $cols, true) && in_array('userid', $cols, true)) {
                    $hasCompositeUnique = true;
                    break;
                }
            }
            if (!$hasCompositeUnique) {
                foreach ($uniqueKeys as $keyName => $cols) {
                    sort($cols);
                    if (count($cols) === 1 && in_array('userid', $cols, true)) {
                        \think\Db::execute("ALTER TABLE `{$prefix}cdkey_usage_log` DROP INDEX `{$keyName}`");
                    }
                }
                \think\Db::execute("ALTER TABLE `{$prefix}cdkey_usage_log` ADD UNIQUE KEY `uk_cdkey_user` (`cdkey`, `userid`)");
            }
        } catch (\Throwable $e) {
            // 迁移失败不阻断业务流程
        }
    } catch (\Exception $e) {
        // 忽略表已存在等异常
    }
}

function ensure_announcements_table() {
    try {
        $prefix = \think\Db::getConfig('prefix');
        $prefix = $prefix ?: '';
        $sql = "CREATE TABLE IF NOT EXISTS `{$prefix}announcements` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `title` varchar(255) NOT NULL DEFAULT '' COMMENT '公告标题',
          `content` text COMMENT '公告内容',
          `notice_type` varchar(10) NOT NULL DEFAULT 'silent' COMMENT '提醒方式: force=强制弹窗, silent=静默红点',
          `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '0隐藏 1显示',
          `send_email` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0不发送邮件 1发送邮件',
          `email_sent` tinyint(1) NOT NULL DEFAULT '0' COMMENT '邮件是否已发送',
          `created_at` int(11) NOT NULL DEFAULT '0' COMMENT '发布时间',
          `updated_at` int(11) NOT NULL DEFAULT '0' COMMENT '更新时间',
          PRIMARY KEY (`id`),
          KEY `idx_status` (`status`),
          KEY `idx_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        \think\Db::execute($sql);
        $sql2 = "CREATE TABLE IF NOT EXISTS `{$prefix}announcement_reads` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `announcement_id` int(11) NOT NULL DEFAULT '0' COMMENT '公告ID',
          `user_id` int(11) NOT NULL DEFAULT '0' COMMENT '用户ID',
          `read_at` int(11) NOT NULL DEFAULT '0' COMMENT '阅读时间',
          PRIMARY KEY (`id`),
          UNIQUE KEY `uk_user_ann` (`user_id`, `announcement_id`),
          KEY `idx_ann` (`announcement_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        \think\Db::execute($sql2);
    } catch (\Exception $e) {
        // 忽略表已存在等异常
    }
}

/**
 * 确保购物车表存在（供前台购物车和后台统计共用）
 */
function ensure_shopping_cart_table() {
    try {
        $prefix = \think\Db::getConfig('prefix');
        $prefix = $prefix ?: '';
        $sql = "CREATE TABLE IF NOT EXISTS `{$prefix}shopping_cart` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `userid` int(11) NOT NULL,
          `cartid` int(11) NOT NULL,
          `user` varchar(300) DEFAULT NULL,
          `password` varchar(320) DEFAULT NULL,
          `time` int(11) NOT NULL DEFAULT '1',
          `money` varchar(100) NOT NULL DEFAULT '0',
          `cycle` varchar(100) NOT NULL,
          `created_at` int(11) NOT NULL,
          `status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0未支付 1已支付 2已过期',
          PRIMARY KEY (`id`),
          KEY `idx_userid_status` (`userid`,`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        \think\Db::execute($sql);
    } catch (\Exception $e) {
        // 忽略表已存在等异常
    }
}

/**
 * 确保产品表 cart 存在且包含全部标准字段（兼容旧库升级，防止后台添加产品报 Unknown column）
 */
function ensure_cart_table() {
    try {
        $prefix = \think\Db::getConfig('prefix');
        $prefix = $prefix ?: '';
        $table = "{$prefix}cart";
        $tables = \think\Db::query("SHOW TABLES LIKE '{$table}'");
        if (empty($tables)) {
            \think\Db::execute("CREATE TABLE IF NOT EXISTS `{$table}` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `product` varchar(200) NOT NULL,
                `name` varchar(200) NOT NULL,
                `content` text NOT NULL,
                `money` varchar(200) NOT NULL DEFAULT '0',
                `cycle` varchar(100) NOT NULL,
                `firstmo` varchar(288) NOT NULL DEFAULT '0',
                `serverid` varchar(200) NOT NULL,
                `upgrade` varchar(10) NOT NULL DEFAULT '0',
                `upgrades` text,
                `buy` varchar(10) NOT NULL DEFAULT '0',
                `hide` varchar(10) NOT NULL DEFAULT '0',
                `sort` int(11) NOT NULL DEFAULT '0',
                `renew` varchar(100) NOT NULL DEFAULT '0',
                `limits` varchar(288) NOT NULL DEFAULT '0',
                `inventory` varchar(100) NOT NULL DEFAULT '0',
                `data1` text, `data2` text, `data3` text, `data4` text, `data5` text,
                `data6` text, `data7` text, `data8` text, `data9` text, `data10` text,
                `data11` text, `data12` text, `data13` text, `data14` text, `data15` text,
                `data16` text, `data17` text, `data18` text, `data19` text, `data20` text,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            return;
        }
        // 已有表：补齐缺失字段
        $columns = \think\Db::query("SHOW COLUMNS FROM `{$table}`");
        $columnNames = array_column($columns, 'Field');
        $defs = [
            'product' => "varchar(200) NOT NULL DEFAULT ''",
            'name' => "varchar(200) NOT NULL DEFAULT ''",
            'content' => "text",
            'money' => "varchar(200) NOT NULL DEFAULT '0'",
            'cycle' => "varchar(100) NOT NULL DEFAULT ''",
            'firstmo' => "varchar(288) NOT NULL DEFAULT '0'",
            'serverid' => "varchar(200) NOT NULL DEFAULT ''",
            'upgrade' => "varchar(10) NOT NULL DEFAULT '0'",
            'upgrades' => "text",
            'buy' => "varchar(10) NOT NULL DEFAULT '0'",
            'hide' => "varchar(10) NOT NULL DEFAULT '0'",
            'sort' => "int(11) NOT NULL DEFAULT '0'",
            'renew' => "varchar(100) NOT NULL DEFAULT '0'",
            'limits' => "varchar(288) NOT NULL DEFAULT '0'",
            'inventory' => "varchar(100) NOT NULL DEFAULT '0'",
        ];
        foreach ($defs as $field => $ddl) {
            if (!in_array($field, $columnNames)) {
                \think\Db::execute("ALTER TABLE `{$table}` ADD COLUMN `{$field}` {$ddl}");
            }
        }
        // 自定义配置加价单价（用户每多买 1MB 加收的金额）
        $priceDefs = [
            'price_space_mb'   => "decimal(10,4) NOT NULL DEFAULT '0'",
            'price_db_mb'      => "decimal(10,4) NOT NULL DEFAULT '0'",
            'price_traffic_mb' => "decimal(10,4) NOT NULL DEFAULT '0'",
            'price_traffic_gb' => "decimal(10,4) NOT NULL DEFAULT '0' COMMENT '月流量每加 1GB 的加价（优先于 price_traffic_mb）'",
            'price_domain'     => "decimal(10,4) NOT NULL DEFAULT '0' COMMENT '每多 1 个域名绑定的加价'",
            'points_price'     => "int(11) NOT NULL DEFAULT '0' COMMENT '积分价：>0 表示可用积分购买（消耗积分数）'",
            'cycle_prices'     => "text COMMENT '各周期自定义价格 JSON，键为时长倍数，值为整单价格'",
        ];
        foreach ($priceDefs as $field => $ddl) {
            if (!in_array($field, $columnNames)) {
                \think\Db::execute("ALTER TABLE `{$table}` ADD COLUMN `{$field}` {$ddl}");
            }
        }
        for ($i = 1; $i <= 20; $i++) {
            $f = "data{$i}";
            if (!in_array($f, $columnNames)) {
                \think\Db::execute("ALTER TABLE `{$table}` ADD COLUMN `{$f}` text");
            }
        }
    } catch (\Throwable $e) {
        // 忽略异常，避免影响主流程
    }
}

/**
 * 确保 shopping_cart 表包含自定义主机配置列（space_mb / db_mb / traffic_mb）
 */
function ensure_shopping_cart_config_columns() {
    try {
        $prefix = \think\Db::getConfig('prefix');
        $prefix = $prefix ?: '';
        $table = "{$prefix}shopping_cart";
        $tables = \think\Db::query("SHOW TABLES LIKE '{$table}'");
        if (empty($tables)) {
            return;
        }
        $columns = \think\Db::query("SHOW COLUMNS FROM `{$table}`");
        $columnNames = array_column($columns, 'Field');
        $defs = [
            'space_mb'   => "int(11) NOT NULL DEFAULT '0'",
            'db_mb'      => "int(11) NOT NULL DEFAULT '0'",
            'traffic_mb' => "int(11) NOT NULL DEFAULT '0'",
            'domain_num' => "int(11) NOT NULL DEFAULT '0' COMMENT '自定义域名绑定数'",
            'points_used'=> "int(11) NOT NULL DEFAULT '0' COMMENT '本单消耗的积分'",
            'serverid'   => "int(11) NOT NULL DEFAULT '0' COMMENT '本单选择的线路(服务器ID)，0=用套餐默认'",
            'docker'     => "tinyint(1) NOT NULL DEFAULT '0' COMMENT '购买时是否勾选开通 Docker 容器 1=勾选 0=不勾选'",
        ];
        foreach ($defs as $field => $ddl) {
            if (!in_array($field, $columnNames)) {
                \think\Db::execute("ALTER TABLE `{$table}` ADD COLUMN `{$field}` {$ddl}");
            }
        }
    } catch (\Throwable $e) {
        // 忽略异常，避免影响主流程
    }
}

/**
 * 从购物车行提取自定义主机配置
 * @return array|null space_mb/db_mb/traffic_mb（0 表示使用套餐默认值）
 */
function host_config_from_cart_row($row) {
    if (!is_array($row)) {
        return null;
    }
    $space   = isset($row['space_mb']) ? intval($row['space_mb']) : 0;
    $db      = isset($row['db_mb']) ? intval($row['db_mb']) : 0;
    $traffic = isset($row['traffic_mb']) ? intval($row['traffic_mb']) : 0;
    $domain  = isset($row['domain_num']) ? intval($row['domain_num']) : 0;
    if ($space <= 0 && $db <= 0 && $traffic <= 0 && $domain <= 0) {
        return null;
    }
    return ['space_mb' => $space, 'db_mb' => $db, 'traffic_mb' => $traffic, 'domain_num' => $domain];
}

/**
 * 从订单行提取自定义主机配置（存储于 order.data6 的 JSON）
 * @return array|null
 */
function host_config_from_order($order) {
    if (!is_array($order) || empty($order['data6'])) {
        return null;
    }
    $config = json_decode((string) $order['data6'], true);
    if (!is_array($config)) {
        return null;
    }
    $space   = isset($config['space_mb']) ? intval($config['space_mb']) : 0;
    $db      = isset($config['db_mb']) ? intval($config['db_mb']) : 0;
    $traffic = isset($config['traffic_mb']) ? intval($config['traffic_mb']) : 0;
    if ($space <= 0 && $db <= 0 && $traffic <= 0) {
        return null;
    }
    return ['space_mb' => $space, 'db_mb' => $db, 'traffic_mb' => $traffic];
}

/**
 * 将自定义主机配置覆盖到套餐数据（不修改原套餐，返回副本）
 * 套餐字段约定：data2=空间(M) data3=数据库(M) data4=月流量(G) data5=绑定域名数
 * 自定义流量以 MB 传入，转换为 G 写回 data4
 */
function apply_host_custom_config($cart, $config) {
    if (!is_array($cart) || !is_array($config)) {
        return $cart;
    }
    $effective = $cart;
    if (!empty($config['space_mb']) && intval($config['space_mb']) > 0) {
        $effective['data2'] = intval($config['space_mb']);
    }
    if (!empty($config['db_mb']) && intval($config['db_mb']) > 0) {
        $effective['data3'] = intval($config['db_mb']);
    }
    if (!empty($config['traffic_mb']) && intval($config['traffic_mb']) > 0) {
        $gb = intval($config['traffic_mb']) / 1024;
        $effective['data4'] = ($gb == floor($gb)) ? (string) intval($gb) : (string) round($gb, 2);
    }
    if (!empty($config['domain_num']) && intval($config['domain_num']) > 0) {
        $effective['data5'] = intval($config['domain_num']);
    }
    return $effective;
}

/**
 * 计算自定义配置加价金额（仅对超出套餐默认值的部分按每 MB 单价加价）
 * @param array $cart 套餐行（含 price_space_mb/price_db_mb/price_traffic_mb）
 * @param int $spaceMb 空间(MB) 0 表示未填
 * @param int $dbMb 数据库(MB)
 * @param int $trafficMb 月流量(MB)
 * @return float 加价金额（保留 2 位小数）
 */
/**
 * 计算订单可退款信息（前台「主机退款」预览与实际退款共用同一口径）
 * 退款金额 = 累计已支付金额 × 剩余时长占比 − 手续费
 * 剩余时长占比 =（到期时间 − 当前时间）/（到期时间 − 开通时间），续费会同时拉长分子分母，结果依然正确
 *
 * @param array $order order 表记录
 * @param array $web   web 站点配置
 * @return array ['can'=>bool,'amount'=>float,'paid'=>float,'ratio'=>float,'msg'=>string]
 */
function calc_order_refund($order, $web = []) {
    $out = ['can' => false, 'amount' => 0.0, 'paid' => 0.0, 'ratio' => 0.0, 'msg' => ''];
    if (!is_array($order)) {
        $out['msg'] = '订单不存在';
        return $out;
    }
    $paid  = floatval($order['paid_amount'] ?? 0);
    $atime = intval($order['atime'] ?? 0);
    $ztime = intval($order['ztime'] ?? 0);
    $now   = time();
    $out['paid'] = $paid;

    if (floatval($order['refund_amount'] ?? 0) > 0) {
        $out['msg'] = '该订单已退款，不可重复申请';
        return $out;
    }
    if (!in_array((string) ($order['state'] ?? ''), ['1', '2'], true)) {
        $out['msg'] = '该主机当前状态不可退款';
        return $out;
    }
    if ($paid <= 0) {
        $out['msg'] = '该订单缺少支付金额记录，请联系客服处理';
        return $out;
    }
    if ($ztime <= $now) {
        $out['msg'] = '该主机已到期，无可退金额';
        return $out;
    }
    $span  = max(1, $ztime - $atime);
    $ratio = max(0, min(1, ($ztime - $now) / $span));
    $refund = round($paid * $ratio, 2);
    $fee = max(0, min(100, floatval($web['refund_fee_percent'] ?? 0)));
    if ($fee > 0) {
        $refund = round($refund * (1 - $fee / 100), 2);
    }
    $min = max(0, floatval($web['refund_min_amount'] ?? 0));
    $out['ratio']  = $ratio;
    $out['amount'] = $refund;
    if ($refund <= 0 || ($min > 0 && $refund < $min)) {
        $out['msg'] = '按剩余时长计算的可退金额为 ¥' . $refund . '，低于本站最低退款金额（¥' . $min . '），请联系客服处理';
        return $out;
    }
    $out['can'] = true;
    return $out;
}

/**
 * 邮箱脱敏展示：ab***@example.com
 */
function mask_email_addr($mail) {
    $mail = trim((string) $mail);
    if ($mail === '' || strpos($mail, '@') === false) {
        return $mail;
    }
    list($name, $domain) = explode('@', $mail, 2);
    $keep = mb_substr($name, 0, 2, 'UTF-8');
    return $keep . str_repeat('*', max(3, mb_strlen($name, 'UTF-8') - 2)) . '@' . $domain;
}

/* ============================================================================
 * Docker 容器开通（产品控制台自助开通）
 *
 * 业务模型：
 *   用户在产品控制台点「Docker 容器开通」→ 支付开通费（余额 / 在线支付）→
 *   后台按 web.docker_mode 决定「自动开通」（直接调面板 API）还是「人工开通」
 *   （生成待办记录，管理员在后台一键开通）。
 *   「开通」只代表允许该主机使用 Docker，容器规格由用户在自己的面板里填写。
 *
 * docker_order.state：
 *   0=待支付  1=已支付待开通  2=已开通  3=已关闭  4=开通失败  5=已取消
 * ========================================================================== */

/**
 * Docker 开通记录表（自动建表，无需手工执行 SQL）
 */
function ensure_docker_order_table() {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $prefix = \think\Db::getConfig('prefix');
        $prefix = $prefix ?: '';
        $table = "{$prefix}docker_order";
        \think\Db::execute("CREATE TABLE IF NOT EXISTS `{$table}` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `userid` int(11) NOT NULL DEFAULT '0',
            `orderid` int(11) NOT NULL DEFAULT '0' COMMENT '主机订单ID',
            `cartid` int(11) NOT NULL DEFAULT '0' COMMENT '产品ID',
            `serverid` int(11) NOT NULL DEFAULT '0' COMMENT '服务器ID',
            `username` varchar(100) NOT NULL DEFAULT '' COMMENT '面板用户名',
            `price` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '开通费',
            `paid` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '实付金额',
            `payway` varchar(20) NOT NULL DEFAULT 'balance' COMMENT 'balance=余额 online=在线支付 free=免费',
            `mode` varchar(20) NOT NULL DEFAULT 'manual' COMMENT 'auto=自动开通 manual=人工开通',
            `state` varchar(10) NOT NULL DEFAULT '0' COMMENT '0待支付 1待开通 2已开通 3已关闭 4失败 5已取消',
            `pay_id` int(11) NOT NULL DEFAULT '0' COMMENT '关联 pay 表充值单ID(在线支付)',
            `ordernumber` varchar(64) NOT NULL DEFAULT '' COMMENT '关联的充值单号',
            `container_id` varchar(100) NOT NULL DEFAULT '' COMMENT '容器ID(面板返回)',
            `opened_by` varchar(20) NOT NULL DEFAULT '' COMMENT 'auto=系统 admin=管理员',
            `admin_id` int(11) NOT NULL DEFAULT '0' COMMENT '操作管理员ID',
            `opened_at` int(11) NOT NULL DEFAULT '0' COMMENT '开通成功时间',
            `fail_reason` varchar(255) NOT NULL DEFAULT '' COMMENT '失败原因',
            `remark` varchar(255) NOT NULL DEFAULT '',
            `atime` int(11) NOT NULL DEFAULT '0',
            `utime` int(11) NOT NULL DEFAULT '0',
            PRIMARY KEY (`id`),
            KEY `userid` (`userid`),
            KEY `orderid` (`orderid`),
            KEY `pay_id` (`pay_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        // 增量补列（兼容已创建过旧表的老用户）
        try {
            $cols = \think\Db::query("SHOW COLUMNS FROM `{$table}` LIKE 'opened_at'");
            if (empty($cols)) {
                \think\Db::execute("ALTER TABLE `{$table}` ADD COLUMN `opened_at` int(11) NOT NULL DEFAULT '0' COMMENT '开通成功时间' AFTER `admin_id`");
            }
        } catch (\Throwable $e) {
        }
    } catch (\Throwable $e) {
    }
}

/**
 * Docker 开通记录状态文案
 */
function docker_state_text($state) {
    $map = [
        '0' => '待支付',
        '1' => '待开通',
        '2' => '已开通',
        '3' => '已关闭',
        '4' => '开通失败',
        '5' => '已取消',
    ];
    $state = (string) $state;
    return isset($map[$state]) ? $map[$state] : $state;
}

/**
 * Docker 全局开通价（后台统一配置）
 */
function docker_price($web) {
    return round(floatval(isset($web['docker_price']) ? $web['docker_price'] : 0), 2);
}

/**
 * 该产品是否允许开通 Docker：全局开关 + 产品开关 + 服务器插件支持
 */
function docker_is_enabled($web, $cart, $server = null) {
    if (!is_array($web) || empty($web['docker_enabled']) || (string) $web['docker_enabled'] !== '1') {
        return false;
    }
    if (!is_array($cart) || empty($cart['docker_enabled']) || (string) $cart['docker_enabled'] !== '1') {
        return false;
    }
    if (is_array($server)) {
        if (empty($server['serverplugins'])) {
            return false;
        }
        if (function_exists('docker_plugin_supported') && !docker_plugin_supported($server)) {
            return false;
        }
    }
    return true;
}

/**
 * 服务器插件是否实现了 Docker 开通（mnbt 面板支持）
 */
function docker_plugin_supported($server) {
    static $cache = [];
    if (!is_array($server) || empty($server['serverplugins'])) {
        return false;
    }
    $plugin = $server['serverplugins'];
    if (isset($cache[$plugin])) {
        return $cache[$plugin];
    }
    $file = PATH . 'plugins/host/' . $plugin . '/' . $plugin . '.php';
    if (!file_exists($file)) {
        $cache[$plugin] = false;
        return false;
    }
    include_once $file;
    $fn = $plugin . '_DockerOpen';
    $cache[$plugin] = function_exists($fn);
    return $cache[$plugin];
}

/**
 * Docker 开通方式（auto / manual），默认 manual
 */
function docker_mode($web) {
    $mode = isset($web['docker_mode']) ? (string) $web['docker_mode'] : 'manual';
    return $mode === 'auto' ? 'auto' : 'manual';
}

/**
 * 允许的支付方式列表：['balance','online']
 */
function docker_pay_ways($web) {
    $ways = [];
    if (!isset($web['docker_pay_balance']) || (string) $web['docker_pay_balance'] === '1') {
        $ways[] = 'balance';
    }
    if (isset($web['docker_pay_online']) && (string) $web['docker_pay_online'] === '1') {
        $ways[] = 'online';
    }
    return $ways;
}

/**
 * 取某台主机最近一条 Docker 开通记录
 */
function docker_latest_for_host($orderid, $userid = 0) {
    try {
        ensure_docker_order_table();
        $q = \think\Db::name('docker_order')->where('orderid', intval($orderid));
        if ($userid > 0) {
            $q->where('userid', intval($userid));
        }
        return $q->order('id desc')->find();
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * 真正调用面板开通 Docker（自动开通 / 管理员手动开通都走这里）
 *
 * @param array $dockerOrder docker_order 表的一行
 * @return array ['code'=>1|-1,'msg'=>string]
 */
function docker_provision($dockerOrder) {
    $id = intval(isset($dockerOrder['id']) ? $dockerOrder['id'] : 0);
    if ($id <= 0) {
        return ['code' => -1, 'msg' => '开通记录不存在'];
    }
    $fail = function ($reason) use ($id) {
        try {
            \think\Db::name('docker_order')->where('id', $id)->update([
                'state'       => '4',
                'fail_reason' => mb_substr($reason, 0, 250),
                'utime'       => time(),
            ]);
        } catch (\Throwable $e) {
        }
        return ['code' => -1, 'msg' => $reason];
    };

    try {
        $order = \think\Db::name('order')->where('id', intval($dockerOrder['orderid']))->find();
        if (!$order) {
            return $fail('主机订单不存在，可能已被删除');
        }
        $cart = \think\Db::name('cart')->where('id', $order['cartid'])->find();
        if (!$cart) {
            return $fail('产品已删除，无法获取服务器配置');
        }
        $server = \think\Db::name('server')->where('id', $cart['serverid'])->find();
        if (!$server || empty($server['serverplugins'])) {
            return $fail('该产品的服务器未配置控制面板插件');
        }
        $pluginFile = PATH . 'plugins/host/' . $server['serverplugins'] . '/' . $server['serverplugins'] . '.php';
        if (!file_exists($pluginFile)) {
            return $fail('控制面板插件文件缺失：' . $server['serverplugins']);
        }
        include_once $pluginFile;
        $fn = $server['serverplugins'] . '_DockerOpen';
        if (!function_exists($fn)) {
            return $fail('当前控制面板插件（' . $server['serverplugins'] . '）不支持 Docker 容器开通');
        }
        $res = @$fn($server, $order);
        if (!is_array($res) || !isset($res['code'])) {
            return $fail('面板接口返回异常，请检查服务器地址 / 端口 / SSL 配置');
        }
        if ((string) $res['code'] === '1' || (string) $res['code'] === '200') {
            $containerId = '';
            if (!empty($res['data']) && is_array($res['data'])) {
                $containerId = isset($res['data']['container_id']) ? (string) $res['data']['container_id'] : '';
            }
            \think\Db::name('docker_order')->where('id', $id)->update([
                'state'        => '2',
                'container_id' => mb_substr($containerId, 0, 100),
                'opened_at'    => time(),
                'fail_reason'  => '',
                'utime'        => time(),
            ]);
            return ['code' => 1, 'msg' => isset($res['msg']) && $res['msg'] !== '' ? $res['msg'] : 'Docker 容器开通成功'];
        }
        return $fail(isset($res['msg']) ? $res['msg'] : '面板返回开通失败');
    } catch (\Throwable $e) {
        return $fail('开通异常：' . $e->getMessage());
    }
}

/**
 * 关闭主机 Docker（调用面板 close，仅改开关，用户已填的规格与容器记录保留）
 */
function docker_shutdown($dockerOrder) {
    try {
        $order = \think\Db::name('order')->where('id', intval($dockerOrder['orderid']))->find();
        if (!$order) {
            return ['code' => -1, 'msg' => '主机订单不存在'];
        }
        $cart = \think\Db::name('cart')->where('id', $order['cartid'])->find();
        $server = $cart ? \think\Db::name('server')->where('id', $cart['serverid'])->find() : null;
        if (!$server || empty($server['serverplugins'])) {
            return ['code' => -1, 'msg' => '该产品的服务器未配置控制面板插件'];
        }
        $pluginFile = PATH . 'plugins/host/' . $server['serverplugins'] . '/' . $server['serverplugins'] . '.php';
        if (!file_exists($pluginFile)) {
            return ['code' => -1, 'msg' => '控制面板插件文件缺失'];
        }
        include_once $pluginFile;
        $fn = $server['serverplugins'] . '_DockerClose';
        if (!function_exists($fn)) {
            return ['code' => -1, 'msg' => '当前控制面板插件不支持 Docker 关闭'];
        }
        $res = @$fn($server, $order);
        if (is_array($res) && (string) $res['code'] === '1') {
            \think\Db::name('docker_order')->where('id', intval($dockerOrder['id']))->update([
                'state' => '3',
                'utime' => time(),
            ]);
            return ['code' => 1, 'msg' => isset($res['msg']) && $res['msg'] !== '' ? $res['msg'] : 'Docker 容器已关闭'];
        }
        return ['code' => -1, 'msg' => (is_array($res) && isset($res['msg'])) ? $res['msg'] : '面板返回关闭失败'];
    } catch (\Throwable $e) {
        return ['code' => -1, 'msg' => '关闭异常：' . $e->getMessage()];
    }
}

/**
 * 结算「在线支付」的 Docker 订单：充值到账后自动扣款并开通
 *
 * 在线支付复用站点既有的「充值 → 结算」链路（不侵入支付插件）：
 *   用户下单 → 生成充值单 → 支付成功后余额到账 → 本函数把该笔金额扣回并开通 Docker。
 * 调用时机：用户支付返回页、控制台打开、计划任务（兜底）。
 *
 * @param int $userid 0=扫描全部用户
 * @return int 本次成功开通的条数
 */
function docker_settle_pending($userid = 0) {
    $ok = 0;
    try {
        ensure_docker_order_table();
        $web = \think\Db::name('web')->where('id', 1)->find();
        $autoMode = (docker_mode($web) === 'auto');
        $q = \think\Db::name('docker_order')
            ->where('state', '0')
            ->where('payway', 'online')
            ->where('pay_id', '>', 0);
        if ($userid > 0) {
            $q->where('userid', intval($userid));
        }
        $rows = $q->order('id asc')->limit(50)->select();
        foreach ($rows as $row) {
            try {
                $pay = \think\Db::name('pay')->where('id', intval($row['pay_id']))->find();
                if (!$pay || (string) $pay['state'] !== '1') {
                    continue; // 充值单尚未支付成功
                }
                $user = \think\Db::name('user')->where('id', intval($row['userid']))->find();
                if (!$user) {
                    continue;
                }
                $price = round(floatval($row['price']), 2);
                if (floatval($user['money']) < $price) {
                    continue; // 余额尚未到账或被占用，留待下次（计划任务会兜底）
                }
                // 条件更新，防止并发/重复结算
                $affected = \think\Db::name('docker_order')->where([
                    'id'    => intval($row['id']),
                    'state' => '0',
                ])->update([
                    'state' => '1',
                    'paid'  => $price,
                    'utime' => time(),
                ]);
                if ($affected <= 0) {
                    continue;
                }
                if ($price > 0) {
                    \think\Db::name('user')->where('id', intval($row['userid']))->update([
                        'money' => round(floatval($user['money']) - $price, 2),
                    ]);
                    try {
                        \think\Db::name('transaction')->insert([
                            'userid'  => intval($row['userid']),
                            'content' => 'Docker 容器开通费（在线支付），扣除' . $price . '元',
                            'time'    => time(),
                        ]);
                    } catch (\Throwable $e) {
                    }
                }
                $row['state'] = '1';
                if ($autoMode) {
                    $r = docker_provision($row);
                    if (!empty($r['code']) && (string) $r['code'] === '1') {
                        $ok++;
                    }
                }
            } catch (\Throwable $e) {
                continue;
            }
        }
    } catch (\Throwable $e) {
    }
    return $ok;
}

/**
 * 购买下单时创建 Docker 开通记录（随主机订单一并支付）
 *
 * 在 settleItems() / cartSettle() 创建主机订单之后调用：
 *   - 校验全局开关 + 产品 docker_enabled + 服务器插件支持，任一不满足则跳过
 *   - 写入 docker_order，state=1（已支付待开通），price/paid 取全局统一价
 *     payway 与主机支付方式保持一致（balance / online）
 *   - 幂等：同一主机订单只允许生成一条 Docker 开通记录
 *
 * @param int    $hostOrderId 主机订单 ID（order.id）
 * @param string $payway      balance | online
 * @return bool 是否成功创建
 */
function docker_create_for_purchase($hostOrderId, $payway = 'balance') {
    try {
        ensure_docker_order_table();
        $hostOrderId = intval($hostOrderId);
        if ($hostOrderId <= 0) { return false; }
        // 幂等：已存在该主机订单的 Docker 记录则不再重复创建
        $exists = \think\Db::name('docker_order')->where('orderid', $hostOrderId)->find();
        if ($exists) { return false; }
        $web = \think\Db::name('web')->where('id', 1)->find();
        if (!is_array($web)) { return false; }
        $order = \think\Db::name('order')->where('id', $hostOrderId)->find();
        if (!$order) { return false; }
        $cart = \think\Db::name('cart')->where('id', $order['cartid'])->find();
        if (!$cart) { return false; }
        $server = \think\Db::name('server')->where('id', $cart['serverid'])->find();
        if (!docker_is_enabled($web, $cart, $server ? $server : null)) { return false; }
        $price = docker_price($web);
        $payway = in_array($payway, ['balance', 'online']) ? $payway : 'balance';
        $now = time();
        \think\Db::name('docker_order')->insert([
            'userid'   => intval($order['userid']),
            'orderid'  => $hostOrderId,
            'cartid'   => intval($order['cartid']),
            'serverid' => intval($cart['serverid']),
            'username' => isset($order['user']) ? $order['user'] : '',
            'price'    => round($price, 2),
            'paid'     => round($price, 2),
            'payway'   => $payway,
            'mode'     => docker_mode($web),
            'state'    => '1', // 已支付待开通
            'atime'    => $now,
            'utime'    => $now,
        ]);
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * 主机实际开通成功后，按 docker_mode 自动/人工开通该主机名下的待开通 Docker 记录
 *
 * 调用时机：4 个 CreateAccount 调用点（settleItems 立即分支 / cartSettle 立即分支 /
 * process_pending_host_orders / 后台手动开通 createhost）在开通成功后调用。
 *   - 人工开通模式：仅保留 state=1（待开通），由管理员在后台一键开通
 *   - 自动开通模式：立即调面板 API 开通，失败则标记 state=4（失败）留待人工处理
 *
 * @param int $hostOrderId 主机订单 ID（order.id）
 * @return int 本次成功开通的条数
 */
function docker_provision_pending_for_order($hostOrderId) {
    try {
        ensure_docker_order_table();
        $hostOrderId = intval($hostOrderId);
        if ($hostOrderId <= 0) { return 0; }
        $web = \think\Db::name('web')->where('id', 1)->find();
        if (!is_array($web) || docker_mode($web) !== 'auto') {
            return 0; // 人工开通模式：保留待开通，等管理员手动开通
        }
        $rows = \think\Db::name('docker_order')->where('orderid', $hostOrderId)->where('state', '1')->select();
        $ok = 0;
        foreach ($rows as $row) {
            $r = docker_provision($row);
            if (!empty($r['code']) && (string) $r['code'] === '1') { $ok++; }
        }
        return $ok;
    } catch (\Throwable $e) {
        return 0;
    }
}

/**
 * 解析套餐「各周期自定义价格」配置
 * 存储格式：JSON，键为时长倍数（相对套餐自身 cycle 单位），值为该周期的整单价格
 * 例：{"1":10,"3":25,"12":90} 表示 1 个月 10 元、3 个月 25 元、12 个月 90 元
 * @return array [倍数 => 价格]，仅保留 >0 的项
 */
function cart_cycle_prices($cart) {
    if (!is_array($cart) || !isset($cart['cycle_prices']) || $cart['cycle_prices'] === '' || $cart['cycle_prices'] === null) {
        return [];
    }
    $raw = $cart['cycle_prices'];
    $arr = is_array($raw) ? $raw : json_decode((string) $raw, true);
    if (!is_array($arr)) {
        return [];
    }
    $out = [];
    foreach ($arr as $k => $v) {
        $k = intval($k);
        $v = round(floatval($v), 2);
        if ($k > 0 && $v > 0) {
            $out[$k] = $v;
        }
    }
    return $out;
}

/**
 * 计算某个时长倍数对应的套餐金额
 * 管理员为该周期配置了自定义价格则用自定义价，否则回退「单价 × 倍数」
 * @param array $cart 套餐（cart 表记录）
 * @param int   $mult 时长倍数（如月付套餐传 12 表示一年）
 * @return float
 */
function cart_cycle_price($cart, $mult) {
    $mult = max(1, intval($mult));
    if (!is_array($cart)) {
        return 0.0;
    }
    $map = cart_cycle_prices($cart);
    if (isset($map[$mult])) {
        return round($map[$mult], 2);
    }
    return round(floatval($cart['money'] ?? 0) * $mult, 2);
}

/**
 * 月流量加价单价（元/GB）
 * 优先取「每 GB 加价」，未配置时回退旧的「每 MB 加价 × 1024」，保证历史数据不丢价
 */
function cart_traffic_price_per_gb($cart) {
    if (!is_array($cart)) {
        return 0.0;
    }
    $perGb = floatval($cart['price_traffic_gb'] ?? 0);
    if ($perGb > 0) {
        return $perGb;
    }
    return round(floatval($cart['price_traffic_mb'] ?? 0) * 1024, 4);
}

/**
 * 格式化流量（GB）为展示文本：整数不带小数点
 */
function format_traffic_gb($gb) {
    $gb = floatval($gb);
    if ($gb == floor($gb)) {
        return intval($gb) . 'GB';
    }
    return rtrim(rtrim(number_format($gb, 2, '.', ''), '0'), '.') . 'GB';
}

function calc_host_config_extra($cart, $spaceMb, $dbMb, $trafficMb, $domainNum = 0) {
    $extra = 0.0;
    if (!is_array($cart)) {
        return 0.0;
    }
    $spaceMb   = max(0, intval($spaceMb));
    $dbMb      = max(0, intval($dbMb));
    $trafficMb = max(0, intval($trafficMb));
    $domainNum = max(0, intval($domainNum));
    $defSpace  = floatval($cart['data2'] ?? 0);
    $defDb     = floatval($cart['data3'] ?? 0);
    $defTraffic= floatval($cart['data4'] ?? 0) * 1024; // 套餐流量以 G 存，换算为 MB
    $defDomain = intval($cart['data5'] ?? 0);
    if ($spaceMb > $defSpace) {
        $extra += ($spaceMb - $defSpace) * floatval($cart['price_space_mb'] ?? 0);
    }
    if ($dbMb > $defDb) {
        $extra += ($dbMb - $defDb) * floatval($cart['price_db_mb'] ?? 0);
    }
    if ($trafficMb > $defTraffic) {
        // 流量加价统一按「元/GB」折算，兼容旧的「元/MB」配置
        $perMb = cart_traffic_price_per_gb($cart) / 1024;
        $extra += ($trafficMb - $defTraffic) * $perMb;
    }
    if ($domainNum > $defDomain) {
        $extra += ($domainNum - $defDomain) * floatval($cart['price_domain'] ?? 0);
    }
    return round($extra, 2);
}

/**
 * 取订单/购物车记录实际使用的线路（服务器）ID：
 * 用户在购买页可自由选择线路，优先用记录上的 serverid，没有才回退套餐默认线路。
 */
function host_order_server_id($item, $cart) {
    $sid = 0;
    if (is_array($item) && !empty($item['serverid'])) $sid = intval($item['serverid']);
    if (!$sid && is_array($cart) && !empty($cart['serverid'])) $sid = intval($cart['serverid']);
    return $sid;
}

/**
 * 校验自定义主机配置不得低于套餐默认值（用户只允许加配，不允许降配）
 * @return string 错误信息，空字符串表示通过
 */
function validate_host_config_floor($cart, $spaceMb, $dbMb, $trafficMb, $domainNum) {
    if (!is_array($cart)) return '';
    $defSpace   = floatval($cart['data2'] ?? 0);
    $defDb      = floatval($cart['data3'] ?? 0);
    $defTraffic = floatval($cart['data4'] ?? 0) * 1024;
    $defDomain  = intval($cart['data5'] ?? 0);
    if (intval($spaceMb) > 0 && intval($spaceMb) < $defSpace) {
        return '存储空间不能低于套餐默认的 ' . intval($defSpace) . 'MB';
    }
    if (intval($dbMb) > 0 && intval($dbMb) < $defDb) {
        return '数据库不能低于套餐默认的 ' . intval($defDb) . 'MB';
    }
    if (intval($trafficMb) > 0 && intval($trafficMb) < $defTraffic) {
        return '月流量不能低于套餐默认的 ' . format_traffic_gb(floatval($cart['data4'] ?? 0));
    }
    if (intval($domainNum) > 0 && intval($domainNum) < $defDomain) {
        return '域名绑定数不能低于套餐默认的 ' . $defDomain . ' 个';
    }
    return '';
}

/**
 * 确保 web 表有 bg_image / bg_blur / bg_gradient / bg_images / bg_switch_interval 字段
 */
function ensure_web_bg_column() {
    try {
        $prefix = \think\Db::getConfig('prefix');
        $prefix = $prefix ?: '';
        $table = "{$prefix}web";
        $columns = \think\Db::query("SHOW COLUMNS FROM `{$table}`");
        $columnNames = array_column($columns, 'Field');
        if (!in_array('bg_image', $columnNames)) {
            \think\Db::execute("ALTER TABLE `{$table}` ADD COLUMN `bg_image` varchar(500) DEFAULT '' COMMENT '全局背景图'");
        }
        if (!in_array('bg_blur', $columnNames)) {
            \think\Db::execute("ALTER TABLE `{$table}` ADD COLUMN `bg_blur` int(2) DEFAULT '0' COMMENT '背景图模糊程度(0-10)'");
        }
        // 修正历史默认值：旧版本默认 bg_blur=3 导致背景发糊，统一重置为 0（清晰原图）
        try {
            \think\Db::execute("UPDATE `{$table}` SET `bg_blur` = 0 WHERE `bg_blur` = 3");
        } catch (\Throwable $e) {}
        if (!in_array('bg_gradient', $columnNames)) {
            \think\Db::execute("ALTER TABLE `{$table}` ADD COLUMN `bg_gradient` varchar(50) DEFAULT 'default' COMMENT '预设渐变色'");
        }
        if (!in_array('bg_images', $columnNames)) {
            \think\Db::execute("ALTER TABLE `{$table}` ADD COLUMN `bg_images` text COMMENT '轮播背景图URL列表（逗号分隔）'");
        }
        if (!in_array('bg_switch_interval', $columnNames)) {
            \think\Db::execute("ALTER TABLE `{$table}` ADD COLUMN `bg_switch_interval` int(6) DEFAULT '0' COMMENT '背景图轮播间隔(秒，0=不轮播)'");
        }
        if (!in_array('glass_enabled', $columnNames)) {
            \think\Db::execute("ALTER TABLE `{$table}` ADD COLUMN `glass_enabled` tinyint(1) NOT NULL DEFAULT '1' COMMENT '是否启用液态玻璃主题:0关闭 1开启'");
        }
        if (!in_array('glass_opacity', $columnNames)) {
            \think\Db::execute("ALTER TABLE `{$table}` ADD COLUMN `glass_opacity` int(3) DEFAULT '72' COMMENT '液态玻璃透明度(30-100)'");
        }
        if (!in_array('glass_theme', $columnNames)) {
            \think\Db::execute("ALTER TABLE `{$table}` ADD COLUMN `glass_theme` varchar(20) NOT NULL DEFAULT 'default' COMMENT '配色方案:default/pink_blue'");
        }
        if (!in_array('bg_type', $columnNames)) {
            \think\Db::execute("ALTER TABLE `{$table}` ADD COLUMN `bg_type` varchar(20) DEFAULT 'image' COMMENT '背景类型：image/video/gif'");
        }
        if (!in_array('bg_video_loop', $columnNames)) {
            \think\Db::execute("ALTER TABLE `{$table}` ADD COLUMN `bg_video_loop` tinyint(1) NOT NULL DEFAULT '1' COMMENT '视频背景是否循环播放'");
        }
        if (!in_array('bg_video_muted', $columnNames)) {
            \think\Db::execute("ALTER TABLE `{$table}` ADD COLUMN `bg_video_muted` tinyint(1) NOT NULL DEFAULT '1' COMMENT '视频背景是否静音'");
        }
        if (!in_array('service_email', $columnNames)) {
            \think\Db::execute("ALTER TABLE `{$table}` ADD COLUMN `service_email` varchar(255) DEFAULT '' COMMENT '客服邮箱'");
        }
        if (!in_array('live2d_enabled', $columnNames)) {
            \think\Db::execute("ALTER TABLE `{$table}` ADD COLUMN `live2d_enabled` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'Live2D看板娘:0关闭 1开启'");
        }
        // 加载动画（品牌开屏）自定义字段
        if (!in_array('loading_enabled', $columnNames)) {
            \think\Db::execute("ALTER TABLE `{$table}` ADD COLUMN `loading_enabled` tinyint(1) NOT NULL DEFAULT '1' COMMENT '加载动画开关:0关闭 1开启'");
        }
        if (!in_array('loading_logo', $columnNames)) {
            \think\Db::execute("ALTER TABLE `{$table}` ADD COLUMN `loading_logo` varchar(500) DEFAULT '' COMMENT '加载动画专属LOGO(空则用网站logo)'");
        }
        if (!in_array('loading_brand', $columnNames)) {
            \think\Db::execute("ALTER TABLE `{$table}` ADD COLUMN `loading_brand` varchar(100) DEFAULT '' COMMENT '加载动画品牌英文名'");
        }
        if (!in_array('loading_text', $columnNames)) {
            \think\Db::execute("ALTER TABLE `{$table}` ADD COLUMN `loading_text` varchar(200) DEFAULT '' COMMENT '加载动画提示文字'");
        }
        if (!in_array('loading_subtext', $columnNames)) {
            \think\Db::execute("ALTER TABLE `{$table}` ADD COLUMN `loading_subtext` varchar(200) DEFAULT '' COMMENT '加载动画副文字'");
        }
    } catch (\Exception $e) {
        // 忽略
    }
}

/**
 * 确保 host_transfer 表存在
 */
function ensure_host_transfer_table() {
    try {
        $prefix = \think\Db::getConfig('prefix');
        $prefix = $prefix ?: '';
        $table = "{$prefix}host_transfer";
        $exists = \think\Db::query("SHOW TABLES LIKE '{$table}'");
        if (empty($exists)) {
            \think\Db::execute("CREATE TABLE IF NOT EXISTS `{$table}` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `order_id` int(11) NOT NULL COMMENT '订单ID',
                `userid` int(11) NOT NULL COMMENT '转让方用户ID',
                `target_userid` int(11) NOT NULL DEFAULT '0' COMMENT '指定接收用户ID',
                `price` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '转让价格',
                `original_price` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '原购买价格',
                `status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0=待转让 1=已完成 2=已驳回 3=已取消',
                `buyer_userid` int(11) NOT NULL DEFAULT '0' COMMENT '购买方用户ID',
                `email_verified` tinyint(1) NOT NULL DEFAULT '0' COMMENT '邮箱验证状态',
                `contact_info` varchar(500) DEFAULT '' COMMENT '卖家联系方式',
                `reject_reason` varchar(500) DEFAULT '' COMMENT '驳回原因',
                `created_at` int(11) NOT NULL DEFAULT '0',
                `updated_at` int(11) NOT NULL DEFAULT '0',
                PRIMARY KEY (`id`),
                KEY `idx_order_id` (`order_id`),
                KEY `idx_userid` (`userid`),
                KEY `idx_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    } catch (\Exception $e) {
        // 忽略
    }
}

// 确保转让消息表存在
function ensure_host_transfer_message_table() {
    try {
        $prefix = \think\Db::getConfig('prefix');
        $prefix = $prefix ?: '';
        $table = "{$prefix}host_transfer_message";
        $exists = \think\Db::query("SHOW TABLES LIKE '{$table}'");
        if (empty($exists)) {
            \think\Db::execute("CREATE TABLE IF NOT EXISTS `{$table}` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `transfer_id` int(11) NOT NULL COMMENT '转让ID',
                `sender_id` int(11) NOT NULL COMMENT '发送方用户ID',
                `receiver_id` int(11) NOT NULL COMMENT '接收方用户ID',
                `content` text NOT NULL COMMENT '消息内容',
                `is_read` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0=未读 1=已读',
                `created_at` int(11) NOT NULL DEFAULT '0',
                PRIMARY KEY (`id`),
                KEY `idx_transfer_id` (`transfer_id`),
                KEY `idx_sender_id` (`sender_id`),
                KEY `idx_receiver_id` (`receiver_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    } catch (\Exception $e) {
        // 忽略
    }
}

/**
 * 确保开发者 API 密钥表存在
 */
function ensure_api_keys_table() {
    try {
        $prefix = \think\Db::getConfig('prefix');
        $prefix = $prefix ?: '';
        $table = "{$prefix}api_keys";
        $exists = \think\Db::query("SHOW TABLES LIKE '{$table}'");
        if (empty($exists)) {
            \think\Db::execute("CREATE TABLE IF NOT EXISTS `{$table}` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `userid` int(11) NOT NULL COMMENT '用户ID',
                `name` varchar(100) NOT NULL DEFAULT '' COMMENT '密钥名称',
                `api_key` varchar(64) NOT NULL DEFAULT '' COMMENT 'API密钥',
                `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1启用 0停用',
                `last_used_at` int(11) NOT NULL DEFAULT '0' COMMENT '最后使用时间',
                `created_at` int(11) NOT NULL DEFAULT '0' COMMENT '创建时间',
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_api_key` (`api_key`),
                KEY `idx_userid` (`userid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='开发者API密钥'");
        }
    } catch (\Exception $e) {
        // 忽略表已存在等异常
    }
}

/**
 * 确保 API 调用统计表存在
 */
function ensure_api_call_log_table() {
    static $ensured = false;
    if ($ensured) return;
    $ensured = true;
    try {
        $prefix = \think\Db::getConfig('prefix');
        $prefix = $prefix ?: '';
        $table = "{$prefix}api_call_log";
        $exists = \think\Db::query("SHOW TABLES LIKE '{$table}'");
        if (empty($exists)) {
            \think\Db::execute("CREATE TABLE IF NOT EXISTS `{$table}` (
                `id` bigint(20) NOT NULL AUTO_INCREMENT,
                `api_key` varchar(64) NOT NULL DEFAULT '' COMMENT 'API密钥(可截断)',
                `userid` int(11) NOT NULL DEFAULT 0 COMMENT '用户ID',
                `action` varchar(60) NOT NULL DEFAULT '' COMMENT '调用的功能(action)',
                `ip` varchar(50) NOT NULL DEFAULT '' COMMENT '调用IP',
                `status` varchar(20) NOT NULL DEFAULT 'success' COMMENT 'success/fail/blocked/denied',
                `cost_ms` int(11) NOT NULL DEFAULT 0 COMMENT '耗时毫秒',
                `created_at` int(11) NOT NULL DEFAULT 0 COMMENT '调用时间',
                PRIMARY KEY (`id`),
                KEY `idx_action_time` (`action`, `created_at`),
                KEY `idx_userid_time` (`userid`, `created_at`),
                KEY `idx_created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='开发者API调用统计'");
        }
    } catch (\Exception $e) {
        // 忽略表已存在等异常
    }
}

/**
 * 确保 API 频率限流表存在
 */
function ensure_api_rate_table() {
    static $ensured = false;
    if ($ensured) return;
    $ensured = true;
    try {
        $prefix = \think\Db::getConfig('prefix');
        $prefix = $prefix ?: '';
        $table = "{$prefix}api_rate";
        $exists = \think\Db::query("SHOW TABLES LIKE '{$table}'");
        if (empty($exists)) {
            \think\Db::execute("CREATE TABLE IF NOT EXISTS `{$table}` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `identifier` varchar(90) NOT NULL DEFAULT '' COMMENT '限流标识(ip:xxx / key:xxx)',
                `window_start` int(11) NOT NULL DEFAULT 0 COMMENT '当前窗口起始时间戳',
                `count` int(11) NOT NULL DEFAULT 0 COMMENT '窗口内计数',
                `updated_at` int(11) NOT NULL DEFAULT 0 COMMENT '更新时间',
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_identifier` (`identifier`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='开发者API频率限流'");
        }
    } catch (\Exception $e) {
        // 忽略表已存在等异常
    }
}

/**
 * 开发者 API 全功能清单（用于后台开关与展示）
 * 返回：[分组名 => [action => 中文名, ...], ...]
 */
function api_function_list() {
    return [
        '用户' => [
            'user.info' => '查询用户信息',
            'user.update' => '修改用户资料',
        ],
        '订单' => [
            'order.list' => '订单列表',
            'order.detail' => '订单详情',
            'order.renew' => '续费主机',
        ],
        '主机' => [
            'host.list' => '主机列表',
            'host.status' => '查询主机状态',
            'host.panel' => '主机面板一键登录',
            'host.reset' => '重置主机密码',
            'host.suspend' => '暂停主机',
            'host.unsuspend' => '恢复主机',
            'host.delete' => '删除主机',
            'host.upgrade' => '升降级主机',
        ],
        '工单' => [
            'ticket.list' => '工单列表',
            'ticket.detail' => '工单详情',
            'ticket.submit' => '提交工单',
            'ticket.reply' => '回复工单',
            'ticket.close' => '关闭工单',
        ],
        '公告' => [
            'announcement.list' => '公告列表',
            'announcement.detail' => '公告详情',
        ],
        '积分与卡密' => [
            'checkin' => '每日签到',
            'points.shop' => '积分商城',
            'points.exchange' => '积分兑换',
            'cdkey.use' => '卡密兑换',
        ],
        '推广' => [
            'aff.info' => '推广信息',
            'aff.enable' => '开启推广',
            'aff.withdraw' => '推广提现',
        ],
        '财务' => [
            'payrecord.list' => '充值记录',
            'transaction.list' => '消费记录',
        ],
        '产品与购物车' => [
            'product.list' => '产品列表',
            'cart.add' => '加入购物车',
            'cart.list' => '购物车列表',
            'cart.delete' => '删除购物车项',
            'cart.checkout' => '购物车结算',
        ],
        '余额充值' => [
            'pay.ways' => '支付方式',
            'pay.create' => '创建充值订单',
            'pay.status' => '查询充值状态',
        ],
        '转让市场' => [
            'transfer.market' => '转让市场',
            'transfer.list' => '我的转让',
            'transfer.publish' => '发布转让',
            'transfer.buy' => '购买转让',
            'transfer.contact' => '转让联系',
            'transfer.detail' => '转让详情',
            'transfer.cancel' => '取消转让',
            'transfer.sendcode' => '发送转让验证码',
            'transfer.message.send' => '转让发消息',
            'transfer.message.list' => '转让消息列表',
            'transfer.unread' => '转让未读数',
        ],
    ];
}

/**
 * 将 API 动作别名归一化为规范动作名
 */
function api_canonical_action($action) {
    $map = [
        'host.panel.login' => 'host.panel',
        'host.login' => 'host.panel',
        'host.sso' => 'host.panel',
    ];
    return isset($map[$action]) ? $map[$action] : $action;
}

/**
 * 获取被关闭的功能映射 [action => 0]
 * @return array
 */
function api_function_disabled_map() {
    $raw = web_config('api_function_status');
    $map = json_decode($raw, true);
    if (!is_array($map)) $map = [];
    return $map;
}

/**
 * 判断某个 API 功能是否开启
 */
function api_action_enabled($action) {
    $canonical = api_canonical_action($action);
    // 遍历清单确认是否为合法功能；非法 action 由 Open 控制器自行拒绝
    $known = false;
    foreach (api_function_list() as $group) {
        if (isset($group[$canonical])) { $known = true; break; }
    }
    if (!$known) return true;
    $map = api_function_disabled_map();
    return !(isset($map[$canonical]) && intval($map[$canonical]) === 0);
}

/**
 * 频率限流检查（每窗口限制 N 次）
 * @param string $identifier 标识（ip:xxx / key:xxx）
 * @param int    $limit      每窗口最大次数（0=不限）
 * @return array ['pass'=>bool, 'count'=>int, 'msg'=>string]
 */
function api_rate_check($identifier, $limit) {
    if ($limit <= 0) {
        return ['pass' => true, 'count' => 0, 'msg' => ''];
    }
    try {
        ensure_api_rate_table();
        $now = time();
        $row = \think\Db::name('api_rate')->where('identifier', $identifier)->find();
        if ($row && intval($row['window_start']) > 0 && ($now - intval($row['window_start'])) < 60) {
            // 同一窗口内：累加
            $count = intval($row['count']) + 1;
            \think\Db::name('api_rate')->where('id', $row['id'])->update([
                'count' => $count,
                'updated_at' => $now,
            ]);
        } else {
            // 新窗口（或记录缺失）：重置计数
            $count = 1;
            if ($row) {
                \think\Db::name('api_rate')->where('id', $row['id'])->update([
                    'window_start' => $now,
                    'count' => 1,
                    'updated_at' => $now,
                ]);
            } else {
                try {
                    \think\Db::name('api_rate')->insert([
                        'identifier' => $identifier,
                        'window_start' => $now,
                        'count' => 1,
                        'updated_at' => $now,
                    ]);
                } catch (\Exception $e) {
                    // 并发插入冲突：回退为更新
                    \think\Db::name('api_rate')->where('identifier', $identifier)->update([
                        'window_start' => $now,
                        'count' => 1,
                        'updated_at' => $now,
                    ]);
                }
            }
        }
        if ($count > $limit) {
            return ['pass' => false, 'count' => $count, 'msg' => '请求频率超限（每分钟最多 ' . $limit . ' 次）'];
        }
        return ['pass' => true, 'count' => $count, 'msg' => ''];
    } catch (\Exception $e) {
        // 限流表异常时放行，避免误伤正常请求
        return ['pass' => true, 'count' => 0, 'msg' => ''];
    }
}

/**
 * 记录一次 API 调用（统计用）
 */
function api_log_call($apiKey, $userid, $action, $ip, $status = 'success', $costMs = 0) {
    try {
        ensure_api_call_log_table();
        \think\Db::name('api_call_log')->insert([
            'api_key'   => mb_substr($apiKey, 0, 64),
            'userid'    => intval($userid),
            'action'    => api_canonical_action($action),
            'ip'        => mb_substr($ip, 0, 50),
            'status'    => $status,
            'cost_ms'   => intval($costMs),
            'created_at'=> time(),
        ]);
    } catch (\Exception $e) {
        // 忽略统计异常
    }
}

/**
 * 检查 IP 是否处于 API 封禁状态（含自动封禁 auto 类型）
 * 与全局 is_ip_banned() 的区别：此处额外识别 ban_type='auto' 的 API 自动封禁记录。
 * @param string $ip
 * @return bool true=已封禁
 */
function api_ip_banned($ip) {
    if (empty($ip) || $ip === '0.0.0.0' || $ip === 'unknown') return false;
    try {
        ensure_ip_ban_table();
        $record = \think\Db::name('ip_ban')
            ->where('ip', $ip)
            ->where('status', 1)
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
 * 获取/生成实名信息前端加密的会话密钥（32字节，hex 表示）
 */
function realname_cipher_key($forceNew = false) {
    $key = session('realname_cipher_key');
    if (empty($key) || $forceNew) {
        $key = bin2hex(function_exists('random_bytes') ? random_bytes(16) : md5(uniqid(mt_rand(), true) . microtime(true)));
        session('realname_cipher_key', $key);
    }
    return $key;
}

/**
 * 解密前端提交的实名加密负载
 * 负载格式由前端生成：base64(iv(16字节) + aes-256-cbc密文)
 * @param string $payload 前端提交的加密串
 * @return array|false 解密得到的 ['realname'=>..,'idcard'=>..,'mobile'=>..,'ts'=>..]，失败返回 false
 */
function realname_decrypt_payload($payload) {
    if (empty($payload)) return false;
    if (!function_exists('openssl_decrypt')) return false;
    $key = session('realname_cipher_key');
    if (empty($key)) return false;
    try {
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < 17) return false;
        $iv = substr($raw, 0, 16);
        $cipher = substr($raw, 16);
        $binKey = hex2bin($key);
        if ($binKey === false || strlen($binKey) !== 16) {
            $binKey = hash('sha256', $key, true);
            $binKey = substr($binKey, 0, 16);
        }
        $plain = openssl_decrypt($cipher, 'aes-256-cbc', hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv);
        if ($plain === false) return false;
        $data = json_decode($plain, true);
        if (!is_array($data)) return false;
        // 防重放：时间戳超过10分钟视为无效
        if (isset($data['ts']) && abs(time() - intval($data['ts'])) > 600) {
            return false;
        }
        return $data;
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * 处理待开通订单（延迟自动开通 / 手动开通重试）
 * 可在 cron、用户中心、后台总览中调用，作为无 cron 时的兜底机制
 */
function process_pending_host_orders() {
    try {
        $time = time();
        $pendingOrders = \think\Db::name("order")
            ->where("state", "0")
            ->where("auto_create_at", ">", 0)
            ->where("auto_create_at", "<=", $time)
            ->select();
        if (empty($pendingOrders)) {
            return;
        }
        $cartIds = array_unique(array_filter(array_column($pendingOrders, 'cartid')));
        $carts = [];
        if (!empty($cartIds)) {
            $cartRows = \think\Db::name('cart')->where('id', 'in', $cartIds)->select();
            foreach ($cartRows as $row) { $carts[$row['id']] = $row; }
        }
        $serverIds = [];
        foreach ($carts as $c) { if (!empty($c['serverid'])) { $serverIds[] = $c['serverid']; } }
        $serverIds = array_unique($serverIds);
        $servers = [];
        if (!empty($serverIds)) {
            $serverRows = \think\Db::name('server')->where('id', 'in', $serverIds)->select();
            foreach ($serverRows as $row) { $servers[$row['id']] = $row; }
        }
        foreach ($pendingOrders as $order) {
            $cart = isset($carts[$order['cartid']]) ? $carts[$order['cartid']] : null;
            if (!$cart) { continue; }
            $server = isset($servers[$cart['serverid']]) ? $servers[$cart['serverid']] : null;
            if (!$server || $server['serverplugins'] == "") { continue; }
            // 如果订单账号密码为空，自动生成随机凭据
            $hostUser = $order["user"];
            $hostPass = $order["password"];
            if(empty($hostUser) || strlen($hostUser) < 3){
                $hostUser = 'user' . rand(10000, 99999);
            }
            if(empty($hostPass) || strlen($hostPass) < 6){
                $hostPass = substr(md5(uniqid(mt_rand(), true)), 0, 12);
            }
            if($hostUser != $order["user"] || $hostPass != $order["password"]){
                \think\Db::name('order')->where('id', $order["id"])->update([
                    "user" => $hostUser,
                    "password" => $hostPass,
                ]);
                $order["user"] = $hostUser;
                $order["password"] = $hostPass;
            }
            $pluginFile = PATH . "plugins/host/" . $server["serverplugins"] . "/" . $server["serverplugins"] . ".php";
            if (!file_exists($pluginFile)) { continue; }
            include_once $pluginFile;
            $function = $server["serverplugins"] . "_CreateAccount";
            if (!function_exists($function)) { continue; }
            $times = intval($order["ztime"]) - intval($order["atime"]);
            if ($times < 0) { $times = 0; }
            $cycleTime = 1;
            if ($cart["cycle"] == "month") $cycleTime = 2592000;
            elseif ($cart["cycle"] == "season") $cycleTime = 7879680;
            elseif ($cart["cycle"] == "year") $cycleTime = 31536000;
            elseif ($cart["cycle"] == "day") $cycleTime = 86400;
            elseif ($cart["cycle"] == "unrestricted") $cycleTime = 3153600000;
            $buyTime = ($cycleTime > 0) ? intval($times / $cycleTime) : 1;
            if ($buyTime < 1) { $buyTime = 1; }
            // 应用用户自定义主机配置（存储于订单 data6）
            $customConfig = host_config_from_order($order);
            if ($customConfig) { $cart = apply_host_custom_config($cart, $customConfig); }
            $result = @$function($server, ["user" => $order["user"], "password" => $order["password"], "time" => $buyTime], $cart, $times, $order["id"]);
            if (!is_array($result) || !isset($result["code"]) || $result["code"] != "1") {
                // 开通失败，5分钟后重试
                \think\Db::name("order")->where("id", $order["id"])->update(["auto_create_at" => $time + 300]);
            } else {
                // 主机开通成功：按 docker_mode 自动/人工开通该主机名下的 Docker 容器
                if (function_exists('docker_provision_pending_for_order')) {
                    docker_provision_pending_for_order($order["id"]);
                }
            }
        }
    } catch (\Exception $e) {
        // 忽略异常，避免影响页面正常加载
    }
}

/**
 * HTTP GET 请求（简单封装）
 */
function http_get($url, $timeout = 3) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        $res = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        return $err ? false : $res;
    } else {
        $ctx = stream_context_create([
            'http' => ['timeout' => $timeout, 'user_agent' => 'Mozilla/5.0'],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        return @file_get_contents($url, false, $ctx);
    }
}

/**
 * 获取客户端真实 IP（兼容 CDN / 反向代理）
 * 优先读取 X-Forwarded-For、X-Real-IP、CF-Connecting-IP 等头部
 * @return string
 */
function get_client_ip() {
    $headers = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'REMOTE_ADDR'];
    foreach ($headers as $h) {
        if (!empty($_SERVER[$h])) {
            $ip = $_SERVER[$h];
            if ($h === 'HTTP_X_FORWARDED_FOR') {
                $ips = explode(',', $ip);
                $ip = trim($ips[0]);
            }
            $ip = trim($ip);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }
    }
    return '0.0.0.0';
}

/**
 * 根据 IP 获取所在地区（省份）
 * 多源 fallback：ip-api.com → pconline → taobao
 * @param string $ip IP 地址
 * @return string 中文省份名或“未知/本地”
 */
function get_ip_region($ip = '') {
    if (empty($ip)) {
        $ip = function_exists('get_client_ip') ? get_client_ip() : (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0');
    }
    // 本地/内网 IP
    if (in_array($ip, ['127.0.0.1', 'localhost', '::1']) || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return '本地';
    }

    $cnProvinces = ['北京','天津','上海','重庆','河北','山西','辽宁','吉林','黑龙江','江苏','浙江','安徽','福建','江西','山东','河南','湖北','湖南','广东','海南','四川','贵州','云南','陕西','甘肃','青海','台湾','内蒙古','广西','西藏','宁夏','新疆','香港','澳门'];

    $extractProvince = function($str) use ($cnProvinces) {
        foreach ($cnProvinces as $p) {
            if (mb_strpos($str, $p) !== false) return normalize_province_name($p);
        }
        return '';
    };

    // 源1: ip-api.com (HTTPS)
    $res1 = http_get('https://ip-api.com/json/' . urlencode($ip) . '?lang=zh-CN&fields=status,regionName', 3);
    if ($res1) {
        $json = json_decode($res1, true);
        if (!empty($json['status']) && $json['status'] === 'success' && !empty($json['regionName'])) {
            $p = $extractProvince($json['regionName']);
            return $p ?: normalize_province_name($json['regionName']);
        }
    }

    // 源2: pconline（国内稳定，GBK 编码）
    $res2 = http_get('https://whois.pconline.com.cn/ipJson.jsp?ip=' . urlencode($ip) . '&json=true', 3);
    if ($res2) {
        $res2 = @iconv('GBK', 'UTF-8//TRANSLIT', $res2);
        if ($res2) {
            $json2 = json_decode($res2, true);
            if (!empty($json2['pro'])) {
                $p = $extractProvince($json2['pro']);
                return $p ?: normalize_province_name($json2['pro']);
            }
        }
    }

    return '未知';
}

/**
 * 将常见短省份名标准化为 ECharts 中国地图使用的全称
 * @param string $name 省份短名（如“广东”）
 * @return string 全称（如“广东省”），无法识别则原样返回
 */
function normalize_province_name($name) {
    $map = [
        '北京' => '北京市', '天津' => '天津市', '上海' => '上海市', '重庆' => '重庆市',
        '河北' => '河北省', '山西' => '山西省', '辽宁' => '辽宁省', '吉林' => '吉林省',
        '黑龙江' => '黑龙江省', '江苏' => '江苏省', '浙江' => '浙江省', '安徽' => '安徽省',
        '福建' => '福建省', '江西' => '江西省', '山东' => '山东省', '河南' => '河南省',
        '湖北' => '湖北省', '湖南' => '湖南省', '广东' => '广东省', '海南' => '海南省',
        '四川' => '四川省', '贵州' => '贵州省', '云南' => '云南省', '陕西' => '陕西省',
        '甘肃' => '甘肃省', '青海' => '青海省', '台湾' => '台湾省',
        '内蒙古' => '内蒙古自治区', '广西' => '广西壮族自治区', '西藏' => '西藏自治区',
        '宁夏' => '宁夏回族自治区', '新疆' => '新疆维吾尔自治区',
        '香港' => '香港特别行政区', '澳门' => '澳门特别行政区',
    ];
    return isset($map[$name]) ? $map[$name] : $name;
}

/**
 * 获取后台待处理工单列表（state 1/2）
 * @param int $limit 返回条数
 * @return array
 */
function admin_pending_tickets($limit = 5) {
    try {
        $rows = \think\Db::name('ticket')
            ->where('state', 'in', ['1', '2'])
            ->order('id desc')
            ->limit(intval($limit))
            ->select();
        $users = [];
        if (!empty($rows)) {
            $userIds = array_unique(array_filter(array_column($rows, 'userid')));
            if (!empty($userIds)) {
                foreach (\think\Db::name('user')->where('id', 'in', $userIds)->column('name', 'id') as $uid => $uname) {
                    $users[$uid] = $uname;
                }
            }
        }
        foreach ($rows as &$row) {
            $row['username'] = isset($users[$row['userid']]) ? $users[$row['userid']] : ('用户#' . $row['userid']);
        }
        return $rows;
    } catch (\Exception $e) {
        return [];
    }
}

/**
 * 获取待审核实名认证列表（后台通知用）
 * @param int $limit 返回条数
 * @return array
 */
function admin_pending_realname($limit = 5) {
    try {
        $rows = \think\Db::name('user')
            ->where('realname_status', '3')
            ->order('id desc')
            ->limit(intval($limit))
            ->field('id, name, realname, realname_status')
            ->select();
        return $rows ?: [];
    } catch (\Exception $e) {
        return [];
    }
}

/**
 * 实名认证二要素核验（API 模式）
 * 对接花迹数据（huajidata.com）身份证二要素验证接口
 * 接口URL: https://api.huajidata.com/id_name/check
 * 签名方式: md5(appid + timestamp + appkey)
 * @param string $name 真实姓名
 * @param string $idcard 身份证号
 * @return array ['code'=>1|-1, 'msg'=>'', 'api_result'=>?]
 */
function realname_api_verify($name, $idcard, $mobile = '') {
    $logDir = defined('LOG_PATH') ? LOG_PATH : (PATH . '/runtime/log/');
    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
    try {
        @file_put_contents($logDir . 'realname_debug.log', date('Y-m-d H:i:s') . " realname_api_verify start name={$name}\n", FILE_APPEND);
        $web = web_config();
        $apiType = isset($web['realname_api_type']) ? $web['realname_api_type'] : '1';
        // 云市场接口地址（后台可配置；留空使用内置默认地址）
        $apiUrl = isset($web['realname_api_url']) ? trim((string) $web['realname_api_url']) : '';
        @file_put_contents($logDir . 'realname_debug.log', date('Y-m-d H:i:s') . " apiType={$apiType} apiUrl={$apiUrl}\n", FILE_APPEND);
        if ($apiType == '2') {
            $secretId = isset($web['realname_secret_id']) ? $web['realname_secret_id'] : '';
            $secretKey = isset($web['realname_secret_key']) ? $web['realname_secret_key'] : '';
            @file_put_contents($logDir . 'realname_debug.log', date('Y-m-d H:i:s') . " call cloudmarket secretId len=" . strlen($secretId) . " secretKey len=" . strlen($secretKey) . "\n", FILE_APPEND);
            return cloudmarket_realname_verify($name, $idcard, $secretId, $secretKey, $apiUrl);
        }
        if ($apiType == '3') {
            $secretId = isset($web['realname_secret_id']) ? $web['realname_secret_id'] : '';
            $secretKey = isset($web['realname_secret_key']) ? $web['realname_secret_key'] : '';
            // 手机三要素：只有明确传了手机号时才走三要素接口
            if (!empty($mobile)) {
                @file_put_contents($logDir . 'realname_debug.log', date('Y-m-d H:i:s') . " call phone3element secretId len=" . strlen($secretId) . " secretKey len=" . strlen($secretKey) . " mobile={$mobile}\n", FILE_APPEND);
                return phone3element_verify($name, $idcard, $mobile, $secretId, $secretKey, $apiUrl);
            }
            // 未传手机号（如身份证上传方式）：退回二要素核验，不要求手机号
            @file_put_contents($logDir . 'realname_debug.log', date('Y-m-d H:i:s') . " apiType=3 but no mobile, fallback to 2-element verify\n", FILE_APPEND);
            return cloudmarket_realname_verify($name, $idcard, $secretId, $secretKey, $apiUrl);
        }
        $appid = isset($web['realname_api_appid']) ? $web['realname_api_appid'] : '';
        $appkey = isset($web['realname_api_appkey']) ? $web['realname_api_appkey'] : '';
        return realname_api_verify_with_key($name, $idcard, $appid, $appkey);
    } catch (\Throwable $e) {
        @file_put_contents($logDir . 'realname_debug.log', date('Y-m-d H:i:s') . " realname_api_verify error: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n", FILE_APPEND);
        return ['code' => -1, 'msg' => '实名认证调用异常：' . $e->getMessage()];
    }
}

function realname_api_verify_with_key($name, $idcard, $appid, $appkey) {
    if (empty($name) || empty($idcard)) {
        return ['code' => -1, 'msg' => '姓名和身份证号不能为空'];
    }
    if (empty($appid) || empty($appkey)) {
        return ['code' => -1, 'config_error' => 1, 'msg' => '实名认证API未配置，请联系管理员'];
    }
    // 与花迹数据官方 PHP 示例保持一致：timestamp = time() + '000'
    $timestamp = time() . '000';
    $signStr = $appid . $timestamp . $appkey;
    $sign = md5($signStr);
    $url = 'https://api.huajidata.com/id_name/check';
    $bodyParams = [
        'appid' => $appid,
        'timestamp' => $timestamp,
        'sign' => $sign,
        'idcard' => $idcard,
        'name' => $name,
    ];
    $postData = http_build_query($bodyParams);

    $res = false;
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        $res = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($res === false || $err) {
            return ['code' => -1, 'config_error' => 1, 'msg' => 'API 接口请求失败：' . ($err ?: '网络错误')];
        }
    } else {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($postData),
                'content' => $postData,
                'timeout' => 60,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $res = @file_get_contents($url, false, $ctx);
        if ($res === false) {
            return ['code' => -1, 'config_error' => 1, 'msg' => 'API 接口请求失败：网络不可达'];
        }
    }
    $data = json_decode($res, true);
    if (!$data || !isset($data['code'])) {
        return ['code' => -1, 'config_error' => 1, 'msg' => 'API 返回数据异常'];
    }
    if ($data['code'] != 200) {
        $errMsg = isset($data['msg']) ? $data['msg'] : '未知错误';
        // 余额不足
        if ($data['code'] == 1003) {
            return ['code' => -1, 'config_error' => 1, 'msg' => '实名认证服务余额不足，请联系管理员充值'];
        }
        // 账号/签名错误（花迹数据返回 code=400，msg 含"错误码:4000"）
        if ($data['code'] == 400 || stripos($errMsg, '4000') !== false) {
            return ['code' => -1, 'config_error' => 1, 'msg' => '实名认证 API 账号或密钥错误（错误码：4000），请管理员检查后台 AppID / AppKey 是否正确'];
        }
        // 账号停用
        if ($data['code'] == 1002) {
            return ['code' => -1, 'config_error' => 1, 'msg' => '实名认证 API 账号已停用，请联系服务商'];
        }
        // 无接口权限
        if ($data['code'] == 1004) {
            return ['code' => -1, 'config_error' => 1, 'msg' => '实名认证 API 接口权限未开通，请联系服务商'];
        }
        // 接口已停用
        if ($data['code'] == 1005) {
            return ['code' => -1, 'config_error' => 1, 'msg' => '实名认证 API 接口已停用，请联系服务商'];
        }
        return ['code' => -1, 'msg' => '实名认证失败：' . $errMsg];
    }
    $result = isset($data['data']['result']) ? intval($data['data']['result']) : 0;
    if ($result === 1) {
        return ['code' => 1, 'msg' => '实名认证通过'];
    }
    if ($result === 2) {
        return ['code' => -1, 'msg' => '实名认证不通过：姓名与身份证号不匹配'];
    }
    if ($result === 3) {
        return ['code' => -1, 'msg' => '实名认证不通过：身份证信息库无记录'];
    }
    return ['code' => -1, 'msg' => '实名认证失败：' . (isset($data['data']['message']) ? $data['data']['message'] : '未知结果')];
}

function cloudmarket_realname_verify($name, $idcard, $secretId, $secretKey, $apiUrl = '') {
    $logDir = defined('LOG_PATH') ? LOG_PATH : (PATH . '/runtime/log/');
    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
    @file_put_contents($logDir . 'realname_debug.log', date('Y-m-d H:i:s') . " cloudmarket start name={$name} idcard={$idcard}\n", FILE_APPEND);
    if (empty($name) || empty($idcard)) {
        return ['code' => -1, 'msg' => '姓名和身份证号不能为空'];
    }
    if (empty($secretId) || empty($secretKey)) {
        return ['code' => -1, 'config_error' => 1, 'msg' => '云市场实名认证API未配置密钥，请联系管理员'];
    }
    if (!function_exists('hash_hmac')) {
        return ['code' => -1, 'config_error' => 1, 'msg' => '服务器 PHP 未启用 hash 扩展，无法调用云市场 API'];
    }
    if (!function_exists('curl_init')) {
        return ['code' => -1, 'config_error' => 1, 'msg' => '服务器未启用 cURL 扩展，无法调用云市场 API'];
    }
    // 接口地址：后台可自定义（云市场每个商品/账号的服务地址不同），留空或格式不对时用默认地址
    $customUrl = trim((string) $apiUrl);
    $defaultUrl = 'https://ap-shanghai.cloudmarket-apigw.com/service-hvx9h497/id_name/verify';
    $reqUrl = ($customUrl !== '' && preg_match('#^https?://#i', $customUrl)) ? $customUrl : $defaultUrl;
    try {
        $datetime = gmdate('D, d M Y H:i:s T');
        $signStr = sprintf("x-date: %s", $datetime);
        $sign = base64_encode(hash_hmac('sha1', $signStr, $secretKey, true));
        $auth = sprintf('{"id": "%s", "x-date": "%s" , "signature": "%s"}', $secretId, $datetime, $sign);

        $method = 'POST';
        $requestId = strtolower(md5(uniqid(mt_rand(), true)));
        $headers = array(
            'Authorization' => $auth,
            'request-id' => $requestId,
            'X-Requested-With' => 'XMLHttpRequest',
        );
        $queryParams = array();
        $bodyParams = array(
            'idcard' => $idcard,
            'name' => $name,
        );
        $sendData = http_build_query($bodyParams);
        $url = $reqUrl;
        if (count($queryParams) > 0) {
            $url .= '?' . http_build_query($queryParams);
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if (in_array($method, array('POST', 'PUT', 'PATCH'), true)) {
            $headers['Content-Type'] = 'application/x-www-form-urlencoded';
            curl_setopt($ch, CURLOPT_POSTFIELDS, $sendData);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_map(function ($v, $k) {
            return $k . ': ' . $v;
        }, array_values($headers), array_keys($headers)));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        $res = curl_exec($ch);
        $err = '';
        if (curl_errno($ch)) {
            $err = curl_error($ch);
        }
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        @file_put_contents($logDir . 'realname_cloudmarket.log', date('Y-m-d H:i:s') . "\nURL: {$url}\nrequest-id: {$requestId}\nAuth: {$auth}\nBody: {$sendData}\nHTTP: {$httpCode}\nError: {$err}\nResponse: " . ($res !== false ? $res : '(empty)') . "\n--------------------\n", FILE_APPEND);

        if ($err) {
            return ['code' => -1, 'config_error' => 1, 'msg' => 'API 接口请求失败：' . $err];
        }
        if ($res === false || $res === '') {
            return ['code' => -1, 'config_error' => 1, 'msg' => 'API 接口请求失败：网络错误（HTTP ' . $httpCode . '）'];
        }
        $data = json_decode($res, true);
        // 网关层错误：响应里没有业务 code 字段。绝大多数是「接口地址 / 密钥与该商品不匹配」，
        // 例如 {"message":"在配置中使用计划不存在","requestid":"..."}（HTTP 421）
        if (!$data || !isset($data['code'])) {
            $gwMsg = (is_array($data) && isset($data['message'])) ? (string) $data['message'] : '';
            $isPlanMiss = ($gwMsg !== '' && (strpos($gwMsg, '使用计划不存在') !== false
                    || strpos($gwMsg, '服务不存在') !== false
                    || strpos($gwMsg, '不存在的服务') !== false
                    || strpos($gwMsg, 'not exist') !== false))
                || $httpCode == 421;
            if ($isPlanMiss) {
                return [
                    'code' => -1,
                    'config_error' => 1,
                    'msg' => '云市场接口地址或密钥与该商品不匹配（网关返回：' . ($gwMsg !== '' ? $gwMsg : 'HTTP ' . $httpCode) . '）。'
                        . '请登录腾讯云 → 云市场 → 控制台 → 我的订单，打开你购买的「身份证二要素实名认证」商品，'
                        . '使用该商品自己提供的 SecretId / SecretKey 与接口地址（云市场每个 API 商品都会分配独立密钥，'
                        . '不能用 OCR 商品或其它商品的密钥），填到后台「系统设置 → 实名认证」，并确认商品未过期、未欠费。',
                ];
            }
            if ($httpCode == 401 || $httpCode == 403) {
                return [
                    'code' => -1,
                    'config_error' => 1,
                    'msg' => '云市场鉴权失败（HTTP ' . $httpCode . '）：SecretId / SecretKey 不正确或未与该商品绑定，'
                        . '请重新复制云市场控制台里该商品提供的密钥对。',
                ];
            }
            $raw = $res ? substr($res, 0, 500) : '(empty)';
            return [
                'code' => -1,
                'config_error' => 1,
                'msg' => '云市场接口返回异常（HTTP ' . $httpCode . '）' . ($gwMsg !== '' ? '：' . $gwMsg : '') . '，原始响应：' . $raw,
            ];
        }
        if ($data['code'] != 200) {
            $errMsg = isset($data['msg']) && $data['msg'] !== '' ? $data['msg'] : '未知错误';
            if ($data['code'] == 400) {
                return ['code' => -1, 'msg' => '实名认证请求参数错误：' . $errMsg];
            }
            if ($data['code'] == 500) {
                return ['code' => -1, 'config_error' => 1, 'msg' => '实名认证服务系统异常，请联系服务商'];
            }
            if ($data['code'] == 1000) {
                return ['code' => -1, 'msg' => '实名认证服务异常：' . $errMsg];
            }
            if ($data['code'] == 9999) {
                return ['code' => -1, 'config_error' => 1, 'msg' => '实名认证核验中心异常，请稍后重试'];
            }
            return ['code' => -1, 'msg' => '实名认证失败：' . $errMsg];
        }
        $result = isset($data['data']['result']) ? intval($data['data']['result']) : 0;
        $message = isset($data['data']['message']) ? $data['data']['message'] : '';
        if ($result === 1) {
            return ['code' => 1, 'msg' => '实名认证通过'];
        }
        if ($result === 2) {
            return ['code' => -1, 'msg' => '实名认证不通过：姓名与身份证号不匹配'];
        }
        if ($result === 3) {
            return ['code' => -1, 'msg' => '实名认证不通过：身份证信息库无记录'];
        }
        $failMsg = $message ?: '未知结果';
        return ['code' => -1, 'msg' => '实名认证失败：' . $failMsg];
    } catch (\Throwable $e) {
        @file_put_contents($logDir . 'realname_debug.log', date('Y-m-d H:i:s') . " cloudmarket throwable: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n", FILE_APPEND);
        return ['code' => -1, 'msg' => '实名认证调用异常：' . $e->getMessage()];
    }
}

/**
 * 手机三要素核验（腾讯云市场 API）
 * 接口: https://ap-beijing.cloudmarket-apigw.com/service-4epp7bin/phone3element
 * @param string $name 真实姓名
 * @param string $idcard 身份证号
 * @param string $mobile 手机号码
 * @param string $secretId 云市场 SecretId
 * @param string $secretKey 云市场 SecretKey
 * @return array ['code'=>1|-1, 'msg'=>'']
 */
function phone3element_verify($name, $idcard, $mobile, $secretId, $secretKey, $apiUrl = '') {
    $logDir = defined('LOG_PATH') ? LOG_PATH : (PATH . '/runtime/log/');
    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
    @file_put_contents($logDir . 'realname_debug.log', date('Y-m-d H:i:s') . " phone3element start name={$name} mobile={$mobile}\n", FILE_APPEND);
    if (empty($name) || empty($idcard) || empty($mobile)) {
        return ['code' => -1, 'msg' => '姓名、身份证号和手机号不能为空'];
    }
    if (empty($secretId) || empty($secretKey)) {
        return ['code' => -1, 'config_error' => 1, 'msg' => '云市场 API 未配置密钥，请联系管理员'];
    }
    if (!function_exists('hash_hmac')) {
        return ['code' => -1, 'config_error' => 1, 'msg' => '服务器 PHP 未启用 hash 扩展'];
    }
    if (!function_exists('curl_init')) {
        return ['code' => -1, 'config_error' => 1, 'msg' => '服务器未启用 cURL 扩展'];
    }
    // 接口地址：后台可自定义，留空或格式不对时用默认地址
    $customUrl = trim((string) $apiUrl);
    $defaultUrl = 'https://ap-beijing.cloudmarket-apigw.com/service-4epp7bin/phone3element';
    $reqUrl = ($customUrl !== '' && preg_match('#^https?://#i', $customUrl)) ? $customUrl : $defaultUrl;
    try {
        $datetime = gmdate('D, d M Y H:i:s T');
        $signStr = sprintf("x-date: %s", $datetime);
        $sign = base64_encode(hash_hmac('sha1', $signStr, $secretKey, true));
        $auth = sprintf('{"id": "%s", "x-date": "%s" , "signature": "%s"}', $secretId, $datetime, $sign);

        $requestId = strtolower(md5(uniqid(mt_rand(), true)));
        $headers = array(
            'Authorization' => $auth,
            'request-id' => $requestId,
            'X-Requested-With' => 'XMLHttpRequest',
            'Content-Type' => 'application/x-www-form-urlencoded',
        );
        $bodyParams = array(
            'idCard' => $idcard,
            'mobile' => $mobile,
            'realName' => $name,
        );
        $sendData = http_build_query($bodyParams);
        $url = $reqUrl;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $sendData);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_map(function ($v, $k) {
            return $k . ': ' . $v;
        }, array_values($headers), array_keys($headers)));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        $res = curl_exec($ch);
        $err = '';
        if (curl_errno($ch)) {
            $err = curl_error($ch);
        }
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        @file_put_contents($logDir . 'realname_phone3e.log', date('Y-m-d H:i:s') . "\nURL: {$url}\nrequest-id: {$requestId}\nAuth: {$auth}\nBody: {$sendData}\nHTTP: {$httpCode}\nError: {$err}\nResponse: " . ($res !== false ? $res : '(empty)') . "\n--------------------\n", FILE_APPEND);

        if ($err) {
            return ['code' => -1, 'config_error' => 1, 'msg' => 'API 请求失败：' . $err];
        }
        if ($res === false || $res === '') {
            return ['code' => -1, 'config_error' => 1, 'msg' => 'API 请求失败：网络错误（HTTP ' . $httpCode . '）'];
        }
        $data = json_decode($res, true);
        if (!$data) {
            $raw = $res ? substr($res, 0, 500) : '(empty)';
            return ['code' => -1, 'config_error' => 1, 'msg' => 'API 返回数据异常（HTTP ' . $httpCode . '），原始响应：' . $raw];
        }
        if (!isset($data['error_code'])) {
            // 网关层错误：多为接口地址 / 密钥与商品不匹配（如 HTTP 421「在配置中使用计划不存在」）
            $gwMsg = isset($data['message']) ? (string) $data['message'] : (isset($data['reason']) ? (string) $data['reason'] : '');
            $isPlanMiss = ($gwMsg !== '' && (strpos($gwMsg, '使用计划不存在') !== false
                    || strpos($gwMsg, '服务不存在') !== false
                    || strpos($gwMsg, '不存在的服务') !== false
                    || strpos($gwMsg, 'not exist') !== false))
                || $httpCode == 421;
            if ($isPlanMiss) {
                return [
                    'code' => -1,
                    'config_error' => 1,
                    'msg' => '云市场接口地址或密钥与该商品不匹配（网关返回：' . ($gwMsg !== '' ? $gwMsg : 'HTTP ' . $httpCode) . '）。'
                        . '请登录腾讯云 → 云市场 → 控制台 → 我的订单，打开你购买的「手机三要素」商品，'
                        . '使用该商品自己提供的 SecretId / SecretKey 与接口地址（云市场每个 API 商品都会分配独立密钥，'
                        . '不能用 OCR 商品或其它商品的密钥），填到后台「系统设置 → 实名认证」，并确认商品未过期、未欠费。',
                ];
            }
            return ['code' => -1, 'config_error' => 1, 'msg' => 'API 返回格式异常（HTTP ' . $httpCode . '）：' . ($gwMsg !== '' ? $gwMsg : json_encode($data))];
        }
        if ($data['error_code'] != 0) {
            $reason = isset($data['reason']) ? $data['reason'] : '未知错误';
            return ['code' => -1, 'msg' => '手机三要素核验失败：' . $reason . ' (error_code=' . $data['error_code'] . ')'];
        }
        $result = isset($data['result']['VerificationResult']) ? $data['result']['VerificationResult'] : '';
        if ($result === '1') {
            return ['code' => 1, 'msg' => '实名认证通过'];
        }
        if ($result === '-1') {
            return ['code' => -1, 'msg' => '实名认证不通过：手机号、姓名、身份证号不匹配'];
        }
        if ($result === '0') {
            return ['code' => -1, 'msg' => '实名认证不通过：运营商系统中无此手机号记录'];
        }
        return ['code' => -1, 'msg' => '实名认证失败：未知结果'];
    } catch (\Throwable $e) {
        @file_put_contents($logDir . 'realname_debug.log', date('Y-m-d H:i:s') . " phone3element throwable: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n", FILE_APPEND);
        return ['code' => -1, 'msg' => '实名认证调用异常：' . $e->getMessage()];
    }
}

/**
 * 检测当前登录用户是否通过实名认证，未通过时返回限制提示
 * @param string $type 限制类型：pay(充值) / buy(购买主机) / ticket(提交工单) / renew(续费) / transfer(转让主机)
 * @return array ['code'=>1] 表示通过，['code'=>-2, 'msg'=>'...'] 表示未实名被限制
 */
function check_realname_limit($type = '') {
    $web = web_config();
    $mode = isset($web['realname_mode']) ? $web['realname_mode'] : '0';
    // 未开启实名认证时不限制
    if ($mode == '0') {
        return ['code' => 1];
    }
    // 检查后台是否对该功能开启限制
    $key = 'realname_limit_' . $type;
    if (!isset($web[$key]) || $web[$key] != '1') {
        return ['code' => 1];
    }
    $userId = session('userid');
    if (!$userId) {
        return ['code' => -1, 'msg' => '请先登录'];
    }
    $user = \think\Db::name('user')->where('id', $userId)->find();
    if (!$user) {
        return ['code' => -1, 'msg' => '用户不存在'];
    }
    // 已认证通过
    if (isset($user['realname_status']) && $user['realname_status'] == '1') {
        return ['code' => 1];
    }
    $typeText = [
        'pay' => '充值',
        'buy' => '购买主机',
        'ticket' => '提交工单',
        'renew' => '续费主机',
        'transfer' => '进入转让市场',
    ];
    $text = isset($typeText[$type]) ? $typeText[$type] : '此操作';
    // 待审核与未认证统一使用 -2，便于前端弹出实名认证提示框
    if (isset($user['realname_status']) && $user['realname_status'] == '3') {
        return ['code' => -2, 'msg' => '实名认证正在审核中，暂时无法进行' . $text];
    }
    return ['code' => -2, 'msg' => '请先完成实名认证后再进行' . $text];
}

/**
 * Sanitize email body content
 */
function sanitize_email_body($body) {
    // Allow only safe HTML tags
    $allowed = '<br><br/><p><b><strong><i><em><span><div><a><hr><hr/><ul><ol><li><table><tr><td><th><thead><tbody><h1><h2><h3><h4><h5><h6><img>';
    return strip_tags($body, $allowed);
}

/**
 * 检测是否为临时/一次性邮箱（10分钟邮箱等）
 * @param string $email 邮箱地址
 * @return bool true=是临时邮箱
 */
function is_disposable_email($email) {
    $domain = strtolower(substr(strrchr($email, '@'), 1));
    if (!$domain) return false;
    $disposableDomains = [
        '0-mail.com', '0815.ru', '0wnd.net', '0wnd.org', '10minutemail.com',
        '10minutemail.info', '10minutemail.net', '10minutemail.org', '20minutemail.com',
        '20minutemail.it', '30minutemail.com', '33mail.com', '3d-painting.com',
        '4warding.com', '4warding.net', '4warding.org', '60minutemail.com',
        '675hosting.com', '675hosting.net', '675hosting.org', '6url.com',
        '75hosting.com', '75hosting.net', '75hosting.org', '7tags.com',
        '9ox.net', 'a-bc.net', 'abyssmail.com', 'afrobacon.com',
        'ajaxapp.net', 'amilegit.com', 'amiri.net', 'amiriindustries.com',
        'anonbox.net', 'anonymbox.com', 'antichef.com', 'antichef.net',
        'antireg.ru', 'antispam.de', 'antispammail.de', 'armyspy.com',
        'artman-conception.com', 'azmeil.tk', 'baxomale.ht.cx', 'beddly.com',
        'bigstring.com', 'binkmail.com', 'bio-muesli.info', 'bobmail.info',
        'bodhi.lawlita.com', 'bofthew.com', 'bootybay.de', 'boun.cr',
        'bouncr.com', 'breakthru.com', 'brefmail.com', 'bsnow.net',
        'bugmenever.com', 'bumpymail.com', 'bund.us', 'burstmail.info',
        'buymoreplays.com', 'byom.de', 'c2.hu', 'card.zp.ua',
        'casualdx.com', 'cek.pm', 'chammy.info', 'cheatmail.de',
        'chogmail.com', 'choicemail1.com', 'clixser.com', 'clrmail.com',
        'cmail.com', 'cmail.net', 'cmail.org', 'coldemail.info',
        'cool.fr.nf', 'courriel.fr.nf', 'courrieltemporaire.com', 'crapmail.org',
        'cust.in', 'cuvox.de', 'd3p.dk', 'dacoolest.com',
        'dandikmail.com', 'dayrep.com', 'dbunker.com', 'dcemail.com',
        'deadaddress.com', 'deadspam.com', 'delikkt.de', 'despam.it',
        'devnullmail.com', 'dfgh.net', 'digitalsanctuary.com', 'dingbone.com',
        'discard.email', 'discardmail.com', 'discardmail.de', 'disposableaddress.com',
        'disposableemailaddresses.com', 'disposableinbox.com', 'dispose.it', 'dispostable.com',
        'dmarc.ro', 'dodgeit.com', 'dodgit.com', 'dodgit.org',
        'donemail.ru', 'dontreg.com', 'dontsendmespam.de', 'dotmsg.com',
        'drdrb.com', 'drdrb.net', 'dropmail.me', 'dt.com',
        'duam.net', 'dudmail.com', 'dump-email.info', 'dumpandjunk.com',
        'dumpmail.de', 'dumpyemail.com', 'e-mail.com', 'e-mail.org',
        'e4ward.com', 'easytrashmail.com', 'einmalmail.de', 'einrot.com',
        'eintagsmail.de', 'email60.com', 'emailfake.com', 'emailgo.de',
        'emailias.com', 'emailigo.de', 'emaillime.com', 'emailmiser.com',
        'emailna.co', 'emailproxsy.com', 'emails.ga', 'emailsensei.com',
        'emailtemporanea.com', 'emailtemporanea.net', 'emailtemporar.ro', 'emailtemporario.com.br',
        'emailthe.net', 'emailtmp.com', 'emailto.de', 'emailwarden.com',
        'emailx.at.hm', 'emailxfer.com', 'emz.net', 'enterto.com',
        'ephemail.net', 'ero-tube.org', 'etranquil.com', 'etranquil.net',
        'etranquil.org', 'evopo.com', 'explodemail.com', 'express.net.ua',
        'eyepaste.com', 'fakeinbox.com', 'fakeinformation.com', 'fakemail.fr',
        'fakemailz.com', 'fammix.com', 'fastacura.com', 'fastchevy.com',
        'fastchrysler.com', 'fasternet.biz', 'fastkawasaki.com', 'fastmazda.com',
        'fastmitsubishi.com', 'fastnissan.com', 'fastsubaru.com', 'fastsuzuki.com',
        'fasttoyota.com', 'fastyamaha.com', 'fatflap.com', 'fdfdsfds.com',
        'fightallspam.com', 'fiifke.de', 'filzmail.com', 'fivemail.de',
        'fixmail.tk', 'fizmail.com', 'fleckens.hu', 'flemail.ru',
        'flyspam.com', 'footard.com', 'forgetmail.com', 'fr33mail.info',
        'frapmail.com', 'free-email-addresses.info', 'freemail.ms', 'freundin.ru',
        'friendlymail.co.uk', 'front14.org', 'fuckingduh.com', 'fudgerub.com',
        'fux0ringduh.com', 'fw2.me', 'fw6m0bd.com', 'fyii.de',
        'garliclife.com', 'gehensiemirnichtaufdenkeks.de', 'gelitik.in', 'get-mail.info',
        'get1mail.com', 'get2mail.fr', 'getairmail.com', 'getmails.eu',
        'getonemail.com', 'getonemail.net', 'ghosttexter.de', 'giantmail.de',
        'girlsundertheinfluence.com', 'gishpuppy.com', 'gmial.com', 'goemailgo.com',
        'gorillaswithdirtyarmpits.com', 'gotmail.com', 'gotmail.net', 'gotmail.org',
        'gotti.otherinbox.com', 'gowikibooks.com', 'gowikicampus.com', 'gowikicars.com',
        'gowikifilms.com', 'gowikigames.com', 'gowikimusic.com', 'gowikinetwork.com',
        'gowikitravel.com', 'gowikitv.com', 'grandmamail.com', 'grandmasmail.com',
        'great-host.in', 'greensloth.com', 'grr.la', 'gsrv.co.uk',
        'guerrillamail.biz', 'guerrillamail.com', 'guerrillamail.de', 'guerrillamail.info',
        'guerrillamail.net', 'guerrillamail.org', 'guerrillamailblock.com', 'gustr.com',
        'h.mintemail.com', 'h8s.org', 'hacccc.com', 'haltospam.com',
        'harakirimail.com', 'hartbot.de', 'hatespam.org', 'herp.in',
        'hidemail.de', 'hidzz.com', 'hmamail.com', 'hochsitze.com',
        'hopemail.biz', 'hotpop.com', 'hulapla.de', 'iaoss.com',
        'ieatspam.eu', 'ieatspam.info', 'ieh-mail.de', 'ihateyoualot.info',
        'iheartspam.org', 'imails.info', 'imgof.com', 'imstations.com',
        'inbax.tk', 'inbox.si', 'incognitomail.com', 'incognitomail.net',
        'incognitomail.org', 'insorg-mail.info', 'instant-mail.de', 'ip6.li',
        'irish2me.com', 'iwi.net', 'jetable.com', 'jetable.fr.nf',
        'jetable.net', 'jetable.org', 'jnxjn.com', 'jourrapide.com',
        'jsrsolutions.com', 'junk1e.com', 'kasmail.com', 'kaspop.com',
        'keepmymail.com', 'killmail.com', 'killmail.net', 'kimsdisk.com',
        'kingsq.ga', 'kir.ch.tc', 'klassmaster.com', 'klassmaster.net',
        'klzlk.com', 'koszmail.pl', 'kulturbetrieb.info', 'kurzepost.de',
        'l33r.eu', 'lackmail.net', 'lackmail.ru', 'lags.us',
        'lawlita.com', 'lazyinbox.com', 'letthemeatspam.com', 'lhsdv.com',
        'lifebyfood.com', 'link2mail.net', 'litedrop.com', 'loadby.us',
        'login-email.ml', 'lol.ovpn.to', 'lookugly.com', 'lopl.co.cc',
        'lortemail.dk', 'lovemeet.com', 'lr78.com', 'lroid.com',
        'lukop.dk', 'm21.cc', 'm4ilweb.info', 'maboard.com',
        'mail-filter.com', 'mail-temporaire.fr', 'mail.by', 'mail.mezimages.net',
        'mail.zp.ua', 'mail114.net', 'mail1a.de', 'mail21.cc',
        'mail2rss.org', 'mail333.com', 'mail4trash.com', 'mailbidon.com',
        'mailbiscuit.com', 'mailcatch.com', 'mailde.de', 'mailde.info',
        'maildrop.cc', 'maildx.com', 'maileater.com', 'mailed.ro',
        'maileimer.de', 'mailexpire.com', 'mailf5.com', 'mailfa.tk',
        'mailfall.com', 'mailforspam.com', 'mailfree.ga', 'mailfree.gq',
        'mailfree.ml', 'mailfs.com', 'mailguard.me', 'mailgutter.com',
        'mailhazard.com', 'mailhazard.us', 'mailhz.me', 'mailimate.com',
        'mailin8r.com', 'mailinater.com', 'mailinator.com', 'mailinator.net',
        'mailinator.org', 'mailinator2.com', 'mailincubator.com', 'mailismagic.com',
        'mailita.tk', 'mailjunk.com', 'mailmate.com', 'mailme.ir',
        'mailme.lv', 'mailmetrash.com', 'mailmoat.com', 'mailms.com',
        'mailnator.com', 'mailnesia.com', 'mailnull.com', 'mailorg.org',
        'mailpick.biz', 'mailproxsy.com', 'mailquack.com', 'mailrock.biz',
        'mailscrap.com', 'mailseal.de', 'mailshell.com', 'mailshiv.com',
        'mailsiphon.com', 'mailslapping.com', 'mailslite.com', 'mailtemp.info',
        'mailtome.de', 'mailtothis.com', 'mailtrash.net', 'mailtv.net',
        'mailtv.tv', 'mailzi.ru', 'mailzilla.com', 'mailzilla.org',
        'makemetheking.com', 'manifestgenerator.com', 'manybrain.com', 'mbx.cc',
        'mciek.com', 'mega.zik.dj', 'meinspamschutz.de', 'meltmail.com',
        'messagebeamer.de', 'mezimages.net', 'mfsa.ru', 'mierdamail.com',
        'migmail.net', 'migmail.pl', 'migumail.com', 'mintemail.com',
        'mjukglass.nu', 'moakt.com', 'mobi.web.id', 'mobileninja.co.uk',
        'moburl.com', 'mohmal.com', 'moncourrier.fr.nf', 'monemail.fr.nf',
        'monmail.fr.nf', 'monumentmail.com', 'mor19.uu.gl', 'msa.minsmail.com',
        'mt2009.com', 'mt2014.com', 'mx0.wwwnew.eu', 'my10minutemail.com',
        'mycard.net.ua', 'mycleaninbox.net', 'myemailboxy.com', 'mymail-in.net',
        'mymailoasis.com', 'mynetstore.de', 'mypacks.net', 'mypartyclip.de',
        'myphantomemail.com', 'mysamp.de', 'myspaceinc.com', 'myspaceinc.net',
        'myspaceinc.org', 'myspacepimpedup.com', 'myspamless.com', 'mytemp.email',
        'mytempemail.com', 'mytempmail.com', 'n1nja.org', 'n8.gs',
        'nepwk.com', 'nervmich.net', 'nervtmich.net', 'netmails.com',
        'netmails.net', 'netzidiot.de', 'neverbox.com', 'nice-4u.com',
        'nincsmail.com', 'nincsmail.hu', 'nneko.com', 'no-spam.ws',
        'noblepioneer.com', 'nobulk.com', 'noclickemail.com', 'nogmailspam.info',
        'nomail.pw', 'nomail2me.com', 'nomorespamemails.com', 'nonspam.eu',
        'nonspammer.de', 'noref.in', 'nospam.ze.tc', 'nospam4.us',
        'nospamfor.us', 'nospamthanks.info', 'notmailinator.com', 'nowmymail.com',
        'nurfuerspam.de', 'nus.edu.sg', 'nwldx.com', 'objectmail.com',
        'obobbo.com', 'odaymail.com', 'odnorazovoe.ru', 'one-time.email',
        'oneoffemail.com', 'oneoffmail.com', 'onewaymail.com', 'onlatedotcom.info',
        'online.ms', 'oopi.org', 'opayq.com', 'ordinaryamerican.net',
        'otherinbox.com', 'ourklips.com', 'outlawspam.com', 'ovpn.to',
        'owlpic.com', 'pancakemail.com', 'paplease.com', 'pcusers.otherinbox.com',
        'pepbot.com', 'pfui.ru', 'pimpedupmyspace.com', 'pjjkp.com',
        'plexolan.de', 'poczta.onet.pl', 'politikerclub.de', 'pooae.com',
        'pookmail.com', 'privacy.net', 'privy-mail.com', 'privymail.de',
        'proxymail.eu', 'prtnx.com', 'punkass.com', 'putthisinyourspamdatabase.com',
        'pwrby.com', 'qasti.com', 'quickinbox.com', 'quickmail.nl',
        'rcpt.at', 'reallymymail.com', 'realtyalerts.ca', 'recode.me',
        'recursor.net', 'regbypass.com', 'regbypass.comsafe-mail.net', 'rejectmail.com',
        'rklips.com', 'rmqkr.net', 'royal.net', 'rppkn.com',
        'rtrtr.com', 's0ny.net', 'safe-mail.net', 'safersignup.de',
        'safetymail.info', 'safetypost.de', 'sandelf.de', 'saynotospams.com',
        'schafmail.de', 'schrott-email.de', 'secretemail.de', 'secure-mail.biz',
        'senseless-entertainment.com', 'services391.com', 'sharklasers.com', 'shieldemail.com',
        'shiftmail.com', 'shitmail.me', 'shitmail.org', 'shitware.nl',
        'shmeriously.com', 'shortmail.net', 'shotmail.ru', 'showslow.de',
        'sibmail.com', 'sinnlos-mail.de', 'siteposter.net', 'skeefmail.com',
        'slapsfromlastnight.com', 'slaskpost.se', 'slipry.net', 'slopsbox.com',
        'slushmail.com', 'smaakt.naar.gravel', 'smashmail.de', 'smellfear.com',
        'snakemail.com', 'sneakemail.com', 'sneakmail.de', 'snkmail.com',
        'sofimail.com', 'sofort-mail.de', 'softpls.asia', 'sogetthis.com',
        'soodonims.com', 'spam.la', 'spam.su', 'spam4.me',
        'spamail.de', 'spamarrest.com', 'spamavert.com', 'spambob.com',
        'spambob.net', 'spambob.org', 'spambog.com', 'spambog.de',
        'spambog.net', 'spambog.ru', 'spambox.info', 'spambox.irishspringrealty.com',
        'spambox.us', 'spamcannon.com', 'spamcannon.net', 'spamcero.com',
        'spamcon.org', 'spamcorptastic.com', 'spamcowboy.com', 'spamcowboy.net',
        'spamcowboy.org', 'spamday.com', 'spamex.com', 'spamfighter.cf',
        'spamfighter.ga', 'spamfighter.gq', 'spamfighter.ml', 'spamfighter.tk',
        'spamfree.eu', 'spamfree24.com', 'spamfree24.de', 'spamfree24.eu',
        'spamfree24.info', 'spamfree24.net', 'spamfree24.org', 'spamgoose.xyz',
        'spamgourmet.com', 'spamgourmet.net', 'spamgourmet.org', 'spamherelots.com',
        'spamhole.com', 'spamify.com', 'spaminator.de', 'spamkill.info',
        'spaml.com', 'spaml.de', 'spamlot.net', 'spammotel.com',
        'spamobox.com', 'spamoff.de', 'spamsalad.in', 'spamslicer.com',
        'spamspot.com', 'spamstack.net', 'spamthis.co.uk', 'spamthisplease.com',
        'spamtrail.com', 'spamtrap.ro', 'spamtroll.net', 'speed.1s.fr',
        'spikio.com', 'spoofmail.de', 'squizzy.de', 'ssoia.com',
        'startkeys.com', 'stinkefinger.net', 'stop-my-spam.com', 'stuffmail.de',
        'super-auswahl.de', 'supergreatmail.com', 'supermailer.jp', 'superrito.com',
        'superstachel.de', 'suremail.info', 'svk.jp', 'sweetxxx.de',
        't.odour.fr', 'talkinator.com', 'tapchicuoihoi.com', 'teewars.org',
        'teleworm.com', 'teleworm.us', 'temp-mail.com', 'temp-mail.de',
        'temp-mail.org', 'temp-mail.ru', 'temp.emeraldwebmail.com', 'temp15mail.com',
        'tempail.com', 'tempalias.com', 'tempe-mail.com', 'tempemail.biz',
        'tempemail.co.za', 'tempemail.com', 'tempemail.net', 'tempinbox.co.uk',
        'tempinbox.com', 'tempmail.co', 'tempmail.de', 'tempmail.eu',
        'tempmail.it', 'tempmail.org', 'tempmail.us', 'tempmail.ws',
        'tempmail2.com', 'tempmaildemo.com', 'tempmailer.com', 'tempmailer.de',
        'tempomail.fr', 'temporarily.de', 'temporarioemail.com.br', 'temporaryemail.net',
        'temporaryemail.us', 'temporaryforwarding.com', 'temporaryinbox.com', 'temporarymailaddress.com',
        'tempr.email', 'tempsky.com', 'tempthe.net', 'tempymail.com',
        'thanksnospam.info', 'thankyou2010.com', 'thc.st', 'thelimestones.com',
        'thisisnotmyrealemail.com', 'thismail.net', 'throwawayemailaddress.com', 'tilien.com',
        'tittbit.in', 'tmail.ws', 'tmailinator.com', 'toiea.com',
        'toomail.biz', 'topranklist.de', 'tradermail.info', 'trash-amil.com',
        'trash-mail.at', 'trash-mail.com', 'trash-mail.de', 'trash2009.com',
        'trashemail.de', 'trashmail.at', 'trashmail.com', 'trashmail.de',
        'trashmail.me', 'trashmail.net', 'trashmail.org', 'trashmail.ws',
        'trashmailer.com', 'trashymail.com', 'trashymail.net', 'trbvm.com',
        'trialmail.de', 'trillianpro.com', 'tryalert.com', 'turual.com',
        'twinmail.de', 'tyldd.com', 'uggsrock.com', 'umail.net',
        'upliftnow.com', 'uplipht.com', 'uroid.com', 'us.af',
        'venompen.com', 'veryrealemail.com', 'vidchart.com', 'viditag.com',
        'viewcastmedia.com', 'viewcastmedia.net', 'viewcastmedia.org', 'viralplays.com',
        'vomoto.com', 'vpn.st', 'vps30.com', 'vsimcard.com',
        'vubby.com', 'wasteland.rfc822.org', 'webemail.me', 'webm4il.info',
        'webuser.in', 'wee.my', 'weg-werf-email.de', 'wegwerf-email-addressen.de',
        'wegwerf-email-adressen.de', 'wegwerf-email.at', 'wegwerf-email.de', 'wegwerf-email.net',
        'wegwerf-emails.de', 'wegwerfadresse.de', 'wegwerfmail.com', 'wegwerfmail.de',
        'wegwerfmail.info', 'wegwerfmail.net', 'wegwerfmail.org', 'wetrainbayarea.com',
        'wetrainbayarea.org', 'wh4f.org', 'whatiaas.com', 'whatpaas.com',
        'whatsaas.com', 'whopy.com', 'whtjddn.33mail.com', 'whyspam.me',
        'wilemail.com', 'willhackforfood.biz', 'willselfdestruct.com', 'winemaven.info',
        'wronghead.com', 'wuzup.net', 'wuzupmail.net', 'x.ip6.li',
        'xagloo.com', 'xemaps.com', 'xents.com', 'xmaily.com',
        'xoxy.net', 'yep.it', 'yogamaven.com', 'yopmail.com',
        'yopmail.fr', 'yopmail.net', 'yopmail.org', 'yopmail.pp.ua',
        'yourtube.ml', 'ypmail.webarnak.fr.eu.org', 'yuurok.com', 'z1p.biz',
        'za.com', 'zehnminuten.de', 'zehnminutenmail.de', 'zippymail.info',
        'zoaxe.com', 'zoemail.com', 'zoemail.net', 'zoemail.org',
        'zomg.info',
    ];
    return in_array($domain, $disposableDomains);
}

/**
 * 记录访客访问日志
 * 仅记录一次（基于 session 标记），避免频繁写入
 */
function log_visitor() {
	if (!empty($_SESSION['visitor_logged'])) {
		return;
	}
	try {
		$tableName = \think\Db::name('visitor_log')->getTable();
		\think\Db::execute("CREATE TABLE IF NOT EXISTS `{$tableName}` (
			`id` int(11) NOT NULL AUTO_INCREMENT,
			`ip` varchar(50) DEFAULT '' COMMENT '访问IP',
			`url` varchar(500) DEFAULT '' COMMENT '访问URL',
			`referer` varchar(500) DEFAULT '' COMMENT '来源页面',
			`user_agent` varchar(500) DEFAULT '' COMMENT '浏览器UA',
			`visit_time` int(11) DEFAULT 0 COMMENT '访问时间戳',
			`date` varchar(10) DEFAULT '' COMMENT '日期',
			`hour` int(2) DEFAULT 0 COMMENT '小时0-23',
			PRIMARY KEY (`id`),
			KEY `idx_date` (`date`),
			KEY `idx_hour` (`date`,`hour`),
			KEY `idx_visit_time` (`visit_time`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8");
		$now = time();
		$ip = function_exists('get_client_ip') ? get_client_ip() : (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown');
		$url = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
		$referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
		$ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
		$ua = mb_substr($ua, 0, 500);
		\think\Db::name('visitor_log')->insert([
			'ip'         => $ip,
			'url'        => mb_substr($url, 0, 500),
			'referer'    => mb_substr($referer, 0, 500),
			'user_agent' => $ua,
			'visit_time' => $now,
			'date'       => date('Y-m-d', $now),
			'hour'       => (int)date('H', $now),
		]);
		$_SESSION['visitor_logged'] = true;
	} catch (\Exception $e) {
		// 记录失败不影响正常访问
	}
}

/**
 * 确保管理操作日志表存在
 */
function ensure_admin_op_log_table() {
	try {
		$prefix = \think\Db::getConfig('prefix');
		$prefix = $prefix ?: '';
		$table = "{$prefix}admin_op_log";
		$exists = \think\Db::query("SHOW TABLES LIKE '{$table}'");
		if (empty($exists)) {
			\think\Db::execute("CREATE TABLE IF NOT EXISTS `{$table}` (
				`id` int(11) NOT NULL AUTO_INCREMENT,
				`admin_id` int(11) NOT NULL DEFAULT 0 COMMENT '操作管理员ID',
				`admin_name` varchar(50) NOT NULL DEFAULT '' COMMENT '操作管理员用户名',
				`action` varchar(50) NOT NULL DEFAULT '' COMMENT '操作类型',
				`target` varchar(200) DEFAULT '' COMMENT '操作目标描述',
				`ip` varchar(50) DEFAULT '' COMMENT '操作IP',
				`detail` text COMMENT '操作详情JSON',
				`create_time` int(11) NOT NULL DEFAULT 0,
				PRIMARY KEY (`id`),
				KEY `idx_admin_id` (`admin_id`),
				KEY `idx_action` (`action`),
				KEY `idx_create_time` (`create_time`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
		}
	} catch (\Exception $e) {
		// 忽略
	}
}

/**
 * 记录管理员操作日志
 * @param string $action 操作类型
 * @param string $target 操作目标描述
 * @param array|string $detail 操作详情
 */
function admin_op_log($action, $target = '', $detail = '') {
	try {
		$adminId = session('adminid') ?: 0;
		$adminName = '';
		$roleId = 0;
		$roleName = '';
		if ($adminId) {
			$admin = \think\Db::name('admin')->where('id', $adminId)->field('user,role_id')->find();
			$adminName = $admin ? $admin['user'] : '';
			$roleId = $admin ? $admin['role_id'] : 0;
			if ($roleId) {
				$role = \think\Db::name('admin_role')->where('id', $roleId)->field('name')->find();
				$roleName = $role ? $role['name'] : '';
			}
		}
		// 自动捕获详细信息
		$autoDetail = [
			'role'       => $roleName ?: ($adminId ? '超级管理员' : ''),
			'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : '',
			'url'        => isset($_SERVER['REQUEST_URI']) ? mb_substr($_SERVER['REQUEST_URI'], 0, 255) : '',
			'method'     => isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '',
			'referer'    => isset($_SERVER['HTTP_REFERER']) ? mb_substr($_SERVER['HTTP_REFERER'], 0, 255) : '',
			'time'       => date('Y-m-d H:i:s'),
		];
		if (is_array($detail)) {
			$detail = array_merge($autoDetail, $detail);
		} elseif (!empty($detail)) {
			$detail = array_merge($autoDetail, ['extra' => $detail]);
		} else {
			$detail = $autoDetail;
		}
		$detailJson = json_encode($detail, JSON_UNESCAPED_UNICODE);
		\think\Db::name('admin_op_log')->insert([
			'admin_id'    => $adminId,
			'admin_name'  => $adminName,
			'action'      => $action,
			'target'      => mb_substr($target, 0, 200),
			'ip'          => function_exists('get_client_ip') ? get_client_ip() : '',
			'detail'      => $detailJson,
			'create_time' => time(),
		]);

		// ── 同步写入「系统重要记录」中心（后台修改 / 功能配置）──
		if (function_exists('sys_record')) {
			// 配置类操作单独归类，其余归为后台修改
			$cfgKeys = ['set_', 'config', 'api_', 'pay', 'captcha', 'sms', 'mail', 'oss', 'template', 'logo', 'geetest', 'bt_server', 'domain_api'];
			$isCfg   = false;
			foreach ($cfgKeys as $k) {
				if (stripos($action, $k) !== false) { $isCfg = true; break; }
			}
			$category = $isCfg ? 'config' : 'admin_op';
			$opDetail = [
				'操作类型' => $action,
				'操作者'   => $adminName ?: ($adminId ? '管理员#' . $adminId : '系统'),
				'角色'     => $roleName ?: ($adminId ? '超级管理员' : ''),
				'时间'     => date('Y-m-d H:i:s'),
			];
			if (is_array($detail)) {
				foreach ($detail as $dk => $dv) {
					if (in_array($dk, ['url', 'method', 'referer', 'user_agent', 'time'], true)) continue;
					if (is_array($dv) || is_object($dv)) $dv = json_encode($dv, JSON_UNESCAPED_UNICODE);
					$opDetail[$dk] = $dv;
				}
			}
			sys_record($category, mb_substr((string)$target, 0, 200) ?: $action, $opDetail, [
				'operator_type' => 'admin',
				'operator_id'   => $adminId,
				'operator_name' => $adminName,
				'level'         => 2,
				'dedup'         => 3,
			]);
		}
	} catch (\Exception $e) {
		// 日志记录失败不影响正常操作
	}
}

/**
 * 判断是否为手机端访问
 * @return bool
 */
function is_mobile() {
    if (isset($_SERVER['HTTP_USER_AGENT'])) {
        $ua = $_SERVER['HTTP_USER_AGENT'];
        $mobileKeywords = ['Mobile', 'Android', 'iPhone', 'iPad', 'iPod', 'webOS', 'BlackBerry', 'Windows Phone'];
        foreach ($mobileKeywords as $kw) {
            if (stripos($ua, $kw) !== false) {
                return true;
            }
        }
    }
    return false;
}

/**
 * 确保扫码登录临时表存在
 */
function ensure_qr_login_table() {
    static $ensured = false;
    if ($ensured) return;
    $ensured = true;
    try {
        $prefix = \think\Db::getConfig('prefix');
        $prefix = $prefix ?: '';
        $table = "{$prefix}qr_login";
        $exists = \think\Db::query("SHOW TABLES LIKE '{$table}'");
        if (empty($exists)) {
            \think\Db::execute("CREATE TABLE IF NOT EXISTS `{$table}` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `token` varchar(64) NOT NULL DEFAULT '' COMMENT '二维码token',
                `userid` int(11) NOT NULL DEFAULT 0 COMMENT '确认登录的用户ID',
                `status` varchar(20) NOT NULL DEFAULT 'pending' COMMENT 'pending待扫码/scanned已扫码/confirmed已确认/expired已过期',
                `created_at` int(11) NOT NULL DEFAULT 0,
                `expired_at` int(11) NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_token` (`token`),
                KEY `idx_status` (`status`),
                KEY `idx_expired` (`expired_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='扫码登录临时凭证'");
        }
    } catch (\Exception $e) {}
}

/**
 * 解析 User-Agent，识别设备品牌/型号/操作系统（用于用户访问详细统计）
 * @param string $ua User-Agent 字符串
 * @return array ['brand'=>品牌标识, 'brand_cn'=>品牌中文, 'model'=>型号, 'os'=>操作系统, 'is_mobile'=>是否移动端]
 */
function parse_device_ua($ua) {
    $ua = (string)$ua;
    $result = [
        'brand'     => '',
        'brand_cn'  => '',
        'model'     => '',
        'os'        => '',
        'is_mobile' => false,
    ];
    if ($ua === '') return $result;

    if (stripos($ua, 'Mobile') !== false || stripos($ua, 'Android') !== false || stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false || stripos($ua, 'iPod') !== false) {
        $result['is_mobile'] = true;
    }

    // 品牌匹配（顺序即优先级）；Apple 同时覆盖 Mac 电脑
    $brands = [
        'Apple'     => ['苹果',   ['iPhone', 'iPad', 'iPod', 'Macintosh', 'Mac OS']],
        'Huawei'    => ['华为',   ['HUAWEI', 'HW-', 'Huawei']],
        'Honor'     => ['荣耀',   ['HONOR', 'Honor']],
        'Xiaomi'    => ['小米',   ['Xiaomi', 'Redmi', 'POCO', 'MIUI', ' Mi ', ' MI5', ' MI6']],
        'OPPO'      => ['OPPO',   ['OPPO']],
        'vivo'      => ['vivo',   ['vivo', 'VIVO']],
        'Samsung'   => ['三星',   ['Samsung', 'SM-']],
        'OnePlus'   => ['一加',   ['OnePlus', 'ONEPLUS']],
        'Meizu'     => ['魅族',   ['Meizu', 'MEIZU']],
        'realme'    => ['真我',   ['realme', 'Realme', 'RMX']],
        'Motorola'  => ['摩托罗拉', ['Moto', 'Motorola']],
        'Google'    => ['谷歌',   ['Pixel']],
        'Lenovo'    => ['联想',   ['Lenovo']],
        'ZTE'       => ['中兴',   ['ZTE']],
        'Nubia'     => ['努比亚', ['Nubia', 'nubia']],
        'BlackBerry'=> ['黑莓',   ['BlackBerry', 'BB']],
    ];
    foreach ($brands as $brand => $info) {
        foreach ($info[1] as $kw) {
            if (stripos($ua, $kw) !== false) {
                $result['brand'] = $brand;
                $result['brand_cn'] = $info[0];
                break 2;
            }
        }
    }

    // 型号提取
    if ($result['brand'] === 'Apple') {
        if (stripos($ua, 'iPhone') !== false) $result['model'] = 'iPhone';
        elseif (stripos($ua, 'iPad') !== false) $result['model'] = 'iPad';
        elseif (stripos($ua, 'iPod') !== false) $result['model'] = 'iPod';
        elseif (stripos($ua, 'Macintosh') !== false || stripos($ua, 'Mac OS') !== false) {
            $result['model'] = 'Mac';
            if (stripos($ua, 'MacBookPro') !== false) $result['model'] = 'MacBook Pro';
            elseif (stripos($ua, 'MacBookAir') !== false) $result['model'] = 'MacBook Air';
            elseif (stripos($ua, 'MacBook') !== false) $result['model'] = 'MacBook';
            elseif (stripos($ua, 'iMac') !== false) $result['model'] = 'iMac';
            elseif (stripos($ua, 'Macmini') !== false) $result['model'] = 'Mac mini';
            elseif (stripos($ua, 'MacPro') !== false) $result['model'] = 'Mac Pro';
        }
    } elseif ($result['brand'] !== '' && $result['is_mobile']) {
        // 通用型号提取：品牌关键词后的 token（含数字的型号）
        $patterns = ['HUAWEI', 'HONOR', 'Xiaomi', 'Redmi', 'POCO', 'OPPO', 'vivo', 'Samsung', 'OnePlus', 'Meizu', 'realme', 'RMX', 'Pixel', 'Lenovo', 'SM-', 'Moto'];
        foreach ($patterns as $p) {
            if (stripos($ua, $p) !== false) {
                if (preg_match('/' . preg_quote($p, '/') . '[\s\/_\-]*([A-Za-z0-9\-]+)/i', $ua, $m)) {
                    $model = trim($m[1]);
                    // 过滤纯品牌名或无意义 token
                    if ($model !== '' && !preg_match('/^(Build|Mobile|Android|AppleWebKit|Chrome|Safari|Version|MicroMessenger|NetType|WIFI|Mobile|Language|zh|cn|en|US)$/i', $model)) {
                        $result['model'] = $model;
                    }
                }
                break;
            }
        }
    }

    // 操作系统（含版本号）
    if (stripos($ua, 'Android') !== false) {
        $result['os'] = 'Android';
        if (preg_match('/Android[\/ ]([\d.]+)/i', $ua, $m)) $result['os'] = 'Android ' . $m[1];
    } elseif (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false) {
        $result['os'] = 'iOS';
        if (preg_match('/ OS ([\d_]+)/i', $ua, $m)) $result['os'] = 'iOS ' . str_replace('_', '.', $m[1]);
    } elseif (stripos($ua, 'Windows Phone') !== false) {
        $result['os'] = 'Windows Phone';
    } elseif (stripos($ua, 'Windows NT') !== false) {
        $result['os'] = 'Windows';
        if (preg_match('/Windows NT ([\d.]+)/i', $ua, $m)) {
            $v = $m[1];
            if ($v === '10.0') $result['os'] = 'Windows 10/11';
            elseif ($v === '6.3') $result['os'] = 'Windows 8.1';
            elseif ($v === '6.2') $result['os'] = 'Windows 8';
            elseif ($v === '6.1') $result['os'] = 'Windows 7';
            elseif ($v === '6.0') $result['os'] = 'Windows Vista';
            elseif ($v === '5.1') $result['os'] = 'Windows XP';
            else $result['os'] = 'Windows NT ' . $v;
        }
    } elseif (stripos($ua, 'Windows') !== false) {
        $result['os'] = 'Windows';
    } elseif (stripos($ua, 'Mac OS X') !== false || stripos($ua, 'Macintosh') !== false) {
        $result['os'] = 'macOS';
        if (preg_match('/Mac OS X ([0-9_]+)/i', $ua, $m)) {
            $result['os'] = 'macOS ' . str_replace('_', '.', $m[1]);
        }
    } elseif (stripos($ua, 'Linux') !== false) {
        $result['os'] = 'Linux';
    }

    // 桌面电脑兜底：Mac 已识别为 Apple；Windows/Linux 等无品牌电脑归为 PC（展示浏览器）
    if ($result['brand'] === '' && !$result['is_mobile']) {
        $result['brand'] = 'PC';
        $result['brand_cn'] = '电脑';
        if (stripos($ua, 'Edg') !== false) $result['model'] = 'Edge';
        elseif (stripos($ua, 'Firefox') !== false) $result['model'] = 'Firefox';
        elseif (stripos($ua, 'Chrome') !== false) $result['model'] = 'Chrome';
        elseif (stripos($ua, 'Safari') !== false) $result['model'] = 'Safari';
        elseif (stripos($ua, 'MSIE') !== false || stripos($ua, 'Trident') !== false) $result['model'] = 'IE';
    }

    return $result;
}

// ===== 加载活动中心/抽奖/域名/数据大屏公共函数 =====
if (is_file(__DIR__ . '/common_activity.php')) {
    include_once __DIR__ . '/common_activity.php';
}