<?php
namespace app\index\controller;

use think\Controller;
use think\Db;
use think\Request;

/**
 * 开放 API（用户开发者）
 * 通过专属 API 密钥调用，覆盖官网全部用户功能：
 * 查询/购买/续费/升降级主机、重置密码、暂停/恢复/删除主机、
 * 余额充值、转让市场买卖、工单、积分、卡密、推广、财务记录等。
 * 限制：不支持修改账号与密码（仅 user.password / user.account 被禁止）。
 */
class Open extends Controller
{
    private $apiUser = null;
    private $apiKeyRow = null;

    public function index($action = '')
    {
        // 统一 JSON 响应

        // CORS 跨域支持：浏览器网页（HTML/JS）跨域调用时，必须先返回 CORS 响应头，
        // 否则浏览器会拦截响应并报“网络错误 / 无法请求”
        $this->applyCorsHeaders();

        // OPTIONS 预检请求直接放行：浏览器跨域请求携带自定义头（如 X-API-Key、Content-Type）
        // 时会先发送一次 OPTIONS 预检，若不放行则真实请求永远无法发出
        if (Request::instance()->isOptions()) {
            return response('', 204);
        }

        // 0. 统一解析请求体：兼容 JSON 请求体（含 Content-Type 缺失/错误的情况）、
        // PUT/DELETE 等 PHP 不会自动填充 $_POST 的请求方式，保证 action 及业务参数均可读取
        $this->parseRawBody();

        $startTime = microtime(true);
        $ip = function_exists('get_client_ip') ? get_client_ip() : (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0');
        $web = web_config();

        // ============ 安全层 1：开发者 API 总开关 ============
        if (isset($web['api_enabled']) && (string)$web['api_enabled'] === '0') {
            return json(['code' => -1, 'msg' => '开发者 API 已关闭']);
        }

        // ============ 安全层 2：IP 封禁检查（含自动封禁） ============
        if (function_exists('api_ip_banned') && api_ip_banned($ip)) {
            $this->logCall('', 0, $action, $ip, 'blocked', $this->costMs($startTime));
            return json(['code' => -1, 'msg' => '您的 IP 因频繁请求已被临时封禁，请稍后再试']);
        }

        // ============ 安全层 3：IP 维度频率限流 ============
        $rate = intval(isset($web['api_rate']) ? $web['api_rate'] : 0);
        $banDuration = intval(isset($web['api_rate_ban']) ? $web['api_rate_ban'] : 3600);
        if ($rate > 0) {
            $ipCheck = api_rate_check('ip:' . $ip, $rate);
            if (!$ipCheck['pass']) {
                $this->autoBanIp($ip, $rate, $banDuration);
                $this->logCall('', 0, $action, $ip, 'blocked', $this->costMs($startTime));
                return json(['code' => -1, 'msg' => $ipCheck['msg'] . $this->banHint($banDuration)]);
            }
        }

        // 2. 解析动作（提前到密钥校验之前，用于识别无需密钥的登录类接口）
        if ($action === '' || $action === null) {
            $req = Request::instance();
            $action = trim((string)$req->get('action', ''));
            if ($action === '') {
                $action = trim((string)$req->post('action', ''));
            }
            if ($action === '') {
                // 兜底：PUT/DELETE 请求体（POST 表单/JSON 已在上面覆盖，这里不会重复解析）
                $action = trim((string)$req->put('action', ''));
            }
        }
        if ($action === '' || $action === null) {
            return json(['code' => -1, 'msg' => '缺少 action 参数（可通过 URL 路径 /api/{action}，或 GET/POST 参数 action 传入）']);
        }

        // ============ 登录类接口（账号密码登录 / 扫码登录，无需 API 密钥） ============
        if (in_array($action, ['login', 'qrlogin.create', 'qrlogin.status'], true)) {
            try {
                $resp = $this->dispatchLoginAction($action);
                $this->logCall('', 0, $action, $ip, 'success', $this->costMs($startTime));
                return $resp;
            } catch (\Throwable $e) {
                $this->logCall('', 0, $action, $ip, 'fail', $this->costMs($startTime));
                return json(['code' => -1, 'msg' => '接口异常：' . (\think\App::$debug ? $e->getMessage() : '系统繁忙，请稍后再试')]);
            }
        }

        // 1. 校验密钥
        $key = $this->getApiKey();
        if ($key === '') {
            return json(['code' => -1, 'msg' => '缺少 API 密钥，请在请求头 X-API-Key 或参数 api_key 中提供']);
        }

        // ============ 安全层 4：密钥维度频率限流（防止单密钥/脚本速刷） ============
        if ($rate > 0) {
            $keyCheck = api_rate_check('key:' . $key, $rate);
            if (!$keyCheck['pass']) {
                $this->autoBanIp($ip, $rate, $banDuration);
                $this->logCall($key, 0, $action, $ip, 'blocked', $this->costMs($startTime));
                return json(['code' => -1, 'msg' => $keyCheck['msg'] . $this->banHint($banDuration)]);
            }
        }

        ensure_api_keys_table();
        $row = Db::name('api_keys')->where('api_key', $key)->find();
        if (!$row) {
            $this->logCall($key, 0, $action, $ip, 'fail', $this->costMs($startTime));
            return json(['code' => -1, 'msg' => 'API 密钥无效']);
        }
        if ($row['status'] != 1) {
            $this->logCall($key, $row['userid'], $action, $ip, 'denied', $this->costMs($startTime));
            return json(['code' => -1, 'msg' => 'API 密钥已被停用']);
        }
        $user = Db::name('user')->where('id', $row['userid'])->find();
        if (!$user || $user['state'] == '0') {
            $this->logCall($key, $row['userid'], $action, $ip, 'denied', $this->costMs($startTime));
            return json(['code' => -1, 'msg' => '账号不存在或已禁用']);
        }
        if (isset($user['ban_time']) && intval($user['ban_time']) > time()) {
            $this->logCall($key, $row['userid'], $action, $ip, 'denied', $this->costMs($startTime));
            return json(['code' => -1, 'msg' => '账号已被封禁至 ' . date('Y-m-d H:i:s', $user['ban_time'])]);
        }
        // ============ 安全层 5：用户级 API 禁用（后台可对特定用户禁用） ============
        if (isset($user['api_disabled']) && intval($user['api_disabled']) == 1) {
            $this->logCall($key, $row['userid'], $action, $ip, 'denied', $this->costMs($startTime));
            return json(['code' => -1, 'msg' => '您的账号已被禁止使用开发者 API，请联系管理员']);
        }
        $this->apiUser = $user;
        $this->apiKeyRow = $row;

        // 更新最后使用时间
        try {
            Db::name('api_keys')->where('id', $row['id'])->update(['last_used_at' => time()]);
        } catch (\Exception $e) {}

        // ============ 安全层 6：功能级开关（后台可对每个 API 功能单独开启/关闭） ============
        if (!api_action_enabled($action)) {
            $this->logCall($key, $user['id'], $action, $ip, 'denied', $this->costMs($startTime));
            return json(['code' => -1, 'msg' => '该 API 功能已被管理员关闭']);
        }

        // 3. 分发（捕获异常，保证接口始终返回 JSON，且带上 CORS 头）
        try {
            $resp = $this->dispatch($action);
            $this->logCall($key, $user['id'], $action, $ip, 'success', $this->costMs($startTime));
            return $resp;
        } catch (\Throwable $e) {
            $this->logCall($key, $user['id'], $action, $ip, 'fail', $this->costMs($startTime));
            return json(['code' => -1, 'msg' => '接口异常：' . (\think\App::$debug ? $e->getMessage() : '系统繁忙，请稍后再试')]);
        }
    }

    /**
     * 记录一次 API 调用（统计用，静默失败不影响业务）
     */
    private function logCall($key, $userid, $action, $ip, $status, $costMs)
    {
        try {
            if (function_exists('api_log_call')) {
                api_log_call($key, $userid, $action ?: 'unknown', $ip, $status, $costMs);
            }
        } catch (\Throwable $e) {}
    }

    /**
     * 计算请求耗时（毫秒）
     */
    private function costMs($startTime)
    {
        return intval((microtime(true) - $startTime) * 1000);
    }

    /**
     * 生成封禁提示文案
     */
    private function banHint($banDuration)
    {
        if ($banDuration > 0) {
            $m = round($banDuration / 60, 1);
            return '，该 IP 已被自动封禁 ' . $m . ' 分钟';
        }
        return '，该 IP 已被自动封禁（永久）';
    }

    /**
     * 频率超限自动封禁 IP（写入 ip_ban 表，ban_type=auto，便于后台查看与解封）
     */
    private function autoBanIp($ip, $rate, $banDuration)
    {
        if (empty($ip) || $ip === '0.0.0.0' || $ip === 'unknown') return;
        try {
            ensure_ip_ban_table();
            $now = time();
            $unbanAt = $banDuration > 0 ? ($now + $banDuration) : 0;
            $reason = '开发者API请求频率超限（每分钟超过 ' . $rate . ' 次）';
            $existing = Db::name('ip_ban')->where('ip', $ip)->find();
            if ($existing) {
                $update = [
                    'trigger_count' => intval($existing['trigger_count']) + 1,
                    'last_seen' => $now,
                    'reason' => $reason,
                ];
                // 已解除或已过期的记录需重新激活封禁
                $expired = ($existing['status'] == 0)
                    || ($existing['status'] == 1 && intval($existing['unban_at']) > 0 && intval($existing['unban_at']) < $now);
                if ($expired) {
                    $update['status'] = 1;
                    $update['banned_at'] = $now;
                    $update['ban_type'] = 'auto';
                    $update['unban_at'] = $unbanAt;
                }
                Db::name('ip_ban')->where('id', $existing['id'])->update($update);
            } else {
                Db::name('ip_ban')->insert([
                    'ip' => $ip,
                    'reason' => $reason,
                    'trigger_count' => 1,
                    'first_seen' => $now,
                    'last_seen' => $now,
                    'ban_type' => 'auto',
                    'status' => 1,
                    'banned_at' => $now,
                    'unban_at' => $unbanAt,
                ]);
            }
        } catch (\Throwable $e) {}
    }

    /**
     * 输出 CORS 跨域响应头（开放接口允许任意来源调用）
     */
    private function applyCorsHeaders()
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        // 优先反射浏览器预检请求头，保证任何自定义头都能通过预检
        $allowHeaders = isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'])
            ? $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']
            : 'X-API-Key, Content-Type, Authorization, Accept, Origin, X-Requested-With';
        header('Access-Control-Allow-Headers: ' . $allowHeaders);
        header('Access-Control-Max-Age: 86400');
    }

