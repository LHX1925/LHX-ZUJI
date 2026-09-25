<?php
namespace app\index\controller;
use think\Controller;
use think\Db;
use think\Request;

/**
 * 域名商城控制器
 * 支持用户上架自己的域名（必须对接阿里云万网/DNSPod API），需邮箱验证
 */
class Domain extends Base {
    public function _initialize() {
        $this->web = web_config();
        if ($this->web["template"] == "layui") {
            $this->web["template"] = "default";
        }
        if ($this->web["wh"] == "1") {
            exit($this->web["whxx"]);
        }
        ensure_activity_tables();
        // 域名商城需要登录
        if (!session("userid")) {
            $this->redirect('/login');
        }
        $this->user = Db::name('user')->where('id', session("userid"))->find();
        if (!$this->user || $this->user["state"] == "0") {
            session("userid", null);
            $this->redirect('/login');
        }
        $this->assignCommonVars();
        // 注入会员信息（user/header.html 顶部 VIP 角标需要）
        $membershipLevel = intval($this->user['membership_level'] ?? 0);
        $membershipInfo = null;
        if ($membershipLevel > 0) {
            try {
                $membershipInfo = Db::name('membership_levels')->where('level', $membershipLevel)->where('status', 1)->find();
            } catch (\Exception $e) {}
        }
        $this->assign([
            'user' => $this->user,
            'membershipLevel' => $membershipLevel,
            'membershipInfo'  => $membershipInfo,
        ]);
    }

    /**
     * 域名商城首页
     */
    public function index() {
        $domains = Db::name('domain_product')->where('status', 1)->order('id desc')->paginate(20);
        $this->assign('domains', $domains);
        $this->assign('active', 'domain');
        return $this->fetch('/'.$this->web["template"].'/user/domain', [
            'domains' => $domains,
            'user' => $this->user,
        ]);
    }

    /**
     * 我的域名
     */
    public function mine() {
        $domains = Db::name('domain_product')->where('userid', session('userid'))->order('id desc')->paginate(20);
        $this->assign('domains', $domains);
        return $this->fetch('/'.$this->web["template"].'/user/domain_mine', [
            'domains' => $domains,
            'user' => $this->user,
        ]);
    }

    /**
     * 上架域名
     */
    public function add() {
        $array = ['code' => '-1', 'msg' => ''];
        if (!Request::instance()->isPost()) {
            // 查询已启用的域名注册商 API 配置，供模板提示「未配置 API」
            $apiConfigured = false;
            try {
                $apiConfigs = Db::name('domain_api_config')->where('status', 1)->select();
                foreach ($apiConfigs as $cfg) {
                    if (!empty($cfg['appid']) && !empty($cfg['appkey'])) {
                        $apiConfigured = true;
                        break;
                    }
                }
            } catch (\Exception $e) {}
            // 显示上架页面
            return $this->fetch('/'.$this->web["template"].'/user/domain_add', [
                'user' => $this->user,
                'apiConfigured' => $apiConfigured,
            ]);
        }

        $act = input('act', '');
        $userId = session('userid');

        // 发送邮箱验证码
        if ($act == 'send_verify') {
            $mail = $this->user['mail'];
            if (empty($mail)) {
                $array['msg'] = '请先在账户设置中绑定邮箱';
                return json($array);
            }
            $code = rand(100000, 999999);
            session('domain_verify_code', $code);
            session('domain_verify_time', time());
            try {
                self::email($mail, '域名上架邮箱验证码', '<p>您的域名上架验证码为：<b style="font-size:20px;color:#2563eb;">' . $code . '</b></p><p>验证码10分钟内有效。</p>');
                return json(['code' => '1', 'msg' => '验证码已发送至您的邮箱']);
            } catch (\Throwable $e) {
                $array['msg'] = '验证码发送失败：' . $e->getMessage();
                return json($array);
            }
        }

        // 提交上架
        if ($act == 'submit') {
            $domain = strtolower(trim(input('domain', '')));
            $price = floatval(input('price', 0));
            $registrar = input('registrar', 'aliyun');
            $description = trim(input('description', ''));
            $verifyCode = trim(input('verify_code', ''));

            // 邮箱验证
            $savedCode = session('domain_verify_code');
            $savedTime = intval(session('domain_verify_time'));
            if (empty($savedCode) || empty($verifyCode) || $verifyCode != $savedCode) {
                $array['msg'] = '邮箱验证码错误';
                return json($array);
            }
            if (time() - $savedTime > 600) {
                $array['msg'] = '邮箱验证码已过期，请重新获取';
                return json($array);
            }

            // 域名格式校验
            if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $domain)) {
                $array['msg'] = '域名格式错误';
                return json($array);
            }
            if ($price <= 0) {
                $array['msg'] = '请输入有效的价格';
                return json($array);
            }
            if (!in_array($registrar, ['aliyun', 'dnspod'])) {
                $array['msg'] = '无效的域名注册商';
                return json($array);
            }

