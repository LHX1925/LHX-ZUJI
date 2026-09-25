<?php
namespace app\index\controller;
use think\Controller;
use think\Db;
use think\Request;
use PHPMailer\PHPMailer\PHPMailer;

class Index extends Base {
	public function _initialize() {
		// 确保用户表包含最后登录相关字段
		ensure_user_columns();
		// 确保邮箱验证记录表存在
		ensure_email_verify_table();

		$this->web=web_config();
		// 如果数据库中仍配置为旧版 layui 主题，强制使用已重构的 default 主题
		if($this->web["template"]=="layui"){
			$this->web["template"]="default";
		}
if($this->web["wh"]=="1"){
exit($this->web["whxx"]);
}
       if(session("userid")) {
$judge=Db::name("user")->where("id",session("userid"))->find();
if($judge){
if($judge["state"]=="0"){
session("userid",null);
$this->redirect('/login');
}
			$userstate="1";
}else{
session("userid",null);
			$userstate="0";
}
		} else {
			$userstate="0";
		}
$file=file_exists(PATH."/app/index/view/".$this->web["template"]."/set.php");
if($file){
if($this->web["templateset"]){
$tempset=json_decode($this->web["templateset"],true);
$templateset=array(""=>"");
for($i=0;$i<count($tempset);$i++){
$templateset=array_merge($templateset,array($tempset[$i]["name"]=>$tempset[$i]["value"]));
}
}else{
$templateset=array(""=>"");
}
}else{
$templateset=array(""=>"");
}
		$global_datacenters_raw = $this->web['global_datacenters'] ?? '';
		$global_datacenters = json_decode($global_datacenters_raw, true);
		if (empty($global_datacenters) || !is_array($global_datacenters)) {
			$global_datacenters = [
				['name' => '中国香港', 'region' => 'Hong Kong / Asia Pacific', 'status' => '运行正常', 'icon' => 'fas fa-map-marker-alt'],
				['name' => '美国洛杉矶', 'region' => 'US West / California', 'status' => '运行正常', 'icon' => 'fas fa-map-marker-alt'],
				['name' => '新加坡', 'region' => 'Singapore / Asia Pacific', 'status' => '运行正常', 'icon' => 'fas fa-map-marker-alt'],
				['name' => '日本东京', 'region' => 'Japan / Tokyo', 'status' => '运行正常', 'icon' => 'fas fa-map-marker-alt'],
			];
		}

		$this->assign([
		            'webname'  => $this->web['name'],
		            'description'  => $this->web['description'],
		            'keywords'  => $this->web['keywords'],
		            'favicon'  => $this->web['favicon'],
		            'web'      => $this->web,
		            'global_datacenters' => $global_datacenters,
		"userstate"=>$userstate,
"templateset"=>$templateset,
		        ]);
	}
public function null(){
return $this->fetch('/'.$this->web["template"].'/index/404',[		
		]);

}




	public function aff($upper) {
$data=Db::name('user')->where('aff',$upper)->find();
if($data){
cookie("upperid",$data["id"]);
	$this->redirect("/index");
}else{
	$this->redirect("/index");
}
}
	public function pwreset() {
	if(Request::instance()->isPost()) {
$act=input("act");
if($act=="sendcode"){
$array=["code"=>"-1","msg"=>""];
$email=input("email");
$captcha=input("captcha");
// CSRF 防护
$csrf = input('csrf_token', '');
if (!csrf_verify($csrf)) {
	$array["msg"] = "安全验证失败，请刷新页面后重试";
	return $array;
}
// 邮箱发送频率限制（同一邮箱每60秒最多发1次，同一IP每60秒最多发3次）
if (!rate_limit('pwreset_email_' . $email, 1, 60)) {
	$array["msg"] = "验证码发送过于频繁，请60秒后再试";
	return $array;
}
if (!rate_limit('pwreset_ip_' . request()->ip(), 3, 60)) {
	$array["msg"] = "操作过于频繁，请稍后再试";
	return $array;
}
if($email=="" || $captcha==""){
	$array["code"]="-1";
	$array["msg"]="必填参数不可为空!";
}else{
if(!\app\index\controller\Captcha::check()){
	$array["code"]="-1";
	$array["msg"]="验证码错误!";
} else {
$data1=Db::name('user')->where("mail",$email)->find();
if(!$data1){
	$array["code"]="-1";
	$array["msg"]="未找到该邮箱注册的账号!";
}else{
session("zhmmzh",$data1["user"]);
$zhmmyzm=random(6,'0123456789');
session("zhmmyzm",$zhmmyzm);
if($this->web["email"]=="1"){
if($data1["mail"]){
$codeBody = "<p>您好，</p><p>您正在找回 {$this->web['name']} 账号密码，本次验证码为：</p><p style='text-align:center;margin:28px 0;'><span style='display:inline-block;background:#eff6ff;color:#2563eb;font-size:28px;font-weight:700;padding:14px 32px;border-radius:10px;letter-spacing:4px;border:1px solid #bfdbfe;'>{$zhmmyzm}</span></p><p style='color:#64748b;font-size:13px;'>验证码 10 分钟内有效，请勿将验证码告知他人。如非本人操作，请忽略此邮件。</p>";
$mailbox=$this->email($data1["mail"],"找回密码通知",$codeBody);
$array["code"]="1";
$array["msg"]="已发送验证码到邮箱!";
}
}else{
$array["code"]="-1";
$array["msg"]="站点未开启邮箱通知!";
}
}
}
}
return $array;
}

if($act=="reset"){
$array=["code"=>"-1","msg"=>""];
$email=input("email");
$code=input("code");
$password=input("password");
$repassword=input("repassword");
// CSRF 防护
$csrf = input('csrf_token', '');
if (!csrf_verify($csrf)) {
	$array["msg"] = "安全验证失败，请刷新页面后重试";
	return $array;
}
if($email=="" || $code=="" || $password=="" || $repassword==""){
	$array["code"]="-1";
	$array["msg"]="必填参数不可为空!";
}elseif(strlen($password) < 6){
	$array["code"]="-1";
	$array["msg"]="密码长度不能少于6位!";
}else{
$data1=Db::name('user')->where("mail",$email)->find();
if(!$data1){
	$array["code"]="-1";
	$array["msg"]="未找到该邮箱注册的账号!";
}else{
if($password!=$repassword){
	$array["code"]="-1";
	$array["msg"]="两次密码不一致!";
}else{
if(!session("zhmmyzm") || !session("zhmmzh")){
	$array["code"]="-1";
	$array["msg"]="请你重新获取邮箱验证码!";
}else{
if(session("zhmmzh")!=$data1["user"]){
	$array["code"]="-1";
	$array["msg"]="账号不匹配,请重新获取验证码!";
}else{
if(session("zhmmyzm")==$code){
$data2=Db::name('user')->where("id",$data1["id"])->update([
"password"=>password_hash($password,PASSWORD_DEFAULT),
]);
if($this->web["email"]=="1"){
if($data1["mail"]){
$mailbox=$this->email($data1["mail"],"重置密码通知","你账号:".$data1["user"]."在时间:".date("Y-m-d H:i:s")."在本站重置密码成功!<br/><br/>");
}
}
	$array["code"]="1";
	$array["msg"]="已成功重置密码!";
					}else{
					$array["code"]="-1";
					$array["msg"]="填写的邮箱验证码错误!";
					}
				}
			}
		}
	}
}
return $array;
}
}


		return $this->fetch('/'.$this->web["template"].'/index/pwreset',[		
		]);
	}

	public function notify($id) {
$data1=Db::name('pays')->where('id',$id)->find();
if(!$data1){
exit("<title>出错啦!</title>没有此支付通道!");
}
@include PATH."plugins/pay/".$data1["plugins"]."/notify.php";
}

