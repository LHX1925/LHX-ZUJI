<?php
namespace app\admin\controller;
use think\Controller;
use think\Db;
use think\Request;

class Login extends Controller
{

    public function index()
    {
		$web=web_config();
		// 如果数据库中仍配置为旧版 layui 后台主题，强制使用已重构的 default 主题
		if($web["admintemplate"]=="layui"){
			$web["admintemplate"]="default";
		}

	if(Request::instance()->isPost()) {
            // CSRF 防护
            if (!csrf_verify(input('__token__', ''))) {
                $array["code"] = "-1";
                $array["msg"] = "安全验证失败，请刷新页面后重试";
                return $array;
            }

            $user=input("user");
			$password=input("password");
            $captcha=input("captcha");
			$ip = request()->ip();

			// ── 第0层：滑块验证码 ──
if(!\app\index\controller\Captcha::check()){
	$array["code"]="-2";
	$array["msg"]="验证码错误!";
	return $array;
}

			// ── 第0.5层：蜜罐字段（Burp Suite等工具会自动填充隐藏字段）──
			$honeypot = input('_hp', '');
			if (!empty($honeypot)) {
				// 蜜罐被填充 → 机器人行为，返回虚假成功响应迷惑攻击者
				$array["code"]="-1";
				$array["msg"]="账号或密码错误!";
				self::logLogin(0, $user, 0, '蜜罐触发(BOT)');
				return $array;
			}

			// ── 第0.6层：请求间隔检查（防自动化工具高速重放）──
			if (!$this->checkRequestInterval($ip)) {
				$array["code"]="-1";
				$array["msg"]="操作过快，请稍后再试";
				self::logLogin(0, $user, 0, '请求间隔过短(BOT)');
				return $array;
			}

			// ── 第0.7层：User-Agent 检查（Burp Suite 默认UA特征）──
			if ($this->isAutomatedUA()) {
				$array["code"]="-1";
				$array["msg"]="账号或密码错误!";
				self::logLogin(0, $user, 0, '自动化工具UA(BOT)');
				return $array;
			}

			// ── 第1层：IP 频率限制（10分钟内最多 15 次尝试） ──
			if (!$this->checkIpRateLimit($ip, 15, 600)) {
				$array["code"]="-1";
				$array["msg"]="登录尝试过于频繁，请10分钟后再试";
				self::logLogin(0, $user, 0, 'IP频率超限');
				return $array;
			}

			// ── 第2层：IP 封锁检查（30分钟内同一IP失败 ≥10 次） ──
			if ($this->isIpBlocked($ip)) {
				$array["code"]="-1";
				$array["msg"]="当前网络登录失败次数过多，请30分钟后再试";
				self::logLogin(0, $user, 0, 'IP已被封锁');
				return $array;
			}

			// ── 第3层：账号锁定检查（15分钟内同一账号失败 ≥5 次） ──
			if ($this->isAccountLocked($user)) {
				$array["code"]="-1";
				$array["msg"]="该账号登录失败次数过多，请15分钟后再试";
				self::logLogin(0, $user, 0, '账号已被锁定');
				return $array;
			}

			// ── 第4层：渐进延迟（根据近期失败次数） ──
			$this->applyProgressiveDelay($ip, $user);

			$data=Db::name('admin')->where([
			"user"=>$user,
			])->find();
if($data && $data['status'] == 0) {
	$array["code"]="-1";
	$array["msg"]="账号已被禁用!";
	self::logLogin($data['id'], $user, 0, '账号已禁用');
	return $array;
}
if($data){
$data1=password_verify($password,$data["password"]);
			if($data1) {
				session_regenerate_id(true); // 防止会话固定攻击
				session("adminid",$data["id"]);
				$array["code"]="1";
				$array["msg"]="登录成功!";
				self::logLogin($data['id'], $data['user'], 1, '登录成功');
				admin_op_log('login', '管理员登录', ['ip' => function_exists('get_client_ip') ? get_client_ip() : '']);
			} else {
				$array["code"]="-1";
				$array["msg"]="密码错误!";
				self::logLogin($data['id'], $data['user'], 0, '密码错误');
			}
}else{
				$array["code"]="-2";
				$array["msg"]="账号不存在!";
				self::logLogin(0, $user, 0, '账号不存在');
}
			return $array;
		}
	return $this->fetch('/'.$web["admintemplate"]."/login",[
            'webname'  => $web['name'],
			'admintemplate' => $web['admintemplate'],
			// 真实站点信息（LOGO 用 web 表 logo 字段，留空回退 logo_icon 图标）
			'web'       => $web,
			// ?out=1 表示从后台安全退出跳转而来，视图顶部显示「您已安全退出登录」提示
			'loggedOut' => input('param.out') == '1',
			// 登录成功后进入后台首页的地址（自动适配「后台登录入口自定义」）
			'adminHomeUrl' => function_exists('admin_index_url')
				? admin_index_url()
				: ((isHTTPS() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '') . '/admin/index'),
]);
    }