    /**
     * 统一解析原始请求体并合并进 POST 数据
     * 兼容场景：
     * 1. JSON 请求体但 Content-Type 缺失或不是 application/json（如 fetch 未设置请求头）
     * 2. JSON 被终端额外引号包裹（如 Windows 终端 curl 使用单引号导致解析失败）
     * 3. PUT/DELETE 等 PHP 不会自动填充 $_POST 的请求方式
     * 合并后 param() 缓存会被重置，后续 input() 读取业务参数即可正常命中。
     */
    private function parseRawBody()
    {
        $req = Request::instance();
        // 表单提交时 PHP 已自动解析 $_POST，无需处理
        if (!empty($_POST)) {
            return;
        }
        // TP5 在 Content-Type: application/json 且解析成功时已填充 POST，无需重复
        if (!empty($req->post(false))) {
            return;
        }
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return;
        }
        $raw = trim($raw);
        $parsed = json_decode($raw, true);
        if (!is_array($parsed)) {
            // 兼容被额外引号包裹的 JSON（如 Windows 终端下 curl 的单引号被当作字面字符发送）
            $stripped = trim($raw, "'\"");
            if ($stripped !== $raw) {
                $parsed = json_decode($stripped, true);
            }
        }
        if (!is_array($parsed)) {
            // 尝试按表单格式解析（application/x-www-form-urlencoded 或纯文本键值对）
            $parsed = [];
            parse_str($raw, $parsed);
            if (isset($stripped) && $stripped !== $raw && !isset($parsed['action'])) {
                // 原始串被外层引号包裹导致键名带引号时，用剥离后的串再解析一次并合并
                $more = [];
                parse_str($stripped, $more);
                if (!empty($more)) {
                    $parsed = array_merge($parsed, $more);
                }
            }
        }
        if (!empty($parsed) && is_array($parsed)) {
            $req->post($parsed);
        }
    }

    private function getApiKey()
    {
        $key = '';
        if (isset($_SERVER['HTTP_X_API_KEY']) && $_SERVER['HTTP_X_API_KEY'] !== '') {
            $key = trim($_SERVER['HTTP_X_API_KEY']);
        } elseif (isset($_SERVER['HTTP_X-API-KEY']) && $_SERVER['HTTP_X-API-KEY'] !== '') {
            // 部分 FastCGI 环境会将请求头名保留为连字符形式
            $key = trim($_SERVER['HTTP_X-API-KEY']);
        }
        if ($key === '') {
            $key = trim((string)input('api_key', ''));
        }
        return $key;
    }

    private function dispatch($action)
    {
        // 只读 / 简单操作：直接处理
        $handlers = [
            'user.info' => 'apiUserInfo',
            'user.update' => 'apiUserUpdate',
            'order.list' => 'apiOrderList',
            'order.detail' => 'apiOrderDetail',
            'host.list' => 'apiHostList',
            'host.status' => 'apiHostStatus',
            'host.panel' => 'apiHostPanelLogin',
            'host.panel.login' => 'apiHostPanelLogin',
            'host.login' => 'apiHostPanelLogin',
            'host.sso' => 'apiHostPanelLogin',
            'host.reset' => 'apiHostReset',
            'host.suspend' => 'apiHostSuspend',
            'host.unsuspend' => 'apiHostUnsuspend',
            'host.delete' => 'apiHostDelete',
            'host.upgrade' => 'apiHostUpgrade',
            'order.renew' => 'apiOrderRenew',
            'ticket.list' => 'apiTicketList',
            'ticket.detail' => 'apiTicketDetail',
            'ticket.submit' => 'apiTicketSubmit',
            'ticket.reply' => 'apiTicketReply',
            'ticket.close' => 'apiTicketClose',
            'announcement.list' => 'apiAnnouncementList',
            'announcement.detail' => 'apiAnnouncementDetail',
            'checkin' => 'apiCheckin',
            'points.shop' => 'apiPointsShop',
            'points.exchange' => 'apiPointsExchange',
            'cdkey.use' => 'apiCdkeyUse',
            'aff.info' => 'apiAffInfo',
            'aff.enable' => 'apiAffEnable',
            'aff.withdraw' => 'apiAffWithdraw',
            'payrecord.list' => 'apiPayrecordList',
            'transaction.list' => 'apiTransactionList',
            // 产品与购买
            'product.list' => 'apiProductList',
            'cart.add' => 'apiCartAdd',
            'cart.list' => 'apiCartList',
            'cart.delete' => 'apiCartDelete',
            'cart.checkout' => 'apiCartCheckout',
            // 余额充值
            'pay.ways' => 'apiPayWays',
            'pay.create' => 'apiPayCreate',
            'pay.status' => 'apiPayStatus',
            // 转让市场
            'transfer.market' => 'apiTransferMarket',
            'transfer.list' => 'apiTransferList',
            'transfer.publish' => 'apiTransferPublish',
            'transfer.buy' => 'apiTransferBuy',
            'transfer.contact' => 'apiTransferContact',
            'transfer.detail' => 'apiTransferDetail',
            'transfer.cancel' => 'apiTransferCancel',
            'transfer.sendcode' => 'apiTransferSendCode',
            'transfer.message.send' => 'apiTransferSendMsg',
            'transfer.message.list' => 'apiTransferGetMsgs',
            'transfer.unread' => 'apiTransferUnread',
        ];
        if (isset($handlers[$action])) {
            $m = $handlers[$action];
            return $this->$m();
        }

        // 明确禁止的动作（仅账号密码类，其余用户功能均已开放）
        $forbidden = ['user.password', 'user.account'];
        if (in_array($action, $forbidden, true)) {
            return json(['code' => -1, 'msg' => '该操作被禁止：开发者 API 不支持修改账号与密码']);
        }

        return json(['code' => -1, 'msg' => '未知的 action：' . $action]);
    }

    // ==================== 登录类接口（账号密码登录 / 扫码登录，无需 API 密钥） ====================

    /**
     * 登录类接口分发
     */
    private function dispatchLoginAction($action)
    {
        if ($action === 'login') {
            return $this->apiLogin();
        }
        if ($action === 'qrlogin.create') {
            return $this->apiQrLoginCreate();
        }
        if ($action === 'qrlogin.status') {
            return $this->apiQrLoginStatus();
        }
        return json(['code' => -1, 'msg' => '未知的登录操作']);
    }

    /**
     * 账号密码登录：用户名/邮箱 + 密码 → 返回 API 密钥
     */
    private function apiLogin()
    {
        $account  = trim((string)input('account', ''));
        $password = (string)input('password', '');
        if ($account === '' || $password === '') {
            return json(['code' => -1, 'msg' => '账号和密码不能为空']);
        }

        $ip = function_exists('get_client_ip') ? get_client_ip() : (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0');

        // 登录尝试限流：同 IP 每分钟最多 10 次，防暴力破解
        $loginCheck = api_rate_check('apilogin_ip:' . $ip, 10);
        if (!$loginCheck['pass']) {
            return json(['code' => -1, 'msg' => '登录尝试过于频繁，请稍后再试']);
        }

        $user = Db::name('user')->where('user', $account)->whereOr('mail', $account)->find();
        if (!$user) {
            return json(['code' => -1, 'msg' => '账号或密码错误']);
        }
        if (!password_verify($password, $user['password'])) {
            return json(['code' => -1, 'msg' => '账号或密码错误']);
        }
        if ($user['state'] == '0') {
            return json(['code' => -1, 'msg' => '账号已被冻结，禁止登录']);
        }
        if (isset($user['ban_time']) && intval($user['ban_time']) > time()) {
            return json(['code' => -1, 'msg' => '账号已被封禁至 ' . date('Y-m-d H:i:s', $user['ban_time'])]);
        }
        if (isset($user['api_disabled']) && intval($user['api_disabled']) == 1) {
            return json(['code' => -1, 'msg' => '该账号已被禁止使用开发者 API，请联系管理员']);
        }

        // 更新登录信息
        try {
            Db::name('user')->where('id', $user['id'])->update([
                'last_login_time'   => time(),
                'last_login_ip'     => $ip,
                'last_login_region' => get_ip_region($ip),
            ]);
        } catch (\Exception $e) {}

        $apiKey = $this->issueApiKey($user['id']);
        if ($apiKey === '') {
            return json(['code' => -1, 'msg' => '密钥创建失败，请稍后重试']);
        }

        return json(['code' => 1, 'msg' => '登录成功', 'data' => [
            'api_key'  => $apiKey,
            'userid'   => $user['id'],
            'username' => $user['user'],
            'nickname' => isset($user['name']) ? $user['name'] : '',
            'email'    => isset($user['mail']) ? $user['mail'] : '',
            'balance'  => floatval(isset($user['money']) ? $user['money'] : 0),
            'points'   => intval(isset($user['points']) ? $user['points'] : 0),
        ]]);
    }

    /**
     * 扫码登录第一步：生成二维码 token（用户手机扫码确认后，调用方轮询 status）
     */
    private function apiQrLoginCreate()
    {
        ensure_qr_login_table();
        try { Db::name('qr_login')->where('expired_at', '<', time())->delete(); } catch (\Exception $e) {}

        $token = bin2hex(function_exists('random_bytes') ? random_bytes(16) : md5(uniqid(mt_rand(), true) . microtime(true)));
        $now = time();
        try {
            Db::name('qr_login')->insert([
                'token'      => $token,
                'userid'     => 0,
                'status'     => 'pending',
                'created_at' => $now,
                'expired_at' => $now + 300,
            ]);
        } catch (\Exception $e) {
            return json(['code' => -1, 'msg' => '生成二维码失败，请重试']);
        }
        $confirmUrl = (isHTTPS() ? 'https://' : 'http://') . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '') . '/qrlogin/confirm?token=' . $token;
        return json(['code' => 1, 'token' => $token, 'qrurl' => $confirmUrl, 'expire' => 300]);
    }

    /**
     * 扫码登录第二步：轮询扫码状态；已确认则返回 API 密钥
     */
    private function apiQrLoginStatus()
    {
        ensure_qr_login_table();
        $token = trim((string)input('token', ''));
        if ($token === '') {
            return json(['code' => -1, 'status' => 'error', 'msg' => '参数错误']);
        }
        $row = Db::name('qr_login')->where('token', $token)->find();
        if (!$row || intval($row['expired_at']) < time()) {
            return json(['code' => -1, 'status' => 'expired', 'msg' => '二维码已失效，请刷新']);
        }
        if ($row['status'] === 'confirmed') {
            $user = Db::name('user')->where('id', intval($row['userid']))->find();
            if (!$user || $user['state'] == '0') {
                return json(['code' => -1, 'status' => 'expired', 'msg' => '账号异常或已被禁用']);
            }
            if (isset($user['api_disabled']) && intval($user['api_disabled']) == 1) {
                return json(['code' => -1, 'status' => 'error', 'msg' => '该账号已被禁止使用开发者 API']);
            }

            // 更新登录信息
            $ip = function_exists('get_client_ip') ? get_client_ip() : (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0');
            try {
                Db::name('user')->where('id', $user['id'])->update([
                    'last_login_time'   => time(),
                    'last_login_ip'     => $ip,
                    'last_login_region' => get_ip_region($ip),
                ]);
            } catch (\Exception $e) {}

            $apiKey = $this->issueApiKey($user['id']);
            Db::name('qr_login')->where('id', $row['id'])->update(['status' => 'used']);
            return json(['code' => 1, 'status' => 'confirmed', 'msg' => '登录成功', 'data' => [
                'api_key'  => $apiKey,
                'userid'   => $user['id'],
                'username' => $user['user'],
                'nickname' => isset($user['name']) ? $user['name'] : '',
                'email'    => isset($user['mail']) ? $user['mail'] : '',
            ]]);
        }
        return json(['code' => 1, 'status' => $row['status'], 'msg' => '']);
    }

    /**
     * 为用户查找或创建 API 密钥（登录成功后签发，优先复用已有启用密钥）
     */
    private function issueApiKey($userid)
    {
        ensure_api_keys_table();
        $existing = Db::name('api_keys')->where('userid', $userid)->where('status', 1)->order('id asc')->find();
        if ($existing) {
            return $existing['api_key'];
        }
        $count = Db::name('api_keys')->where('userid', $userid)->count();
        if ($count >= 20) {
            $any = Db::name('api_keys')->where('userid', $userid)->order('id asc')->find();
            return $any ? $any['api_key'] : '';
        }
        do {
            $apiKey = 'LHX-' . random(48);
        } while (Db::name('api_keys')->where('api_key', $apiKey)->find());
        $id = Db::name('api_keys')->insertGetId([
            'userid'       => $userid,
            'name'         => '登录自动创建',
            'api_key'      => $apiKey,
            'status'       => 1,
            'last_used_at' => 0,
            'created_at'   => time(),
        ]);
        return $id ? $apiKey : '';
    }

    // ==================== 委托 User 控制器执行（保持与官网一致） ====================

    private function callUser($method, $args = [], $extra = [])
    {
        if (!empty($extra)) {
            Request::instance()->post($extra);
        }
        session('userid', $this->apiUser['id']);
        try {
            $userController = new User();
            $response = call_user_func_array([$userController, $method], $args);
        } catch (\Throwable $e) {
            return json(['code' => -1, 'msg' => '操作异常：' . $e->getMessage()]);
        }
        if ($response instanceof \think\Response) {
            $content = $response->getContent();
        } elseif (is_array($response)) {
            $content = json_encode($response, JSON_UNESCAPED_UNICODE);
        } else {
            $content = (string)$response;
        }
        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            return json($decoded);
        }
        return json(['code' => -1, 'msg' => '操作返回异常：' . mb_substr($content, 0, 200)]);
    }

    // ==================== 只读 / 简单操作 ====================

    private function apiUserInfo()
    {
        $u = $this->apiUser;
        return json(['code' => 1, 'msg' => 'ok', 'data' => [
            'id' => $u['id'],
            'username' => $u['user'],
            'nickname' => $u['name'],
            'email' => isset($u['mail']) ? $u['mail'] : '',
            'qq' => isset($u['qq']) ? $u['qq'] : '',
            'address' => isset($u['address']) ? $u['address'] : '',
            'balance' => floatval($u['money'] ?? 0),
            'points' => intval($u['points'] ?? 0),
            'membership_level' => intval($u['membership_level'] ?? 0),
            'total_recharge' => floatval($u['total_recharge'] ?? 0),
            'realname_status' => intval($u['realname_status'] ?? 0),
            'aff' => isset($u['aff']) ? $u['aff'] : '',
            'affmoney' => floatval($u['affmoney'] ?? 0),
            'avatar' => function_exists('get_user_avatar') ? get_user_avatar($u) : '',
        ]]);
    }

    private function apiUserUpdate()
    {
        if (!Request::instance()->isPost()) {
            return json(['code' => -1, 'msg' => '请使用 POST 请求']);
        }
        $name = trim((string)input('name', ''));
        $qq = trim((string)input('qq', ''));
        $address = trim((string)input('address', ''));
        if ($name === '' || $qq === '') {
            return json(['code' => -1, 'msg' => '必填参数不能为空：name、qq']);
        }
        if (!preg_match('/^\d{4,12}$/', $qq)) {
            return json(['code' => -1, 'msg' => 'QQ号码必须是4-12位纯数字']);
        }
        Db::name('user')->where('id', $this->apiUser['id'])->update([
            'name' => $name,
            'qq' => $qq,
            'address' => $address,
        ]);
        return json(['code' => 1, 'msg' => '修改资料成功']);
    }

    private function apiOrderList()
    {
        $page = max(1, intval(input('page', 1)));
        $limit = min(50, max(1, intval(input('limit', 20))));
        $list = Db::name('order')->where('userid', $this->apiUser['id'])
            ->order('id desc')->page($page, $limit)->select();
        $list = $this->decorateOrders($list);
        $total = Db::name('order')->where('userid', $this->apiUser['id'])->count();
        return json(['code' => 1, 'msg' => 'ok', 'data' => [
            'total' => $total, 'page' => $page, 'limit' => $limit, 'list' => $list,
        ]]);
    }

    private function apiOrderDetail()
    {
        $id = intval(input('id', 0));
        if ($id <= 0) return json(['code' => -1, 'msg' => '缺少 id']);
        $order = Db::name('order')->where('id', $id)->where('userid', $this->apiUser['id'])->find();
        if (!$order) return json(['code' => -1, 'msg' => '订单不存在或不属于当前用户']);
        $list = $this->decorateOrders([$order]);
        return json(['code' => 1, 'msg' => 'ok', 'data' => $list[0]]);
    }

    private function apiHostList()
    {
        $list = Db::name('order')->where('userid', $this->apiUser['id'])
            ->where('state', '<>', '3')->order('id desc')->select();
        $list = $this->decorateOrders($list);
        return json(['code' => 1, 'msg' => 'ok', 'data' => $list]);
    }

    private function apiHostStatus()
    {
        $orderId = intval(input('order_id', 0));
        $hostUser = trim((string)input('host_user', ''));
        if ($orderId <= 0) return json(['code' => -1, 'msg' => '缺少 order_id']);
        $query = Db::name('order')->alias('o')
            ->join('cart c', 'o.cartid = c.id')
            ->join('server s', 'c.serverid = s.id')
            ->where('o.id', $orderId)->where('o.userid', $this->apiUser['id'])
            ->field('o.id,o.user,o.userid,o.state,o.atime,o.ztime,s.host,s.port,s.ssl,s.serverplugins,s.user as bt_bh,s.security,s.password as bt_keye,s.data1');
        if ($hostUser !== '') {
            $query->where('o.user', $hostUser);
        }
        $order = $query->find();
        if (!$order) return json(['code' => -1, 'msg' => '订单不存在或不属于当前用户（产品可能已下架）']);

        // 本地订单信息：即使面板查询失败也返回，便于调用方判断主机在站内状态
        $localInfo = [
            'order_id' => intval($order['id']),
            'host_user' => $order['user'],
            'state' => $order['state'],
            'state_text' => $this->orderStateText($order['state']),
            'opened_at' => intval($order['atime']) > 0 ? date('Y-m-d H:i:s', $order['atime']) : '',
            'expire_at' => intval($order['ztime']) > 0 ? date('Y-m-d H:i:s', $order['ztime']) : '',
            'expire_timestamp' => intval($order['ztime']),
        ];

        // 仅梦奈宝塔（mnbt）面板支持实时状态查询
        $plugin = strtolower(trim((string)$order['serverplugins']));
        if ($plugin != 'mnbt') {
            return json(['code' => -1, 'msg' => '当前主机面板类型（' . ($plugin ?: '未配置') . '）暂不支持实时状态查询，可通过 host.list 查看站内主机状态', 'data' => $localInfo]);
        }

        // 规范化服务器地址（兼容 host 字段带协议/端口、SSL与端口不一致等填写习惯）
        $ep = $this->parseHost($order);
        if ($ep['host'] === '') {
            return json(['code' => -1, 'msg' => '未配置服务器地址，请联系管理员', 'data' => $localInfo]);
        }
        $apiUrl = $ep['base'] . '/api/api.php';
        $version = $order['data1'] ?: '20';
        $postData = [
            'username' => $hostUser ?: $order['user'],
            'mn_bh' => $order['bt_bh'],
            'mn_key' => $order['security'],
            'mn_keye' => $order['bt_keye'],
            'mn_vs' => $version,
        ];
        try {
            $ch = curl_init($apiUrl . '?gn=host_status&' . http_build_query($postData));
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            $response = curl_exec($ch);
            $curlError = curl_error($ch);
            curl_close($ch);
            if ($curlError) return json(['code' => -1, 'msg' => '连接失败：' . $curlError . '（目标：' . $apiUrl . '）', 'data' => $localInfo]);
            $data = json_decode($response, true);
            if (!is_array($data)) return json(['code' => -1, 'msg' => '面板API返回格式错误：' . mb_substr(strip_tags($response), 0, 200), 'data' => $localInfo]);
            if (isset($data['code']) && (string)$data['code'] === '200') {
                // 面板查询成功：统一返回 code=1，面板数据放在 data.panel 中
                $panelData = isset($data['data']) && is_array($data['data']) ? $data['data'] : $data;
                return json(['code' => 1, 'msg' => 'ok', 'data' => array_merge($localInfo, ['panel' => $panelData])]);
            }
            $msg = isset($data['msg']) ? $data['msg'] : '面板返回异常';
            return json(['code' => -1, 'msg' => '主机状态查询失败：' . $msg, 'data' => $localInfo]);
        } catch (\Exception $e) {
            return json(['code' => -1, 'msg' => '请求异常：' . $e->getMessage(), 'data' => $localInfo]);
        }
    }

    /**
     * 规范化主机服务器地址（去协议/路径，解析端口，修正 SSL 与端口一致性）
     */
    private function parseHost($server)
    {
        $host = isset($server['host']) ? trim((string)$server['host']) : '';
        $port = isset($server['port']) ? trim((string)$server['port']) : '';
        $ssl = isset($server['ssl']) ? (string)$server['ssl'] : '0';

        $host = preg_replace('#^\s*https?://#i', '', $host);
        $host = preg_replace('#/.*$#', '', $host);
        $host = rtrim($host, '/');

        if (preg_match('/^\[(.+)\]:(\d+)$/', $host, $m)) {
            $host = $m[1];
            if ($port === '' || $port === '0') $port = $m[2];
        } elseif (preg_match('/^([^:]+):(\d+)$/', $host, $m)) {
            $host = $m[1];
            if ($port === '' || $port === '0') $port = $m[2];
        }

        if (isset($server['host']) && stripos($server['host'], 'https://') === 0) $ssl = '1';

        if ($ssl === '1' && $port === '80') $port = '443';
        elseif ($ssl === '0' && $port === '443') $port = '80';
        elseif ($port === '' || $port === '0') $port = ($ssl === '1') ? '443' : '80';

        return [
            'host' => $host,
            'port' => $port,
            'ssl' => $ssl,
            'scheme' => ($ssl === '1') ? 'https' : 'http',
            'base' => (($ssl === '1') ? 'https://' : 'http://') . $host . ':' . $port,
        ];
    }

    // 一键登录控制面板：返回登录地址与表单字段，调用方据此提交（GET 或 POST）即可跳转登录
    private function apiHostPanelLogin()
    {
        $orderId = intval(input('order_id', 0));
        if ($orderId <= 0) return json(['code' => -1, 'msg' => '缺少 order_id']);
        $order = Db::name('order')->alias('o')
            ->join('cart c', 'o.cartid = c.id')
            ->join('server s', 'c.serverid = s.id')
            ->where('o.id', $orderId)->where('o.userid', $this->apiUser['id'])
            ->field('o.id,o.user,o.password,s.serverplugins,s.host,s.port,s.ssl')
            ->find();
        if (!$order) return json(['code' => -1, 'msg' => '订单不存在或不属于当前用户']);
        if (empty($order['host']) || empty($order['port'])) {
            return json(['code' => -1, 'msg' => '未配置服务器地址，请联系管理员']);
        }
        $protocol = ($order['ssl'] == '1') ? 'https' : 'http';
        $plugin = strtolower(trim((string)$order['serverplugins']));
        if ($plugin == 'mnbt') {
            $loginUrl = $protocol . '://' . $order['host'] . ':' . $order['port'] . '/user/idcdl.php?gn=logine';
            $fields = ['username' => $order['user'], 'password' => $order['password']];
            $method = 'POST';
        } elseif ($plugin == 'easypanel') {
            $loginUrl = $protocol . '://' . $order['host'] . ':' . $order['port'] . '/vhost/?c=session&a=login';
            $fields = ['username' => $order['user'], 'passwd' => $order['password']];
            $method = 'POST';
        } else {
            $loginUrl = $protocol . '://' . $order['host'] . ':' . $order['port'];
            $fields = [];
            $method = 'GET';
        }
        return json(['code' => 1, 'msg' => 'ok', 'data' => [
            'order_id' => $orderId,
            'panel_type' => $plugin,
            'login_url' => $loginUrl,
            'method' => $method,
            'fields' => $fields,
        ]]);
    }

    private function apiOrderRenew()
    {
        $id = intval(input('order_id', 0));
        if ($id <= 0) return json(['code' => -1, 'msg' => '缺少 order_id']);
        $order = Db::name('order')->where('id', $id)->where('userid', $this->apiUser['id'])->find();
        if (!$order) return json(['code' => -1, 'msg' => '订单不存在或不属于当前用户']);
        return $this->callUser('order', [$id], ['act' => 'renew']);
    }

    private function apiTicketList()
    {
        $page = max(1, intval(input('page', 1)));
        $limit = min(50, max(1, intval(input('limit', 20))));
        $list = Db::name('ticket')->where('userid', $this->apiUser['id'])
            ->order('id desc')->page($page, $limit)->select();
        foreach ($list as &$t) {
            $t['content'] = json_decode($t['content'], true);
        }
        unset($t);
        $total = Db::name('ticket')->where('userid', $this->apiUser['id'])->count();
        return json(['code' => 1, 'msg' => 'ok', 'data' => [
            'total' => $total, 'page' => $page, 'limit' => $limit, 'list' => $list,
        ]]);
    }

    private function apiTicketDetail()
    {
        $id = intval(input('id', 0));
        if ($id <= 0) return json(['code' => -1, 'msg' => '缺少 id']);
        $ticket = Db::name('ticket')->where('id', $id)->where('userid', $this->apiUser['id'])->find();
        if (!$ticket) return json(['code' => -1, 'msg' => '工单不存在或不属于当前用户']);
        $ticket['content'] = json_decode($ticket['content'], true);
        return json(['code' => 1, 'msg' => 'ok', 'data' => $ticket]);
    }

    private function apiTicketSubmit()
    {
        return $this->callUser('submitticket', [], []);
    }

    private function apiTicketReply()
    {
        $id = intval(input('id', 0));
        if ($id <= 0) return json(['code' => -1, 'msg' => '缺少 id']);
        $ticket = Db::name('ticket')->where('id', $id)->where('userid', $this->apiUser['id'])->find();
        if (!$ticket) return json(['code' => -1, 'msg' => '工单不存在或不属于当前用户']);
        return $this->callUser('supportticket', [$id], ['act' => 'reply']);
    }

    private function apiTicketClose()
    {
        $id = intval(input('id', 0));
        if ($id <= 0) return json(['code' => -1, 'msg' => '缺少 id']);
        $ticket = Db::name('ticket')->where('id', $id)->where('userid', $this->apiUser['id'])->find();
        if (!$ticket) return json(['code' => -1, 'msg' => '工单不存在或不属于当前用户']);
        return $this->callUser('supportticket', [$id], ['act' => 'end']);
    }

    private function apiAnnouncementList()
    {
        $page = max(1, intval(input('page', 1)));
        $limit = min(50, max(1, intval(input('limit', 20))));
        $list = Db::name('announcements')->where('status', 1)
            ->order('id desc')->page($page, $limit)->select();
        $total = Db::name('announcements')->where('status', 1)->count();
        return json(['code' => 1, 'msg' => 'ok', 'data' => [
            'total' => $total, 'page' => $page, 'limit' => $limit, 'list' => $list,
        ]]);
    }

    private function apiAnnouncementDetail()
    {
        $id = intval(input('id', 0));
        if ($id <= 0) return json(['code' => -1, 'msg' => '缺少 id']);
        $row = Db::name('announcements')->where('id', $id)->where('status', 1)->find();
        if (!$row) return json(['code' => -1, 'msg' => '公告不存在']);
        return json(['code' => 1, 'msg' => 'ok', 'data' => $row]);
    }

    private function apiCheckin()
    {
        return $this->callUser('checkin', [], []);
    }

    private function apiPointsShop()
    {
        ensure_points_products_table();
        $products = Db::name('points_products')->where('status', 1)
            ->order('sort asc, id asc')->select();
        return json(['code' => 1, 'msg' => 'ok', 'data' => [
            'points' => intval($this->apiUser['points'] ?? 0),
            'products' => $products,
        ]]);
    }

    private function apiPointsExchange()
    {
        if (!Request::instance()->isPost()) {
            return json(['code' => -1, 'msg' => '请使用 POST 请求']);
        }
        ensure_points_products_table();
        ensure_points_log_table();
        $productId = intval(input('product_id', 0));
        $product = Db::name('points_products')->where('id', $productId)->where('status', 1)->find();
        if (!$product) return json(['code' => -1, 'msg' => '产品不存在或已下架']);
        // 限制：不支持兑换主机（视为购买主机）
        if ($product['type'] == 'host') {
            return json(['code' => -1, 'msg' => '开发者 API 不支持兑换主机，请前往官网操作']);
        }
        // balance / unban / renew 交由官网逻辑处理
        return $this->callUser('pointsExchange', [], []);
    }

    private function apiCdkeyUse()
    {
        if (!Request::instance()->isPost()) {
            return json(['code' => -1, 'msg' => '请使用 POST 请求']);
        }
        ensure_cdkey_table();
        $cdkey = trim((string)input('cdkey', ''));
        if ($cdkey === '') return json(['code' => -1, 'msg' => '请输入卡密']);
        $key = Db::name('cdkey')->where('cdkey', $cdkey)->find();
        if ($key && isset($key['type']) && $key['type'] == 'host') {
            return json(['code' => -1, 'msg' => '开发者 API 不支持使用主机类卡密（购买主机），请前往官网兑换']);
        }
        return $this->callUser('cdkey', [], []);
    }

    private function apiAffInfo()
    {
        $web = web_config();
        $u = $this->apiUser;
        return json(['code' => 1, 'msg' => 'ok', 'data' => [
            'aff_code' => isset($u['aff']) ? $u['aff'] : '',
            'aff_url' => (isHTTPS() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '') . '/aff/' . (isset($u['aff']) ? $u['aff'] : ''),
            'affmoney' => floatval($u['affmoney'] ?? 0),
            'affdiscount' => floatval($web['affdiscount'] ?? 0),
            'affwithdrawal' => floatval($web['affwithdrawal'] ?? 0),
        ]]);
    }

    private function apiAffEnable()
    {
        if (!empty($this->apiUser['aff'])) {
            return json(['code' => -1, 'msg' => '您已开启推广']);
        }
        while (true) {
            $affsj = random('6');
            $exists = Db::name('user')->where('aff', $affsj)->find();
            if (!$exists) break;
        }
        Db::name('user')->where('id', $this->apiUser['id'])->update(['aff' => $affsj]);
        return json(['code' => 1, 'msg' => '开启推广成功', 'data' => ['aff' => $affsj]]);
    }

    private function apiAffWithdraw()
    {
        $web = web_config();
        $min = floatval($web['affwithdrawal'] ?? 0);
        $affMoney = floatval($this->apiUser['affmoney'] ?? 0);
        if ($affMoney < $min) {
            return json(['code' => -1, 'msg' => '最小提现金额为：' . $min . ' 元']);
        }
        Db::name('user')->where('id', $this->apiUser['id'])->update([
            'money' => round(floatval($this->apiUser['money'] ?? 0) + $affMoney, 2),
            'affmoney' => 0,
        ]);
        Db::name('afftxjl')->insertGetId([
            'information' => 'API提现到账户余额',
            'money' => $affMoney,
            'userid' => $this->apiUser['id'],
            'state' => '1',
            'time' => time(),
        ]);
        return json(['code' => 1, 'msg' => '已成功提现到账户余额', 'data' => ['amount' => $affMoney]]);
    }

    private function apiPayrecordList()
    {
        $page = max(1, intval(input('page', 1)));
        $limit = min(50, max(1, intval(input('limit', 20))));
        $list = Db::name('pay')->where('userid', $this->apiUser['id'])
            ->order('id desc')->page($page, $limit)->select();
        $total = Db::name('pay')->where('userid', $this->apiUser['id'])->count();
        return json(['code' => 1, 'msg' => 'ok', 'data' => [
            'total' => $total, 'page' => $page, 'limit' => $limit, 'list' => $list,
        ]]);
    }

    private function apiTransactionList()
    {
        $page = max(1, intval(input('page', 1)));
        $limit = min(50, max(1, intval(input('limit', 20))));
        $list = Db::name('transaction')->where('userid', $this->apiUser['id'])
            ->order('id desc')->page($page, $limit)->select();
        $total = Db::name('transaction')->where('userid', $this->apiUser['id'])->count();
        return json(['code' => 1, 'msg' => 'ok', 'data' => [
            'total' => $total, 'page' => $page, 'limit' => $limit, 'list' => $list,
        ]]);
    }

    // ==================== 主机操作（重置密码 / 暂停 / 恢复 / 删除 / 升降级） ====================

    // 重置主机面板密码（不传 password 时自动生成随机密码）
    private function apiHostReset()
    {
        if (!Request::instance()->isPost()) {
            return json(['code' => -1, 'msg' => '请使用 POST 请求']);
        }
        $orderId = intval(input('order_id', 0));
        if ($orderId <= 0) return json(['code' => -1, 'msg' => '缺少 order_id']);
        $order = Db::name('order')->where(['id' => $orderId, 'userid' => $this->apiUser['id']])->find();
        if (!$order) return json(['code' => -1, 'msg' => '订单不存在或不属于当前用户']);

        $cart = Db::name('cart')->where('id', $order['cartid'])->find();
        $server = $cart ? Db::name('server')->where('id', $cart['serverid'])->find() : null;

        $newpass = trim((string)input('password', ''));
        if ($newpass === '') {
            $newpass = random(10);
        } else {
            if (strlen($newpass) < 6 || strlen($newpass) > 50 || preg_match('/[\x{4e00}-\x{9fa5}]/u', $newpass)) {
                return json(['code' => -1, 'msg' => '面板密码长度需在6-50个字符之间，且不能包含中文']);
            }
        }

        if ($server && !empty($server['serverplugins'])) {
            $pluginFile = PATH . 'plugins/host/' . $server['serverplugins'] . '/' . $server['serverplugins'] . '.php';
            if (file_exists($pluginFile)) {
                include_once $pluginFile;
                $fn = $server['serverplugins'] . '_ChangePassword';
                if (function_exists($fn)) {
                    $result = @$fn($server, $order, $newpass);
                    if (!is_array($result) || !isset($result['code']) || $result['code'] != 1) {
                        return json(['code' => -1, 'msg' => '重置失败：' . (is_array($result) && isset($result['msg']) ? $result['msg'] : '面板接口异常')]);
                    }
                }
            }
        }
        Db::name('order')->where(['id' => $orderId, 'userid' => $this->apiUser['id']])->update(['password' => $newpass]);
        return json(['code' => 1, 'msg' => '密码已重置', 'data' => ['order_id' => $orderId, 'password' => $newpass]]);
    }

    private function apiHostSuspend()
    {
        if (!Request::instance()->isPost()) {
            return json(['code' => -1, 'msg' => '请使用 POST 请求']);
        }
        $orderId = intval(input('order_id', 0));
        if ($orderId <= 0) return json(['code' => -1, 'msg' => '缺少 order_id']);
        $order = Db::name('order')->where(['id' => $orderId, 'userid' => $this->apiUser['id']])->find();
        if (!$order) return json(['code' => -1, 'msg' => '订单不存在或不属于当前用户']);
        return $this->callUser('order', [$orderId], ['act' => 'suspend']);
    }

    private function apiHostUnsuspend()
    {
        if (!Request::instance()->isPost()) {
            return json(['code' => -1, 'msg' => '请使用 POST 请求']);
        }
        $orderId = intval(input('order_id', 0));
        if ($orderId <= 0) return json(['code' => -1, 'msg' => '缺少 order_id']);
        $order = Db::name('order')->where(['id' => $orderId, 'userid' => $this->apiUser['id']])->find();
        if (!$order) return json(['code' => -1, 'msg' => '订单不存在或不属于当前用户']);
        return $this->callUser('order', [$orderId], ['act' => 'unsuspend']);
    }

    private function apiHostDelete()
    {
        if (!Request::instance()->isPost()) {
            return json(['code' => -1, 'msg' => '请使用 POST 请求']);
        }
        $orderId = intval(input('order_id', 0));
        if ($orderId <= 0) return json(['code' => -1, 'msg' => '缺少 order_id']);
        $order = Db::name('order')->where(['id' => $orderId, 'userid' => $this->apiUser['id']])->find();
        if (!$order) return json(['code' => -1, 'msg' => '订单不存在或不属于当前用户']);
        return $this->callUser('order', [$orderId], ['act' => 'delete']);
    }

    private function apiHostUpgrade()
    {
        if (!Request::instance()->isPost()) {
            return json(['code' => -1, 'msg' => '请使用 POST 请求']);
        }
        $orderId = intval(input('order_id', 0));
        if ($orderId <= 0) return json(['code' => -1, 'msg' => '缺少 order_id']);
        $newCartId = intval(input('newcartid', 0));
        if ($newCartId <= 0) return json(['code' => -1, 'msg' => '缺少 newcartid（目标产品ID，可通过 host.list 所在套餐的 upgrades 获取）']);
        $order = Db::name('order')->where(['id' => $orderId, 'userid' => $this->apiUser['id']])->find();
        if (!$order) return json(['code' => -1, 'msg' => '订单不存在或不属于当前用户']);
        return $this->callUser('order', [$orderId], ['act' => 'upgrade', 'newcartid' => $newCartId]);
    }

    // ==================== 产品与购物车 ====================

    private function apiProductList()
    {
        $categories = Db::name('product')->where('hide', '0')->order('sort', 'DESC')->select();
        foreach ($categories as &$cat) {
            $cat['products'] = Db::name('cart')->where(['product' => $cat['id'], 'hide' => '0'])->order('sort', 'DESC')->select();
        }
        unset($cat);
        return json(['code' => 1, 'msg' => 'ok', 'data' => ['categories' => $categories]]);
    }

    private function apiCartAdd()
    {
        if (!Request::instance()->isPost()) {
            return json(['code' => -1, 'msg' => '请使用 POST 请求']);
        }
        return $this->callUser('cartAdd', [], []);
    }

    private function apiCartList()
    {
        // 清理15分钟未结算的过期购物车项（与官网一致）
        try {
            Db::name('shopping_cart')->where('status', '0')->where('created_at', '<', time() - 900)->update(['status' => '2']);
        } catch (\Exception $e) {}
        $items = Db::name('shopping_cart')
            ->where(['userid' => $this->apiUser['id'], 'status' => '0'])
            ->order('id desc')->select();
        $cartIds = array_unique(array_filter(array_column($items, 'cartid')));
        $cartMap = [];
        if (!empty($cartIds)) {
            $cartMap = Db::name('cart')->where('id', 'in', $cartIds)->column('*', 'id');
        }
        $total = 0;
        foreach ($items as &$item) {
            $c = isset($cartMap[$item['cartid']]) ? $cartMap[$item['cartid']] : null;
            $item['product_name'] = $c ? $c['name'] : '产品#' . $item['cartid'];
            $item['product_money'] = $c ? $c['money'] : '0';
            $item['product_cycle'] = $c ? $c['cycle'] : '';
            $item['expire_at'] = intval($item['created_at']) + 900;
            $total += floatval($item['money']);
        }
        unset($item);
        return json(['code' => 1, 'msg' => 'ok', 'data' => ['list' => $items, 'total' => round($total, 2)]]);
    }

    private function apiCartDelete()
    {
        if (!Request::instance()->isPost()) {
            return json(['code' => -1, 'msg' => '请使用 POST 请求']);
        }
        $id = intval(input('id', 0));
        if ($id <= 0) return json(['code' => -1, 'msg' => '缺少 id（购物车项ID，可通过 cart.list 获取）']);
        $deleted = Db::name('shopping_cart')->where(['id' => $id, 'userid' => $this->apiUser['id']])->delete();
        if ($deleted === false) return json(['code' => -1, 'msg' => '删除失败']);
        return json(['code' => 1, 'msg' => '已删除']);
    }

    private function apiCartCheckout()
    {
        if (!Request::instance()->isPost()) {
            return json(['code' => -1, 'msg' => '请使用 POST 请求']);
        }
        $paytype = strtolower(trim((string)input('paytype', 'balance')));
        if ($paytype !== '' && $paytype !== 'balance') {
            return json(['code' => -1, 'msg' => '开发者 API 暂不支持在线支付结算，请先通过 pay.create 充值余额，再以 paytype=balance 结算']);
        }
        return $this->callUser('cartCheckout', [], []);
    }

    // ==================== 余额充值 ====================

    private function apiPayWays()
    {
        $pays = Db::name('pays')->where('state', '1')->select();
        foreach ($pays as &$p) {
            unset($p['plugins'], $p['data']);
        }
        unset($p);
        return json(['code' => 1, 'msg' => 'ok', 'data' => ['list' => $pays]]);
    }

    private function apiPayCreate()
    {
        if (!Request::instance()->isPost()) {
            return json(['code' => -1, 'msg' => '请使用 POST 请求']);
        }
        // 实名限制校验依赖 session('userid')，先注入当前 API 用户身份
        session('userid', $this->apiUser['id']);
        $check = check_realname_limit('pay');
        if ($check['code'] != 1) {
            return json(['code' => (string)$check['code'], 'msg' => $check['msg'], 'realname_required' => 1]);
        }
        $money = trim((string)input('money', ''));
        $payid = intval(input('payid', 0));
        if ($payid <= 0) return json(['code' => -1, 'msg' => '缺少 payid（支付方式ID，可通过 pay.ways 获取）']);
        if (!is_numeric($money) || floatval($money) < 0.01) {
            return json(['code' => -1, 'msg' => '充值金额必须是数字且不少于 0.01 元']);
        }
        if (getLen($money) > 2) {
            return json(['code' => -1, 'msg' => '金额的小数点后不能超过两位']);
        }
        $payInfo = Db::name('pays')->where(['id' => $payid, 'state' => '1'])->find();
        if (!$payInfo) return json(['code' => -1, 'msg' => '支付方式不存在或已关闭']);
        $plugin = strtolower(trim((string)$payInfo['plugins']));
        if (!in_array($plugin, ['epay', 'f2fpay'], true)) {
            return json(['code' => -1, 'msg' => '该支付插件（' . $plugin . '）暂不支持 API 下单，请在官网充值']);
        }
        $ppay = json_decode($payInfo['data'], true);
        if (!is_array($ppay)) return json(['code' => -1, 'msg' => '支付接口未配置参数，请联系管理员']);

        $orderNumber = date('YmdHis') . rand(0, 999);
        $domain = Request::instance()->domain();
        $root = Request::instance()->root();
        $notifyUrl = $domain . $root . '/index/notify/' . $payid . '/';
        $returnUrl = $domain . $root . '/user/return/' . $payid . '/';
        $orderName = '账号ID:' . $this->apiUser['id'] . ',余额充值';

        if ($plugin == 'epay') {
            $apiurl = isset($ppay[0]['value']) ? trim($ppay[0]['value']) : '';
            $pid = isset($ppay[1]['value']) ? trim($ppay[1]['value']) : '';
            $key = isset($ppay[2]['value']) ? trim($ppay[2]['value']) : '';
            $typeName = isset($ppay[3]['value']) ? trim($ppay[3]['value']) : '';
            if ($apiurl === '' || $pid === '' || $key === '') {
                return json(['code' => -1, 'msg' => '此站点未配置易支付接口']);
            }
            $typeMap = ['支付宝' => 'alipay', '微信' => 'wxpay', 'QQ' => 'qqpay'];
            $type = isset($typeMap[$typeName]) ? $typeMap[$typeName] : 'alipay';
            $epay = new \pay\epay(['apiurl' => $apiurl, 'pid' => $pid, 'key' => $key]);
            $payUrl = $epay->getPayLink([
                'pid' => $pid,
                'type' => $type,
                'notify_url' => $notifyUrl,
                'return_url' => $returnUrl,
                'out_trade_no' => $orderNumber,
                'name' => $orderName,
                'money' => $money,
            ]);
            $payResult = ['pay_url' => $payUrl, 'type' => $type];
        } else {
            $appid = isset($ppay[0]['value']) ? trim($ppay[0]['value']) : '';
            $rsaPrivateKey = isset($ppay[1]['value']) ? trim($ppay[1]['value']) : '';
            if ($appid === '' || $rsaPrivateKey === '') {
                return json(['code' => -1, 'msg' => '此站点未配置支付宝当面付接口']);
            }
            $f2f = new \pay\F2FPay(['appid' => $appid, 'rsa_private_key' => $rsaPrivateKey]);
            $result = $f2f->precreate($orderNumber, $money, $orderName, $notifyUrl);
            if (!isset($result['code']) || $result['code'] != 1) {
                return json(['code' => -1, 'msg' => isset($result['msg']) ? $result['msg'] : '支付宝下单失败']);
            }
            $payResult = ['qr_code' => $result['qr_code'], 'pay_url' => $result['qr_code'], 'type' => 'alipay_f2f'];
        }

        $payId = Db::name('pay')->insertGetId([
            'name' => '余额充值',
            'ordernumber' => $orderNumber,
            'pay' => $payid,
            'money' => $money,
            'userid' => $this->apiUser['id'],
            'time' => time(),
            'state' => '2',
        ]);
        return json(['code' => 1, 'msg' => '充值订单已创建', 'data' => array_merge([
            'pay_order_id' => $payId,
            'ordernumber' => $orderNumber,
            'money' => floatval($money),
        ], $payResult)]);
    }

    private function apiPayStatus()
    {
        $orderNumber = trim((string)input('ordernumber', ''));
        $payOrderId = intval(input('pay_order_id', 0));
        if ($orderNumber === '' && $payOrderId <= 0) {
            return json(['code' => -1, 'msg' => '缺少 ordernumber 或 pay_order_id']);
        }
        $query = Db::name('pay')->where('userid', $this->apiUser['id']);
        if ($orderNumber !== '') {
            $query->where('ordernumber', $orderNumber);
        } else {
            $query->where('id', $payOrderId);
        }
        $pay = $query->find();
        if (!$pay) return json(['code' => -1, 'msg' => '充值订单不存在或不属于当前用户']);

        $paid = ($pay['state'] == '1');
        $upstream = null;
        // 未支付时主动向易支付上游查询一次，防止异步通知丢失导致余额不到账
        if (!$paid) {
            $payInfo = Db::name('pays')->where('id', $pay['pay'])->find();
            $plugin = $payInfo ? strtolower(trim((string)$payInfo['plugins'])) : '';
            if ($plugin == 'epay') {
                $ppay = json_decode($payInfo['data'], true);
                if (is_array($ppay) && isset($ppay[0]['value']) && isset($ppay[1]['value']) && isset($ppay[2]['value'])) {
                    $epay = new \pay\epay(['apiurl' => trim($ppay[0]['value']), 'pid' => trim($ppay[1]['value']), 'key' => trim($ppay[2]['value'])]);
                    $qr = $epay->queryOrder($pay['ordernumber']);
                    if (is_array($qr)) {
                        $upstream = [
                            'status' => isset($qr['status']) ? intval($qr['status']) : 0,
                            'trade_no' => isset($qr['trade_no']) ? $qr['trade_no'] : '',
                            'money' => isset($qr['money']) ? $qr['money'] : '',
                        ];
                        // 与异步通知一致：条件更新 + 补账，防止并发重复充值
                        if (isset($qr['status']) && intval($qr['status']) === 1) {
                            $affected = Db::name('pay')->where(['ordernumber' => $pay['ordernumber'], 'state' => '2'])->update(['state' => '1', 'time' => time()]);
                            if ($affected > 0) {
                                $user = Db::name('user')->where('id', $pay['userid'])->find();
                                if ($user) {
                                    Db::name('user')->where('id', $pay['userid'])->update([
                                        'money' => round(floatval($user['money']) + floatval($pay['money']), 2),
                                        'total_recharge' => round(floatval($user['total_recharge'] ?? 0) + floatval($pay['money']), 2),
                                    ]);
                                }
                                if (function_exists('update_user_membership')) {
                                    update_user_membership($pay['userid']);
                                }
                            }
                            $paid = true;
                        }
                    }
                }
            }
        }
        return json(['code' => 1, 'msg' => 'ok', 'data' => [
            'ordernumber' => $pay['ordernumber'],
            'money' => floatval($pay['money']),
            'state' => $pay['state'],
            'state_text' => $paid ? '已支付' : '未支付',
            'paid' => $paid,
            'paid_time' => $paid && intval($pay['time']) > 0 ? date('Y-m-d H:i:s', $pay['time']) : '',
            'upstream' => $upstream,
        ]]);
    }

    // ==================== 转让市场 ====================

    private function apiTransferMarket()
    {
        ensure_host_transfer_table();
        $page = max(1, intval(input('page', 1)));
        $limit = min(50, max(1, intval(input('limit', 20))));
        $myUserId = intval($this->apiUser['id']);
        $total = Db::name('host_transfer')->alias('t')
            ->join('order o', 't.order_id = o.id')
            ->where('t.status', '0')
            ->where(function ($q) use ($myUserId) {
                $q->where('t.userid', $myUserId)
                  ->whereOr('t.target_userid', 0)
                  ->whereOr('t.target_userid', $myUserId);
            })
            ->count();
        $list = Db::name('host_transfer')
            ->alias('t')
            ->join('order o', 't.order_id = o.id')
            ->join('user u', 't.userid = u.id')
            ->join('cart c', 'o.cartid = c.id')
            ->where('t.status', '0')
            ->where(function ($q) use ($myUserId) {
                $q->where('t.userid', $myUserId)
                  ->whereOr('t.target_userid', 0)
                  ->whereOr('t.target_userid', $myUserId);
            })
            ->field('t.*, o.user as host_user, o.atime, o.ztime, c.name as product_name, c.money as product_money, c.serverid, c.id as cart_id, u.user as seller_name, u.qq as seller_qq, u.id as seller_id')
            ->order('t.id desc')
            ->page($page, $limit)
            ->select();
        return json(['code' => 1, 'msg' => 'ok', 'data' => [
            'total' => $total, 'page' => $page, 'limit' => $limit, 'list' => $list,
        ]]);
    }

    private function apiTransferList()
    {
        ensure_host_transfer_table();
        $myUserId = intval($this->apiUser['id']);
        $myTransfers = Db::name('host_transfer')
            ->alias('t')
            ->join('order o', 't.order_id = o.id')
            ->join('cart c', 'o.cartid = c.id')
            ->where('t.userid', $myUserId)
            ->field('t.*, o.user as host_user, o.state as host_state, c.name as product_name, c.money as product_money')
            ->order('t.id desc')
            ->select();
        $statusText = ['0' => '待转让', '1' => '已售出', '2' => '已驳回', '3' => '已取消'];
        foreach ($myTransfers as &$t) {
            $t['status_text'] = isset($statusText[(string)$t['status']]) ? $statusText[(string)$t['status']] : '未知';
        }
        unset($t);
        // 可发起转让的运行中主机列表（发布时使用 order_id）
        $sellableOrders = Db::name('order')->alias('o')
            ->join('cart c', 'o.cartid = c.id')
            ->where('o.userid', $myUserId)
            ->where('o.state', '1')
            ->field('o.id, o.user, o.atime, o.ztime, c.name as product_name, c.money as product_money')
            ->select();
        return json(['code' => 1, 'msg' => 'ok', 'data' => [
            'my_transfers' => $myTransfers,
            'sellable_orders' => $sellableOrders,
        ]]);
    }

    // 发送转让邮箱验证码（无状态：验证码存入 email_verify 表，不依赖会话Cookie）
    private function apiTransferSendCode()
    {
        if (!Request::instance()->isPost()) {
            return json(['code' => -1, 'msg' => '请使用 POST 请求']);
        }
        $user = Db::name('user')->where('id', $this->apiUser['id'])->find();
        if (empty($user['mail'])) {
            return json(['code' => -1, 'msg' => '请先在官网绑定邮箱后再操作']);
        }
        ensure_email_verify_table();
        // 60秒发送频率限制
        $last = Db::name('email_verify')->where('mail', $user['mail'])->order('id desc')->find();
        if ($last && time() - intval($last['create_time']) < 60) {
            return json(['code' => -1, 'msg' => '发送频率过快，请' . (60 - (time() - intval($last['create_time']))) . '秒后再试']);
        }
        $code = random(6, '0123456789');
        $now = time();
        // 清除该邮箱旧的未验证记录，避免验证码冲突
        Db::name('email_verify')->where('mail', $user['mail'])->where('verified', 0)->delete();
        Db::name('email_verify')->insert([
            'mail' => $user['mail'],
            'token' => '',
            'code' => $code,
            'verified' => 0,
            'create_time' => $now,
            'expire_time' => $now + 600,
        ]);
        $web = web_config();
        if ($web['email'] != '1') {
            return json(['code' => -1, 'msg' => '站点未开启邮件发送功能，无法发送验证码，请联系管理员']);
        }
        $codeBody = "<p>您好，</p><p>您正在 {$web['name']} 通过开发者 API 发起主机转让，本次验证码为：</p><p style='text-align:center;margin:28px 0;'><span style='display:inline-block;background:#eff6ff;color:#2563eb;font-size:28px;font-weight:700;padding:14px 32px;border-radius:10px;letter-spacing:4px;border:1px solid #bfdbfe;'>{$code}</span></p><p style='color:#64748b;font-size:13px;'>验证码 10 分钟内有效。如非本人操作，请忽略此邮件。</p>";
        User::email($user['mail'], '主机转让验证码', $codeBody);
        return json(['code' => 1, 'msg' => '验证码已发送至邮箱']);
    }

    private function apiTransferPublish()
    {
        if (!Request::instance()->isPost()) {
            return json(['code' => -1, 'msg' => '请使用 POST 请求']);
        }
        ensure_host_transfer_table();
        // 实名限制校验依赖 session('userid')，先注入当前 API 用户身份
        session('userid', $this->apiUser['id']);
        $check = check_realname_limit('transfer');
        if ($check['code'] != 1) {
            return json(['code' => (string)$check['code'], 'msg' => $check['msg'], 'realname_required' => 1]);
        }
        $orderId = intval(input('order_id', 0));
        $price = floatval(input('price', 0));
        $targetUserid = intval(input('target_userid', 0));
        $contactInfo = trim((string)input('contact_info', ''));
        $emailCode = trim((string)input('email_code', ''));

        if ($orderId <= 0) return json(['code' => -1, 'msg' => '请选择要转让的主机（order_id，可通过 transfer.list 的 sellable_orders 获取）']);
        if ($price < 0) return json(['code' => -1, 'msg' => '转让价格不能为负数']);

        // 验证邮箱验证码（通过 transfer.sendcode 获取）
        $user = Db::name('user')->where('id', $this->apiUser['id'])->find();
        if (empty($user['mail'])) return json(['code' => -1, 'msg' => '请先在官网绑定邮箱后再操作']);
        if ($emailCode === '') return json(['code' => -1, 'msg' => '请输入邮箱验证码（先调用 transfer.sendcode 获取）']);
        ensure_email_verify_table();
        $verify = Db::name('email_verify')->where([
            'mail' => $user['mail'],
            'code' => $emailCode,
            'verified' => 0,
        ])->find();
        if (!$verify || intval($verify['expire_time']) < time()) {
            return json(['code' => -1, 'msg' => '邮箱验证码错误或已过期，请重新获取']);
        }
        Db::name('email_verify')->where('id', $verify['id'])->update(['verified' => 1]);

        $order = Db::name('order')->where(['id' => $orderId, 'userid' => $this->apiUser['id']])->find();
        if (!$order) return json(['code' => -1, 'msg' => '订单不存在或不属于您']);
        if ($order['state'] != '1') return json(['code' => -1, 'msg' => '只有运行中的主机才能转让']);

        $cart = Db::name('cart')->where('id', $order['cartid'])->find();
        $originalPrice = $cart ? floatval($cart['money']) : 0;
        if ($originalPrice > 0 && $price > $originalPrice) {
            return json(['code' => -1, 'msg' => '转让价格不能超过原购买价格（¥' . $originalPrice . '）']);
        }
        if ($originalPrice == 0 && $price > 0) {
            return json(['code' => -1, 'msg' => '免费主机转让价格必须为0']);
        }

        if ($targetUserid > 0) {
            $targetUser = Db::name('user')->where('id', $targetUserid)->find();
            if (!$targetUser) return json(['code' => -1, 'msg' => '指定的目标用户不存在']);
            if ($targetUserid == $this->apiUser['id']) return json(['code' => -1, 'msg' => '不能转让给自己']);
        }

        $now = time();
        $transferId = Db::name('host_transfer')->insertGetId([
            'order_id' => $orderId,
            'userid' => $this->apiUser['id'],
            'target_userid' => $targetUserid,
            'price' => $price,
            'original_price' => $originalPrice,
            'status' => 0,
            'email_verified' => 1,
            'contact_info' => $contactInfo,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return json(['code' => 1, 'msg' => '主机已成功发布到转让市场', 'data' => ['transfer_id' => $transferId]]);
    }

    private function apiTransferBuy()
    {
        if (!Request::instance()->isPost()) {
            return json(['code' => -1, 'msg' => '请使用 POST 请求']);
        }
        return $this->callUser('transferBuy', [], []);
    }

    private function apiTransferContact()
    {
        return $this->callUser('transferContact', [], []);
    }

    private function apiTransferDetail()
    {
        return $this->callUser('transferDetail', [], []);
    }

    private function apiTransferCancel()
    {
        if (!Request::instance()->isPost()) {
            return json(['code' => -1, 'msg' => '请使用 POST 请求']);
        }
        return $this->callUser('transferCancel', [], []);
    }

    private function apiTransferSendMsg()
    {
        if (!Request::instance()->isPost()) {
            return json(['code' => -1, 'msg' => '请使用 POST 请求']);
        }
        return $this->callUser('transferSendMsg', [], []);
    }

    private function apiTransferGetMsgs()
    {
        return $this->callUser('transferGetMsgs', [], []);
    }

    private function apiTransferUnread()
    {
        return $this->callUser('transferUnreadCount', [], []);
    }

    // ==================== 工具 ====================

    private function decorateOrders($list)
    {
        if (empty($list)) return [];
        $cartIds = array_unique(array_filter(array_column($list, 'cartid')));
        $cartMap = [];
        if (!empty($cartIds)) {
            $cartMap = Db::name('cart')->where('id', 'in', $cartIds)->column('name', 'id');
        }
        foreach ($list as &$item) {
            $item['product_name'] = isset($cartMap[$item['cartid']]) ? $cartMap[$item['cartid']] : '';
            $item['state_text'] = $this->orderStateText($item['state']);
        }
        unset($item);
        return $list;
    }

    private function orderStateText($state)
    {
        $map = ['0' => '待开通', '1' => '正常', '2' => '已暂停', '3' => '已终止'];
        return isset($map[$state]) ? $map[$state] : '未知';
    }
}