	public function index() {
// 处理待开通订单（cron 未配置时兜底）
process_pending_host_orders();
// 记录访客访问（同一会话仅记录一次）
if(function_exists('log_visitor')) log_visitor();
// 确保公告表存在（兼容全新安装后未访问过后台）
ensure_announcements_table();
$data=Db::name('announcements')->where('status',1)->order('id desc')->paginate(5);
$countserver=Db::name('server')->count();
$countuser=Db::name('user')->count();
$countorder=Db::name('order')->count();
$sumpay=Db::name('pay')->where("state",1)->sum("money");
// 获取首页展示的产品
	$class=Db::name('product')->where("hide","0")->order("sort","DESC")->find();
	if($class){
		$cart=Db::name('cart')->where(["product"=>$class['id'],"hide"=>"0"])->order("sort","DESC")->select();
		foreach($cart as &$c){
			// 首页套餐容量统一使用 data2（空间大小，单位M）
			$c['capacity_show'] = $this->formatSizeM($c['data2'] ?? '');
		}
		unset($c);
	}else{
		$cart=[];
	}
	// 服务器状态（宝塔 API）：仅取列表用于首屏骨架渲染，指标由前端异步拉取，避免阻塞页面
	$bt_servers = [];
	$bt_interval = 15;
	try {
		if (function_exists('ensure_bt_servers_table')) {
			ensure_bt_servers_table();
			$rows = Db::name('bt_servers')->where('status', 1)->order('sort', 'DESC')->order('id', 'ASC')->select();
			foreach ($rows as $r) {
				$bt_servers[] = [
					'id'     => intval($r['id']),
					'name'   => $r['name'],
					'tag'    => isset($r['tag']) ? $r['tag'] : '',
					'remark' => isset($r['remark']) ? $r['remark'] : '',
				];
				$bt_interval = max(5, min(60, intval($r['cache_ttl'] ?: 15)));
			}
		}
	} catch (\Exception $e) {
		$bt_servers = [];
	}

		return $this->fetch('/'.$this->web["template"].'/index/index',[
				"announcement"=>$data,
				"countserver"=>$countserver,
				"countuser"=>$countuser,
				"countorder"=>$countorder,
				"sumpay"=>$sumpay,
				"cart"=>$cart,
				"class"=>$class,
				"bt_servers"=>$bt_servers,
				"bt_servers_json"=>json_encode($bt_servers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
				"bt_interval"=>$bt_interval,
			]);
	}

	// ── 用户登录安全防护辅助方法 ──

	private function logUserLogin($userId, $username, $status, $msg) {
		// ── 精简写入原则 ──
		// 1) 登录失败属于安全事件，必须记录（用于 IP 封锁 / 爆破拦截）
		// 2) 登录成功默认不写库（用户的登录 IP、设备属于无关数据），
		//    仅当后台「记录设置」开启时才写入
		$failed = intval($status) === 0;
		if (!$failed) {
			$on = function_exists('sys_record_opt') ? sys_record_opt('rec_user_login') : null;
			if ($on === null) $on = '0'; // 默认关闭
			if (!intval($on)) {
				return;
			}
		}
		try {
			$tableName = \think\Db::name('user_login_log')->getTable();
			\think\Db::execute("CREATE TABLE IF NOT EXISTS `{$tableName}` (
				`id` int(11) NOT NULL AUTO_INCREMENT,
				`user_id` int(11) DEFAULT 0 COMMENT '用户ID',
				`username` varchar(50) DEFAULT '' COMMENT '登录用户名',
				`ip` varchar(50) DEFAULT '' COMMENT '登录IP',
				`status` tinyint(1) DEFAULT 0 COMMENT '1=成功 0=失败',
				`msg` varchar(255) DEFAULT '' COMMENT '备注',
				`user_agent` varchar(500) DEFAULT '' COMMENT '登录UA',
				`create_time` int(11) DEFAULT 0,
				PRIMARY KEY (`id`),
				KEY `idx_user_id` (`user_id`),
				KEY `idx_create_time` (`create_time`),
				KEY `idx_ip_status` (`ip`, `status`, `create_time`),
				KEY `idx_username_status` (`username`, `status`, `create_time`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8");
			// 兼容旧表：补齐 user_agent 字段
			try {
				$cols = array_column(\think\Db::query("SHOW COLUMNS FROM `{$tableName}`"), 'Field');
				if (!in_array('user_agent', $cols, true)) {
					\think\Db::execute("ALTER TABLE `{$tableName}` ADD COLUMN `user_agent` varchar(500) DEFAULT '' COMMENT '登录UA'");
				}
			} catch (\Exception $e) {}
			// UA / 设备信息默认不再采集（无关数据），可在后台「记录设置」中开启
			$uaOn = function_exists('sys_record_opt') ? intval(sys_record_opt('rec_ua')) : 0;
			\think\Db::name('user_login_log')->insert([
				'user_id'     => $userId,
				'username'    => $username,
				'ip'          => request()->ip(),
				'status'      => $status,
				'msg'         => $msg,
				'user_agent'  => $uaOn ? (isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr($_SERVER['HTTP_USER_AGENT'], 0, 500) : '') : '',
				'create_time' => time(),
			]);
		} catch (\Exception $e) {}
	}

	private function checkUserIpRateLimit($ip, $maxTimes, $windowSec) {
		$ipKey = md5($ip . 'user_login_rate');
		$rateDir = defined('LOG_PATH') ? LOG_PATH . 'rate_limit/' : (PATH . 'runtime/log/rate_limit/');
		if (!is_dir($rateDir)) @mkdir($rateDir, 0755, true);
		$file = $rateDir . $ipKey . '.lim';
		$now = time();
		$records = [];
		if (file_exists($file)) {
			$content = @file_get_contents($file);
			if ($content) $records = @json_decode($content, true) ?: [];
		}
		$records = array_filter($records, function($t) use ($now, $windowSec) { return ($now - $t) < $windowSec; });
		if (count($records) >= $maxTimes) return false;
		$records[] = $now;
		@file_put_contents($file, json_encode(array_values($records)), LOCK_EX);
		return true;
	}

	private function isUserIpBlocked($ip) {
		try {
			$cutoff = time() - 1800;
			$count = \think\Db::name('user_login_log')->where('ip', $ip)->where('status', 0)->where('create_time', '>', $cutoff)->count();
			return $count >= 10;
		} catch (\Exception $e) { return false; }
	}

	private function isUserAccountLocked($username) {
		try {
			$cutoff = time() - 900;
			$count = \think\Db::name('user_login_log')->where('username', $username)->where('status', 0)->where('create_time', '>', $cutoff)->count();
			return $count >= 5;
		} catch (\Exception $e) { return false; }
	}

	private function applyUserProgressiveDelay($ip, $username) {
		try {
			$cutoff = time() - 600;
			$recentFails = \think\Db::name('user_login_log')
				->where(function($query) use ($ip, $username) { $query->where('ip', $ip)->whereOr('username', $username); })
				->where('status', 0)->where('create_time', '>', $cutoff)->count();
		} catch (\Exception $e) { $recentFails = 0; }
		if ($recentFails > 0) {
			$delay = min($recentFails * 500, 5000);
			usleep($delay * 1000);
		}
	}

	private function checkUserRequestInterval($ip) {
		$ipKey = md5($ip . 'user_login_interval');
		$rateDir = defined('LOG_PATH') ? LOG_PATH . 'rate_limit/' : (PATH . 'runtime/log/rate_limit/');
		if (!is_dir($rateDir)) @mkdir($rateDir, 0755, true);
		$file = $rateDir . $ipKey . '.ts';
		$now = time();
		$lastTime = 0;
		if (file_exists($file)) $lastTime = (int) @file_get_contents($file);
		@file_put_contents($file, $now, LOCK_EX);
		if ($lastTime > 0 && ($now - $lastTime) < 2) return false;
		return true;
	}

	public function login() {
		if(Request::instance()->isPost()) {
			// 确保邮箱验证表存在（防止500错误）
			if (function_exists('ensure_email_verify_table')) {
				ensure_email_verify_table();
			}
			$act = input("act");
			$array = ["code" => "-1", "msg" => ""];

			if($act == "ptdl") {
				// CSRF 防护
				if (!csrf_verify(input('__token__', ''))) {
					$array["msg"] = "安全验证失败，请刷新页面后重试";
					return $array;
				}
				$user = trim(input("user"));
				$password = input("password");
				$captcha = input("captcha");
				$ip = request()->ip();

				// ── 第0层：蜜罐字段（Burp Suite等自动化工具会自动填充隐藏字段）──
				$honeypot = input('_hp', '');
				if (!empty($honeypot)) {
					$array["msg"] = "账号或密码错误!";
					self::logUserLogin(0, $user, 0, '蜜罐触发(BOT)');
					return $array;
				}

				// ── 第0.5层：请求间隔检查（同IP两次POST间隔<2秒拒绝）──
				if (!$this->checkUserRequestInterval($ip)) {
					$array["msg"] = "操作过快，请稍后再试";
					self::logUserLogin(0, $user, 0, '请求间隔过短(BOT)');
					return $array;
				}

				// ── 第1层：IP频率限制（10分钟内最多15次尝试）──
				if (!$this->checkUserIpRateLimit($ip, 15, 600)) {
					$array["msg"] = "登录尝试过于频繁，请10分钟后再试";
					self::logUserLogin(0, $user, 0, 'IP频率超限');
					return $array;
				}

				// ── 第2层：IP封锁（30分钟内同IP失败≥10次）──
				if ($this->isUserIpBlocked($ip)) {
					$array["msg"] = "当前网络登录失败次数过多，请30分钟后再试";
					self::logUserLogin(0, $user, 0, 'IP已被封锁');
					return $array;
				}

				// ── 第3层：账号锁定（15分钟内同账号失败≥5次）──
				if (!empty($user) && $this->isUserAccountLocked($user)) {
					$array["msg"] = "该账号登录失败次数过多，请15分钟后再试";
					self::logUserLogin(0, $user, 0, '账号已被锁定');
					return $array;
				}

				// ── 第4层：渐进延迟（根据近期失败次数拖慢撞库速度）──
				$this->applyUserProgressiveDelay($ip, $user);

				if($user == "" || $password == "" || $captcha == "") {
					$array["code"] = "-1";
					$array["msg"] = "必填参数不可为空!";
				} else {
					if(!\app\index\controller\Captcha::check()) {
						$array["code"] = "-1";
						$array["msg"] = "验证码错误!";
						self::logUserLogin(0, $user, 0, '验证码错误');
					} else {
						$data = Db::name('user')->whereor([
							"user" => $user,
						])->whereor([
							"mail" => $user,
						])->find();
						if($data) {
							$data1 = password_verify($password, $data["password"]);
							if($data1) {
								if($data["state"] == "0") {
									$array["code"] = "-1";
									$array["msg"] = "账户已被冻结,禁止登录!";
									self::logUserLogin($data['id'], $data['user'], 0, '账号已禁用');
								} else {
									session_regenerate_id(true); // 防止会话固定攻击
									session("userid", $data["id"]);
									$loginIp = function_exists('get_client_ip') ? get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
									try {
										Db::name('user')->where("id", $data["id"])->update([
											"last_login_time" => time(),
											"last_login_ip" => $loginIp,
											"last_login_region" => get_ip_region($loginIp)
										]);
									} catch (\Exception $e) {}
									$db = Db::name('user')->where("id", session("userid"))->find();
									if($this->web["email"] == "1") {
										if($db["mail"]) {
											$mailbox = $this->email($db["mail"], "登录成功通知", "你账号:" . $db["user"] . "在时间:" . date("Y-m-d H:i:s") . "在本站登录成功,若不是本人所为,请修改密码!<br/><br/>");
										}
									}
									$array["code"] = "1";
									$array["msg"] = "登录成功!";
									self::logUserLogin($data['id'], $data['user'], 1, '登录成功');
								}
							} else {
								$array["code"] = "-1";
								$array["msg"] = "账号或密码错误!";
								self::logUserLogin($data['id'], $data['user'], 0, '密码错误');
							}
						} else {
							$array["code"] = "-1";
							$array["msg"] = "账号或密码错误!";
							self::logUserLogin(0, $user, 0, '账号不存在');
						}
					}
				}
				return $array;
			}

			if($act == "yxvalidate") {
				if($this->web["yxdl"] != "1") {
					$array["code"] = "-1";
					$array["msg"] = "未开启邮箱登录!";
				} else {
					$captcha = input("captcha");
					$mail = input("mail");
					if($mail == "" || $captcha == "") {
						$array["code"] = "-1";
						$array["msg"] = "必填参数不可为空!";
					} else {
						if(!is_valid_email($mail)) {
							$array["code"] = "-1";
							$array["msg"] = "邮箱格式错误!";
						} else {
							if(!\app\index\controller\Captcha::check()) {
								$array["code"] = "-1";
								$array["msg"] = "验证码错误或已过期，请重新验证!";
							} else {
								$data11 = Db::name('user')->where("mail", $mail)->find();
								if(!$data11) {
									$array["code"] = "-1";
									$array["msg"] = "邮箱不存在!";
								} else {
									if($this->web["email"] == "1") {
										if($mail) {
											$yxyzm = random(6, '0123456789');
											session("yxyzmyx1", $mail);
											session("yxyzm1", $yxyzm);
											$codeBody = "<p>您好，</p><p>您正在使用邮箱登录 {$this->web['name']}，本次验证码为：</p><p style='text-align:center;margin:28px 0;'><span style='display:inline-block;background:#eff6ff;color:#2563eb;font-size:28px;font-weight:700;padding:14px 32px;border-radius:10px;letter-spacing:4px;border:1px solid #bfdbfe;'>{$yxyzm}</span></p><p style='color:#64748b;font-size:13px;'>验证码 10 分钟内有效，请勿将验证码告知他人。如非本人操作，请忽略此邮件。</p>";
											$mailbox = $this->email($mail, "邮箱登录验证码通知", $codeBody);
											$array["code"] = "1";
											$array["msg"] = "获取邮箱验证码成功!";
										}
									} else {
										$array["code"] = "-1";
										$array["msg"] = "未开启邮箱通知!";
									}
								}
							}
						}
					}
				}
				return $array;
			}

			if($act == "yxdl") {
				if($this->web["yxdl"] != "1") {
					$array["code"] = "-1";
					$array["msg"] = "未开启邮箱登录!";
				} else {
					$mail = input("mail");
					$code = input("code");
					if($mail == "" || $code == "") {
						$array["code"] = "-1";
						$array["msg"] = "必填参数不可为空!";
					} else {
						if(!is_valid_email($mail)) {
							$array["code"] = "-1";
							$array["msg"] = "邮箱格式错误!";
						} else {
							if(!session("yxyzmyx1") || !session("yxyzm1")) {
								$array["code"] = "-1";
								$array["msg"] = "请你重新获取验证码!";
							} else {
								if(session("yxyzmyx1") != $mail) {
									$array["code"] = "-1";
									$array["msg"] = "邮箱不匹配,请重新获取邮箱验证码!";
								} else {
									if(session("yxyzm1") != $code) {
										$array["code"] = "-1";
										$array["msg"] = "邮箱验证码错误!";
									} else {
										$data = Db::name('user')->where([
											"mail" => $mail,
										])->find();
										if($data) {
											if($data["state"] == "0") {
												$array["code"] = "-1";
												$array["msg"] = "账户已被冻结,禁止登录!";
											} else {
									session("yxyzmyx1", null);
									session("yxyzm1", null);
									session("userid", $data["id"]);
									$loginIp = function_exists('get_client_ip') ? get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
									try {
										Db::name('user')->where("id", $data["id"])->update([
											"last_login_time" => time(),
											"last_login_ip" => $loginIp,
											"last_login_region" => get_ip_region($loginIp)
										]);
									} catch (\Exception $e) {}
									$db = Db::name('user')->where("id", session("userid"))->find();
											if($this->web["email"] == "1") {
												if($db["mail"]) {
													$mailbox = $this->email($db["mail"], "邮箱登录成功通知", "你邮箱:" . $db["mail"] . "在时间:" . date("Y-m-d H:i:s") . "在本站登录成功,若不是本人所为,请修改密码!<br/><br/>");
												}
											}
											$array["code"] = "1";
											$array["msg"] = "登录成功!";
										}
										} else {
											$array["code"] = "-1";
											$array["msg"] = "邮箱不存在!";
										}
									}
								}
							}
						}
					}
				}
				return json($array);
			}

			return json($array);
		}

		return $this->fetch('/' . $this->web["template"] . '/index/login', [
			"yxdl" => $this->web["yxdl"],
		]);
	}

	public function register() {
		if(Request::instance()->isPost()) {
			try {
			// 确保邮箱验证表存在（防止500错误）
			if (function_exists('ensure_email_verify_table')) {
				ensure_email_verify_table();
			}
			$act = input("act");
			$array = ["code" => "-1", "msg" => ""];

			// 前端实时检测邮箱是否允许（AJAX钩子）
			if($act == "check_disposable") {
				$checkMail = input("mail", "");
				if(!empty($checkMail)) {
					if(!is_allowed_email($checkMail)) {
						$array["code"] = "-1";
						$array["msg"] = "仅支持国内邮箱注册（QQ、163、126、sina、sohu等），请使用真实邮箱！";
					} else {
						$array["code"] = "1";
					}
				} else {
					$array["code"] = "1";
				}
				return json($array);
			}

			if($act == "ptzc") {
				$name = input("name");
				$user = input("user");
				$password = input("password");
				$repassword = input("repassword");
				$qq = input("qq");
				$captcha = input("captcha");
				if($name == "" || $user == "" || $password == "" || $repassword == "" || $qq == "" || $captcha == "") {
					$array["code"] = "-1";
					$array["msg"] = "必填参数不可为空!";
				} else {
					if(!\app\index\controller\Captcha::check()) {
						$array["code"] = "-1";
						$array["msg"] = "验证码错误!";
					} else {
						if($password != $repassword) {
							$array["code"] = "-1";
							$array["msg"] = "两次输入的密码不一样!";
						} else {
							// 密码复杂度：必须同时包含大写字母、小写字母和数字（账号不做限制）
							$credErr = validate_user_credential($user, $password);
							if($credErr !== '') {
								$array["code"] = "-1";
								$array["msg"] = $credErr;
								return json($array);
							}
							// 仅允许国内邮箱注册
							$mail = input("mail");
							if(!empty($mail) && !is_allowed_email($mail)) {
								$array["code"] = "-1";
								$array["msg"] = "仅支持国内邮箱注册（QQ、163、126、sina、sohu等），请使用真实邮箱！";
								return json($array);
							}
							$data = Db::name('user')->where("user", $user)->find();
							if($data) {
								$array["code"] = "-1";
								$array["msg"] = "账号已存在!";
							} else {
								$upperid = cookie("upperid");
								if(!$upperid) {
									$upperid = "";
								} else {
									if(!Db::name('user')->where("id", $upperid)->find()) {
										$upperid = "";
									}
								}
								$data1 = Db::name('user')->insertGetId([
									"name" => $name,
									"user" => $user,
									"password" => password_hash($password, PASSWORD_DEFAULT),
									"mail" => "",
									"time" => time(),
									"qq" => $qq,
									"address" => "",
									"aff" => "",
									"upperid" => $upperid,
									"state" => "1",
								]);
								if($data1) {
									// ── 写入「系统重要记录」：新用户注册 ──
									try {
										if (function_exists('sys_record')) {
											sys_record('user', '新用户注册：' . $user, [
												'账号'   => $user,
												'昵称'   => $name,
												'QQ'     => $qq,
												'时间'   => date('Y-m-d H:i:s'),
											], [
												'operator_type' => 'user',
												'operator_id'   => intval($data1),
												'operator_name' => $user,
												'target_type'   => 'user',
												'target_id'     => intval($data1),
												'level'         => 1,
												'summary'       => '账号：' . $user . ' · 昵称：' . $name,
											]);
										}
									} catch (\Exception $e) {}
									$array["code"] = "1";
									$array["msg"] = "注册成功!";
								} else {
									$array["code"] = "-1";
									$array["msg"] = "注册失败!";
								}
							}
						}
					}
				}
				return json($array);
			}

			if($act == "yxzc") {
				if($this->web["zcyxyz"] != "1") {
					$array["code"] = "-1";
					$array["msg"] = "未开启邮箱注册!";
				} else {
						$name = input("name");
						$user = input("user");
						$password = input("password");
						$repassword = input("repassword");
						$mail = input("mail");
						$qq = input("qq");
						$code = input("code");
						$verifiedRecord = Db::name('email_verify')->where('mail', $mail)->where('verified', 1)->order('id', 'desc')->find();
						if($name == "" || $user == "" || $password == "" || $repassword == "" || $mail == "" || $qq == "") {
							$array["code"] = "-1";
							$array["msg"] = "必填参数不可为空!";
						} elseif (!$verifiedRecord && $code == "") {
							$array["code"] = "-1";
							$array["msg"] = "请完成邮箱验证（点击邮件链接或输入备用验证码）!";
						} else {
							if (!$verifiedRecord) {
								$codeRecord = Db::name('email_verify')->where('mail', $mail)->where('expire_time', '>', time())->order('id', 'desc')->find();
								if(!$codeRecord) {
									$array["code"] = "-1";
									$array["msg"] = "请获取邮箱验证码!";
									return json($array);
								}
								if($codeRecord['code'] != $code) {
									$array["code"] = "-1";
									$array["msg"] = "备用验证码错误!";
									return json($array);
								}
							}
						if($password != $repassword) {
							$array["code"] = "-1";
							$array["msg"] = "两次输入的密码不一样!";
						} else {
							// 密码复杂度：必须同时包含大写字母、小写字母和数字（账号不做限制）
							$credErr = validate_user_credential($user, $password);
							if($credErr !== '') {
								$array["code"] = "-1";
								$array["msg"] = $credErr;
								return json($array);
							}
							$data3 = is_valid_email($mail);
							if($data3) {
								if(!is_allowed_email($mail)) {
									$array["code"] = "-1";
									$array["msg"] = "仅支持国内邮箱注册（QQ、163、126、sina、sohu等），请使用真实邮箱！";
									return json($array);
								}
								$data = Db::name('user')->where("user", $user)->find();
								if($data) {
									$array["code"] = "-1";
									$array["msg"] = "账号已存在!";
								} else {
									$data2 = Db::name('user')->where("mail", $mail)->find();
									if($data2) {
										$array["code"] = "-1";
										$array["msg"] = "邮箱已存在!";
									} else {
										$upperid = cookie("upperid");
										if(!$upperid) {
											$upperid = "";
										} else {
											if(!Db::name('user')->where("id", $upperid)->find()) {
												$upperid = "";
											}
										}
										$data1 = Db::name('user')->insertGetId([
											"name" => $name,
											"user" => $user,
											"password" => password_hash($password, PASSWORD_DEFAULT),
											"mail" => $mail,
											"time" => time(),
											"qq" => $qq,
											"address" => "",
											"aff" => "",
											"upperid" => $upperid,
											"state" => "1",
										]);
									if($data1) {
										// ── 写入「系统重要记录」：新用户注册（邮箱）──
										try {
											if (function_exists('sys_record')) {
												sys_record('user', '新用户注册：' . $user, [
													'账号' => $user,
													'昵称' => $name,
													'邮箱' => $mail,
													'时间' => date('Y-m-d H:i:s'),
												], [
													'operator_type' => 'user',
													'operator_id'   => intval($data1),
													'operator_name' => $user,
													'target_type'   => 'user',
													'target_id'     => intval($data1),
													'level'         => 1,
													'summary'       => '账号：' . $user . ' · 邮箱：' . $mail,
												]);
											}
										} catch (\Exception $e) {}
										Db::name('email_verify')->where('mail', $mail)->delete();
										$array["code"] = "1";
										$array["msg"] = "注册成功!";
											if($this->web["email"] == "1") {
												if($mail) {
													$mailbox = $this->email($mail, "注册成功通知", "时间:" . date("Y-m-d H:i:s") . "<br/>恭喜你在本站注册成功!<br/>ID:" . $data1 . "<br/>账号:" . $name . "<br/><br/>");
												}
											}
										} else {
											$array["code"] = "-1";
											$array["msg"] = "注册失败!";
										}
									}
								}
							} else {
								$array["code"] = "-1";
								$array["msg"] = "邮箱格式错误!";
							}
						}
					}
				}
				return json($array);
			}

			if($act == "yxvalidate") {
				if($this->web["zcyxyz"] != "1") {
					$array["code"] = "-1";
					$array["msg"] = "未开启邮箱注册!";
				} else {
					$captcha = input("captcha");
					$mail = input("mail");
					if($mail == "" || $captcha == "") {
						$array["code"] = "-1";
						$array["msg"] = "必填参数不可为空!";
					} else {
						if(!is_valid_email($mail)) {
							$array["code"] = "-1";
							$array["msg"] = "邮箱格式错误!";
						} else {
							// 仅允许国内邮箱注册（拦截临时邮箱/海外邮箱）
							if(!is_allowed_email($mail)) {
								$array["code"] = "-1";
								$array["msg"] = "仅支持国内邮箱注册（QQ、163、126、sina、sohu等），请使用真实邮箱！";
								return json($array);
							}
							// 验证码绑定邮箱检查，防止请求包被篡改
							if(!\app\index\controller\Captcha::check()) {
								$array["code"] = "-1";
								$array["msg"] = "验证码错误或已过期，请重新验证!";
							} else {
								if($this->web["email"] == "1") {
									if($mail) {
										$yxyzm = random(6, '0123456789');
										$verifyToken = md5(uniqid(mt_rand(), true));
										$expireTime = time() + 600;
										// 清除该邮箱旧的已验证记录，防止轮询误判为已通过
										Db::name('email_verify')->where('mail', $mail)->where('verified', 1)->update(['verified' => 0]);
										$insertId = Db::name('email_verify')->insertGetId([
											'mail' => $mail,
											'token' => $verifyToken,
											'code' => $yxyzm,
											'verified' => 0,
											'create_time' => time(),
											'expire_time' => $expireTime,
										]);
										if (!$insertId) {
											$array["code"] = "-1";
											$array["msg"] = "系统繁忙，请稍后重试";
											return json($array);
										}
										$verifyLink = request()->domain() . request()->root() . '/verify_email?token=' . $verifyToken . '&mail=' . urlencode($mail);
										$codeBody = "<p>您好，</p><p>您正在注册 {$this->web['name']} 账号，请选择以下任意一种方式完成验证：</p>";
										$codeBody .= "<p style='text-align:center;margin:24px 0;'><a href='{$verifyLink}' style='display:inline-block;background:#2563eb;color:#fff;font-size:16px;font-weight:600;padding:14px 32px;border-radius:10px;text-decoration:none;'>点击此链接验证邮箱</a></p>";
										$codeBody .= "<p style='color:#64748b;font-size:13px;text-align:center;'>或输入以下备用验证码：</p>";
										$codeBody .= "<p style='text-align:center;margin:16px 0 28px;'><span style='display:inline-block;background:#eff6ff;color:#2563eb;font-size:28px;font-weight:700;padding:14px 32px;border-radius:10px;letter-spacing:4px;border:1px solid #bfdbfe;'>{$yxyzm}</span></p>";
										$codeBody .= "<p style='color:#64748b;font-size:13px;'>验证码和链接 10 分钟内有效，如非本人操作，请忽略此邮件。</p>";
										// 同步发送邮件，确保邮件真实发出后才返回成功
										$sendResult = self::email($mail, "注册验证通知", $codeBody, true);
										if ($sendResult['code'] == '1') {
											$array["code"] = "1";
											$array["msg"] = "验证邮件已发送，请检查邮箱";
										} else {
											// 邮件发送失败，删除刚插入的记录
											Db::name('email_verify')->where('id', $insertId)->delete();
											$array["code"] = "-1";
											$array["msg"] = $sendResult['msg'] ?: '邮件发送失败，请稍后重试';
										}
									}
								} else {
									$array["code"] = "-1";
									$array["msg"] = "未开启邮箱通知!";
								}
							}
						}
					}
				}
				return json($array);
			}

			if($act == "check_link_verify") {
				try {
					$mail = input("mail", "");
					// 仅查询最近1小时内的已验证记录，防止旧记录误判
					$record = Db::name('email_verify')->where('mail', $mail)->where('verified', 1)->where('create_time', '>', time() - 3600)->order('id', 'desc')->find();
					if ($record) {
						$array["code"] = "1";
						$array["verified"] = true;
					} else {
						$array["code"] = "1";
						$array["verified"] = false;
					}
				} catch (\Throwable $e) {
					$array["code"] = "1";
					$array["verified"] = false;
				}
				return json($array);
			}

			return json($array);
			} catch (\Throwable $e) {
				// 记录错误日志以便排查
				$logDir = defined('LOG_PATH') ? LOG_PATH : (PATH . '/runtime/log/');
				if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
				@file_put_contents($logDir . 'register_error.log', date('Y-m-d H:i:s') . " " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n", FILE_APPEND);
				$array = ["code" => "-1", "msg" => "系统繁忙，请稍后重试"];
				return json($array);
			}
		}

		$zcyxyz = $this->web["zcyxyz"];
		return $this->fetch('/' . $this->web["template"] . '/index/register', [
			"zcyxyz" => $zcyxyz,
		]);
	}





	public function cart($id = null) {
		if ($id) {
			// 旧分类链接统一跳转到新购买页
			return $this->redirect('/cart');
		}
		ensure_cart_table();
		if (function_exists('ensure_shopping_cart_config_columns')) { ensure_shopping_cart_config_columns(); }
		// 全部可见套餐
		$cartRows = Db::name('cart')->where("hide", "0")->order("sort", "DESC")->select();
		// 线路 = 后台添加的全部服务器中「插件为 mnbt」的（有多少个服务器就显示多少条线路，
		// 不再要求该服务器下必须已经挂套餐，因为套餐可以自由选线路）
		$servers = [];
		$serverRows = Db::name('server')->select();
		foreach ($serverRows as $s) {
			$plugin = strtolower(trim((string) ($s['serverplugins'] ?? '')));
			if ($plugin !== 'mnbt') { continue; }
			// 统计默认挂在该线路下的套餐数（仅用于展示）
			$planCount = 0;
			foreach ($cartRows as $c) {
				if ((string) $c['serverid'] === (string) $s['id']) { $planCount++; }
			}
			$servers[] = [
				'id' => $s['id'],
				'name' => $s['name'],
				'ip' => isset($s['ip']) ? $s['ip'] : '',
				'plan_count' => $planCount,
				'plan_total' => count($cartRows),
				// Docker 容器开通依赖 mnbt 面板插件（_DockerOpen），只有插件支持时才允许勾选
				'docker_supported' => function_exists('docker_plugin_supported') ? docker_plugin_supported($s) : false,
			];
		}
		// 套餐数据（前台 JS 渲染用）
		$planJson = [];
		foreach ($cartRows as $c) {
			$planJson[] = [
				'id' => $c['id'],
				'serverid' => $c['serverid'],
				'name' => $c['name'],
				'content' => (string) ($c['content'] ?? ''),
				'money' => $c['money'],
				'cycle' => $c['cycle'],
				'firstmo' => $c['firstmo'],
				'inventory' => $c['inventory'],
				'space' => intval($c['data2'] ?? 0),      // 空间 MB
				'db' => intval($c['data3'] ?? 0),         // 数据库 MB
				'traffic' => round(floatval($c['data4'] ?? 0) * 1024), // 流量 GB→MB（兼容旧逻辑）
				'traffic_gb' => floatval($c['data4'] ?? 0),            // 月流量默认值（GB，前台按 G 展示与输入）
				'domain' => intval($c['data5'] ?? 0),
				'price_space_mb' => floatval($c['price_space_mb'] ?? 0),
				'price_db_mb' => floatval($c['price_db_mb'] ?? 0),
				'price_traffic_mb' => floatval($c['price_traffic_mb'] ?? 0),
				'price_traffic_gb' => function_exists('cart_traffic_price_per_gb')
					? cart_traffic_price_per_gb($c) : floatval($c['price_traffic_mb'] ?? 0),
				'price_domain' => floatval($c['price_domain'] ?? 0),
				'points_price' => intval($c['points_price'] ?? 0),
				'cycle_prices' => function_exists('cart_cycle_prices') ? cart_cycle_prices($c) : [],
				// 该产品是否开放 Docker 容器开通（价格取全局统一价，这里只放开关）
				'docker_enabled' => !empty($c['docker_enabled']) && (string) $c['docker_enabled'] === '1',
			];
		}
		// 支付方式（供购买页直接下单付款）
		$pays = [];
		$balance = 0;
		$points = 0;
		$logged = session("userid") ? 1 : 0;
		if ($logged) {
			$pays = Db::name("pays")->where("state", "1")->select();
			foreach ($pays as $k => $p) {
				unset($pays[$k]["plugins"]);
				unset($pays[$k]["data"]);
			}
			$me = Db::name('user')->where('id', session("userid"))->find();
			$balance = $me ? floatval($me['money']) : 0;
			$points  = $me ? intval($me['points'] ?? 0) : 0;
		}

		// Docker 容器开通（购买页可勾选的附加项）：全局统一价 + 全局开关 + 开通方式
		$dockerCfg = [
			'enabled'  => !empty($this->web['docker_enabled']) && (string) $this->web['docker_enabled'] === '1',
			'price'    => function_exists('docker_price') ? docker_price($this->web) : 0,
			'mode'     => function_exists('docker_mode') ? docker_mode($this->web) : 'manual',
			'pay_ways' => function_exists('docker_pay_ways') ? docker_pay_ways($this->web) : ['balance'],
			'intro'    => isset($this->web['docker_intro']) ? $this->web['docker_intro'] : '',
		];

		return $this->fetch('/' . $this->web["template"] . '/index/cart', [
			"servers" => $servers,
			"plans" => $planJson,
			"pays" => $pays,
			"balance" => $balance,
			"points" => $points,
			"logged" => $logged,
			"docker" => $dockerCfg,
		]);
	}

	public function product($id=null) {
		if(!$id){
			$this->redirect('/cart');
		}
		// 购买页已合并为新购买页，旧详情页跳转过去并预选该套餐
		$this->redirect('/cart?plan='.$id);
	}


	// 规格单位格式化辅助方法
	private function formatSizeM($value) {
		$value = trim((string)$value);
		if ($value === '' || !is_numeric($value)) return '-';
		$num = floatval($value);
		if ($num >= 1024) {
			$gb = round($num / 1024, 1);
			return strpos((string)$gb, '.0') !== false ? intval($gb) . 'GB' : $gb . 'GB';
		}
		return intval($num) . 'MB';
	}

	private function formatTraffic($value) {
		$value = trim((string)$value);
		if ($value === '' || !is_numeric($value)) return '不限';
		$num = floatval($value);
		if ($num >= 1024) {
			$tb = round($num / 1024, 1);
			return strpos((string)$tb, '.0') !== false ? intval($tb) . 'TB' : $tb . 'TB';
		}
		return intval($num) . 'GB';
	}

	private function formatCount($value) {
		$value = trim((string)$value);
		if ($value === '' || !is_numeric($value)) return '不限';
		return intval($value) . ' 个';
	}

	public function help() {
		$serviceEmail = !empty($this->web["service_email"]) ? $this->web["service_email"] : (!empty($this->web["emailname"]) ? $this->web["emailname"] : "");
		$qqGroup = isset($this->web["qq_group"]) ? $this->web["qq_group"] : "";
		$qqNumber = $qqGroup;
		if ($qqGroup && !preg_match('/^\d+$/', $qqGroup)) {
			if (preg_match('/group_code=(\d+)/', $qqGroup, $m)) {
				$qqNumber = $m[1];
			} elseif (preg_match('/\/q\/([a-zA-Z0-9]+)/', $qqGroup, $m)) {
				$qqNumber = "官方QQ群";
			}
		}
		return $this->fetch('/'.$this->web["template"].'/index/help',[
			"qq_group"=>$qqGroup,
			"qq_number"=>$qqNumber,
			"email"=>$serviceEmail,
		]);
	}

	public function announcement($id=null) {
		// 确保新公告表存在（后台管理用的 announcements 表）
		ensure_announcements_table();
		if ($id) {
			$data = Db::name('announcements')->where('id', $id)->where('status', 1)->find();
			if ($data) {
				// 标记已读
				if (session('userid')) {
					$exists = Db::name('announcement_reads')
						->where('announcement_id', $id)
						->where('user_id', session('userid'))
						->find();
					if (!$exists) {
						Db::name('announcement_reads')->insert([
							'announcement_id' => $id,
							'user_id'         => session('userid'),
							'read_at'         => time(),
						]);
					}
				}
				return $this->fetch('/'.$this->web["template"].'/index/announcement', [
					"announcement" => $data,
				]);
			} else {
				$this->redirect('/announcements');
			}
		} else {
			$data = Db::name('announcements')
				->where('status', 1)
				->order('id desc')
				->paginate(10);
			return $this->fetch('/'.$this->web["template"].'/index/announcements', [
				"announcement" => $data,
			]);
		}
	}

public function rankings() {
		ensure_user_columns();
		ensure_membership_levels_table();
		// 积分排行榜
		$pointsRanking = Db::name('user')
			->where('state', '1')
			->order('points', 'desc')
			->limit(50)
			->field('id,name,user,qq,points')
			->select();

		// 充值排行榜
		$rechargeRanking = Db::name('user')
			->where('state', '1')
			->order('total_recharge', 'desc')
			->limit(50)
			->field('id,name,user,qq,total_recharge')
			->select();

		// VIP排行榜
		$vipRanking = Db::name('user')
			->alias('u')
			->join('membership_levels ml', 'u.membership_level = ml.level AND ml.status = 1', 'LEFT')
			->where('u.state', '1')
			->where('u.membership_level', '>', 0)
			->order('u.membership_level', 'desc')
			->limit(50)
			->field('u.id,u.name,u.user,u.qq,u.membership_level,ml.name as vip_name')
			->select();

		// 主机数排行榜
		$hostRanking = Db::name('user')
			->alias('u')
			->join('order o', 'u.id = o.userid AND o.state = \'1\'', 'LEFT')
			->where('u.state', '1')
			->group('u.id')
			->order('host_count', 'desc')
			->limit(50)
			->field('u.id,u.name,u.user,u.qq,COUNT(o.id) as host_count')
			->select();

		return $this->fetch('/'.$this->web["template"].'/index/rankings',[
			'pointsRanking'   => $pointsRanking,
			'rechargeRanking' => $rechargeRanking,
			'vipRanking'      => $vipRanking,
			'hostRanking'     => $hostRanking,
		]);
	}

public function cron(){
$time=time();
$sendEmail=($this->web["email"]=="1");
$userEmails=[];

// === 0. Docker 在线支付订单结算（兜底：用户支付后未回到站点时也要到账并开通）===
if(function_exists('docker_settle_pending')){
	try { docker_settle_pending(0); } catch (\Throwable $e) {}
}

// === 1. 订单过期处理 ===
// 优化: 只查询已过期订单 (ztime < time), 避免加载全部订单再 PHP 过滤
$orders=Db::name("order")->where("ztime","<",$time)->select();
if(!empty($orders)){
	// 优化: 批量预加载 cart/server/user 数据, 避免循环内 N+1 查询
	$cartIds=array_unique(array_filter(array_column($orders,'cartid')));
	$carts=[];
	if(!empty($cartIds)){
		$cartRows=Db::name('cart')->where('id','in',$cartIds)->select();
		foreach($cartRows as $row){ $carts[$row['id']]=$row; }
	}
	$serverIds=[];
	foreach($carts as $c){ if(!empty($c['serverid'])){ $serverIds[]=$c['serverid']; } }
	$serverIds=array_unique($serverIds);
	$servers=[];
	if(!empty($serverIds)){
		$serverRows=Db::name('server')->where('id','in',$serverIds)->select();
		foreach($serverRows as $row){ $servers[$row['id']]=$row; }
	}
	if($sendEmail){
		$userIds=array_unique(array_filter(array_column($orders,'userid')));
		if(!empty($userIds)){
			$userEmails=Db::name('user')->where('id','in',$userIds)->column('mail','id');
		}
	}

	$cronzz=$this->web["cronzz"]*86400;
	$cronsc=$this->web["cronsc"]*86400;

	for($i=0;$i<count($orders);$i++){
		$order=$orders[$i];
		$data2=isset($carts[$order["cartid"]])?$carts[$order["cartid"]]:null;
		if(!$data2){ continue; }
		$data3=isset($servers[$data2["serverid"]])?$servers[$data2["serverid"]]:null;
		if(!$data3){ continue; }
		include_once PATH."plugins/host/".$data3["serverplugins"]."/".$data3["serverplugins"].".php";

		if($order["ztime"]+$cronzz>$time){
			//过期三天内暂停
			if($order["state"]!="2"){
				Db::name("order")->where("id",$order["id"])->update(["state"=>"2"]);
				if($sendEmail && !empty($userEmails[$order["userid"]])){
					$this->email($userEmails[$order["userid"]],"产品暂停通知","时间:".date("Y-m-d H:i:s")."<br>产品id:".$order["id"]."<br>产品已到期,自动暂停!请登录产品控制台续费!<br/><br/>");
				}
				$function=$data3["serverplugins"]."_"."SuspendAccount";
				if(function_exists($function)){
					@$function($data3,$order,$data2);
				}
			}
		}elseif($order["ztime"]+$cronzz+$cronsc>$time){
			//过期六天内终止
			if($order["state"]!="3"){
				Db::name("order")->where("id",$order["id"])->update(["state"=>"3"]);
				Db::name("cart")->where("id",$order["cartid"])->update(["inventory"=>$data2["inventory"]+1]);
				if($sendEmail && !empty($userEmails[$order["userid"]])){
					$this->email($userEmails[$order["userid"]],"产品终止通知","时间:".date("Y-m-d H:i:s")."<br>产品id:".$order["id"]."<br>产品到期,已终止!!<br/><br/>");
				}
				$function=$data3["serverplugins"]."_"."TerminateAccount";
				if(function_exists($function)){
					@$function($data3,$order,$data2);
				}
			}
		}else{
			//过期六天后处理
			if($order["state"]!="3"){
				Db::name("cart")->where("id",$order["cartid"])->update(["inventory"=>$data2["inventory"]+1]);
				if($sendEmail && !empty($userEmails[$order["userid"]])){
					$this->email($userEmails[$order["userid"]],"产品终止通知","时间:".date("Y-m-d H:i:s")."<br>产品id:".$order["id"]."<br>产品到期,已终止!<br/><br/>");
				}
				$function=$data3["serverplugins"]."_"."TerminateAccount";
				if(function_exists($function)){
					@$function($data3,$order,$data2);
				}
			}
			Db::name("order")->where("id",$order["id"])->delete();
		}
	}
}

// === 2. 删除超时未支付的订单 ===
// 优化: 单条 DELETE 完成, 避免 select + 循环 delete 的 N+1 模式
$paycron=$this->web["paycron"]*60;
if($paycron>0){
	Db::name("pay")->where("state","2")->where("time","<",time()-$paycron)->delete();
}

// === 3. 工单超时未回复自动关闭 ===
// 优化: 只查询未关闭工单 (state != 4), 批量预加载用户邮箱
$tickets=Db::name("ticket")->where("state","<>","4")->select();
if(!empty($tickets)){
	$tickcron=$this->web["tickcron"]*86400;
	if($sendEmail){
		$ticketUserIds=array_unique(array_filter(array_column($tickets,'userid')));
		$missingIds=array_diff($ticketUserIds, array_keys($userEmails));
		if(!empty($missingIds)){
			$extra=Db::name('user')->where('id','in',$missingIds)->column('mail','id');
			$userEmails=array_merge($userEmails, $extra);
		}
	}
	for($i=0;$i<count($tickets);$i++){
		$json=json_decode($tickets[$i]["content"],true);
		// PHP 8 兼容: end() 不再接受 null, 需检查 json_decode 返回值
		if(!is_array($json) || empty($json)){ continue; }
		$content=end($json);
		if(isset($content["time"]) && $content["time"]+$tickcron<time()){
			Db::name("ticket")->where("id",$tickets[$i]["id"])->update(["state"=>"4"]);
			if($sendEmail && !empty($userEmails[$tickets[$i]["userid"]])){
				$this->email($userEmails[$tickets[$i]["userid"]],"工单关闭通知","时间:".date("Y-m-d H:i:s")."<br>工单id:".$tickets[$i]["id"]."<br>超时未回复,自动关闭!<br/><br/>");
			}
		}
	}
}

// === 4. 封禁到期自动解封 ===
$bannedUsers=Db::name('user')->where('ban_time','>',0)->where('ban_time','<=',$time)->select();
if(!empty($bannedUsers)){
	$unbanIds=array_column($bannedUsers,'id');
	Db::name('user')->where('id','in',$unbanIds)->update([
		'ban_time'=>0,
		'ban_reason'=>'',
	]);
	// 自动恢复该用户所有被暂停的主机
	foreach($bannedUsers as $bu){
		$suspendedOrders=Db::name('order')->where([
			"userid"=>$bu['id'],
			"state"=>"2",
		])->select();
		if(!empty($suspendedOrders)){
			$cartIds=array_unique(array_column($suspendedOrders,'cartid'));
			$cartMap=Db::name('cart')->where('id','in',$cartIds)->column('serverid','id');
			$serverIds=array_unique(array_values($cartMap));
			$serverMap=Db::name('server')->where('id','in',$serverIds)->column('serverplugins','id');
			foreach($suspendedOrders as $order){
				$serverId=isset($cartMap[$order['cartid']])?$cartMap[$order['cartid']]:null;
				if($serverId && isset($serverMap[$serverId])){
					$pluginFile=PATH."plugins/host/".$serverMap[$serverId]."/".$serverMap[$serverId].".php";
					if(file_exists($pluginFile)){
						include_once $pluginFile;
						$function=$serverMap[$serverId]."_"."UnsuspendAccount";
						if(function_exists($function)){
							$cart=Db::name('cart')->where('id',$order['cartid'])->find();
							$server=Db::name('server')->where('id',$serverId)->find();
							@$function($server,$order,$cart);
						}
					}
				}
				Db::name('order')->where('id',$order['id'])->update(["state"=>"1"]);
			}
		}
	}
	if($sendEmail){
		foreach($bannedUsers as $bu){
			if(!empty($bu['mail'])){
				$this->email($bu['mail'],"封禁到期通知","你的账号 ".$bu['user']." 封禁已到期，现已自动解封，主机已自动恢复。<br/><br/>");
			}
		}
	}
}

// === 5. 待开通订单自动开通 ===
$pendingOrders=Db::name("order")
	->where("state","0")
	->where("auto_create_at",">",0)
	->where("auto_create_at","<=",$time)
	->select();
if(!empty($pendingOrders)){
	$cartIds=array_unique(array_filter(array_column($pendingOrders,'cartid')));
	$carts=[];
	if(!empty($cartIds)){
		$cartRows=Db::name('cart')->where('id','in',$cartIds)->select();
		foreach($cartRows as $row){ $carts[$row['id']]=$row; }
	}
	$serverIds=[];
	foreach($carts as $c){ if(!empty($c['serverid'])){ $serverIds[]=$c['serverid']; } }
	$serverIds=array_unique($serverIds);
	$servers=[];
	if(!empty($serverIds)){
		$serverRows=Db::name('server')->where('id','in',$serverIds)->select();
		foreach($serverRows as $row){ $servers[$row['id']]=$row; }
	}
	foreach($pendingOrders as $order){
		$cart=isset($carts[$order['cartid']])?$carts[$order['cartid']]:null;
		if(!$cart){ continue; }
		$server=isset($servers[$cart['serverid']])?$servers[$cart['serverid']]:null;
		if(!$server || $server['serverplugins']==""){ continue; }
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
			Db::name('order')->where('id', $order["id"])->update([
				"user" => $hostUser,
				"password" => $hostPass,
			]);
			$order["user"] = $hostUser;
			$order["password"] = $hostPass;
		}
		$pluginFile=PATH."plugins/host/".$server["serverplugins"]."/".$server["serverplugins"].".php";
		if(!file_exists($pluginFile)){ continue; }
		include_once $pluginFile;
		$function=$server["serverplugins"]."_CreateAccount";
		if(!function_exists($function)){ continue; }
		$times=intval($order["ztime"])-intval($order["atime"]);
		if($times<0){ $times=0; }
		$cycleTime=1;
		if($cart["cycle"]=="month") $cycleTime=2592000;
		elseif($cart["cycle"]=="season") $cycleTime=7879680;
		elseif($cart["cycle"]=="year") $cycleTime=31536000;
		elseif($cart["cycle"]=="day") $cycleTime=86400;
		elseif($cart["cycle"]=="unrestricted") $cycleTime=3153600000;
		$buyTime=($cycleTime>0) ? intval($times/$cycleTime) : 1;
		if($buyTime<1){ $buyTime=1; }
		$result=@$function($server, ["user"=>$order["user"],"password"=>$order["password"],"time"=>$buyTime], $cart, $times, $order["id"]);
		if(!is_array($result) || !isset($result["code"]) || $result["code"]!="1"){
			// 开通失败，5分钟后重试
			Db::name("order")->where("id",$order["id"])->update(["auto_create_at"=>$time+300]);
		}
	}
}

// === 6. 处理邮件发送队列（重试之前发送失败的邮件） ===
if (function_exists('process_email_queue')) {
	try {
		process_email_queue(20);
	} catch (\Throwable $e) {}
}

// === 6-1. 处理到期的定时邮件推送任务（后台「邮件推送」） ===
if (function_exists('process_email_tasks')) {
	try {
		process_email_tasks(3);
	} catch (\Throwable $e) {}
}

// === 7. 补全用户详细地理位置（省/市/经纬度，供数据大屏使用） ===
if (function_exists('refresh_user_geo')) {
	try {
		refresh_user_geo(20);
	} catch (\Throwable $e) {}
}

return "任务执行完毕!";
}



	//发送邮箱
	// 修复：移除 register_shutdown_function 异步发送（导致延迟过高、信件丢失需二次发送）。
	// 改为统一「同步发送」，失败时写入邮件队列，由 /cron 定时重试，保证邮件不丢失。
	public static function email($email, $name, $body, $sync = false)
	{
		if (!rate_limit('email_send_' . $email, 3, 60)) { 
			return ['code' => '-1', 'msg' => '发送频率过快，请稍后再试']; 
		}
		$body = sanitize_email_body($body);
		$web = web_config();
		$webData = [
			'emailchar' => $web['emailchar'] ?? 'UTF-8',
			'emailauth' => $web['emailauth'] ?? true,
			'emailsecure' => $web['emailsecure'] ?? '',
			'emailport' => $web['emailport'] ?? 25,
			'emailhost' => $web['emailhost'] ?? '',
			'emailname' => $web['emailname'] ?? '',
			'emailpass' => $web['emailpass'] ?? '',
			'webname' => $web['name'] ?? '',
		];

		// 实际发送逻辑（显式加载 PHPMailer，避免自动加载失败导致静默发不出）
		$doSend = function() use ($email, $name, $body, $webData) {
			if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
				$pmDir = PATH . 'extend/PHPMailer/PHPMailer/';
				if (file_exists($pmDir . 'PHPMailer.php')) {
					require_once $pmDir . 'Exception.php';
					require_once $pmDir . 'PHPMailer.php';
					require_once $pmDir . 'SMTP.php';
				}
			}
			if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
				throw new \Exception('PHPMailer 类加载失败');
			}
			$mail = new \PHPMailer\PHPMailer\PHPMailer(true);
			$mail->IsSMTP();
			$mail->CharSet = $webData['emailchar'];
			$mail->SMTPAuth = true;
			$mail->Timeout = 20;
			$mail->SMTPDebug = 0;
			$mail->Port = intval($webData['emailport']) ?: 465;
			if ($mail->Port == 465) {
				$mail->SMTPSecure = 'ssl';
			} elseif ($webData['emailsecure'] && $webData['emailsecure'] != 'none') {
				$mail->SMTPSecure = $webData['emailsecure'];
			}
			$mail->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]];
			$mail->Host = $webData['emailhost'];
			$mail->Username = $webData['emailname'];
			$mail->Password = $webData['emailpass'];
			$mail->setFrom($webData['emailname'], $webData['webname']);
			$mail->addAddress($email);
			$mail->Subject = $name;
			$mail->Body = build_email_html($name, $body);
			$mail->WordWrap = 80;
			$mail->isHTML(true);
			if ($mail->Send()) {
				return true;
			}
			return $mail->ErrorInfo ?: '未知错误';
		};