            // 检查域名是否已上架
            $exists = Db::name('domain_product')->where('domain', $domain)->where('status', 1)->find();
            if ($exists) {
                $array['msg'] = '该域名已被上架';
                return json($array);
            }

            // 对接接口验证域名归属（必须对接接口）
            $apiConfig = Db::name('domain_api_config')->where('provider', $registrar)->where('status', 1)->find();
            if (!$apiConfig || empty($apiConfig['appid']) || empty($apiConfig['appkey'])) {
                $array['msg'] = '域名注册商 API 未配置，请先联系管理员配置对接接口';
                return json($array);
            }

            // 验证域名（查询域名信息，确认接口可用）
            $verifyResult = domain_api_verify($registrar, $domain, $apiConfig);
            if ($verifyResult !== true) {
                $array['msg'] = $verifyResult ?: '域名验证失败，请确认域名已在该注册商名下';
                return json($array);
            }

            // 上架
            Db::name('domain_product')->insert([
                'userid' => $userId,
                'domain' => $domain,
                'price' => $price,
                'registrar' => $registrar,
                'description' => $description,
                'status' => 1,
                'email_verified' => 1,
                'create_time' => time(),
            ]);

            // 清除验证码
            session('domain_verify_code', null);
            session('domain_verify_time', null);

            $array['code'] = '1';
            $array['msg'] = '域名上架成功';
            return json($array);
        }

        return json($array);
    }

    /**
     * 域名详情/购买
     */
    public function detail($id = 0) {
        $domain = Db::name('domain_product')->where('id', intval($id))->find();
        if (!$domain) {
            $this->error('域名不存在', '/domain');
        }
        $seller = Db::name('user')->where('id', $domain['userid'])->field('id,name')->find();
        return $this->fetch('/'.$this->web["template"].'/user/domain_detail', [
            'domain' => $domain,
            'seller' => $seller,
            'user' => $this->user,
        ]);
    }

    /**
     * 购买域名
     */
    public function buy($id = 0) {
        $array = ['code' => '-1', 'msg' => ''];
        if (!Request::instance()->isPost()) {
            $array['msg'] = '非法请求';
            return json($array);
        }
        $userId = session('userid');
        $domain = Db::name('domain_product')->where('id', intval($id))->where('status', 1)->find();
        if (!$domain) {
            $array['msg'] = '域名不存在或已下架';
            return json($array);
        }
        if ($domain['userid'] == $userId) {
            $array['msg'] = '不能购买自己的域名';
            return json($array);
        }
        $price = floatval($domain['price']);
        if (floatval($this->user['money']) < $price) {
            $array['msg'] = '余额不足，请先充值';
            return json($array);
        }

        // 扣款
        Db::name('user')->where('id', $userId)->setDec('money', $price);
        Db::name('transaction')->insert([
            'userid' => $userId,
            'content' => '购买域名 ' . $domain['domain'] . '，扣除' . $price . '元',
            'time' => time(),
        ]);

        // 给卖家加款
        Db::name('user')->where('id', $domain['userid'])->setInc('money', $price);
        Db::name('transaction')->insert([
            'userid' => $domain['userid'],
            'content' => '域名 ' . $domain['domain'] . ' 售出，收入' . $price . '元',
            'time' => time(),
        ]);

        // 记录订单，域名标记已售
        Db::name('domain_order')->insert([
            'domain_id' => $domain['id'],
            'buyer_id' => $userId,
            'seller_id' => $domain['userid'],
            'domain' => $domain['domain'],
            'price' => $price,
            'status' => 1,
            'create_time' => time(),
        ]);
        Db::name('domain_product')->where('id', $domain['id'])->update(['status' => 2]);

        $array['code'] = '1';
        $array['msg'] = '购买成功，请等待卖家完成域名转移';
        return json($array);
    }

    /**
     * 下架自己的域名
     */
    public function del() {
        $array = ['code' => '-1', 'msg' => ''];
        if (!Request::instance()->isPost()) {
            $array['msg'] = '非法请求';
            return json($array);
        }
        $id = intval(input('id', 0));
        $domain = Db::name('domain_product')->where('id', $id)->where('userid', session('userid'))->find();
        if (!$domain) {
            $array['msg'] = '域名不存在或无权操作';
            return json($array);
        }
        Db::name('domain_product')->where('id', $id)->update(['status' => 0]);
        $array['code'] = '1';
        $array['msg'] = '已下架';
        return json($array);
    }

    // 复用 Index 控制器的 email 方法
    public static function email($email, $name, $body) {
        return \app\index\controller\Index::email($email, $name, $body, true);
    }
}
