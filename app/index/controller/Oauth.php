<?php
namespace app\index\controller;
use think\Controller;
use think\Db;
use think\Request;

class Oauth extends Controller {
    public function _initialize() {
        $this->web = web_config();
        // 若数据库中仍为旧版 layui 主题，强制使用已重构的 default 主题，避免 fetch 模板路径错误导致 500
        if ($this->web["template"] == "layui") {
            $this->web["template"] = "default";
        }
        // 确保用户表字段完整（兼容旧版数据库）
        ensure_user_columns();
        // 确保邮箱验证记录表存在（QQ 一键注册强制邮箱验证用）
        ensure_email_verify_table();
    }

    /**
     * 获取正确的回调地址：优先使用数据库配置，自动校验并以 /oauth/callback 结尾补充
     */
    private function getCallback() {
        $callback = isset($this->web['oauth_callback']) ? trim($this->web['oauth_callback']) : '';
        // 如果配置为空或不以 /oauth/callback 结尾，从当前请求自动构造
        if (empty($callback) || strpos($callback, '/oauth/callback') === false) {
            $callback = request()->domain() . '/oauth/callback';
        }
        return $callback;
    }

    /**
     * 请求 mapay 聚合登录接口（SSL 证书校验失败时兜底重试一次）
     */
    private function apiGet($url) {
        $result = http_get($url);
        if ($result !== false) {
            return $result;
        }
        // 兜底：服务器 CA 配置异常导致证书校验失败时，关闭校验重试
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            $res = curl_exec($ch);
            $err = curl_error($ch);
            curl_close($ch);
            if ($err === '' && $res !== false) {
                return $res;
            }
        }
        return false;
    }

    /**
     * 聚合登录入口
     * @param string $type 登录类型：qq
     */
    public function login($type) {
        if ($type !== 'qq') {
            $this->error('不支持的登录类型');
        }
        if (!$this->web['oauth_enabled']) {
            $this->error('聚合登录功能未启用');
        }
        $appid = $this->web['oauth_appid'];
        $appkey = $this->web['oauth_appkey'];
        $callback = $this->getCallback();
        if (empty($appid) || empty($appkey)) {
            $this->error('聚合登录参数未配置完整，请联系管理员');
        }
        $url = "https://login.mapay.cn/connect.php?act=login&appid={$appid}&appkey={$appkey}&type={$type}&redirect_uri=" . urlencode($callback);
        $result = $this->apiGet($url);
        $data = json_decode($result, true);
        if ($data && isset($data['code']) && $data['code'] == 0 && !empty($data['url'])) {
            $this->redirect($data['url']);
        } else {
            $msg = isset($data['msg']) ? $data['msg'] : '获取登录地址失败';
            $this->error('获取登录地址失败：' . $msg);
        }
    }

    /**
     * 聚合登录回调（统一处理登录和绑定回调）
     */
    public function callback() {
        if (!$this->web['oauth_enabled']) {
            $this->error('聚合登录功能未启用');
        }
        $type = input('type');
        $code = input('code');
        if ($type !== 'qq') {
            $this->error('不支持的登录类型');
        }
        if (empty($code)) {
            $this->error('回调参数缺失');
        }
        $appid = $this->web['oauth_appid'];
        $appkey = $this->web['oauth_appkey'];
        $url = "https://login.mapay.cn/connect.php?act=callback&appid={$appid}&appkey={$appkey}&type={$type}&code={$code}";
        $result = $this->apiGet($url);
        $data = json_decode($result, true);
        if (!$data || !isset($data['code']) || $data['code'] != 0 || empty($data['social_uid'])) {
            $msg = isset($data['msg']) ? $data['msg'] : '回调验证失败';
            $this->error('登录失败：' . $msg);
        }
        $social_uid = $data['social_uid'];
        $oauthField = 'oauth_' . $type;

        // 判断是否为已登录用户绑定 OAuth（兼容 session 丢失场景，同时读取 cookie 兜底）
        $bindUserId = session('oauth_bind_userid') ?: cookie('oauth_bind_userid') ?: input('bind_userid');
        if ($bindUserId) {
            // 已登录用户绑定QQ
            session('oauth_bind_userid', null);
            cookie('oauth_bind_userid', null);
            $existBind = Db::name('user')->where($oauthField, $social_uid)->where('id', '<>', $bindUserId)->find();
            if ($existBind) {
                $this->error('该QQ账号已被其他用户绑定');
            }
            $updateResult = Db::name('user')->where('id', $bindUserId)->update([$oauthField => $social_uid]);
            if ($updateResult === false) {
                $this->error('绑定失败，数据库更新异常，请稍后重试');
            }
            $this->redirect('/user/information');
        }

        // 未登录用户：查找已绑定的用户进行登录
        $user = Db::name('user')->where($oauthField, $social_uid)->find();
        if ($user) {
            if ($user['state'] == '0') {
                $this->error('账户已被冻结，禁止登录');
            }
            session_regenerate_id(true);
            session('userid', $user['id']);
            $loginIp = function_exists('get_client_ip') ? get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
            // 从 faceimg URL 提取真实 QQ 号，每次登录刷新头像和 QQ 号
            $faceimg = isset($data['faceimg']) ? $data['faceimg'] : '';
            $realQq = '';
            if ($faceimg && preg_match('/[?&]nk=(\d{4,12})/', $faceimg, $m)) {
                $realQq = $m[1];
            }
            $updateData = [
                'last_login_time' => time(),
                'last_login_ip' => $loginIp,
                'last_login_region' => get_ip_region($loginIp)
            ];
            if ($faceimg) $updateData['avatar'] = $faceimg;
            // 如果提取到真实QQ号，且当前qq字段为空或不是纯数字（旧令牌），则覆盖
            if ($realQq && (empty($user['qq']) || !preg_match('/^\d{4,12}$/', $user['qq']))) $updateData['qq'] = $realQq;
            try {
                Db::name('user')->where('id', $user['id'])->update($updateData);
            } catch (\Exception $e) {}
            $this->redirect('/user/index');
        }

        // 未绑定：QQ 一键注册（强制邮箱+邮件验证）
        // 安全起见：所有新用户必须先填写邮箱，并通过邮件验证，才能完成注册。
        // 这里不再直接 insert 新用户，而是先 stash oauth data 跳转到 /oauth/bind，
        // 由用户在 bind 页面提交邮箱 + 接收验证码 + 完成用户名/密码设置。
        $faceimg = isset($data['faceimg']) ? $data['faceimg'] : '';
        $realQq = '';
        if ($faceimg && preg_match('/[?&]nk=(\d{4,12})/', $faceimg, $m)) {
            $realQq = $m[1];
        }
        try {
            $nickname = isset($data['nickname']) ? trim($data['nickname']) : '';
            // 将 OAuth 回调的临时数据存入 session，供后续 /oauth/bind 流程使用
            session('oauth_data', [
                'type'       => $type,
                'social_uid' => $social_uid,
                'nickname'   => $nickname,
                'avatar'     => $faceimg,
                'real_qq'    => $realQq,
                'create_time'=> time(),
            ]);
            $this->redirect('/oauth/bind');
        } catch (\Throwable $e) {
            $this->error('QQ 一键注册准备失败：' . $e->getMessage());
        }
    }

    /**
     * 新用户绑定页面（OAuth 登录后设置密码 + 强制邮箱验证）
     * 支持三个动作：
     *   act=send_email_code → 给指定邮箱发 6 位验证码
     *   act=verify_email_code → 校验刚才发送的验证码（ajax 实时校验，可选）
     *   act=save → 最终提交注册（要求邮箱验证码校验通过）
     */
    public function bind() {
        $oauthData = session('oauth_data');
        if (!$oauthData) {
            $this->redirect('/login');
        }
        // 确保邮箱验证表存在
        ensure_email_verify_table();
        // 是否启用邮件发送（与全局邮件开关一致）
        $emailEnabled = isset($this->web['email']) && $this->web['email'] == '1';

        if (Request::instance()->isPost()) {
            $act = input('act', '');
            try {
                // 1) 发送邮箱验证码
                if ($act == 'send_email_code') {
                    $email = trim(input('email', ''));
                    if (empty($email)) {
                        return json(['code' => -1, 'msg' => '请先填写邮箱地址']);
                    }
                    if (!preg_match('/^[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}$/', $email)) {
                        return json(['code' => -1, 'msg' => '邮箱格式错误']);
                    }
                    // 防临时邮箱
                    if (function_exists('is_disposable_email') && is_disposable_email($email)) {
                        return json(['code' => -1, 'msg' => '不支持临时邮箱，请使用常用邮箱']);
                    }
                    // 邮箱唯一性
                    if (Db::name('user')->where('mail', $email)->find()) {
                        return json(['code' => -1, 'msg' => '该邮箱已被注册']);
                    }
                    if (!$emailEnabled) {
                        return json(['code' => -1, 'msg' => '站点未启用邮件发送，无法验证邮箱，请联系管理员开启邮箱通知']);
                    }
                    // 频率限制：同一邮箱 60 秒只能发一次
                    if (!rate_limit('oauth_bind_email_' . strtolower($email), 1, 60)) {
                        return json(['code' => -1, 'msg' => '验证码发送过于频繁，请 60 秒后再试']);
                    }
                    $code = str_pad((string)mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
                    $token = md5(uniqid('oauth_bind_' . $email, true));
                    $now = time();
                    // 清理邮箱历史未验证记录，避免堆积
                    try {
                        Db::name('email_verify')->where('mail', $email)->delete();
                    } catch (\Exception $e) {}
                    Db::name('email_verify')->insert([
                        'mail'        => $email,
                        'token'       => $token,
                        'code'        => $code,
                        'verified'    => 0,
                        'create_time' => $now,
                        'expire_time' => $now + 600,
                    ]);
                    $body = '<p>您好，</p><p>您正在进行 QQ 一键注册，本次邮箱验证的验证码为：</p>' .
                            '<p style="text-align:center;margin:24px 0;">' .
                            '<span style="display:inline-block;background:#eff6ff;color:#2563eb;font-size:28px;font-weight:700;padding:14px 32px;border-radius:10px;letter-spacing:6px;border:1px solid #bfdbfe;">' . $code . '</span></p>' .
                            '<p style="color:#64748b;font-size:13px;">验证码 10 分钟内有效，请勿将验证码告知他人。如非本人操作，请忽略此邮件。</p>';
                    try {
                        $this->email($email, '邮箱验证 - ' . ($this->web['name'] ?? '站点注册'), $body);
                    } catch (\Throwable $e) {
                        return json(['code' => -1, 'msg' => '邮件发送失败：' . $e->getMessage()]);
                    }
                    // 同时把 token 暂存到 session，提交时校验会更严格（防止别处抢码）
                    session('oauth_bind_email', $email);
                    session('oauth_bind_token', $token);
                    return json(['code' => 1, 'msg' => '验证码已发送到 ' . $email . '，请查收']);
                }

                // 2) 校验邮箱验证码
                if ($act == 'verify_email_code') {
                    $email = trim(input('email', ''));
                    $code  = trim(input('code', ''));
                    if (empty($email) || empty($code)) {
                        return json(['code' => -1, 'msg' => '请输入邮箱和验证码']);
                    }
                    $savedToken = session('oauth_bind_token');
                    $row = Db::name('email_verify')
                        ->where('mail', $email)
                        ->where('code', $code)
                        ->where('verified', 0)
                        ->where('expire_time', '>', time())
                        ->order('id desc')
                        ->find();
                    if (!$row) {
                        return json(['code' => -1, 'msg' => '验证码错误或已过期']);
                    }
                    // 双重校验：session 中的 token 与表中一致
                    if ($savedToken && $savedToken !== $row['token']) {
                        return json(['code' => -1, 'msg' => '验证码与邮箱不匹配']);
                    }
                    return json(['code' => 1, 'msg' => '邮箱验证通过']);
                }

                // 3) 最终提交注册
                if ($act == 'save') {
                    $username = trim(input('username'));
                    $password = input('password');
                    $password2 = input('password2');
                    $email = trim(input('email', ''));
                    $code  = trim(input('code', ''));
                    if (empty($username) || empty($password) || empty($password2) || empty($email) || empty($code)) {
                        return json(['code' => -1, 'msg' => '请填写完整信息（含邮箱和验证码）']);
                    }
                    if ($password != $password2) {
                        return json(['code' => -1, 'msg' => '两次密码不一致']);
                    }
                    if (strlen($password) < 6) {
                        return json(['code' => -1, 'msg' => '密码长度不能少于6位']);
                    }
                    if (!preg_match('/^[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}$/', $email)) {
                        return json(['code' => -1, 'msg' => '邮箱格式错误']);
                    }
                    // 校验用户名是否已存在
                    $existUser = Db::name('user')->where('user', $username)->find();
                    if ($existUser) {
                        return json(['code' => -1, 'msg' => '该用户名已被使用，请更换']);
                    }
                    // 校验邮箱是否已被注册
                    if (Db::name('user')->where('mail', $email)->find()) {
                        return json(['code' => -1, 'msg' => '该邮箱已被注册']);
                    }
                    // 校验邮箱验证码（最新一条且在有效期内、且未消费过）
                    $savedToken = session('oauth_bind_token');
                    $row = Db::name('email_verify')
                        ->where('mail', $email)
                        ->where('code', $code)
                        ->where('expire_time', '>', time())
                        ->order('id desc')
                        ->find();
                    if (!$row) {
                        return json(['code' => -1, 'msg' => '邮箱验证码错误或已过期']);
                    }
                    if ($savedToken && $savedToken !== $row['token']) {
                        return json(['code' => -1, 'msg' => '邮箱与发送验证码的邮箱不一致']);
                    }
                    $oauthField = 'oauth_' . $oauthData['type'];
                    $upperid = cookie("upperid");
                    if (!$upperid || !Db::name('user')->where("id", $upperid)->find()) {
                        $upperid = "";
                    }
                    // 从 faceimg URL 提取真实 QQ 号（nk= 参数），social_uid 是令牌不是 QQ 号
                    $realQq = isset($oauthData['real_qq']) ? $oauthData['real_qq'] : '';
                    $newUserData = [
                        'user' => $username,
                        'name' => $oauthData['nickname'] ?: $username,
                        'password' => password_hash($password, PASSWORD_DEFAULT),
                        'money' => '0.00',
                        'mail' => $email,
                        'qq' => $realQq,
                        'avatar' => isset($oauthData['avatar']) ? $oauthData['avatar'] : '',
                        'address' => '',
                        'aff' => random(8),
                        'affmoney' => '0',
                        'upperid' => $upperid,
                        'time' => time(),
                        'state' => '1',
                        'regtime' => date('Y-m-d H:i:s'),
                        'regip' => function_exists('get_client_ip') ? get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
                        $oauthField => $oauthData['social_uid'],
                    ];
                    // 兼容 user 表字段差异，自动过滤不存在的字段
                    $columns = Db::query("SHOW COLUMNS FROM " . config('database.prefix') . "user");
                    $columnNames = array_column($columns, 'Field');
                    foreach (array_keys($newUserData) as $fieldName) {
                        if (!in_array($fieldName, $columnNames)) {
                            unset($newUserData[$fieldName]);
                        }
                    }
                    $newUserId = Db::name('user')->insertGetId($newUserData);
                    if (!$newUserId) {
                        return json(['code' => -1, 'msg' => '用户注册失败，请稍后重试']);
                    }
                    // 标记邮箱已验证
                    Db::name('email_verify')
                        ->where('mail', $email)
                        ->where('token', $row['token'])
                        ->update(['verified' => 1]);
                    session('oauth_data', null);
                    session('oauth_bind_email', null);
                    session('oauth_bind_token', null);
                    session_regenerate_id(true);
                    session('userid', $newUserId);
                    return json(['code' => 1, 'msg' => '注册成功，已自动登录，欢迎使用', 'url' => '/user/index']);
                }

                return json(['code' => -1, 'msg' => '未知操作']);
            } catch (\Throwable $e) {
                return json(['code' => -1, 'msg' => '操作失败：' . $e->getMessage()]);
            }
        }

        $this->assign([
            'webname'       => $this->web['name'],
            'description'   => isset($this->web['description']) ? $this->web['description'] : '',
            'keywords'      => isset($this->web['keywords']) ? $this->web['keywords'] : '',
            'favicon'       => isset($this->web['favicon']) ? $this->web['favicon'] : '',
            'web'           => $this->web,
            'oauthData'     => $oauthData,
            'userstate'     => session('userid') ? "1" : "0",
            'emailEnabled'  => $emailEnabled,
            'bindEmail'     => session('oauth_bind_email') ?: '',
        ]);
        return $this->fetch('/' . $this->web['template'] . '/oauth/bind');
    }

    /**
     * 已登录用户绑定 OAuth（从用户中心触发）
     * 跳转到QQ登录页，回调时通过 callback() 中的 session('oauth_bind_userid') 判断
     */
    public function userBind($type) {
        $userid = session('userid');
        if (!$userid) {
            $this->error('请先登录');
        }
        if ($type !== 'qq') {
            $this->error('不支持的绑定类型');
        }
        if (!$this->web['oauth_enabled']) {
            $this->error('聚合登录功能未启用');
        }
        $appid = $this->web['oauth_appid'];
        $appkey = $this->web['oauth_appkey'];
        $callback = $this->getCallback();
        if (empty($appid) || empty($appkey)) {
            $this->error('聚合登录参数未配置完整，请联系管理员');
        }
        // Mapay 回调时不会透传 redirect_uri 中的额外参数，因此同时使用 session + cookie 标记绑定用户
        $url = "https://login.mapay.cn/connect.php?act=login&appid={$appid}&appkey={$appkey}&type={$type}&redirect_uri=" . urlencode($callback);
        $result = $this->apiGet($url);
        $data = json_decode($result, true);
        if ($data && isset($data['code']) && $data['code'] == 0 && !empty($data['url'])) {
            session('oauth_bind_userid', $userid);
            cookie('oauth_bind_userid', $userid, 300);
            $this->redirect($data['url']);
        } else {
            $msg = isset($data['msg']) ? $data['msg'] : '获取绑定地址失败';
            $this->error('获取绑定地址失败：' . $msg);
        }
    }

    /**
     * 解绑 OAuth
     */
    public function unbind($type) {
        $userid = session('userid');
        if (!$userid) {
            return json(['code' => -1, 'msg' => '请先登录']);
        }
        if ($type !== 'qq') {
            return json(['code' => -1, 'msg' => '不支持的绑定类型']);
        }
        $oauthField = 'oauth_' . $type;
        Db::name('user')->where('id', $userid)->update([$oauthField => '']);
        return json(['code' => 1, 'msg' => '解绑成功']);
    }
}