	/**
	 * 记录管理员登录日志
	 */
	private static function logLogin($adminId, $username, $status, $msg) {
		try {
			$tableName = \think\Db::name('admin_login_log')->getTable();
			\think\Db::execute("CREATE TABLE IF NOT EXISTS `{$tableName}` (
				`id` int(11) NOT NULL AUTO_INCREMENT,
				`admin_id` int(11) DEFAULT 0 COMMENT '管理员ID',
				`username` varchar(50) DEFAULT '' COMMENT '登录用户名',
				`ip` varchar(50) DEFAULT '' COMMENT '登录IP',
				`status` tinyint(1) DEFAULT 0 COMMENT '1=成功 0=失败',
				`msg` varchar(255) DEFAULT '' COMMENT '备注',
				`create_time` int(11) DEFAULT 0,
				PRIMARY KEY (`id`),
				KEY `idx_admin_id` (`admin_id`),
				KEY `idx_create_time` (`create_time`),
				KEY `idx_ip_status` (`ip`, `status`, `create_time`),
				KEY `idx_username_status` (`username`, `status`, `create_time`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8");
			\think\Db::name('admin_login_log')->insert([
				'admin_id'    => $adminId,
				'username'    => $username,
				'ip'          => request()->ip(),
				'status'      => $status,
				'msg'         => $msg,
				'create_time' => time(),
			]);
		} catch (\Exception $e) {}

		// ── 同步写入「系统重要记录」中心（后台登录记录）──
		try {
			if (function_exists('sys_record')) {
				sys_record('admin_login', ($status ? '管理员登录成功' : '管理员登录失败') . '：' . $username, [
					'账号'   => $username,
					'结果'   => $status ? '成功' : '失败',
					'原因'   => $msg,
					'时间'   => date('Y-m-d H:i:s'),
				], [
					'operator_type' => 'admin',
					'operator_id'   => intval($adminId),
					'operator_name' => $username,
					'level'         => $status ? 1 : 3,
					'summary'       => ($status ? '登录成功' : '登录失败') . ' · ' . $msg,
				]);
			}
		} catch (\Exception $e) {}
	}

	/**
	 * IP 频率限制（基于文件计数器，轻量无需查库）
	 * @param string $ip
	 * @param int $maxTimes 最大允许次数
	 * @param int $windowSec 时间窗口（秒）
	 * @return bool true=允许，false=超限
	 */
	private function checkIpRateLimit($ip, $maxTimes, $windowSec)
	{
		$ipKey = md5($ip . 'admin_login_rate');
		$cacheDir = defined('LOG_PATH') ? LOG_PATH : (PATH . 'runtime/log/');
		$rateDir = $cacheDir . 'rate_limit/';
		if (!is_dir($rateDir)) {
			@mkdir($rateDir, 0755, true);
		}
		$file = $rateDir . $ipKey . '.lim';

		$now = time();
		$records = [];
		if (file_exists($file)) {
			$content = @file_get_contents($file);
			if ($content) {
				$records = @json_decode($content, true) ?: [];
			}
		}

		// 清理过期记录
		$records = array_filter($records, function ($t) use ($now, $windowSec) {
			return ($now - $t) < $windowSec;
		});

		if (count($records) >= $maxTimes) {
			return false;
		}

		$records[] = $now;
		@file_put_contents($file, json_encode($records), LOCK_EX);
		return true;
	}

	/**
	 * 检查 IP 是否已被封锁（30分钟内失败 ≥10 次）
	 */
	private function isIpBlocked($ip)
	{
		try {
			$cutoff = time() - 1800; // 30分钟
			$count = \think\Db::name('admin_login_log')
				->where('ip', $ip)
				->where('status', 0)
				->where('create_time', '>', $cutoff)
				->count();
			return $count >= 10;
		} catch (\Exception $e) {
			return false;
		}
	}

	/**
	 * 检查账号是否已被锁定（15分钟内失败 ≥5 次）
	 */
	private function isAccountLocked($username)
	{
		try {
			$cutoff = time() - 900; // 15分钟
			$count = \think\Db::name('admin_login_log')
				->where('username', $username)
				->where('status', 0)
				->where('create_time', '>', $cutoff)
				->count();
			return $count >= 5;
		} catch (\Exception $e) {
			return false;
		}
	}

	/**
	 * 渐进延迟：根据近期失败次数增加响应延迟，拖慢撞库速度
	 */
	private function applyProgressiveDelay($ip, $username)
	{
		try {
			$cutoff = time() - 600; // 10分钟
			$recentFails = \think\Db::name('admin_login_log')
				->where(function ($query) use ($ip, $username) {
					$query->where('ip', $ip)->whereOr('username', $username);
				})
				->where('status', 0)
				->where('create_time', '>', $cutoff)
				->count();
		} catch (\Exception $e) {
			$recentFails = 0;
		}

		if ($recentFails > 0) {
			// 每次失败增加 0.5 秒延迟，最多 5 秒
			$delay = min($recentFails * 500, 5000);
			usleep($delay * 1000);
		}
	}

	/**
	 * 请求间隔检查：同一IP两次POST请求间隔不得小于2秒（防自动化工具高速发包）
	 */
	private function checkRequestInterval($ip)
	{
		$ipKey = md5($ip . 'admin_login_interval');
		$cacheDir = defined('LOG_PATH') ? LOG_PATH : (PATH . 'runtime/log/');
		$rateDir = $cacheDir . 'rate_limit/';
		if (!is_dir($rateDir)) {
			@mkdir($rateDir, 0755, true);
		}
		$file = $rateDir . $ipKey . '.ts';
		$now = time();
		$lastTime = 0;
		if (file_exists($file)) {
			$lastTime = (int) @file_get_contents($file);
		}
		@file_put_contents($file, $now, LOCK_EX);
		if ($lastTime > 0 && ($now - $lastTime) < 2) {
			return false; // 间隔不足2秒
		}
		return true;
	}

	/**
	 * 检测自动化工具 User-Agent（Burp Suite / sqlmap / 扫描器等）
	 */
	private function isAutomatedUA()
	{
		$ua = strtolower(request()->server('HTTP_USER_AGENT', ''));
		if (empty($ua)) return true; // 空UA高度可疑
		$blockedPatterns = [
			'burp',           // Burp Suite
			'sqlmap',         // sqlmap
			'nikto',          // Nikto scanner
			'nmap',           // Nmap
			'acunetix',       // Acunetix
			'netsparker',     // Netsparker
			'webinspect',     // WebInspect
			'nessus',         // Nessus
			'hydra',          // Hydra
			'medusa',         // Medusa
			'gobuster',       // GoBuster
			'dirbuster',      // DirBuster
			'wfuzz',          // Wfuzz
			'ffuf',           // ffuf
			'nuclei',         // Nuclei
			'zgrab',          // ZGrab
			'masscan',        // Masscan
			'python-requests', // Python requests (常见于脚本)
			'python-urllib',   // Python urllib
			'go-http-client',  // Go HTTP client
			'curl/',           // cURL (非浏览器)
			'wget/',           // wget
		];
		foreach ($blockedPatterns as $pattern) {
			if (strpos($ua, $pattern) !== false) {
				return true;
			}
		}
		return false;
	}

}