		try {
			$sendResult = $doSend();
			if ($sendResult === true) {
				return ['code' => '1', 'msg' => '邮箱发送成功'];
			}
			// 发送失败 → 写入队列待重试
			$errMsg = $sendResult;
			$logDir = defined('LOG_PATH') ? LOG_PATH : (PATH . '/runtime/log/');
			if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
			@file_put_contents($logDir . 'email_error.log', date('Y-m-d H:i:s') . " To:{$email} Subject:{$name} Error:{$errMsg}" . "\n", FILE_APPEND);
			enqueue_email($email, $name, $body);
			if ($sync) {
				return ['code' => '-1', 'msg' => '邮件发送失败，请检查邮箱配置'];
			}
			$array["code"] = "1";
			$array["msg"] = "邮箱发送成功";
			return json($array);
		} catch (\Throwable $e) {
			$logDir = defined('LOG_PATH') ? LOG_PATH : (PATH . '/runtime/log/');
			if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
			@file_put_contents($logDir . 'email_error.log', date('Y-m-d H:i:s') . " To:{$email} Subject:{$name} Error:" . $e->getMessage() . "\n", FILE_APPEND);
			enqueue_email($email, $name, $body);
			if ($sync) {
				return ['code' => '-1', 'msg' => '邮件发送失败，请检查邮箱配置'];
			}
			$array["code"] = "1";
			$array["msg"] = "邮箱发送成功";
			return json($array);
		}
	}

	/**
	 * 邮箱验证链接点击处理 - 独立页面，无需登录
	 * GET请求显示确认页（防邮箱客户端自动扫描），POST请求执行验证
	 */
	public function verifyEmail() {
		$token = input('token', '');
		$mail = input('mail', '');
		$web = web_config();
		$siteName = htmlspecialchars((string)($web['name'] ?? '站点'), ENT_QUOTES, 'UTF-8');
		$logo = $web['logo'] ?? '';
		$logoIcon = $web['logo_icon'] ?? '';
		$homeUrl = (string) request()->domain();
		$logoHtml = '';
		if (!empty($logo)) {
			$logoHtml = '<img src="' . htmlspecialchars($logo, ENT_QUOTES, 'UTF-8') . '" alt="' . $siteName . '" style="max-width:180px;max-height:60px;margin-bottom:16px;">';
		} elseif (!empty($logoIcon)) {
			$logoHtml = '<img src="' . htmlspecialchars($logoIcon, ENT_QUOTES, 'UTF-8') . '" alt="' . $siteName . '" style="width:60px;height:60px;border-radius:50%;object-fit:cover;margin-bottom:16px;">';
		}
		if (empty($token) || empty($mail)) {
			return '<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>验证失败 - ' . $siteName . '</title><style>*{margin:0;padding:0;box-sizing:border-box}body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;background:linear-gradient(135deg,#f0f4ff 0%,#e8f0fe 50%,#f8fafc 100%);}.card{text-align:center;background:#fff;padding:56px 48px;border-radius:24px;box-shadow:0 8px 40px rgba(0,0,0,0.06),0 2px 8px rgba(0,0,0,0.04);max-width:440px;width:90%;}.icon{width:72px;height:72px;background:#fee2e2;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;margin-bottom:20px;font-size:32px;color:#dc2626;}h2{color:#0f172a;font-size:22px;margin:0 0 8px;font-weight:600;}p{color:#64748b;font-size:14px;margin:0 0 28px;line-height:1.6;}.btn{display:inline-block;padding:12px 36px;background:linear-gradient(135deg,#3b82f6,#2563eb);color:#fff;text-decoration:none;border-radius:10px;font-size:15px;font-weight:500;transition:all .2s;box-shadow:0 4px 14px rgba(59,130,246,0.35);}.btn:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(59,130,246,0.45);}</style></head><body><div class="card">' . $logoHtml . '<div class="icon">✕</div><h2>验证链接无效</h2><p>请返回注册页面重新获取邮箱验证链接</p><a href="' . $homeUrl . '" class="btn">返回首页</a></div></body></html>';
		}
		$record = Db::name('email_verify')->where('mail', $mail)->where('token', $token)->where('expire_time', '>', time())->order('id', 'desc')->find();
		if (!$record) {
			return '<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>验证失败 - ' . $siteName . '</title><style>*{margin:0;padding:0;box-sizing:border-box}body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;background:linear-gradient(135deg,#f0f4ff 0%,#e8f0fe 50%,#f8fafc 100%);}.card{text-align:center;background:#fff;padding:56px 48px;border-radius:24px;box-shadow:0 8px 40px rgba(0,0,0,0.06),0 2px 8px rgba(0,0,0,0.04);max-width:440px;width:90%;}.icon{width:72px;height:72px;background:#fee2e2;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;margin-bottom:20px;font-size:32px;color:#dc2626;}h2{color:#0f172a;font-size:22px;margin:0 0 8px;font-weight:600;}p{color:#64748b;font-size:14px;margin:0 0 28px;line-height:1.6;}.btn{display:inline-block;padding:12px 36px;background:linear-gradient(135deg,#3b82f6,#2563eb);color:#fff;text-decoration:none;border-radius:10px;font-size:15px;font-weight:500;transition:all .2s;box-shadow:0 4px 14px rgba(59,130,246,0.35);}.btn:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(59,130,246,0.45);}</style></head><body><div class="card">' . $logoHtml . '<div class="icon">✕</div><h2>验证链接已过期</h2><p>该验证链接已超时失效，请返回注册页面重新获取</p><a href="' . $homeUrl . '" class="btn">返回首页</a></div></body></html>';
		}

		// 如果已验证过，直接显示成功页
		if ($record['verified'] == 1) {
			return '<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>已验证 - ' . $siteName . '</title><style>*{margin:0;padding:0;box-sizing:border-box}body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;background:linear-gradient(135deg,#f0f4ff 0%,#e8f0fe 50%,#f8fafc 100%);}.card{text-align:center;background:#fff;padding:56px 48px;border-radius:24px;box-shadow:0 8px 40px rgba(0,0,0,0.06),0 2px 8px rgba(0,0,0,0.04);max-width:440px;width:90%;}.logo-wrap{margin-bottom:20px;}.icon{width:72px;height:72px;background:linear-gradient(135deg,#d1fae5,#a7f3d0);border-radius:50%;display:inline-flex;align-items:center;justify-content:center;margin-bottom:20px;font-size:36px;color:#059669;}h2{color:#0f172a;font-size:24px;margin:0 0 12px;font-weight:700;}.desc{color:#475569;font-size:15px;margin:0 0 8px;line-height:1.7;}.sub{color:#94a3b8;font-size:13px;margin:0 0 32px;}.btn{display:inline-block;padding:14px 40px;background:linear-gradient(135deg,#3b82f6,#2563eb);color:#fff;text-decoration:none;border-radius:12px;font-size:16px;font-weight:500;transition:all .25s;box-shadow:0 4px 16px rgba(59,130,246,0.35);letter-spacing:.5px;}.btn:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(59,130,246,0.45);}</style></head><body><div class="card"><div class="logo-wrap">' . $logoHtml . '</div><div class="icon">✓</div><h2>邮箱已验证</h2><p class="desc">您的邮箱 <strong>' . htmlspecialchars($mail, ENT_QUOTES, 'UTF-8') . '</strong> 已通过验证</p><p class="sub">请返回注册页面继续完成账号注册</p><a href="' . $homeUrl . '" class="btn">进入站点首页</a></div></body></html>';
		}

		// POST请求：用户点击了确认按钮，执行验证
		if (Request::instance()->isPost()) {
			Db::name('email_verify')->where('id', $record['id'])->update(['verified' => 1]);
			return '<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>验证成功 - ' . $siteName . '</title><style>*{margin:0;padding:0;box-sizing:border-box}body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;background:linear-gradient(135deg,#f0f4ff 0%,#e8f0fe 50%,#f8fafc 100%);}.card{text-align:center;background:#fff;padding:56px 48px;border-radius:24px;box-shadow:0 8px 40px rgba(0,0,0,0.06),0 2px 8px rgba(0,0,0,0.04);max-width:440px;width:90%;}.logo-wrap{margin-bottom:20px;}.icon{width:72px;height:72px;background:linear-gradient(135deg,#d1fae5,#a7f3d0);border-radius:50%;display:inline-flex;align-items:center;justify-content:center;margin-bottom:20px;font-size:36px;color:#059669;animation:popIn .4s cubic-bezier(.34,1.56,.64,1);}@keyframes popIn{from{transform:scale(0);opacity:0}to{transform:scale(1);opacity:1}}h2{color:#0f172a;font-size:24px;margin:0 0 12px;font-weight:700;}.desc{color:#475569;font-size:15px;margin:0 0 8px;line-height:1.7;}.sub{color:#94a3b8;font-size:13px;margin:0 0 32px;}.btn{display:inline-block;padding:14px 40px;background:linear-gradient(135deg,#3b82f6,#2563eb);color:#fff;text-decoration:none;border-radius:12px;font-size:16px;font-weight:500;transition:all .25s;box-shadow:0 4px 16px rgba(59,130,246,0.35);letter-spacing:.5px;}.btn:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(59,130,246,0.45);}</style></head><body><div class="card"><div class="logo-wrap">' . $logoHtml . '</div><div class="icon">✓</div><h2>邮箱验证成功</h2><p class="desc">恭喜！您的邮箱 <strong>' . htmlspecialchars($mail, ENT_QUOTES, 'UTF-8') . '</strong> 已通过验证</p><p class="desc">欢迎加入' . $siteName . '，即刻开启您的云服务之旅</p><p class="sub">请返回注册页面继续完成账号注册</p><a href="' . $homeUrl . '" class="btn">进入站点首页</a></div></body></html>';
		}

		// GET请求：显示确认页面，JS自动提交表单（邮箱扫描器不执行JS，无法自动验证）
		$tokenEnc = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');
		$mailEnc = htmlspecialchars($mail, ENT_QUOTES, 'UTF-8');
		return '<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>邮箱验证 - ' . $siteName . '</title><style>*{margin:0;padding:0;box-sizing:border-box}body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;background:linear-gradient(135deg,#f0f4ff 0%,#e8f0fe 50%,#f8fafc 100%);}.card{text-align:center;background:#fff;padding:48px 40px;border-radius:24px;box-shadow:0 8px 40px rgba(0,0,0,0.06),0 2px 8px rgba(0,0,0,0.04);max-width:440px;width:90%;}.icon{width:72px;height:72px;background:linear-gradient(135deg,#dbeafe,#bfdbfe);border-radius:50%;display:inline-flex;align-items:center;justify-content:center;margin-bottom:20px;font-size:32px;color:#2563eb;}h2{color:#0f172a;font-size:22px;margin:0 0 8px;font-weight:600;}p{color:#64748b;font-size:14px;margin:0 0 28px;line-height:1.6;}.email-badge{display:inline-block;background:#f1f5f9;color:#334155;padding:4px 14px;border-radius:20px;font-size:13px;margin-bottom:20px;}.btn{display:inline-block;padding:14px 40px;background:linear-gradient(135deg,#3b82f6,#2563eb);color:#fff;text-decoration:none;border-radius:12px;font-size:16px;font-weight:500;transition:all .25s;box-shadow:0 4px 16px rgba(59,130,246,0.35);cursor:pointer;border:none;}.btn:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(59,130,246,0.45);}.spinner{display:inline-block;width:20px;height:20px;border:2px solid #fff;border-radius:50%;border-top-color:transparent;animation:spin .6s linear infinite;vertical-align:middle;margin-right:8px;}@keyframes spin{to{transform:rotate(360deg)}}.loading-text{color:#94a3b8;font-size:13px;margin-top:16px;}</style></head><body><div class="card"><div class="logo-wrap">' . $logoHtml . '</div><div class="icon">✉</div><h2>验证您的邮箱</h2><p>确认验证邮箱 <span class="email-badge">' . htmlspecialchars($mail, ENT_QUOTES, 'UTF-8') . '</span></p><form id="vf" method="POST"><input type="hidden" name="token" value="' . $tokenEnc . '"><input type="hidden" name="mail" value="' . $mailEnc . '"><button type="submit" class="btn" id="vf-btn"><span class="spinner"></span>验证中...</button></form><p class="loading-text" id="vf-hint">正在自动验证，请稍候...</p><p class="loading-text" style="display:none;" id="vf-fallback">如果没有自动跳转，请<a href="#" onclick="document.getElementById(\'vf\').submit();return false;" style="color:#2563eb;">点击这里</a>手动验证</p></div><script>setTimeout(function(){document.getElementById("vf").submit();},800);setTimeout(function(){document.getElementById("vf-hint").style.display="none";document.getElementById("vf-fallback").style.display="";},5000);</script></body></html>';
	}

	/**
	 * 邮件审核实名认证（无需登录，公开访问）
	 */
	public function emailAudit() {
		$token = input('token', '');
		$action = input('action', '');
		$id = input('id', 0);
		if(empty($token) || empty($action) || !$id) {
			return '<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>审核失败</title><style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;background:#f8fafc;}.box{text-align:center;background:#fff;padding:48px;border-radius:16px;box-shadow:0 10px 40px rgba(0,0,0,0.08);}h2{color:#dc2626;}</style></head><body><div class="box"><h2>审核链接无效</h2><p>参数不完整</p></div></body></html>';
		}
		$user = Db::name('user')->where('id', $id)->where('realname_status', '3')->find();
		if(!$user) {
			return '<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>审核失败</title><style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;background:#f8fafc;}.box{text-align:center;background:#fff;padding:48px;border-radius:16px;box-shadow:0 10px 40px rgba(0,0,0,0.08);}h2{color:#dc2626;}</style></head><body><div class="box"><h2>审核失败</h2><p>该用户不存在或已审核</p></div></body></html>';
		}
		$expectedToken = md5($id . $user['realname'] . 'email_audit_salt_2024');
		if($token !== $expectedToken) {
			return '<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>审核失败</title><style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;background:#f8fafc;}.box{text-align:center;background:#fff;padding:48px;border-radius:16px;box-shadow:0 10px 40px rgba(0,0,0,0.08);}h2{color:#dc2626;}</style></head><body><div class="box"><h2>审核失败</h2><p>Token验证失败</p></div></body></html>';
		}
		if($action == 'approve') {
			Db::name('user')->where('id', $id)->update(['realname_status' => 1]);
			ensure_realname_record_table();
			Db::name('realname_record')->where('user_id', $id)->where('status', 3)->update(['status' => 1, 'review_time' => time(), 'reviewer' => '邮件审核']);
			if($this->web["email"]=="1" && !empty($user["mail"])){
				$realname = $user['realname'] ?: $user['name'];
				try { self::email($user["mail"], "实名认证通过通知", '<p>您好 '.htmlspecialchars($realname).'，</p><p>恭喜！您的实名认证已审核通过。</p>'); } catch (\Exception $e) {}
			}
			return '<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>审核成功</title><style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;background:#f8fafc;}.box{text-align:center;background:#fff;padding:48px;border-radius:16px;box-shadow:0 10px 40px rgba(0,0,0,0.08);}.icon{font-size:48px;color:#059669;margin-bottom:16px;}h2{color:#0f172a;}</style></head><body><div class="box"><div class="icon">✓</div><h2>已通过实名认证</h2><p>用户 '.htmlspecialchars($user['realname'] ?: $user['name']).' 的实名认证已审核通过</p></div></body></html>';
		} elseif($action == 'reject') {
			Db::name('user')->where('id', $id)->update(['realname_status' => 2]);
			ensure_realname_record_table();
			Db::name('realname_record')->where('user_id', $id)->where('status', 3)->update(['status' => 2, 'review_time' => time(), 'reviewer' => '邮件审核']);
			return '<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>审核成功</title><style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;background:#f8fafc;}.box{text-align:center;background:#fff;padding:48px;border-radius:16px;box-shadow:0 10px 40px rgba(0,0,0,0.08);}.icon{font-size:48px;color:#f59e0b;margin-bottom:16px;}h2{color:#0f172a;}</style></head><body><div class="box"><div class="icon">✕</div><h2>已驳回实名认证</h2><p>用户 '.htmlspecialchars($user['realname'] ?: $user['name']).' 的实名认证已驳回</p></div></body></html>';
		}
		return '<html><head><meta charset="utf-8"><title>错误</title></head><body><h2>未知操作</h2></body></html>';
	}

	// ==================== 扫码登录 ====================

	/**
	 * 生成扫码登录二维码 token（电脑端登录页调用）
	 */
	public function qrloginCreate() {
		ensure_qr_login_table();
		// 清理过期凭证
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
		$confirmUrl = (isHTTPS() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '') . '/qrlogin/confirm?token=' . $token;
		return json(['code' => 1, 'token' => $token, 'qrurl' => $confirmUrl]);
	}

	/**
	 * 电脑端轮询扫码状态；已确认则自动登录
	 */
	public function qrloginStatus() {
		ensure_qr_login_table();
		$token = trim(input('token', ''));
		if ($token === '') {
			return json(['code' => -1, 'status' => 'error', 'msg' => '参数错误']);
		}
		$row = Db::name('qr_login')->where('token', $token)->find();
		if (!$row) {
			return json(['code' => -1, 'status' => 'expired', 'msg' => '二维码已失效']);
		}
		if (intval($row['expired_at']) < time()) {
			return json(['code' => -1, 'status' => 'expired', 'msg' => '二维码已过期，请刷新']);
		}
		if ($row['status'] === 'confirmed') {
			$user = Db::name('user')->where('id', intval($row['userid']))->find();
			if (!$user || $user['state'] == '0') {
				return json(['code' => -1, 'status' => 'expired', 'msg' => '账号异常或已被禁用']);
			}
			session_regenerate_id(true);
			session('userid', $user['id']);
			$loginIp = function_exists('get_client_ip') ? get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
			try {
				Db::name('user')->where('id', $user['id'])->update([
					'last_login_time'   => time(),
					'last_login_ip'     => $loginIp,
					'last_login_region' => get_ip_region($loginIp),
				]);
			} catch (\Exception $e) {}
			Db::name('qr_login')->where('id', $row['id'])->update(['status' => 'used']);
			return json(['code' => 1, 'status' => 'confirmed', 'msg' => '登录成功', 'url' => '/user/index']);
		}
		return json(['code' => 1, 'status' => $row['status'], 'msg' => '']);
	}

	/**
	 * 手机端扫码后打开的确认页；已登录用户点击确认即登录电脑端
	 */
	public function qrloginConfirm() {
		ensure_qr_login_table();
		$token = trim(input('token', ''));
		$web  = web_config();
		$logo = isset($web['logo']) ? $web['logo'] : '';
		$siteName = isset($web['name']) ? $web['name'] : '';

		$fail = function($msg) use ($siteName) {
			return '<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>扫码登录</title><style>body{font-family:-apple-system,sans-serif;background:#f1f5f9;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;}.box{background:#fff;padding:40px;border-radius:16px;box-shadow:0 10px 40px rgba(0,0,0,.08);text-align:center;max-width:320px;width:90%;}.ic{font-size:44px;margin-bottom:12px;}h2{color:#0f172a;font-size:18px;margin:0 0 8px;}p{color:#64748b;font-size:14px;margin:0 0 16px;}.btn{display:inline-block;background:#2563eb;color:#fff;text-decoration:none;padding:10px 24px;border-radius:10px;font-size:14px;}</style></head><body><div class="box"><div class="ic">⚠️</div><h2>'.htmlspecialchars($siteName).'</h2><p>'.htmlspecialchars($msg).'</p><a class="btn" href="/login">去登录</a></div></body></html>';
		};

		if ($token === '') return $fail('参数错误');
		$row = Db::name('qr_login')->where('token', $token)->find();
		if (!$row || intval($row['expired_at']) < time()) {
			return $fail('二维码已失效，请刷新电脑端二维码');
		}

		$userId = session('userid');
		if (!$userId) {
			return $fail('请先登录后再扫码登录');
		}

		// 标记已扫码
		if ($row['status'] === 'pending') {
			Db::name('qr_login')->where('id', $row['id'])->update(['status' => 'scanned']);
		}

		if (Request::instance()->isPost()) {
			$act = input('act', '');
			if ($act === 'confirm') {
				Db::name('qr_login')->where('id', $row['id'])->update(['status' => 'confirmed', 'userid' => intval($userId)]);
				return '<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>登录成功</title><style>body{font-family:-apple-system,sans-serif;background:#f1f5f9;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;}.box{background:#fff;padding:40px;border-radius:16px;box-shadow:0 10px 40px rgba(0,0,0,.08);text-align:center;max-width:320px;width:90%;}.ic{font-size:52px;color:#059669;margin-bottom:12px;}h2{color:#0f172a;font-size:18px;margin:0 0 8px;}p{color:#64748b;font-size:14px;margin:0;}</style></head><body><div class="box"><div class="ic">✓</div><h2>已确认登录</h2><p>电脑端将自动登录，请返回电脑查看</p></div></body></html>';
			}
			if ($act === 'cancel') {
				Db::name('qr_login')->where('id', $row['id'])->update(['status' => 'expired']);
				return '<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>已取消</title><style>body{font-family:-apple-system,sans-serif;background:#f1f5f9;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;}.box{background:#fff;padding:40px;border-radius:16px;box-shadow:0 10px 40px rgba(0,0,0,.08);text-align:center;max-width:320px;width:90%;}.ic{font-size:52px;color:#f59e0b;margin-bottom:12px;}h2{color:#0f172a;font-size:18px;margin:0;}</style></head><body><div class="box"><div class="ic">✕</div><h2>已取消登录</h2></div></body></html>';
			}
		}

		$user = Db::name('user')->where('id', intval($userId))->find();
		$displayName = $user ? (($user['name'] ?: $user['user'])) : '当前用户';
		$avatar = $user && !empty($user['avatar']) ? $user['avatar'] : '';

		$logoHtml = '';
		if ($logo !== '') {
			$logoUrl = (strpos($logo, 'http') === 0) ? $logo : '/' . ltrim($logo, '/');
			$logoHtml = '<img src="' . htmlspecialchars($logoUrl) . '" alt="logo" style="width:56px;height:56px;border-radius:50%;object-fit:cover;">';
		} else {
			$logoHtml = '<div style="width:56px;height:56px;border-radius:50%;background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;display:flex;align-items:center;justify-content:center;font-size:24px;font-weight:700;">' . htmlspecialchars(mb_substr($siteName, 0, 1)) . '</div>';
		}

		return '<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>扫码登录确认</title><style>body{font-family:-apple-system,sans-serif;background:#f1f5f9;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;}.box{background:#fff;padding:36px 28px;border-radius:16px;box-shadow:0 10px 40px rgba(0,0,0,.08);text-align:center;max-width:340px;width:90%;}.ttl{font-size:16px;font-weight:700;color:#0f172a;margin:14px 0 4px;} .sub{font-size:13px;color:#64748b;margin:0 0 20px;}.user{display:flex;align-items:center;gap:12px;background:#f8fafc;border-radius:12px;padding:12px;margin-bottom:20px;text-align:left;}.uname{font-weight:600;color:#0f172a;font-size:15px;}.udesc{font-size:12px;color:#94a3b8;}.btn{display:block;width:100%;padding:13px;border:none;border-radius:10px;font-size:15px;font-weight:600;cursor:pointer;margin-bottom:10px;}.btn-confirm{background:#2563eb;color:#fff;}.btn-cancel{background:#fff;color:#64748b;border:1px solid #e2e8f0;}</style></head><body><div class="box">' . $logoHtml . '<div class="ttl">确认登录</div><div class="sub">' . htmlspecialchars($siteName) . ' · 扫码登录确认</div><div class="user">' . ($avatar ? '<img src="' . htmlspecialchars($avatar) . '" style="width:40px;height:40px;border-radius:50%;object-fit:cover;">' : '<div style="width:40px;height:40px;border-radius:50%;background:#e2e8f0;color:#64748b;display:flex;align-items:center;justify-content:center;font-weight:700;">' . htmlspecialchars(mb_substr($displayName, 0, 1)) . '</div>') . '<div><div class="uname">' . htmlspecialchars($displayName) . '</div><div class="udesc">确认在电脑端登录此账号？</div></div></div><button class="btn btn-confirm" onclick="doConfirm()">确认登录</button><button class="btn btn-cancel" onclick="doCancel()">取消</button></div><script>function post(act){var f=document.createElement("form");f.method="POST";f.style.display="none";var i=document.createElement("input");i.name="act";i.value=act;f.appendChild(i);document.body.appendChild(f);f.submit();}function doConfirm(){post("confirm");}function doCancel(){post("cancel");}</script></body></html>';
	}

}
