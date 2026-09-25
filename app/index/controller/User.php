<?php
namespace app\index\controller;
use think\Controller;
use think\Db;
use think\Request;
use PHPMailer\PHPMailer\PHPMailer;


class User extends Base {
	public function _initialize() {
		$this->web=web_config();
		// 如果数据库中仍配置为旧版 layui 主题，强制使用已重构的 default 主题
		if($this->web["template"]=="layui"){
			$this->web["template"]="default";
		}
		// 确保购物车表存在（兼容全新安装后未访问过购物车接口）
		$this->ensureCartTable();
		// 确保邮箱验证表存在
		if (function_exists('ensure_email_verify_table')) ensure_email_verify_table();
if($this->web["wh"]=="1"){
exit($this->web["whxx"]);
}
		if(!session("userid")) {
			$this->redirect('/login');
		}else{
			$userstate="1";
}
		$this->user=Db::name('user')->where('id',session("userid"))->find();
		if(!$this->user || $this->user["state"]=="0"){
			session("userid",null);
			$this->redirect('/login');
		}
// 管理员代看用户面板模式（后台“进入用户控制面板”）
$this->adminViewMode = false;
if (session('admin_view_mode') && session('adminid') && session('admin_view_userid') == session('userid')) {
    $this->adminViewMode = true;
}
// 检查封禁状态
$isBanned = ($this->user['ban_time'] > time());
$banReason = $this->user['ban_reason'] ?? '';
$banEndTime = $this->user['ban_time'] ?? 0;
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
		$webLogo = $this->web['logo'] ?? '';
		if ($webLogo && strpos($webLogo, 'http') !== 0 && strpos($webLogo, '/') !== 0 && strpos($webLogo, '://') === false) {
		    $webLogo = (isHTTPS() ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . '/' . ltrim($webLogo, '/');
		}
		$this->web['logo'] = $webLogo;
		// 会员信息
		$membershipLevel = intval($this->user['membership_level'] ?? 0);
		$membershipInfo = null;
		if ($membershipLevel > 0) {
			try {
				$membershipInfo = \think\Db::name('membership_levels')->where('level', $membershipLevel)->where('status', 1)->find();
			} catch (\Exception $e) {}
		}
		$this->assign([
		            'webname'  => $this->web['name'],
		            'description'  => $this->web['description'],
		            'keywords'  => $this->web['keywords'],
		            'favicon'  => $this->web['favicon'],
		            'web'      => $this->web,
		"user"=>$this->user,
		"userstate"=>$userstate,
"templateset"=>$templateset,
"isBanned"=>$isBanned,
"membershipLevel"=>$membershipLevel,
"membershipInfo"=>$membershipInfo,
"banReason"=>$banReason,
'banEndTime'=>$banEndTime,
'forceQqGroup'=>$isBanned ? false : ($this->web['force_qq_group'] == '1' && !empty($this->web['force_qq_group_key']) && empty($this->user['force_qq_group_verified'])),
'forceQqGroupReason'=>$this->web['force_qq_group_reason'] ?? '',
'forceQqGroupNumber'=>$this->web['force_qq_group_number'] ?? '',
'forceQqGroupLink'=>$this->web['force_qq_group_link'] ?? '',
'adminViewMode'=>$this->adminViewMode,
'adminViewUser'=>$this->adminViewMode ? $this->user : null,
		        ]);
	}

	// 确保用户表包含最后登录相关字段
	private function ensureUserColumns() {
		ensure_user_columns();
	}

	// 确保购物车表存在
	private function ensureCartTable() {
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
		Db::execute($sql);
		// 自定义主机配置列
		if (function_exists('ensure_shopping_cart_config_columns')) {
			ensure_shopping_cart_config_columns();
		}
	}

	// 加入购物车
	public function cartAdd() {
		if(Request::instance()->isPost()) {
			try {
				$this->ensureCartTable();
				if (function_exists('ensure_cart_table')) { ensure_cart_table(); }
				$array = ["code"=>"-1", "msg"=>""];
				$cartid = input("cartid");
				$user = input("user");
				$password = input("password");
				$time = input("time");
				// 自定义主机配置（MB），0/空 表示使用套餐默认值
				$spaceMb   = intval(input("space_mb", 0));
				$dbMb      = intval(input("db_mb", 0));
				$trafficGbRaw = input("traffic_gb", null);
				if ($trafficGbRaw !== null && $trafficGbRaw !== '') {
					$trafficMb = intval(round(floatval($trafficGbRaw) * 1024));
				} else {
					$trafficMb = intval(input("traffic_mb", 0));
				}

				if($cartid=="" || $user=="" || $password=="" || $time==""){
				$array["msg"]="必填参数不可为空!";
				return json($array);
			}
			if(!is_numeric($time) || floor($time)!=$time || $time<1){
				$array["msg"]="购买时长只能填写正整数!";
				return json($array);
			}
			// 账号密码格式验证：只允许小写字母+数字
			if(strlen($user) < 3 || strlen($user) > 50){
				$array["msg"]="主机账号长度需在3-50个字符之间!";
				return json($array);
			}
			if(!preg_match('/^[a-z][a-z0-9]*$/', $user)){
				$array["msg"]="主机账号必须以小写字母开头，且只能包含小写字母和数字!";
				return json($array);
			}
			if(strlen($password) < 6 || strlen($password) > 50){
				$array["msg"]="主机密码长度需在6-50个字符之间!";
				return json($array);
			}
			if(!preg_match('/^[a-z0-9]+$/', $password)){
				$array["msg"]="主机密码只能包含小写字母和数字!";
				return json($array);
			}
			// 自定义配置合法性
			$capMax = 1048576; // 单项上限 1TB(MB)
			if($spaceMb < 0 || $dbMb < 0 || $trafficMb < 0){
				$array["msg"]="自定义配置不能为负数!";
				return json($array);
			}
			if($spaceMb > $capMax || $dbMb > $capMax || $trafficMb > $capMax){
				$array["msg"]="自定义配置超出允许上限!";
				return json($array);
			}

				$cart = Db::name('cart')->where('id', $cartid)->find();
				if(!$cart){
					$array["msg"]="产品不存在!";
					return json($array);
				}
				if($cart["buy"]=="1"){
					$array["msg"]="该产品已设置禁止购买!";
					return json($array);
				}
				if($cart["inventory"] < 1){
					$array["msg"]="该产品已售完!";
					return json($array);
				}
				if($cart["limits"]=="1"){
					$exists = Db::name('order')->where(["cartid"=>$cartid,"userid"=>session("userid")])->find();
					if($exists){
						$array["msg"]="该产品只允许订购一次!";
						return json($array);
					}
				}
				// 各周期专属价：基础单价为 0 的套餐靠它定价
				$cyclePriceMap = function_exists('cart_cycle_prices') ? cart_cycle_prices($cart) : [];
				if(floatval($cart["money"])<=0 && empty($cyclePriceMap) && $time!="1"){
					$array["msg"]="免费产品的购买时间只能填写1!";
					return json($array);
				}
				if(floatval($cart["money"])<=0 && !empty($cyclePriceMap) && !isset($cyclePriceMap[intval($time)])){
					$array["msg"]="该购买时长暂未配置价格，请返回购买页重新选择时长!";
					return json($array);
				}
				if($cart["cycle"]=="unrestricted" && $time!="1"){
					$array["msg"]="一次性产品的购买时间只能填写1!";
					return json($array);
				}

				// ── Docker 容器开通（购买页勾选的附加项）──
				$dockerOn = intval(input("docker", 0));
				$dockerEnabled = false;
				$dockerPrice = 0;
				if ($dockerOn === 1) {
					$webDocker = Db::name('web')->where('id', 1)->find();
					$serverRow = Db::name('server')->where('id', $cart['serverid'])->find();
					if (!function_exists('docker_is_enabled') || !docker_is_enabled($webDocker, $cart, $serverRow)) {
						$array["msg"]="当前套餐/线路不支持 Docker 容器开通!";
						return json($array);
					}
					$dockerEnabled = true;
					$dockerPrice = function_exists('docker_price') ? docker_price($webDocker) : 0;
				}

				// 清空该用户该产品【相同账号】的旧未支付记录（允许同一产品用不同账号购买多台）
				Db::name('shopping_cart')->where([
					"userid"=>session("userid"),
					"cartid"=>$cartid,
					"user"=>$user,
					"status"=>"0"
				])->delete();

				$discount = function_exists('get_membership_discount') ? get_membership_discount(session('userid'), 'buy') : 1.00;
				$basePrice = function_exists('cart_cycle_price')
					? cart_cycle_price($cart, $time)
					: round(floatval($cart['money']) * intval($time), 2);
				$money = ($cart["firstmo"]=="1" && !isset($cyclePriceMap[intval($time)])) ? "0" : round($basePrice * $discount, 2);
				// 自定义配置加价（不受会员折扣影响）
				if(function_exists('calc_host_config_extra')){
					$money = round($money + calc_host_config_extra($cart, $spaceMb, $dbMb, $trafficMb), 2);
				}
				// Docker 容器开通费（全局统一价，叠加到整单）
				if ($dockerEnabled) {
					$money = round(floatval($money) + floatval($dockerPrice), 2);
				}
				$insertId = Db::name('shopping_cart')->insertGetId([
					"userid"=>session("userid"),
					"cartid"=>$cartid,
					"user"=>$user,
					"password"=>$password,
					"time"=>$time,
					"money"=>$money,
					"cycle"=>$cart["cycle"],
					"space_mb"=>$spaceMb,
					"db_mb"=>$dbMb,
					"traffic_mb"=>$trafficMb,
					"docker"=>$dockerEnabled ? 1 : 0,
					"created_at"=>time(),
					"status"=>"0",
				]);

				$array["code"]="1";
				$array["msg"]="已加入购物车";
				$array["id"]=$insertId;
				return json($array);
			} catch (\Exception $e) {
				return json(["code"=>"-1","msg"=>"加入购物车异常：".$e->getMessage()]);
			}
		}
		return json(["code"=>"-1","msg"=>"非法请求"]);
	}

	// 支付回调后自动结算购物车
	public function cartSettle() {
		$this->clearExpiredCart();
		// 处理已到期的待开通订单（cron 未配置时兜底）
		process_pending_host_orders();
		$ids = session("cart_order_ids");
		if(!$ids){
			$this->redirect('/user/order');
		}
		session("cart_order_ids", null);
		session("cart_order_total", null);

		// 重新获取用户最新余额（在线支付后余额已被插件更新，_initialize中的user数据已过时）
		$this->user = Db::name('user')->where('id', session("userid"))->find();

		$items = Db::name('shopping_cart')
			->where([
				"userid"=>session("userid"),
				"status"=>"0",
				"id"=>["in", $ids]
			])
			->select();

		if(empty($items)){
			$this->redirect('/user/order');
		}

		$cartIds = array_unique(array_filter(array_column($items, 'cartid')));
		$cartMap = [];
		if(!empty($cartIds)){
			foreach(Db::name('cart')->where('id', 'in', $cartIds)->select() as $c){
				$cartMap[$c['id']] = $c;
			}
		}

		$total = 0;
		foreach($items as $item){
			$total += floatval($item['money']);
		}
		$total = round($total, 2);

		if($this->user['money'] < $total){
			return $this->fetch('/'.$this->web["template"].'/user/cart_settle',[
				"total"=>$total,
				"balance"=>$this->user['money'],
				"success"=>false,
				"msg"=>"账户余额不足，本次充值后余额:".$this->user['money']."元，需支付:".$total."元，差额:".round($total-$this->user['money'],2)."元",
			]);
		}

		$failedMsg = [];
		$pendingCount = 0;
		foreach($items as $item){
			$cart = isset($cartMap[$item['cartid']]) ? $cartMap[$item['cartid']] : null;
			if(!$cart){ $failedMsg[] = "产品#{$item['cartid']}不存在"; continue; }
			$server = Db::name('server')->where('id', host_order_server_id($item, $cart))->find();
			if(!$server || $server['serverplugins']==""){ $failedMsg[] = "产品#{$item['cartid']}未配置服务器"; continue; }

			$times = 0;
			if($cart['cycle']=="month") $times = 2592000 * $item['time'];
			elseif($cart['cycle']=="season") $times = 7879680 * $item['time'];
			elseif($cart['cycle']=="year") $times = 31536000 * $item['time'];
			elseif($cart['cycle']=="day") $times = 86400 * $item['time'];
			elseif($cart['cycle']=="unrestricted") $times = 3153600000 * $item['time'];

			$autoCreate = isset($this->web['host_auto_create']) ? $this->web['host_auto_create'] : '0';
			$autoDelay = isset($this->web['host_auto_create_delay']) ? intval($this->web['host_auto_create_delay']) : 0;

			// 手动开通模式：插入待开通订单，由后台手动开通
			if($autoCreate == '0'){
				$now = time();
				$orderConfig = host_config_from_cart_row($item);
				$orderId = Db::name('order')->insertGetId([
					"user"=>$item['user'],
					"password"=>$item['password'],
					"userid"=>session("userid"),
					"cartid"=>$cart['id'],
					"atime"=>$now,
					"ztime"=>$now+$times,
					"state"=>"0",
					"auto_create_at"=>"0",
					"ordernumber"=>generate_order_number(0),
					"data1"=>"",
					"data2"=>"",
					"data3"=>"",
					"data4"=>"",
					"data5"=>"",
					"data6"=>$orderConfig ? json_encode($orderConfig, JSON_UNESCAPED_UNICODE) : "",
					"data7"=>"",
					"data8"=>"",
					"data9"=>"",
					"data10"=>"",
				]);
				Db::name('order')->where('id', $orderId)->update(['ordernumber'=>generate_order_number($orderId)]);
Db::name('order')->where('id', $orderId)->update(['paid_amount'=>$item['money']]); // 记录已支付金额，供退款计算
				Db::name('cart')->where('id', $cart['id'])->update(['inventory'=>max(0, intval($cart['inventory'])-1)]);
				Db::name('shopping_cart')->where('id', $item['id'])->update(['status'=>"1"]);
				// 购买时勾选了 Docker 容器开通：随主机订单生成待开通记录（在线支付）
				if (!empty($item['docker'])) { docker_create_for_purchase($orderId, 'online'); }
				Db::name('transaction')->insertGetId([
					"userid"=>session("userid"),
					"content"=>"购物车(在线支付)购买产品,ID:".$orderId."(套餐ID:".$cart['id']."),时长:".$item['time'].",消费:".$item['money']."(待手动开通)",
					"time"=>time(),
				]);
				$pendingCount++;
				continue;
			}

			// 自动开通延迟模式：先插入待开通订单，到达时间后自动开通
			if($autoCreate == '1' && $autoDelay > 0){
				$now = time();
				$orderConfig = host_config_from_cart_row($item);
				$orderId = Db::name('order')->insertGetId([
					"user"=>$item['user'],
					"password"=>$item['password'],
					"userid"=>session("userid"),
					"cartid"=>$cart['id'],
					"atime"=>$now,
					"ztime"=>$now+$times,
					"state"=>"0",
					"auto_create_at"=>$now+$autoDelay*60,
					"ordernumber"=>generate_order_number(0),
					"data1"=>"",
					"data2"=>"",
					"data3"=>"",
					"data4"=>"",
					"data5"=>"",
					"data6"=>$orderConfig ? json_encode($orderConfig, JSON_UNESCAPED_UNICODE) : "",
					"data7"=>"",
					"data8"=>"",
					"data9"=>"",
					"data10"=>"",
				]);
				Db::name('order')->where('id', $orderId)->update(['ordernumber'=>generate_order_number($orderId)]);
Db::name('order')->where('id', $orderId)->update(['paid_amount'=>$item['money']]); // 记录已支付金额，供退款计算
				Db::name('cart')->where('id', $cart['id'])->update(['inventory'=>max(0, intval($cart['inventory'])-1)]);
				Db::name('shopping_cart')->where('id', $item['id'])->update(['status'=>"1"]);
				// 购买时勾选了 Docker 容器开通：随主机订单生成待开通记录（在线支付）
				if (!empty($item['docker'])) { docker_create_for_purchase($orderId, 'online'); }
				Db::name('transaction')->insertGetId([
					"userid"=>session("userid"),
					"content"=>"购物车(在线支付)购买产品,ID:".$orderId."(套餐ID:".$cart['id']."),时长:".$item['time'].",消费:".$item['money']."(延迟".$autoDelay."分钟自动开通)",
					"time"=>time(),
				]);
				$pendingCount++;
				continue;
			}

			// 自动开通模式（延迟为0）：付款后立即开通
			$pluginFile = PATH."plugins/host/".$server['serverplugins']."/".$server['serverplugins'].".php";
			if(!file_exists($pluginFile)){ $failedMsg[] = "产品#{$item['cartid']}插件文件不存在"; continue; }
			include_once $pluginFile;

			$function = $server['serverplugins']."_CreateAccount";
			if(!function_exists($function)){ $failedMsg[] = "产品#{$item['cartid']}未实现开通接口"; continue; }
			$result = @$function($server, ["user"=>$item['user'],"password"=>$item['password'],"time"=>$item['time']], apply_host_custom_config($cart, host_config_from_cart_row($item)), $times);
			if(!is_array($result) || !isset($result['code']) || $result['code']!="1"){
				$failedMsg[] = "产品#{$item['cartid']}开通失败：".($result['msg'] ?? '未知错误');
				continue;
			}
			Db::name('cart')->where('id', $cart['id'])->update(['inventory'=>max(0, intval($cart['inventory'])-1)]);
			Db::name('shopping_cart')->where('id', $item['id'])->update(['status'=>"1"]);
			// 购买时勾选了 Docker 容器开通：生成待开通记录，并按 docker_mode 自动/人工开通（在线支付）
			if (!empty($item['docker'])) {
				docker_create_for_purchase($result['id'], 'online');
				docker_provision_pending_for_order($result['id']);
			}
			Db::name('order')->where('id', $result['id'])->update(['paid_amount'=>$item['money']]); // 记录已支付金额，供退款计算
			Db::name('transaction')->insertGetId([
				"userid"=>session("userid"),
				"content"=>"购物车(在线支付)购买产品,ID:".$result['id']."(套餐ID:".$cart['id']."),时长:".$item['time'].",消费:".$item['money'],
				"time"=>time(),
			]);
		}

		$money1 = round($this->user['money'] - $total, 2);
		Db::name('user')->where('id', session("userid"))->update([
			'money' => $money1,
			'total_recharge' => round(floatval($this->user['total_recharge'] ?? 0) + $total, 2)
		]);
		if (function_exists('update_user_membership')) {
			update_user_membership(session('userid'));
		}

		if($pendingCount > 0 && empty($failedMsg)){
			$successMsg = "结算成功，产品将于 ".$autoDelay." 分钟后自动开通";
		}elseif($pendingCount > 0){
			$successMsg = "部分产品待自动开通，部分产品开通失败：".implode("；", $failedMsg);
		}else{
			$successMsg = empty($failedMsg) ? "结算成功，产品已开通" : "部分产品开通失败：".implode("；", $failedMsg);
		}

		// ── 写入「系统重要记录」：订单交易 ──
		try {
			if (function_exists('sys_record')) {
				sys_record('order', '购物车结算下单', [
					'用户'   => $this->user['user'] ?? '',
					'金额'   => '¥' . $total,
					'商品数' => count($items),
					'结果'   => $successMsg,
					'时间'   => date('Y-m-d H:i:s'),
				], [
					'operator_type' => 'user',
					'operator_id'   => intval(session('userid')),
					'operator_name' => $this->user['user'] ?? '',
					'level'         => 2,
					'summary'       => '共 ' . count($items) . ' 件 · 金额 ¥' . $total,
				]);
			}
		} catch (\Exception $e) {}

		return $this->fetch('/'.$this->web["template"].'/user/cart_settle',[
			"total"=>$total,
			"success"=>true,
			"msg"=>$successMsg,
		]);
	}

	// 清理过期购物车
	private function clearExpiredCart() {
		$this->ensureCartTable();
		Db::name('shopping_cart')
			->where('status', '0')
			->where('created_at', '<', time()-900)
			->update(["status"=>"2"]);
	}

	// 购物车列表页
	// 购物车页面已下线（改为直接购买），旧入口统一跳转到产品购买页
	public function cart() {
		return $this->redirect('/cart');
	}

	// 删除购物车项
	public function cartDel() {
		if(Request::instance()->isPost()){
			$id = input("id");
			if($id==""){
				return json(["code"=>"-1","msg"=>"参数错误"]);
			}
			Db::name('shopping_cart')->where([
				"id"=>$id,
				"userid"=>session("userid")
			])->delete();
			return json(["code"=>"1","msg"=>"已删除"]);
		}
		return json(["code"=>"-1","msg"=>"非法请求"]);
	}

	// 统一的购买项结算（立即购买 / 购物车结算共用）
	// $items: shopping_cart 记录数组  $paytype: balance|online  $payid: 在线支付通道ID
	// 返回 ["code","msg","redirect","success_ids"]
	private function settleItems($items, $paytype, $payid = null) {
		$array = ["code"=>"-1", "msg"=>""];

		$cartIds = array_unique(array_filter(array_column($items, 'cartid')));
		$cartMap = [];
		if(!empty($cartIds)){
			foreach(Db::name('cart')->where('id', 'in', $cartIds)->select() as $c){
				$cartMap[$c['id']] = $c;
			}
		}

		$total = 0;
		foreach($items as $item){
			$total += floatval($item['money']);
		}
		$total = round($total, 2);

		if($paytype == "balance"){
			if($this->user['money'] < $total){
				$array["msg"]="账户余额不足，需充值：".$total."元";
				return $array;
			}

			$successIds = [];
			$failedMsg = [];
			$pendingCount = 0;
			$autoCreate = isset($this->web['host_auto_create']) ? $this->web['host_auto_create'] : '0';
			$autoDelay = isset($this->web['host_auto_create_delay']) ? intval($this->web['host_auto_create_delay']) : 0;
			foreach($items as $item){
				$cart = isset($cartMap[$item['cartid']]) ? $cartMap[$item['cartid']] : null;
				if(!$cart){
					$failedMsg[] = "产品#{$item['cartid']}不存在";
					continue;
				}
				$server = Db::name('server')->where('id', host_order_server_id($item, $cart))->find();
				if(!$server || $server['serverplugins']==""){
					$failedMsg[] = "产品#{$item['cartid']}未配置服务器";
					continue;
				}

				$times = 0;
				if($cart['cycle']=="month") $times = 2592000 * $item['time'];
				elseif($cart['cycle']=="season") $times = 7879680 * $item['time'];
				elseif($cart['cycle']=="year") $times = 31536000 * $item['time'];
				elseif($cart['cycle']=="day") $times = 86400 * $item['time'];
				elseif($cart['cycle']=="unrestricted") $times = 3153600000 * $item['time'];

				// 手动开通模式：插入待开通订单，由后台手动开通
				if($autoCreate == '0'){
					$now = time();
					$orderConfig = host_config_from_cart_row($item);
					$orderId = Db::name('order')->insertGetId([
						"user"=>$item['user'],
						"password"=>$item['password'],
						"userid"=>session("userid"),
						"cartid"=>$cart['id'],
						"atime"=>$now,
						"ztime"=>$now+$times,
						"state"=>"0",
						"auto_create_at"=>"0",
						"ordernumber"=>generate_order_number(0),
						"data1"=>"",
						"data2"=>"",
						"data3"=>"",
						"data4"=>"",
						"data5"=>"",
						"data6"=>$orderConfig ? json_encode($orderConfig, JSON_UNESCAPED_UNICODE) : "",
						"data7"=>"",
						"data8"=>"",
						"data9"=>"",
						"data10"=>"",
					]);
					Db::name('order')->where('id', $orderId)->update(['ordernumber'=>generate_order_number($orderId)]);
Db::name('order')->where('id', $orderId)->update(['paid_amount'=>$item['money']]); // 记录已支付金额，供退款计算
					Db::name('cart')->where('id', $cart['id'])->update(['inventory'=>max(0, intval($cart['inventory'])-1)]);
					Db::name('shopping_cart')->where('id', $item['id'])->update(['status'=>"1"]);
					// 购买时勾选了 Docker 容器开通：随主机订单生成待开通记录（余额支付）
					if (!empty($item['docker'])) { docker_create_for_purchase($orderId, 'balance'); }
					Db::name('transaction')->insertGetId([
						"userid"=>session("userid"),
						"content"=>"(余额支付)购买产品,ID:".$orderId."(套餐ID:".$cart['id']."),时长:".$item['time']."周期:".$item['cycle'].",消费:".$item['money']."(待手动开通)",
						"time"=>time(),
					]);
					$pendingCount++;
					$successIds[] = $item['id'];
					continue;
				}

				// 自动开通延迟模式：先插入待开通订单，到达时间后自动开通
				if($autoCreate == '1' && $autoDelay > 0){
					$now = time();
					$orderConfig = host_config_from_cart_row($item);
					$orderId = Db::name('order')->insertGetId([
						"user"=>$item['user'],
						"password"=>$item['password'],
						"userid"=>session("userid"),
						"cartid"=>$cart['id'],
						"atime"=>$now,
						"ztime"=>$now+$times,
						"state"=>"0",
						"auto_create_at"=>$now+$autoDelay*60,
						"ordernumber"=>generate_order_number(0),
						"data1"=>"",
						"data2"=>"",
						"data3"=>"",
						"data4"=>"",
						"data5"=>"",
						"data6"=>$orderConfig ? json_encode($orderConfig, JSON_UNESCAPED_UNICODE) : "",
						"data7"=>"",
						"data8"=>"",
						"data9"=>"",
						"data10"=>"",
					]);
					Db::name('order')->where('id', $orderId)->update(['ordernumber'=>generate_order_number($orderId)]);
Db::name('order')->where('id', $orderId)->update(['paid_amount'=>$item['money']]); // 记录已支付金额，供退款计算
					Db::name('cart')->where('id', $cart['id'])->update(['inventory'=>max(0, intval($cart['inventory'])-1)]);
					Db::name('shopping_cart')->where('id', $item['id'])->update(['status'=>"1"]);
					// 购买时勾选了 Docker 容器开通：随主机订单生成待开通记录（余额支付）
					if (!empty($item['docker'])) { docker_create_for_purchase($orderId, 'balance'); }
					Db::name('transaction')->insertGetId([
						"userid"=>session("userid"),
						"content"=>"(余额支付)购买产品,ID:".$orderId."(套餐ID:".$cart['id']."),时长:".$item['time']."周期:".$item['cycle'].",消费:".$item['money']."(延迟".$autoDelay."分钟自动开通)",
						"time"=>time(),
					]);
					$pendingCount++;
					$successIds[] = $item['id'];
					continue;
				}

				// 自动开通模式（延迟为0）：付款后立即开通
				$pluginFile = PATH."plugins/host/".$server['serverplugins']."/".$server['serverplugins'].".php";
				if(!file_exists($pluginFile)){
					$failedMsg[] = "产品#{$item['cartid']}插件文件不存在";
					continue;
				}
				include_once $pluginFile;

				$data2 = [
					"user"=>$item['user'],
					"password"=>$item['password'],
					"time"=>$item['time'],
				];
				$function = $server['serverplugins']."_CreateAccount";
				if(!function_exists($function)){
					$failedMsg[] = "产品#{$item['cartid']}未实现开通接口";
					continue;
				}
				$result = @$function($server, $data2, apply_host_custom_config($cart, host_config_from_cart_row($item)), $times);
				if(!is_array($result) || !isset($result['code']) || $result['code']!="1"){
					$failedMsg[] = "产品#{$item['cartid']}开通失败：".($result['msg'] ?? '未知错误');
					continue;
				}

				// 扣库存
				Db::name('cart')->where('id', $cart['id'])->update(['inventory'=>max(0, intval($cart['inventory'])-1)]);
				// 标记已支付
				Db::name('shopping_cart')->where('id', $item['id'])->update(['status'=>"1"]);
				// 购买时勾选了 Docker 容器开通：生成待开通记录，并按 docker_mode 自动/人工开通
				if (!empty($item['docker'])) {
					docker_create_for_purchase($result['id'], 'balance');
					docker_provision_pending_for_order($result['id']);
				}
				// 记录交易日志
				Db::name('order')->where('id', $result['id'])->update(['paid_amount'=>$item['money']]); // 记录已支付金额，供退款计算
				Db::name('transaction')->insertGetId([
					"userid"=>session("userid"),
					"content"=>"购买产品,ID:".$result['id']."(套餐ID:".$cart['id']."),时长:".$item['time']."周期:".$item['cycle'].",消费:".$item['money'],
					"time"=>time(),
				]);
				// 推广佣金
				if(!empty($this->user["upperid"]) && floatval($item['money'])>0 && isset($this->web["affdiscount"])){
					$upper=round(floatval($item['money'])*floatval($this->web["affdiscount"]),2);
					$upperuser=Db::name('user')->where('id',$this->user["upperid"])->find();
					if($upperuser){
						Db::name('user')->where('id',$this->user["upperid"])->update([
							'affmoney' =>round($upperuser["affmoney"]+$upper,2),
						]);
						Db::name('affsymoney')->insertGetId([
							"information"=>"下级ID:".session("userid")."购买产品",
							"money"=>$upper,
							"userid"=>$this->user["upperid"],
							"time"=>time(),
						]);
					}
				}
				$successIds[] = $item['id'];
			}

			if(empty($successIds)){
				$array["msg"]="购买失败：".implode("；", $failedMsg);
				return $array;
			}

			// 扣款
			$money1 = round($this->user['money'] - $total, 2);
			Db::name('user')->where('id', session("userid"))->update([
				'money' => $money1,
				'total_recharge' => round(floatval($this->user['total_recharge'] ?? 0) + $total, 2)
			]);
			if (function_exists('update_user_membership')) {
				update_user_membership(session('userid'));
			}

			// 发送邮件通知
			if(isset($this->web["email"]) && $this->web["email"]=="1"){
				$userInfo = Db::name('user')->where("id",session("userid"))->find();
				if($userInfo && !empty($userInfo["mail"])){
					try {
						$this->email($userInfo["mail"],"购买产品通知","你账号:".$userInfo["user"]."在时间:".date("Y-m-d H:i:s")."在本站购买产品成功,共".$total."元,请登录产品管理查看!<br/><br/>");
					} catch (\Exception $mailEx) {
					}
				}
			}

			$array["code"]="1";
			if($pendingCount > 0 && empty($failedMsg)){
				$array["msg"]="购买成功，产品将于 ".$autoDelay." 分钟后自动开通";
			}elseif($pendingCount > 0){
				$array["msg"]="部分产品待自动开通，部分产品开通失败：".implode("；", $failedMsg);
			}else{
				$array["msg"]="购买成功".(!empty($failedMsg) ? "，部分失败：".implode("；", $failedMsg) : "，产品已开通");
			}
			$array["success_ids"]=$successIds;
			return $array;
		}

		// 在线支付：记录待支付项，跳转到支付通道，回调后自动结算
		if(!$payid){
			$array["msg"]="请选择支付方式";
			return $array;
		}
		$payInfo = Db::name('pays')->where(['id'=>$payid,"state"=>"1"])->find();
		if(!$payInfo){
			$array["msg"]="支付方式不存在或已关闭";
			return $array;
		}

		session("cart_order_ids", array_values(array_unique(array_filter(array_column($items, 'id')))));
		session("cart_order_total", $total);

		$array["code"]="1";
		$array["msg"]="正在跳转支付...";
		$array["redirect"] = Request::instance()->root().'/user/cartPay/'.$payid;
		return $array;
	}

	// 购物车结算（保留兼容，实际已不再使用购物车页面）
	public function cartCheckout() {
		if(Request::instance()->isPost()){
			try {
				$this->clearExpiredCart();
				process_pending_host_orders();
				$check = check_realname_limit('buy');
				if($check['code'] != 1){
					return json(["code"=>(string)$check['code'], "msg"=>$check['msg']]);
				}
				$ids = input("ids");
				$paytype = input("paytype", "balance");
				$payid = input("payid");

				if(!$ids){
					return json(["code"=>"-1","msg"=>"请选择要结算的商品"]);
				}
				$idArr = is_array($ids) ? $ids : explode(',', $ids);
				$idArr = array_filter(array_map('intval', $idArr));
				if(empty($idArr)){
					return json(["code"=>"-1","msg"=>"请选择要结算的商品"]);
				}

				$items = Db::name('shopping_cart')
					->where('userid', session("userid"))
					->where('status', '0')
					->where('id', 'in', $idArr)
					->select();

				if(empty($items)){
					return json(["code"=>"-1","msg"=>"待结算商品已过期或不存在"]);
				}

				return json($this->settleItems($items, $paytype, $payid));
			} catch (\Exception $e) {
				return json(["code"=>"-1","msg"=>"结算异常：".$e->getMessage()]);
			}
		}
		return json(["code"=>"-1","msg"=>"非法请求"]);
	}

	// 立即购买（跳过购物车，选好配置后直接下单结算）
	public function buyNow() {
		if(!Request::instance()->isPost()){
			return json(["code"=>"-1","msg"=>"非法请求"]);
		}
		if(!session("userid")){
			return json(["code"=>"-1","msg"=>"请先登录后再购买","login"=>1]);
		}
		try {
			$this->ensureCartTable();
			if (function_exists('ensure_cart_table')) { ensure_cart_table(); }
			$this->clearExpiredCart();
			process_pending_host_orders();

			$check = check_realname_limit('buy');
			if($check['code'] != 1){
				return json(["code"=>(string)$check['code'], "msg"=>$check['msg']]);
			}

			$cartid = input("cartid");
			$user = input("user");
			$password = input("password");
			$time = input("time");
			// 用户选择的线路（服务器ID），0/空 表示使用套餐默认线路
			$serverid  = intval(input("serverid", 0));
			// 自定义主机配置（MB），0/空 表示使用套餐默认值
			$spaceMb   = intval(input("space_mb", 0));
			$dbMb      = intval(input("db_mb", 0));
			// 月流量：前台已改为按 GB 输入，兼容旧参数 traffic_mb
			$trafficGbRaw = input("traffic_gb", null);
			if ($trafficGbRaw !== null && $trafficGbRaw !== '') {
				$trafficMb = intval(round(floatval($trafficGbRaw) * 1024));
			} else {
				$trafficMb = intval(input("traffic_mb", 0));
			}
			// 自定义域名绑定数
			$domainNum = intval(input("domain_num", 0));
			$paytype   = input("paytype", "balance");
			$payid     = input("payid");

			if($cartid=="" || $user=="" || $password=="" || $time==""){
				return json(["code"=>"-1","msg"=>"必填参数不可为空!"]);
			}
			if(!is_numeric($time) || floor($time)!=$time || $time<1){
				return json(["code"=>"-1","msg"=>"购买时长只能填写正整数!"]);
			}
			// 账号密码格式验证：只允许小写字母+数字
			if(strlen($user) < 3 || strlen($user) > 50){
				return json(["code"=>"-1","msg"=>"主机账号长度需在3-50个字符之间!"]);
			}
			if(!preg_match('/^[a-z][a-z0-9]*$/', $user)){
				return json(["code"=>"-1","msg"=>"主机账号必须以小写字母开头，且只能包含小写字母和数字!"]);
			}
			if(strlen($password) < 6 || strlen($password) > 50){
				return json(["code"=>"-1","msg"=>"主机密码长度需在6-50个字符之间!"]);
			}
			if(!preg_match('/^[a-z0-9]+$/', $password)){
				return json(["code"=>"-1","msg"=>"主机密码只能包含小写字母和数字!"]);
			}
			// 自定义配置合法性
			$capMax = 1048576; // 单项上限 1TB(MB)
			if($spaceMb < 0 || $dbMb < 0 || $trafficMb < 0 || $domainNum < 0){
				return json(["code"=>"-1","msg"=>"自定义配置不能为负数!"]);
			}
			if($spaceMb > $capMax || $dbMb > $capMax || $trafficMb > $capMax){
				return json(["code"=>"-1","msg"=>"自定义配置超出允许上限!"]);
			}
			if($domainNum > 10000){
				return json(["code"=>"-1","msg"=>"域名绑定数超出允许上限!"]);
			}

			$cart = Db::name('cart')->where('id', $cartid)->find();
			if(!$cart){
				return json(["code"=>"-1","msg"=>"产品不存在!"]);
			}
			// 自定义配置不得低于套餐默认值（只能加配不能降配）
			if(function_exists('validate_host_config_floor')){
				$floorErr = validate_host_config_floor($cart, $spaceMb, $dbMb, $trafficMb, $domainNum);
				if($floorErr !== ''){ return json(["code"=>"-1","msg"=>$floorErr]); }
			}
			// 线路校验：必须是 mnbt 插件的服务器；不传则用套餐默认线路
			$serverRow = null;
			if($serverid > 0){
				$serverRow = Db::name('server')->where('id', $serverid)->find();
				if(!$serverRow){
					return json(["code"=>"-1","msg"=>"选择的线路不存在!"]);
				}
				if(strtolower(trim((string)$serverRow['serverplugins'])) !== 'mnbt'){
					return json(["code"=>"-1","msg"=>"该线路暂不支持自助开通!"]);
				}
			} else {
				$serverRow = Db::name('server')->where('id', $cart['serverid'])->find();
				if(!$serverRow){
					return json(["code"=>"-1","msg"=>"该套餐未配置服务器线路!"]);
				}
			}
			if($cart["buy"]=="1"){
				return json(["code"=>"-1","msg"=>"该产品已设置禁止购买!"]);
			}
			if($cart["inventory"] < 1){
				return json(["code"=>"-1","msg"=>"该产品已售完!"]);
			}
			if($cart["limits"]=="1"){
				$exists = Db::name('order')->where(["cartid"=>$cartid,"userid"=>session("userid")])->find();
				if($exists){
					return json(["code"=>"-1","msg"=>"该产品只允许订购一次!"]);
				}
			}
			// 各周期专属价：基础单价为 0 的套餐靠它定价
			$cyclePriceMap = function_exists('cart_cycle_prices') ? cart_cycle_prices($cart) : [];
			if(floatval($cart["money"])<=0 && empty($cyclePriceMap) && $time!="1"){
				return json(["code"=>"-1","msg"=>"免费产品的购买时间只能填写1!"]);
			}
			if(floatval($cart["money"])<=0 && !empty($cyclePriceMap) && !isset($cyclePriceMap[intval($time)])){
				return json(["code"=>"-1","msg"=>"该购买时长暂未配置价格，请返回购买页重新选择时长!"]);
			}
			if($cart["cycle"]=="unrestricted" && $time!="1"){
				return json(["code"=>"-1","msg"=>"一次性产品的购买时间只能填写1!"]);
			}

			// ── Docker 容器开通（购买页勾选的附加项）──
			// 仅当全局开关 + 产品 docker_enabled + 服务器插件(mnbt._DockerOpen) 三者都满足时才生效
			$dockerOn = intval(input("docker", 0));
			$dockerEnabled = false;
			$dockerPrice = 0;
			if ($dockerOn === 1) {
				$webDocker = \think\Db::name('web')->where('id', 1)->find();
				if (!function_exists('docker_is_enabled') || !docker_is_enabled($webDocker, $cart, $serverRow)) {
					return json(["code"=>"-1","msg"=>"当前套餐/线路不支持 Docker 容器开通!"]);
				}
				$dockerEnabled = true;
				$dockerPrice = function_exists('docker_price') ? docker_price($webDocker) : 0;
				// Docker 开通费不支持用积分抵扣，必须走余额或在线支付
				if ($paytype == "points") {
					return json(["code"=>"-1","msg"=>"Docker 容器开通需使用余额或在线支付，不支持积分支付!"]);
				}
			}

			// 清理同一套餐同一账号的旧未支付记录
			Db::name('shopping_cart')->where([
				"userid"=>session("userid"),
				"cartid"=>$cartid,
				"user"=>$user,
				"status"=>"0"
			])->delete();

			$discount = function_exists('get_membership_discount') ? get_membership_discount(session('userid'), 'buy') : 1.00;
			// 套餐金额：优先用后台为该周期配置的自定义价格，未配置则回退「单价 × 时长」
			$basePrice = function_exists('cart_cycle_price')
				? cart_cycle_price($cart, $time)
				: round(floatval($cart['money']) * intval($time), 2);
			$money = ($cart["firstmo"]=="1" && !isset($cyclePriceMap[intval($time)])) ? "0" : round($basePrice * $discount, 2);
			// 自定义配置加价（不受会员折扣影响）
			if(function_exists('calc_host_config_extra')){
				$money = round($money + calc_host_config_extra($cart, $spaceMb, $dbMb, $trafficMb, $domainNum), 2);
			}
			// Docker 容器开通费（全局统一价，叠加到整单，不受会员折扣影响）
			if ($dockerEnabled) {
				$money = round(floatval($money) + floatval($dockerPrice), 2);
			}

			// 积分支付：按套餐的积分价扣积分，扣完视为已付款（金额按 0 结算）
			$pointsUsed = 0;
			if($paytype == "points"){
				$unitPoints = intval($cart['points_price'] ?? 0);
				if($unitPoints <= 0){
					return json(["code"=>"-1","msg"=>"该产品不支持积分购买!"]);
				}
				$pointsUsed = $unitPoints * intval($time);
				$me = Db::name('user')->where('id', session('userid'))->find();
				$myPoints = $me ? intval($me['points'] ?? 0) : 0;
				if($myPoints < $pointsUsed){
					return json(["code"=>"-1","msg"=>"积分不足，本单需 ".$pointsUsed." 积分，当前可用 ".$myPoints." 积分!"]);
				}
				// 先扣积分，结算失败会退回
				Db::name('user')->where('id', session('userid'))->setDec('points', $pointsUsed);
				$money = 0;
				$paytype = "balance";
				$payid = 0;
			}

			$insertId = Db::name('shopping_cart')->insertGetId([
				"userid"=>session("userid"),
				"cartid"=>$cartid,
				"user"=>$user,
				"password"=>$password,
				"time"=>$time,
				"money"=>$money,
				"cycle"=>$cart["cycle"],
				"space_mb"=>$spaceMb,
				"db_mb"=>$dbMb,
				"traffic_mb"=>$trafficMb,
				"domain_num"=>$domainNum,
				"points_used"=>$pointsUsed,
				"serverid"=>$serverid > 0 ? $serverid : intval($cart['serverid']),
				"docker"=>$dockerEnabled ? 1 : 0,
				"created_at"=>time(),
				"status"=>"0",
			]);

			$item = Db::name('shopping_cart')->where('id', $insertId)->find();
			if(!$item){
				if($pointsUsed > 0){ Db::name('user')->where('id', session('userid'))->setInc('points', $pointsUsed); }
				return json(["code"=>"-1","msg"=>"下单失败，请重试"]);
			}

			// 免费订单（套餐为0 / 首月免费 / 积分支付 / 折扣后为0）不走在线支付
			if (floatval($money) <= 0) { $paytype = "balance"; }

			$res = $this->settleItems([$item], $paytype, $payid);
			if(!isset($res["code"]) || $res["code"] != "1"){
				// 结算失败：清理本次待支付记录，避免遗留脏数据；积分支付需要退还积分
				Db::name('shopping_cart')->where('id', $insertId)->delete();
				if($pointsUsed > 0){ Db::name('user')->where('id', session('userid'))->setInc('points', $pointsUsed); }
			}
			return json($res);
		} catch (\Exception $e) {
			return json(["code"=>"-1","msg"=>"购买异常：".$e->getMessage()]);
		}
	}

	// 直接拉起支付插件收款（购物车在线支付）
	public function cartPay() {
		$payid = input("payid");
		if(!$payid){
			exit("<title>出错啦!</title>请选择支付方式!");
		}
		$check = check_realname_limit('buy');
		if($check['code'] != 1){
			// 跳回产品购买页并提示实名认证
			$msg = urlencode($check['msg']);
			$this->redirect('/cart?realname_required=1&msg=' . $msg);
		}
		$cartIds = session("cart_order_ids");
		$cartTotal = session("cart_order_total");
		if(empty($cartIds) || !is_numeric($cartTotal)){
			exit("<title>出错啦!</title>待支付订单已过期，请重新购买!");
		}
		$data1 = Db::name('pays')->where([
			'id'=>$payid,
			"state"=>"1",
		])->find();
		if(!$data1){
			exit("<title>出错啦!</title>支付方式不存在或已关闭!");
		}
		// 插件通过 input() 读取金额和支付方式，这里手动注入到请求参数
		Request::instance()->get(['money'=>$cartTotal, 'payid'=>$payid]);
		Request::instance()->post(['money'=>$cartTotal, 'payid'=>$payid]);
		// 标记订单来源，便于支付回调后区分
		Request::instance()->post(['cart_pay'=>'1']);
		@include PATH."plugins/pay/".$data1["plugins"]."/go.php";
	}

	// 推广联盟
	public function aff() {
if(!$this->user["aff"]){
if(Request::instance()->isPost()) {
while(true){
$affsj=random("6");
$data=Db::name('user')->where('aff',$affsj)->find();
if(!$data){
$user=Db::name('user')->where('id',session("userid"))->update([
"aff"=>$affsj,
]);
break;
}
}
	$array["code"]="1";
	$array["msg"]="开启推广成功!";
return json($array);
}
return $this->fetch('/'.$this->web["template"].'/user/aff',[
]);
}else{
if(Request::instance()->isPost()) {
$act=input("act");
if($act=="txye"){
if($this->user["affmoney"]< $this->web["affwithdrawal"]){
$array["code"]="-1";
$array["msg"]="最小提现金额为:".$this->web["affwithdrawal"];
}else{
$data=Db::name('user')->where('id',session("userid"))->update([
"money"=>$this->user["money"]+$this->user["affmoney"],
"affmoney"=>"0",
]);
if($data){
$data1=Db::name('afftxjl')->insertGetId([
"information"=>"提现到账户余额",
"money"=>$this->user["affmoney"],
"userid"=>session("userid"),
"state"=>"1",
"time"=>time(),
]);
$array["code"]="1";
$array["msg"]="已成功提现到账户余额!";
}else{
$array["code"]="-1";
$array["msg"]="提现到余额失败!";
}
}

return json($array);
}

if($act=="txzfb"){
$zfbxm=input("zfbxm");
$zfbzh=input("zfbzh");
if($zfbxm=="" || $zfbzh==""){
$array["code"]="-1";
$array["msg"]="必填参数不可为空!";
}else{
if($this->user["affmoney"]< $this->web["affwithdrawal"]){
$array["code"]="-1";
$array["msg"]="最小提现金额为:".$this->web["affwithdrawal"];
}else{
$data=Db::name('user')->where('id',session("userid"))->update([
"affmoney"=>"0",
]);
if($data){
$data1=Db::name('afftxjl')->insertGetId([
"information"=>"提现到支付宝账户,姓名:<span style='color:#ff6b6b'>".$zfbxm."</span>账号:<span style='color:#ff6b6b'>".$zfbzh."</span>",
"money"=>$this->user["affmoney"],
"userid"=>session("userid"),
"state"=>"0",
"time"=>time(),
]);
if($this->web["email"]=="1"){
$admin=Db::name('admin')->where("id","1")->find();
if($admin["mail"]){
$mailbox=$this->email($admin["mail"],"推广余额提现通知","账号:".$this->user["user"]."在时间:".date("Y-m-d H:i:s")."申请支付宝提现!<br/>提现记录ID为:".$data1."<br/><br/>");
}
}
$array["code"]="1";
$array["msg"]="提现申请已提交!";
}else{
$array["code"]="-1";
$array["msg"]="提现到余额失败!";
}
}
}
return json($array);
}


}


$affsymoney=Db::name("affsymoney")->where("userid",session("userid"))->order('id desc')->paginate(10);

$afftxjl=Db::name("afftxjl")->where("userid",session("userid"))->order('id desc')->paginate(10);
$affuser=Db::name("user")->where("upperid",session("userid"))->order('id desc')->paginate(10);
//exit(dump($affuser));

// 推广中心汇总数据
$totalEarnings = Db::name("affsymoney")->where("userid",session("userid"))->sum("money") ?: 0;
$totalWithdrawn = Db::name("afftxjl")->where("userid",session("userid"))->where("state","1")->sum("money") ?: 0;
$pendingWithdraw = Db::name("afftxjl")->where("userid",session("userid"))->where("state","0")->sum("money") ?: 0;
$referralCount = Db::name("user")->where("upperid",session("userid"))->count();

$web=$this->web;
return $this->fetch('/'.$this->web["template"].'/user/affs',[
"affurl"=>(isHTTPS() ? 'https://' : 'http://').$_SERVER['HTTP_HOST']."/aff/".$this->user["aff"],
"affdiscount"=>floatval($web["affdiscount"])*100,
"affwithdrawal"=>floatval($web["affwithdrawal"]),
"affsymoney"=>$affsymoney,
"afftxjl"=>$afftxjl,
"affus"=>$affuser,
"totalEarnings"=>round($totalEarnings,2),
"totalWithdrawn"=>round($totalWithdrawn,2),
"pendingWithdraw"=>round($pendingWithdraw,2),
"referralCount"=>$referralCount,
]);



}
}


public function transfer(){
if(Request::instance()->isPost()) {
$act=input("act");
if($act=="validate"){
$captcha=input("captcha");
if($captcha==""){
	$array["code"]="-1";
	$array["msg"]="必填参数不可为空!";
}else{
if(!\app\index\controller\Captcha::check()){
	$array["code"]="-1";
	$array["msg"]="验证码错误!";
}else{
$random=random(6,'0123456789');
session("ghid",$this->user["id"]);
session("ghyzm",$random);
if($this->web["email"]=="1"){
if($this->user["mail"]){
if (!rate_limit('transfer_email_' . $this->user['id'], 3, 60)) { $array['code']='-1'; $array['msg']='发送频率过快，请稍后再试'; return json($array); }
$codeBody = "<p>您好，</p><p>您正在申请产品过户，本次验证码为：</p><p style='text-align:center;margin:28px 0;'><span style='display:inline-block;background:#eff6ff;color:#2563eb;font-size:28px;font-weight:700;padding:14px 32px;border-radius:10px;letter-spacing:4px;border:1px solid #bfdbfe;'>{$random}</span></p><p style='color:#64748b;font-size:13px;'>验证码 10 分钟内有效，请勿将验证码告知他人。如非本人操作，请忽略此邮件。</p>";
$mailbox=$this->email($this->user["mail"],"产品过户通知",$codeBody);
$array["code"]="1";
$array["msg"]="发送验证码成功!";
}else{
	$array["code"]="-1";
	$array["msg"]="没有绑定邮箱!";
}
}else{
$array["code"]="-1";
$array["msg"]="本站未开启邮箱提醒!";
}
}
}
return json($array);
}

if($act=="transfer"){
$code=input("code");
$orderid=input("orderid");
$newuserid=input("newuserid");
if($code=="" || $orderid=="" || $newuserid==""){
$array["code"]="-1";
$array["msg"]="必填参数不可为空!";
}else{
if(!session("ghid") || !session("ghyzm")){
$array["code"]="-1";
$array["msg"]="请你重新获取验证码!";
}else{
if(session("ghid")!=$this->user["id"]){
$array["code"]="-1";
$array["msg"]="账号不匹配,请你重新获取验证码!";
}else{
if($code==session("ghyzm")){
if($newuserid==session("userid")){
$array["code"]="-1";
$array["msg"]="过户的用户ID不能为自己!";
}else{
$data=Db::name("order")->where([
"id"=>$orderid,
"userid"=>session("userid"),
])->find();
if($data){
$data1=Db::name("user")->where("id",$newuserid)->find();
if($data1){
$data2=Db::name("order")->where("id",$data["id"])->update([
"userid"=>$newuserid,
]);
if($data2){
session("ghid",null);
session("ghyzm",null);
if($this->web["email"]=="1"){
if($data1["mail"]){
$mailbox=$this->email($data1["mail"],"产品接收通知","账号:".$data1["user"]."在时间:".date("Y-m-d H:i:s")."接收产品成功!<br/>产品ID:".$orderid."<br/>它的账户ID:".session("userid")."<br/><br/>");
}
if($this->user["mail"]){
$mailbox1=$this->email($this->user["mail"],"过户成功通知","账号:".$this->user["user"]."在时间:".date("Y-m-d H:i:s")."过户产品成功!<br/>过户产品ID:".$orderid."<br/>过户的账号ID:".$newuserid."<br/><br/>");
}
}
$data4=Db::name('transferrecord')->insertGetId([
"userid"=>session("userid"),
"record"=>"产品ID:".$orderid.",过户给用户ID:".$newuserid,
"time"=>time(),
]);

$data5=Db::name('transferrecord')->insertGetId([
"userid"=>$newuserid,
"record"=>"接受产品ID:".$orderid.",过户者ID:".session("userid"),
"time"=>time(),
]);
$array["code"]="1";
$array["msg"]="过户成功!";
}else{
$array["code"]="-1";
$array["msg"]="过户失败!";
}
}else{
$array["code"]="-1";
$array["msg"]="你要过户的用户ID不存在!";
}
}else{
$array["code"]="-1";
$array["msg"]="产品不存在!";
}
}
}else{
$array["code"]="-1";
$array["msg"]="邮箱验证码错误!";
}
}
}
}
return json($array);
}
}

$order=Db::name("order")->where("userid",session("userid"))->order('id desc')->select();
// 优化：批量查询 cart 表，避免 N+1 查询
$cartIds = array_unique(array_filter(array_column($order, 'cartid')));
$cartMap = [];
if (!empty($cartIds)) {
    $cartMap = Db::name('cart')->where('id', 'in', $cartIds)->column('name', 'id');
}
foreach ($order as &$orderItem) {
    $cid = $orderItem['cartid'];
    $orderItem['cartid'] = isset($cartMap[$cid]) ? $cartMap[$cid] : ('产品#' . $cid);
}
unset($orderItem);
return $this->fetch('/'.$this->web["template"].'/user/transfer',[
"order"=>$order,
]);
}

public function mail() {
if($this->user["mail"]){
if(Request::instance()->isPost()) {
$act=input("act");
if($act=="yxvalidate"){
	$captcha=input("captcha");
	if($captcha==""){
		$array["code"]="-1"; $array["msg"]="必填参数不可为空!";
	}else{
		if(!\app\index\controller\Captcha::check()){
			$array["code"]="-1"; $array["msg"]="验证码错误!";
		}else{
			if($this->web["email"]=="1"){
				if (!rate_limit('mail_modify_' . $this->user['id'], 3, 60)) { $array['code']='-1'; $array['msg']='发送频率过快，请稍后再试'; return json($array); }
				$yxyzm = random(6,'0123456789');
				$verifyToken = md5(uniqid(mt_rand(), true));
				$expireTime = time() + 600;
				session("xgmail",$this->user["mail"]);
				session("xgmailyzm",$yxyzm);
				session("xgmail_verified",null);
				Db::name('email_verify')->where('mail', $this->user['mail'])->where('verified', 1)->update(['verified' => 0]);
				$insertId = Db::name('email_verify')->insertGetId([
					'mail' => $this->user['mail'],
					'token' => $verifyToken,
					'code' => $yxyzm,
					'verified' => 0,
					'create_time' => time(),
					'expire_time' => $expireTime,
				]);
				if (!$insertId) { $array["code"]="-1"; $array["msg"]="系统繁忙，请稍后重试"; return json($array); }
				$verifyLink = request()->domain() . request()->root() . '/verify_email?token=' . $verifyToken . '&mail=' . urlencode($this->user['mail']);
				$codeBody = "<p>您好，</p><p>您正在申请修改 {$this->web['name']} 账号的绑定邮箱，请选择以下任意一种方式完成验证：</p>";
				$codeBody .= "<p style='text-align:center;margin:24px 0;'><a href='{$verifyLink}' style='display:inline-block;background:#2563eb;color:#fff;font-size:16px;font-weight:600;padding:14px 32px;border-radius:10px;text-decoration:none;'>点击此链接验证身份</a></p>";
				$codeBody .= "<p style='color:#64748b;font-size:13px;text-align:center;'>或输入以下备用验证码：</p>";
				$codeBody .= "<p style='text-align:center;margin:16px 0 28px;'><span style='display:inline-block;background:#eff6ff;color:#2563eb;font-size:28px;font-weight:700;padding:14px 32px;border-radius:10px;letter-spacing:4px;border:1px solid #bfdbfe;'>{$yxyzm}</span></p>";
				$codeBody .= "<p style='color:#64748b;font-size:13px;'>验证码和链接 10 分钟内有效，如非本人操作，请忽略此邮件。</p>";
				$sendResult = self::email($this->user['mail'], "修改邮箱验证通知", $codeBody);
				$array["code"]="1"; $array["msg"]="验证邮件已发送，请检查邮箱";
			}else{
				$array["code"]="-1"; $array["msg"]="本站未开启邮箱提醒!";
			}
		}
	}
	return json($array);
}

if($act=="check_link_verify"){
	$mail = input("mail", "");
	$record = Db::name('email_verify')->where('mail', $mail)->where('verified', 1)->where('create_time', '>', time() - 3600)->order('id', 'desc')->find();
	if ($record) { session("xgmail_verified", 1); return json(['code' => 1, 'verified' => true]); }
	return json(['code' => 1, 'verified' => false]);
}

if($act=="modify"){
	$code=input("code");
	$newmail=input("newmail");
	if($newmail==""){
		$array["code"]="-1"; $array["msg"]="请输入新邮箱!";
	}else{
		$linkVerified = session("xgmail_verified");
		$codeMatch = (session("xgmailyzm") && session("xgmailyzm") == $code);
		if(!$linkVerified && !$codeMatch){
			$array["code"]="-1"; $array["msg"]="请先验证旧邮箱（点击邮件链接或输入备用验证码）!";
		}else{
			if(is_valid_email($newmail)){
				if($this->user["mail"]==$newmail){
					$array["code"]="-1"; $array["msg"]="新邮箱与旧邮箱一样!";
				}else{
					$newuser=Db::name('user')->where('mail',$newmail)->find();
					if($newuser){
						$array["code"]="-1"; $array["msg"]="新的邮箱已被其他账号绑定!";
					}else{
						$data=Db::name('user')->where('id',session("userid"))->update(["mail"=>$newmail]);
						session("xgmail",null); session("xgmailyzm",null); session("xgmail_verified",null);
						if($data !== false){ $array["code"]="1"; $array["msg"]="修改成功!"; }
						else{ $array["code"]="-1"; $array["msg"]="修改失败!"; }
					}
				}
			}else{ $array["code"]="-1"; $array["msg"]="邮箱格式错误!"; }
		}
	}
	return json($array);
}




}
	return $this->fetch('/'.$this->web["template"].'/user/mail',[
]);
}else{











if(Request::instance()->isPost()) {
$act=input("act");
if($act=="yxvalidate"){
	$captcha=input("captcha");
	$mail=input("mail");
	if($captcha=="" || $mail==""){
		$array["code"]="-1"; $array["msg"]="必填参数不可为空!";
	}else{
		if(is_valid_email($mail)){
			if(!\app\index\controller\Captcha::check()){
				$array["code"]="-1"; $array["msg"]="验证码错误!";
			}else{
				if($this->web["email"]=="1"){
					if (!rate_limit('mail_bind_' . $this->user['id'], 3, 60)) { $array['code']='-1'; $array['msg']='发送频率过快，请稍后再试'; return json($array); }
					$yxyzm = random(6,'0123456789');
					$verifyToken = md5(uniqid(mt_rand(), true));
					$expireTime = time() + 600;
					session("bdmail",$mail); session("bdmailyzm",$yxyzm); session("bdmail_verified",null);
					Db::name('email_verify')->where('mail', $mail)->where('verified', 1)->update(['verified' => 0]);
					$insertId = Db::name('email_verify')->insertGetId([
						'mail' => $mail,
						'token' => $verifyToken,
						'code' => $yxyzm,
						'verified' => 0,
						'create_time' => time(),
						'expire_time' => $expireTime,
					]);
					if (!$insertId) { $array["code"]="-1"; $array["msg"]="系统繁忙，请稍后重试"; return json($array); }
					$verifyLink = request()->domain() . request()->root() . '/verify_email?token=' . $verifyToken . '&mail=' . urlencode($mail);
					$codeBody = "<p>您好，</p><p>您正在为 {$this->web['name']} 账号绑定邮箱，请选择以下任意一种方式完成验证：</p>";
					$codeBody .= "<p style='text-align:center;margin:24px 0;'><a href='{$verifyLink}' style='display:inline-block;background:#2563eb;color:#fff;font-size:16px;font-weight:600;padding:14px 32px;border-radius:10px;text-decoration:none;'>点击此链接验证邮箱</a></p>";
					$codeBody .= "<p style='color:#64748b;font-size:13px;text-align:center;'>或输入以下备用验证码：</p>";
					$codeBody .= "<p style='text-align:center;margin:16px 0 28px;'><span style='display:inline-block;background:#eff6ff;color:#2563eb;font-size:28px;font-weight:700;padding:14px 32px;border-radius:10px;letter-spacing:4px;border:1px solid #bfdbfe;'>{$yxyzm}</span></p>";
					$codeBody .= "<p style='color:#64748b;font-size:13px;'>验证码和链接 10 分钟内有效，如非本人操作，请忽略此邮件。</p>";
					$sendResult = self::email($mail, "绑定邮箱验证通知", $codeBody);
					$array["code"]="1"; $array["msg"]="验证邮件已发送，请检查邮箱";
				}else{
					$array["code"]="-1"; $array["msg"]="本站未开启邮箱提醒!";
				}
			}
		}else{
			$array["code"]="-1"; $array["msg"]="邮箱格式错误!";
		}
	}
	return json($array);
}

if($act=="check_link_verify"){
	$mail = input("mail", "");
	$record = Db::name('email_verify')->where('mail', $mail)->where('verified', 1)->where('create_time', '>', time() - 3600)->order('id', 'desc')->find();
	if ($record) { session("bdmail_verified", 1); return json(['code' => 1, 'verified' => true]); }
	return json(['code' => 1, 'verified' => false]);
}

if($act=="modify"){
	$code=input("code");
	$mail=input("mail");
	if($mail==""){
		$array["code"]="-1"; $array["msg"]="必填参数不可为空!";
	}else{
		$linkVerified = session("bdmail_verified");
		$codeMatch = (session("bdmailyzm") && session("bdmailyzm") == $code);
		if(!$linkVerified && !$codeMatch){
			$array["code"]="-1"; $array["msg"]="请先验证邮箱（点击邮件链接或输入备用验证码）!";
		}else{
			if(is_valid_email($mail)){
				if(session("bdmail")!=$mail){
					$array["code"]="-1"; $array["msg"]="邮箱不匹配，请重新获取验证!";
				}else{
					$newuser=Db::name('user')->where('mail',$mail)->find();
					if($newuser){
						$array["code"]="-1"; $array["msg"]="邮箱已被其他账号绑定!";
					}else{
						$data=Db::name('user')->where('id',session("userid"))->update(["mail"=>$mail]);
						session("bdmail",null); session("bdmailyzm",null); session("bdmail_verified",null);
						if($data !== false){ $array["code"]="1"; $array["msg"]="绑定成功!"; }
						else{ $array["code"]="-1"; $array["msg"]="绑定失败!"; }
					}
				}
			}else{
				$array["code"]="-1"; $array["msg"]="邮箱格式错误!";
			}
		}
	}
	return json($array);
}




}










	return $this->fetch('/'.$this->web["template"].'/user/bdmail',[
]);
}
}



	public function index() {
$this->ensureUserColumns();
ensure_points_products_table();
ensure_membership_levels_table();
ensure_points_log_table();
// 处理待开通订单（cron 未配置时兜底）
process_pending_host_orders();
// 管理员代看用户面板时不更新 last_login_ip/region，避免把管理员 IP 记录到该用户名下
if (!$this->adminViewMode) {
    // 兜底：若用户信息中登录相关字段为空，则重新记录（兼容字段刚新增的老用户）
    // 使用 get_client_ip() 兼容 CDN / 反向代理，避免获取到 CDN 节点 IP
    $loginIp = function_exists('get_client_ip') ? get_client_ip() : (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown');
    $storedIp = isset($this->user['last_login_ip']) ? $this->user['last_login_ip'] : '';
    $storedRegion = isset($this->user['last_login_region']) ? $this->user['last_login_region'] : '';
    // IP为空、地区为空，或IP发生变化时，重新记录真实IP和地区（兼容CDN）
    if (empty($storedIp) || empty($storedRegion) || $storedIp !== $loginIp) {
        try {
            Db::name('user')->where('id', session("userid"))->update([
                "last_login_time" => time(),
                "last_login_ip" => $loginIp,
                "last_login_region" => get_ip_region($loginIp)
            ]);
            $this->user = Db::name('user')->where('id', session("userid"))->find();
        } catch (\Exception $e) {}
    }
}
$data=Db::name('order')->where("userid",session("userid"))->order('id desc')->select();
// 优化：批量查询 cart 表，避免 N+1 查询
$cartIds = array_unique(array_filter(array_column($data, 'cartid')));
$cartMap = [];
if (!empty($cartIds)) {
    $cartMap = Db::name('cart')->where('id', 'in', $cartIds)->column('name', 'id');
}
foreach ($data as &$dataItem) {
    $cid = $dataItem['cartid'];
    $dataItem['cartid'] = isset($cartMap[$cid]) ? $cartMap[$cid] : ('产品#' . $cid);
}
unset($dataItem);
$data1=Db::name('announcements')->where('status',1)->order('id desc')->limit(5)->select();
$countorder=Db::name('order')->where("userid",session("userid"))->count();
$counthost=Db::name('order')->where(["userid"=>session("userid"),"state"=>["<>","3"]])->count();
$countticket=Db::name('ticket')->where("userid",session("userid"))->count();
$countrenew=Db::name('order')->where(["userid"=>session("userid"),"state"=>["<>","3"],"ztime"=>["<",time()]])->count();
$lastLoginTime=$this->user['last_login_time']?date('Y-m-d H:i:s',$this->user['last_login_time']):'-';
$lastLoginIp=$this->user['last_login_ip']?:'-';
$lastLoginRegion=$this->user['last_login_region']?:'-';
$active='index';
// 违规公示：查询该用户的违规记录（status=1 公示中）
$myViolations = [];
$publicViolations = [];
try {
    $myViolations = Db::name('violation')
        ->where('user_id', session('userid'))
        ->where('status', 1)
        ->order('create_time desc')
        ->select();
    // 所有公示中的违规记录（用于公告栏，前端CSS限制最多显示2条高度，其余滚动查看）
    $publicViolations = Db::name('violation')
        ->where('status', 1)
        ->order('create_time desc')
        ->select();
    $publicViolationsTotal = count($publicViolations);
} catch (\Exception $e) {}
		// 积分和会员数据
		$todayStart = strtotime(date('Y-m-d'));
		$canCheckin = ($this->user['last_checkin_time'] ?? 0) < $todayStart;
		$userPoints = intval($this->user['points'] ?? 0);
		$membershipLevel = intval($this->user['membership_level'] ?? 0);
		$membershipInfo = null;
		if ($membershipLevel > 0) {
			try {
				$membershipInfo = Db::name('membership_levels')->where('level', $membershipLevel)->where('status', 1)->find();
			} catch (\Exception $e) {}
		}
		// 最近签到记录
		$recentCheckins = [];
		try {
			$recentCheckins = Db::name('points_log')->where('userid', session('userid'))->where('type', 'checkin')->order('id desc')->limit(5)->select();
		} catch (\Exception $e) {}
		// 累计充值
		$totalRecharge = floatval($this->user['total_recharge'] ?? 0);
		// 下一个会员等级
		$nextLevel = null;
		if ($membershipLevel < 6) {
			try {
				$nextLevel = Db::name('membership_levels')->where('level', $membershipLevel + 1)->where('status', 1)->find();
			} catch (\Exception $e) {}
		}
		return $this->fetch('/'.$this->web["template"].'/user/index',[
"user"=>$this->user,
"order"=>$data,
"announcement"=>$data1,
"countorder"=>$countorder,
"counthost"=>$counthost,
"countticket"=>$countticket,
"countrenew"=>$countrenew,
"lastLoginTime"=>$lastLoginTime,
"lastLoginIp"=>$lastLoginIp,
"lastLoginRegion"=>$lastLoginRegion,
"active"=>$active,
"myViolations"=>$myViolations,
"publicViolations"=>$publicViolations,
"publicViolationsTotal"=>$publicViolationsTotal,
"userPoints"=>$userPoints,
"canCheckin"=>$canCheckin,
"membershipLevel"=>$membershipLevel,
"membershipInfo"=>$membershipInfo,
"totalRecharge"=>$totalRecharge,
"nextLevel"=>$nextLevel,
"recentCheckins"=>$recentCheckins,

]);
	}


public function information() {
	ensure_user_columns();
	$this->user = Db::name('user')->where('id', session("userid"))->find();
	$this->assign('user', $this->user);
if(Request::instance()->isPost()) {
$name=input("name");
$qq=input("qq");
$address=input("address");
if($name=="" || $qq==""){
$array["code"]="-1";
$array["msg"]="必填参数不能为空!";
}else{
// 防止把 OAuth openid 令牌保存进 qq 字段；强制要求 4-12 位纯数字
if(!preg_match('/^\d{4,12}$/', $qq)){
$array["code"]="-1";
$array["msg"]="QQ号码必须是4-12位纯数字!";
}else{
$data=Db::name('user')->where('id',$this->user["id"])->update([
"name" =>$name,
"qq"=>$qq,
"address"=>$address,
]);
if($data !== false){
$array["code"]="1";
$array["msg"]="修改资料成功!";
}else{
$array["code"]="-1";
$array["msg"]="修改资料失败!";
}
}
}
return json($array);
}
return $this->fetch('/'.$this->web["template"].'/user/information');
}

public function theme() {
	ensure_user_columns();
	$this->user = Db::name('user')->where('id', session("userid"))->find();
	$this->assign('user', $this->user);
	return $this->fetch('/'.$this->web["template"].'/user/theme');
}

public function settheme() {
	ensure_user_columns();
	if(!Request::instance()->isPost()) {
		return json(['code' => '-1', 'msg' => '非法请求']);
	}
	$themeMode = trim(input('theme_mode', ''));
	$themeGlass = trim(input('theme_glass', ''));
	$themeBgType = trim(input('theme_bg_type', ''));
	$themeBgImage = trim(input('theme_bg_image', ''));
	$musicEnabled = trim(input('music_enabled', ''));
	$act = trim(input('act', ''));

	// 背景图片上传（用户直接上传，无需输入链接）
	if ($act == 'upload_bg') {
		$file = request()->file('file');
		if (empty($file)) {
			return json(['code' => '-1', 'msg' => '请选择背景图片']);
		}
		$info = $file->validate(['size' => 8 * 1024 * 1024, 'ext' => 'jpg,jpeg,png,gif,webp'])
			->move(PATH . 'public/static/upload/bg');
		if (!$info) {
			return json(['code' => '-1', 'msg' => '上传失败：' . $file->getError()]);
		}
		$saveName = str_replace('\\', '/', $info->getSaveName());
		$imgUrl = '/static/upload/bg/' . $saveName;
		// 保存到用户自定义背景字段
		Db::name('user')->where('id', $this->user['id'])->update([
			'custom_bg_image' => $imgUrl,
			'theme_bg_type' => 'image',
		]);
		return json(['code' => '1', 'msg' => '背景上传成功', 'data' => ['url' => $imgUrl]]);
	}

	// 校验：空值表示跟随后台默认
	$allowMode = ['', 'light', 'dark'];
	$allowGlass = ['', '0', '1'];
	$allowBgType = ['', 'image', 'video', 'gif', 'none'];
	if (!in_array($themeMode, $allowMode, true)) $themeMode = '';
	if (!in_array($themeGlass, $allowGlass, true)) $themeGlass = '';
	if (!in_array($themeBgType, $allowBgType, true)) $themeBgType = '';
	// 背景图/视频 URL 长度限制，并做基础安全校验（拒绝明显危险协议）
	if (mb_strlen($themeBgImage) > 500) {
		return json(['code' => '-1', 'msg' => '背景地址过长']);
	}
	if ($themeBgImage !== '' && !preg_match('#^(https?://|/|\./|\.\./)[^\s]*$#i', $themeBgImage)) {
		return json(['code' => '-1', 'msg' => '背景地址格式不正确，仅支持 http(s) 或站点相对路径']);
	}

	$data = [
		'theme_mode' => $themeMode,
		'theme_glass' => $themeGlass,
		'theme_bg_type' => $themeBgType,
		'theme_bg_image' => $themeBgImage,
	];
	// 音乐开关（0/1）
	if ($musicEnabled !== '') {
		$data['music_enabled'] = ($musicEnabled == '1') ? 1 : 0;
	}
	try {
		Db::name('user')->where('id', $this->user['id'])->update($data);
		return json(['code' => '1', 'msg' => '主题设置已保存']);
	} catch (\Throwable $e) {
		return json(['code' => '-1', 'msg' => '保存失败：' . $e->getMessage()]);
	}
}

	public function password() {

		if(Request::instance()->isPost()) {
			$act=input("act");
			if($act=="jmmxg"){
			$password=input("userPwd");
			$newpassword=input("newUserPwd");
            $newuserrepwd=input("newUserRepwd");
if($password=="" || $newpassword=="" || $newuserrepwd==""){
$array["code"]="-1";
$array["msg"]="必填参数不可为空!";
}else{
if($newpassword!=$newuserrepwd){
	$array["code"]="-1";
	$array["msg"]="两次输入的新密码不一样!";
}else{
if($password==$newpassword){
	$array["code"]="-1";
    $array["msg"]="原始密码不能和新密码一样!";
}else{
			if(password_verify($password,$this->user["password"])) {
				$data=Db::name('user')->where('id',$this->user["id"])->update([
'password' =>password_hash($newpassword,PASSWORD_DEFAULT),
]);
				if($data) {
					$array["code"]="1";
					$array["msg"]="旧密码修改密码成功!";
if($this->web["email"]=="1"){
if($this->user["mail"]){
if (!rate_limit('password_modify_' . $this->user['id'], 3, 60)) { $array['code']='-1'; $array['msg']='发送频率过快，请稍后再试'; return json($array); }
$mailbox=$this->email($this->user["mail"],"旧密码修改密码通知","你账号:".$this->user["user"]."在时间:".date("Y-m-d H:i:s")."在本站旧密码修改密码成功!<br/><br/>");
}
}
				} else {
					$array["code"]="-1";
					$array["msg"]="修改密码失败";
				}
			} else {
				$array["code"]="-1";
				$array["msg"]="原始密码错误!";
			}
}
}
}

			return json($array);
			}
			
			if($act=="validate"){
$captcha=input("captcha");
if($captcha==""){
	$array["code"]="-1";
	$array["msg"]="必填参数不可为空!";
}else{
if(!\app\index\controller\Captcha::check()){
	$array["code"]="-1";
	$array["msg"]="验证码错误!";
}else{
$random=random(6,'0123456789');
session("mmzh",$this->user["id"]);
session("mmyzm",$random);
if($this->web["email"]=="1"){
if($this->user["mail"]){
if (!rate_limit('password_validate_' . $this->user['id'], 3, 60)) { $array['code']='-1'; $array['msg']='发送频率过快，请稍后再试'; return json($array); }
$codeBody = "<p>您好，</p><p>您正在申请修改密码，本次验证码为：</p><p style='text-align:center;margin:28px 0;'><span style='display:inline-block;background:#eff6ff;color:#2563eb;font-size:28px;font-weight:700;padding:14px 32px;border-radius:10px;letter-spacing:4px;border:1px solid #bfdbfe;'>{$random}</span></p><p style='color:#64748b;font-size:13px;'>验证码 10 分钟内有效，请勿将验证码告知他人。如非本人操作，请忽略此邮件。</p>";
$mailbox=$this->email($this->user["mail"],"修改密码通知",$codeBody);
$array["code"]="1";
$array["msg"]="发送验证码成功!";
}else{
	$array["code"]="-1";
	$array["msg"]="没有绑定邮箱!";
}
}else{
$array["code"]="-1";
$array["msg"]="本站未开启邮箱提醒!";
}
}
}
return json($array);
}

if($act=="yxmmxg"){
$code=input("code");
$newpass=input("newpass");
if($code=="" || $newpass==""){
$array["code"]="-1";
$array["msg"]="必填参数不可为空!";
}else{
if(!session("mmzh") || !session("mmyzm")){
$array["code"]="-1";
$array["msg"]="请你重新获取验证码!";
}else{
if(session("mmzh")!=$this->user["id"]){
$array["code"]="-1";
$array["msg"]="账号不匹配,请你重新获取验证码!";
}else{
if(session("mmyzm")==$code){
$data=Db::name('user')->where('id',session("userid"))->update([
"password"=>password_hash($newpass,PASSWORD_DEFAULT),
]);
if($data){
session("mmzh",null);
session("mmyzm",null);
if($this->web["email"]=="1"){
if($this->user["mail"]){
$mailbox=$this->email($this->user["mail"],"邮箱修改密码通知","你账号:".$this->user["user"]."在时间:".date("Y-m-d H:i:s")."在本站邮箱修改密码成功!<br/><br/>");
}
}
$array["code"]="1";
$array["msg"]="修改成功!";
}else{
$array["code"]="-1";
$array["msg"]="修改失败!";
}

}else{
$array["code"]="-1";
$array["msg"]="邮箱验证码错误!";
}
}
}
}
return json($array);
}




		}
		
		
		
		return $this->fetch('/'.$this->web["template"].'/user/password');
	}

	public function logout() {
		$uid = session("userid");
		// 管理员代看用户面板：退出时只清除代看标记并返回后台，不记录用户登出时间
		// 注意：纯普通用户退出必须回到前台登录页，不能因后台 admin 登录态而误跳后台
		if (session('admin_view_mode') || cookie('admin_view_mode')) {
			session('admin_view_userid', null);
			session('admin_view_mode', null);
			session('userid', null);
			cookie('admin_view_userid', null);
			cookie('admin_view_mode', null);
			// 同时还原 admin 自己的 session，使后台可继续操作
			if (session('adminid')) {
				$this->redirect('/admin/index');
			}
			// 没有任何管理员身份时，回到登录页
			$this->redirect(function_exists('admin_login_url') ? admin_login_url() : '/admin/login');
		}
		if ($uid) {
			try {
				ensure_user_columns();
				Db::name('user')->where('id', $uid)->update(['last_logout_time' => time()]);
			} catch (\Exception $e) {}
		}
		session("userid",null);
		$this->redirect('/login');
	}

	// 实名认证（三选一：身份证上传二要素 / 手机号三要素 / 人工审核）
	public function realname() {
		ensure_user_columns();
		$this->user = Db::name('user')->where('id', session("userid"))->find();
		$realnameMode = isset($this->web['realname_mode']) ? $this->web['realname_mode'] : '0';
		// 三种认证方式开关（后台自定义，默认全开）
		$methodIdcard = !isset($this->web['realname_method_idcard']) || $this->web['realname_method_idcard'] == '1';
		$methodPhone   = !isset($this->web['realname_method_phone']) || $this->web['realname_method_phone'] == '1';
		$methodManual  = !isset($this->web['realname_method_manual']) || $this->web['realname_method_manual'] == '1';

		if(Request::instance()->isPost()) {
			$act = input('act');
			$array = ['code' => '-1', 'msg' => ''];

			// 身份证上传接口：接收图片并自动提取姓名+身份证号（二要素）
			if($act == 'upload_idcard') {
				$method = input('method', 'idcard');
				$userId = session("userid");
				if ($this->user['realname_status'] == 1) {
					$array['msg'] = '您已完成实名认证';
					return json($array);
				}
				$file = request()->file('file');
				if (empty($file)) {
					$array['msg'] = '请选择身份证图片';
					return json($array);
				}
				// 保存图片
				$info = $file->validate(['size' => 5 * 1024 * 1024, 'ext' => 'jpg,jpeg,png,webp,bmp'])
					->move(PATH . 'public/static/upload/idcard');
				if (!$info) {
					$array['msg'] = '图片上传失败：' . $file->getError();
					return json($array);
				}
				$saveName = $info->getSaveName();
				$imgUrl = '/static/upload/idcard/' . str_replace('\\', '/', $saveName);

				// 自动提取身份证信息（二要素）
				try {
					$extracted = realname_extract_idcard($imgUrl);
				} catch (\Throwable $e) {
					$logDir = defined('LOG_PATH') ? LOG_PATH : (PATH . '/runtime/log/');
					if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
					@file_put_contents($logDir . 'realname_debug.log', date('Y-m-d H:i:s') . " upload_idcard OCR exception: " . $e->getMessage() . "\n", FILE_APPEND);
					$extracted = ['name' => '', 'idcard' => ''];
				}
				if (!empty($extracted['name']) && !empty($extracted['idcard'])) {
					try {
						ensure_realname_idcard_table();
						Db::name('realname_idcard')->insert([
							'userid' => $userId,
							'realname' => $extracted['name'],
							'idcard' => $extracted['idcard'],
							'idcard_front' => $imgUrl,
							'method' => $method,
							'status' => 0,
							'create_time' => time(),
						]);
					} catch (\Throwable $dbEx) {
						// 数据库插入失败不影响前端展示已识别的信息
						$logDir = defined('LOG_PATH') ? LOG_PATH : (PATH . '/runtime/log/');
						if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
						@file_put_contents($logDir . 'realname_debug.log', date('Y-m-d H:i:s') . " upload_idcard DB insert error: " . $dbEx->getMessage() . "\n", FILE_APPEND);
					}
					return json([
						'code' => '1',
						'msg' => '识别成功',
						'data' => [
							'realname' => $extracted['name'],
							'idcard' => $extracted['idcard'],
							'idcard_front' => $imgUrl,
						],
					]);
				}
				// 识别失败，仍返回图片URL让用户手动补充
				try {
					ensure_realname_idcard_table();
					Db::name('realname_idcard')->insert([
						'userid' => $userId,
						'realname' => '',
						'idcard' => '',
						'idcard_front' => $imgUrl,
						'method' => $method,
						'status' => 0,
						'create_time' => time(),
					]);
				} catch (\Throwable $dbEx) {
					$logDir = defined('LOG_PATH') ? LOG_PATH : (PATH . '/runtime/log/');
					if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
					@file_put_contents($logDir . 'realname_debug.log', date('Y-m-d H:i:s') . " upload_idcard DB insert error: " . $dbEx->getMessage() . "\n", FILE_APPEND);
				}
				$ocrError = isset($extracted['error']) ? $extracted['error'] : '';
				return json([
					'code' => '1',
					'msg' => $ocrError ? '识别未成功：' . $ocrError . '，请手动补充信息' : '图片已上传，请手动补充信息',
					'data' => ['realname' => '', 'idcard' => '', 'idcard_front' => $imgUrl, 'ocr_error' => $ocrError],
				]);
			}

			if($act == 'submit') {
				// 实名信息前端加密传输：开启时优先解密加密负载，防止抓包窃取身份证号等敏感信息
				$encryptEnabled = (isset($this->web['realname_encrypt']) ? $this->web['realname_encrypt'] : '1') == '1';
				$payload = trim(input('payload', ''));
				if ($encryptEnabled && $payload !== '') {
					$dec = function_exists('realname_decrypt_payload') ? realname_decrypt_payload($payload) : false;
					if ($dec === false || !is_array($dec)) {
						$array['msg'] = '安全校验失败，请刷新页面后重试';
						return json($array);
					}
					$name = trim(isset($dec['realname']) ? $dec['realname'] : '');
					$idcard = trim(isset($dec['idcard']) ? $dec['idcard'] : '');
					$mobile = trim(isset($dec['mobile']) ? $dec['mobile'] : '');
					$method = trim(isset($dec['method']) ? $dec['method'] : 'idcard');
				} else {
					$name = trim(input('realname', ''));
					$idcard = trim(input('idcard', ''));
					$mobile = trim(input('mobile', ''));
					$method = trim(input('method', 'idcard'));
				}
				$idcardFront = trim(input('idcard_front', ''));

			// 认证方式开关校验
					if ($method == 'idcard' && !$methodIdcard) { $array['msg'] = '身份证上传认证已关闭'; return json($array); }
					if ($method == 'phone' && !$methodPhone) { $array['msg'] = '手机三要素认证已关闭'; return json($array); }
					if ($method == 'manual' && !$methodManual) { $array['msg'] = '人工审核认证已关闭'; return json($array); }
					if (!in_array($method, ['idcard', 'phone', 'manual'])) {
						$array['msg'] = '无效的认证方式';
						return json($array);
					}

				if(empty($name) || empty($idcard)) {
					$array['msg'] = '姓名和身份证号不能为空';
					return json($array);
				}
				// 基本格式校验
				if(!preg_match('/^[\x{4e00}-\x{9fa5}·]{2,20}$/u', $name)) {
					$array['msg'] = '姓名格式错误';
					return json($array);
				}
				if(!preg_match('/^\d{17}[\dXx]$/', $idcard)) {
					$array['msg'] = '身份证号格式错误';
					return json($array);
				}
				// 手机三要素需要手机号
				if ($method == 'phone' && empty($mobile)) {
					$array['msg'] = '手机号不能为空';
					return json($array);
				}
				if ($method == 'phone' && !preg_match('/^1[3-9]\d{9}$/', $mobile)) {
					$array['msg'] = '手机号格式错误';
					return json($array);
				}

				// 已通过认证的不能重复认证
				if ($this->user['realname_status'] == 1) {
					$array['msg'] = '您已完成实名认证，无需重复认证';
					return json($array);
				}
				// 待审核中的不能重复提交
				if ($this->user['realname_status'] == 3) {
					$array['msg'] = '您的实名认证申请正在审核中，请耐心等待';
					return json($array);
				}

				$userId = session("userid");
				$attempts = intval(isset($this->user['realname_attempts']) ? $this->user['realname_attempts'] : 0);
				$firstFree = (isset($this->web['realname_first_free']) ? $this->web['realname_first_free'] : '1') == '1';
				$chargeAmount = floatval(isset($this->web['realname_charge_amount']) ? $this->web['realname_charge_amount'] : 0);

				// ========== 人工审核方式（不调用API，不扣费） ==========
				if ($method == 'manual') {
					// 人工审核必须上传身份证照片，供管理员核验
					if (empty($idcardFront)) {
						$array['msg'] = '人工审核需上传身份证照片';
						return json($array);
					}
					try {
						ensure_realname_idcard_table();
						Db::name('user')->where('id', $userId)->update([
							'realname' => $name,
							'idcard' => $idcard,
							'realname_status' => 3,
						]);
						$this->user['realname'] = $name;
						$this->user['idcard'] = $idcard;
						$this->user['realname_status'] = 3;
						ensure_realname_record_table();
						Db::name('realname_record')->insert([
							'user_id' => $userId,
							'realname' => $name,
							'idcard' => $idcard,
							'status' => 3,
							'apply_time' => time(),
						]);
						Db::name('realname_idcard')->insert([
							'userid' => $userId,
							'realname' => $name,
							'idcard' => $idcard,
							'mobile' => $mobile,
							'idcard_front' => $idcardFront,
							'method' => 'manual',
							'status' => 0,
							'create_time' => time(),
						]);
						// 发送邮件通知管理员
						if($this->web["email"]=="1" && !empty($this->web['emailname'])){
							$auditToken = md5($userId . $name . 'email_audit_salt_2024');
							$siteUrl = request()->domain() . request()->root();
							$approveUrl = $siteUrl . '/admin/emailAudit?token=' . $auditToken . '&action=approve&id=' . $userId;
							$rejectUrl = $siteUrl . '/admin/emailAudit?token=' . $auditToken . '&action=reject&id=' . $userId;
							try {
								self::email($this->web['emailname'], "新的实名认证待审核", '<p>用户 <b>'.htmlspecialchars($this->user['name'] ?? $this->user['user']).'</b>（ID:'.$userId.'）提交了实名认证申请。</p><p>认证姓名：'.htmlspecialchars($name).'</p><p>认证方式：人工审核</p><p><a href="'.$approveUrl.'">通过</a> | <a href="'.$rejectUrl.'">驳回</a></p>');
							} catch (\Exception $mailEx) {}
						}
						$array['code'] = '1';
						$array['msg'] = '已提交实名认证申请，请等待管理员审核';
						return json($array);
					} catch (\Throwable $e) {
						$logDir = defined('LOG_PATH') ? LOG_PATH : (PATH . '/runtime/log/');
						if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
						@file_put_contents($logDir . 'realname_debug.log', date('Y-m-d H:i:s') . " manual review error: " . $e->getMessage() . "\n", FILE_APPEND);
						$array['msg'] = '提交失败，请稍后重试（错误：' . $e->getMessage() . '）';
						return json($array);
					}
				}

				// ========== 自动核验方式（身份证二要素 / 手机三要素） ==========
				// 判断是否需要收费
				$needCharge = !($firstFree && $attempts == 0);
				if ($needCharge && $chargeAmount > 0) {
					if (floatval($this->user['money']) < $chargeAmount) {
						$array['msg'] = '实名认证需要' . $chargeAmount . '元，您的余额不足，请先充值';
						return json($array);
					}
				}

				try {
					// 先发起核验：若结果带 config_error 标记（密钥错误、接口地址不匹配、
					// 服务商系统异常、网络不可达等），说明是接口配置问题，不计费也不计次
					$apiUrl = isset($this->web['realname_api_url']) ? trim((string) $this->web['realname_api_url']) : '';
					if ($method == 'phone') {
						$secretId = isset($this->web['realname_secret_id']) ? $this->web['realname_secret_id'] : '';
						$secretKey = isset($this->web['realname_secret_key']) ? $this->web['realname_secret_key'] : '';
						$result = phone3element_verify($name, $idcard, $mobile, $secretId, $secretKey, $apiUrl);
					} else {
						$result = realname_api_verify($name, $idcard, $mobile);
					}

					if (!is_array($result) || !isset($result['code'])) {
						$array['msg'] = '实名认证返回数据异常';
						return json($array);
					}

					// 配置/网络类错误：不计费、不计次数，直接把可操作的原因返回给用户
					if (!empty($result['config_error'])) {
						$array['msg'] = $result['msg'] . '（本次属接口配置或网络问题，未消耗认证机会、未扣费）';
						return json($array);
					}

					// 正常完成一次核验调用后才扣费 / 计次
					if ($needCharge && $chargeAmount > 0) {
						Db::name('user')->where('id', $userId)->setDec('money', $chargeAmount);
						Db::name('transaction')->insert([
							'userid' => $userId,
							'content' => '实名认证费用，扣除' . $chargeAmount . '元',
							'time' => time(),
						]);
					}
					Db::name('user')->where('id', $userId)->setInc('realname_attempts');
					$attempts++;

					$status = ($result['code'] == 1) ? 1 : 2;

					ensure_realname_record_table();
					Db::name('realname_record')->insert([
						'user_id' => $userId,
						'realname' => $name,
						'idcard' => $idcard,
						'status' => $status,
						'apply_time' => time(),
						'review_time' => ($status == 1) ? time() : 0,
					]);

					ensure_realname_idcard_table();
					Db::name('realname_idcard')->insert([
						'userid' => $userId,
						'realname' => $name,
						'idcard' => $idcard,
						'mobile' => $mobile,
						'idcard_front' => $idcardFront,
						'method' => $method,
						'status' => ($status == 1) ? 1 : 2,
						'create_time' => time(),
						'review_time' => ($status == 1) ? time() : 0,
					]);

					if($result['code'] == 1) {
						Db::name('user')->where('id', $userId)->update([
							'realname' => $name,
							'idcard' => $idcard,
							'realname_status' => 1,
						]);
						$this->user['realname'] = $name;
						$this->user['idcard'] = $idcard;
						$this->user['realname_status'] = 1;
						if($this->web["email"]=="1" && !empty($this->user["mail"])){
							try {
								self::email($this->user["mail"], "实名认证通过通知", '<p>您好 '.htmlspecialchars($name).'，</p><p>恭喜！您的实名认证已审核通过。</p><p style="color:#64748b;font-size:13px;">认证姓名：'.htmlspecialchars($name).'<br>认证时间：'.date("Y-m-d H:i:s").'</p>');
							} catch (\Exception $mailEx) {}
						}
					}

					if ($result['code'] != 1) {
						$costInfo = $needCharge && $chargeAmount > 0
							? '（已扣除' . $chargeAmount . '元）'
							: '（已消耗认证机会）';
						$remainingFree = max(0, ($firstFree ? 1 : 0) - $attempts);
						if ($firstFree && $remainingFree > 0) {
							$result['msg'] .= $costInfo . '，还剩余 ' . $remainingFree . ' 次免费认证机会';
						} else {
							$result['msg'] .= $costInfo . '，已累计认证 ' . $attempts . ' 次';
						}
					}

					return json($result);
				} catch (\Throwable $e) {
					$logDir = defined('LOG_PATH') ? LOG_PATH : (PATH . '/runtime/log/');
					if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
					@file_put_contents($logDir . 'realname_debug.log', date('Y-m-d H:i:s') . " User.php realname exception: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n", FILE_APPEND);
					$array['msg'] = '实名认证处理异常：' . $e->getMessage();
					return json($array);
				}
			}

			return json($array);
		}

		return $this->fetch('/'.$this->web["template"].'/user/realname', [
			'realnameMode' => $realnameMode,
			'apiType' => isset($this->web['realname_api_type']) ? $this->web['realname_api_type'] : '1',
			'user' => $this->user,
			'methodIdcard' => $methodIdcard ? '1' : '0',
			'methodPhone' => $methodPhone ? '1' : '0',
			'methodManual' => $methodManual ? '1' : '0',
			'realnameEncrypt' => (isset($this->web['realname_encrypt']) ? $this->web['realname_encrypt'] : '1') == '1' ? '1' : '0',
			'cipherKey' => (function_exists('realname_cipher_key') && (isset($this->web['realname_encrypt']) ? $this->web['realname_encrypt'] : '1') == '1') ? realname_cipher_key() : '',
		]);
	}


	public function pay() {
	if($this->user['ban_time'] > time()){
		exit("<title>账号已封禁</title>你的账号已被封禁至 ".date("Y-m-d H:i:s",$this->user['ban_time'])."，封禁期间无法充值。<br>原因：".($this->user['ban_reason'] ?: '无'));
	}
	$check = check_realname_limit('pay');
	if($check['code'] != 1){
		$data=Db::name("pays")->where("state","1")->select();
		for($i=0;$i<count($data);$i++)
		{
			unset($data[$i]["plugins"]);
			unset($data[$i]["data"]);
		}
		return $this->fetch('/'.$this->web["template"].'/user/pay',[
			"pay"=>$data,
			"paycron"=>$this->web["paycron"],
			"cartAmount"=>session("cart_order_total") ?: null,
			"realnameRequired"=>true,
			"realnameMsg"=>$check['msg'],
			"realnameUrl"=>'/user/realname',
		]);
	}
	if(Request::instance()->isPost()) {
if(is_numeric(input("money"))){
if(!input("money")){
exit("<title>出错啦!</title>金额不可为空或为0!");
}else{
if(input("money")<"0.01"){
exit("<title>出错啦!</title>金额不可少于0.01!");
}else{
if(getLen(input("money"))>2){
exit("<title>出错啦!</title>金额的小数点后不能超过两位!");
}else{
$data1=Db::name('pays')->where([
'id'=>input("payid"),
"state"=>"1",
])->find();
if($data1){
@include PATH."plugins/pay/".$data1["plugins"]."/go.php";
}else{
exit("<title>出错啦!</title>支付方式不存在!");
}
}}
}
}else{
exit("<title>出错啦!</title>金额必须是数学!");
}
}
$data=Db::name("pays")->where("state","1")->select();
for($i=0;$i<count($data);$i++)  
   {
unset($data[$i]["plugins"]);
unset($data[$i]["data"]);
}
		return $this->fetch('/'.$this->web["template"].'/user/pay',[
"pay"=>$data,
"paycron"=>$this->web["paycron"],
"cartAmount"=>session("cart_order_total") ?: null,
]);
}

	public function return($id) {
$data1=Db::name('pays')->where('id',$id)->find();
if(!$data1){
exit("<title>出错啦!</title>没有此支付通道!");
}
	@include PATH."plugins/pay/".$data1["plugins"]."/return.php";

// Docker 在线支付：充值到账后自动扣款并开通（同步返回时立即结算）
if(function_exists('docker_settle_pending')){
	try { docker_settle_pending(session("userid")); } catch (\Throwable $e) {}
}

// 检查是否有待结算的购物车订单
if(session("cart_order_ids")){
	$this->redirect('/user/cartSettle');
	return;
}

return $this->fetch('/'.$this->web["template"].'/user/return',[
"msg"=>$msg,
]);
}

	public function order($id=null) {
if($id){

$data=Db::name('order')->where([
'id'=>$id,
"userid"=>session("userid"),
])->find();
if($data){
$a=Db::name('cart')->where('id',$data["cartid"])->find();
$b=Db::name('server')->where('id',$a["serverid"])->find();
if(!$b) $b = [];
if(Request::instance()->isPost()) {
$act=input("act");

$hasPlugin = ($b && !empty($b["serverplugins"]));
if($hasPlugin){
	@include PATH."plugins/host/".$b["serverplugins"]."/ClientArea.php";
}

if($act=="renew"){
$check = check_realname_limit('renew');
if($check['code'] != 1){
	$array["code"]=(string)$check['code'];
	$array["msg"]=$check['msg'];
	return json($array);
}
$time=input("time");
if($time==""){
	$array["code"]="-1";
	$array["msg"]="必填参数不可为空!";
}else{
if(!is_numeric($time)){
$array["code"]="-1";
$array["msg"]="参数有误,只能填写数字!";
}else{
if(floor($time)!=$time){
	$array["code"]="-1";
	$array["msg"]="只能填写整数!";
}else{
if($time<1){
	$array["code"]="-1";
	$array["msg"]="续费时间不能小于1!";
}else{
if($a["renew"]=="1"){
$array["code"]="-1";
$array["msg"]="该产品已设置禁止续费!";
}else{
$db=Db::name('user')->where('id',session("userid"))->find();
$discount = function_exists('get_membership_discount') ? get_membership_discount(session('userid'), 'renew') : 1.00;
// 续费同样按「各周期自定义价格」计算，未配置则回退 单价×时长
$renewCycleMap = function_exists('cart_cycle_prices') ? cart_cycle_prices($a) : [];
// 完全免费产品：基础单价为 0 且没有任何周期专属价
$isFreeCart = (floatval($a["money"])<=0 && empty($renewCycleMap));
$renewBase = function_exists('cart_cycle_price')
	? cart_cycle_price($a, $time)
	: round(floatval($a['money']) * intval($time), 2);
$money = round($renewBase * $discount, 2);
if($a["cycle"]=="unrestricted"){
$array["code"]="-1";
$array["msg"]="一次性付款产品,禁止续费!";
}else if(floatval($a["money"])<=0 && !$isFreeCart && !isset($renewCycleMap[intval($time)])){
$array["code"]="-1";
$array["msg"]="该续费时长暂未配置价格，请重新选择续费时长!";
}else if(floatval($a["money"])<=0 && floatval($money)<=0){
$array["code"]="-1";
$array["msg"]="该产品按周期单独定价，当前时长不可续费，请联系客服!";
}else{
if($db["money"]>=$money){
$times=$data["ztime"]-time();
if($isFreeCart &&  $times > 432000){
$array["code"]="-1";
$array["msg"]="免费产品只能在到期前的5天内续费!";
}else{
if($isFreeCart &&  $time!="1"){
$array["code"]="-1";
$array["msg"]="免费产品续费时间只能填写1";
}else{
if($data["state"]=="3"){
//判断产品为终止状态
$array["code"]="-1";
$array["msg"]="该产品已终止,禁止续费!";
}else{
include_once PATH."plugins/host/".$b["serverplugins"]."/".$b["serverplugins"].".php";
			if($data["state"]=="2"){
			//判断产品为暂停状态 加一个修改主机状态
			if($hasPlugin){
				$function2=$b["serverplugins"]."_"."UnsuspendAccount";
				if(function_exists($function2)){
					$data1=@$function2($b,$data,$a);
				}
			}
			$data2=Db::name('order')->where([
			'id'=>$id,
			"userid"=>session("userid"),
			])->update([
			"state"=>"1",
			]);
			}
			if($a["cycle"]=="month"){
			$times="2592000"*$time;
			}
			if($a["cycle"]=="season"){
			$times="7879680"*$time;
			}
			if($a["cycle"]=="year"){
			$times="31536000"*$time;
			}
			if($a["cycle"]=="day"){
			$times="86400"*$time;
			}
			if($a["cycle"]=="unrestricted"){
			$times="315360000"*$time;
			}
			if($hasPlugin){
				$function1=$b["serverplugins"]."_"."renew";
				if(function_exists($function1)){
					$data11=$function1($b,$data,$a,$times,$time);
				}else{
					$data11=["code"=>"1","msg"=>"续费成功"];
				}
			}else{
				$data11=["code"=>"1","msg"=>"续费成功"];
			}
			if($data11["code"]=="1"){
$money1=round($db["money"]-$money,2);
Db::name('user')->where('id',session("userid"))->update([
	'money' => $money1,
	'total_recharge' => round(floatval($db['total_recharge'] ?? 0) + $money, 2)
]);
if (function_exists('update_user_membership')) {
	update_user_membership(session('userid'));
}
$data1=Db::name('order')->where([
'id'=>$id,
"userid"=>session("userid"),
])->update([
"ztime"=>$data["ztime"]+$times,
// 续费金额累计进「已支付金额」，供退款时按剩余比例计算
"paid_amount"=>round(floatval($data["paid_amount"] ?? 0)+$money,2),
]);
//消费记录
$data66=Db::name('transaction')->insertGetId([
"userid"=>session("userid"),
"content"=>"续费产品,ID:".$id.",时长:".$time.",消费:".$money,
"time"=>time(),
]);

//aff收益
if($db["upperid"]){
if($money!="0"){
$upper=round($money*floatval($this->web["affdiscount"]),2);
$upperuser=Db::name('user')->where('id',$db["upperid"])->find();
$uppermoney=Db::name('user')->where('id',$db["upperid"])->update([
'affmoney' =>round($upperuser["affmoney"]+$upper,2),
]);
$data5=Db::name('affsymoney')->insertGetId([
"information"=>"下级ID:".session("userid")."续费产品",
"money"=>$upper,
"userid"=>$db["upperid"],
"time"=>time(),
]);
}
}

	$array["code"]="1";
	$array["msg"]="续费成功";	
}else{
	$array["code"]="-1";
	$array["msg"]="续费失败!".$data11["msg"];	

}

}

}

}

} else {
	$array["code"]="-1";
	$array["msg"]="余额不足";	
}
}
}
}
}
}
}
return json($array);
}

if($act=="upgrade"){
$db=Db::name('user')->where('id',session("userid"))->find();
$newcartid=input("newcartid");
if($newcartid){
if($data["state"]=="3"){
	$array["code"]="-1";
	$array["msg"]="产品是终止状态,禁止变更!";	
}else{
include_once PATH."plugins/host/".$b["serverplugins"]."/".$b["serverplugins"].".php";
			$function1=$b["serverplugins"]."_"."upgrade";
			if(!$hasPlugin){
				$array["code"]="-1";
				$array["msg"]="当前产品未配置服务器插件，无法升降级!";
				return json($array);
			}
			if($a["upgrade"]!="1" || judge(json_decode($a["upgrades"],true),$newcartid)!="1" || !function_exists($function1)){
$array["code"]="-1";
$array["msg"]="变更三要素检测不通过,禁止变更!";
}else{
if($data["cartid"]==$newcartid){
	$array["code"]="-1";
	$array["msg"]="产品一样,无需变更!";	
}else{
$db12=Db::name('cart')->where('id',$newcartid)->find();
if($a["serverid"]!=$db12["serverid"]){
	$array["code"]="-1";
	$array["msg"]="不可升级!";	
}else{
if($db12["inventory"] < 1){
$array["code"]="-1";
$array["msg"]="变更失败,变更的产品库存不足!";
}else{
if($db12){
if($a["cycle"]=="month"){
$timess="2592000";
}
if($a["cycle"]=="season"){
$timess="7879680";
}
if($a["cycle"]=="year"){
$timess="31536000";
}
if($a["cycle"]=="day"){
$timess="86400";
}
if($a["cycle"]=="unrestricted"){
$timess="315360000";
}
if($db12["cycle"]=="month"){
$times="2592000";
}
if($db12["cycle"]=="season"){
$times="7879680";
}
if($db12["cycle"]=="year"){
$times="31536000";
}
if($db12["cycle"]=="day"){
$times="86400";
}
if($db12["cycle"]=="unrestricted"){
$times="315360000";
}
$money12=(($db12["money"]/($times/86400))*(($data["ztime"]-time())/86400));
$money12=$money12-(($a["money"]/($timess/86400))*(($data["ztime"]- time())/86400));
$money12=round($money12,2);
if($money12<0){
$money12="0";
}
if($this->user["money"]<$money12){
	$array["code"]="-1";
	$array["msg"]="余额不足,需要:".$money12."元";	
}else{
include_once PATH."plugins/host/".$b["serverplugins"]."/".$b["serverplugins"].".php";
$function=$b["serverplugins"]."_"."upgrade";
if(function_exists($function)){
$data1=@$function($b,$data,$a,$db12);
}
if($data1["code"]=="1"){
$db13=Db::name('user')->where('id',session("userid"))->update([
'money' =>round($this->user["money"]-$money12,2),
]);
$db14=Db::name('order')->where('id',$data["id"])->update([
'cartid' =>$db12["id"],
]);
$db15=Db::name('cart')->where('id',$a["id"])->update([
'inventory' =>$a["inventory"]+1,
]);
$db16=Db::name('cart')->where('id',$db12["id"])->update([
'inventory' =>$db12["inventory"]-1,
]);

//消费记录
if($money12!="0"){
$data66=Db::name('transaction')->insertGetId([
"userid"=>session("userid"),
"content"=>"升级产品,ID:".$id.",消费:".$money12,
"time"=>time(),
]);
}

//aff收益
if($db["upperid"]){
if($money12!="0"){
$upper=round($money12*floatval($this->web["affdiscount"]),2);
$upperuser=Db::name('user')->where('id',$db["upperid"])->find();
$uppermoney=Db::name('user')->where('id',$db["upperid"])->update([
'affmoney' =>round($upperuser["affmoney"]+$upper,2),
]);
$data5=Db::name('affsymoney')->insertGetId([
"information"=>"下级ID:".session("userid")."升级产品",
"money"=>$upper,
"userid"=>$db["upperid"],
"time"=>time(),
]);
}
}
	$array["code"]="1";
	$array["msg"]="操作成功!";	
}else{
	$array["code"]="-1";
	$array["msg"]=$data1["msg"];	
}
}
}else{
	$array["code"]="-1";
	$array["msg"]="产品不存在!";	
}
}
}
}
}
}
}else{
	$array["code"]="-1";
	$array["msg"]="请选择产品!";	
}
			return json($array);
			}

			if($act=="reset"){
				$array=["code"=>"-1","msg"=>"重置失败"];
				if(!$hasPlugin){
					// 无插件：生成本地密码
					$newpass = random(10);
					Db::name('order')->where(['id'=>$id,"userid"=>session("userid")])->update(["password"=>$newpass]);
					$array["code"]="1";
					$array["msg"]="密码已重置";
					return json($array);
				}
				include_once PATH."plugins/host/".$b["serverplugins"]."/".$b["serverplugins"].".php";
				$function=$b["serverplugins"]."_"."ChangePassword";
				if(!function_exists($function)){
					$newpass = random(10);
					Db::name('order')->where(['id'=>$id,"userid"=>session("userid")])->update(["password"=>$newpass]);
					$array["code"]="1";
					$array["msg"]="密码已重置";
					return json($array);
				}
				$newpass = random(10);
				$result = @$function($b,$data,$newpass);
				if(is_array($result) && isset($result['code']) && $result['code']=="1"){
					Db::name('order')->where(['id'=>$id,"userid"=>session("userid")])->update(["password"=>$newpass]);
					$array["code"]="1";
					$array["msg"]="密码已重置";
				}else{
					$array["msg"]="重置失败: ".($result['msg'] ?? '接口异常');
				}
				return json($array);
			}

			if($act=="suspend"){
				$array=["code"=>"-1","msg"=>"停用失败"];
				if($data["state"]!="1"){
					$array["msg"]="当前状态不可停用";
					return json($array);
				}
				$suspendOk=true;
				if($hasPlugin){
					include_once PATH."plugins/host/".$b["serverplugins"]."/".$b["serverplugins"].".php";
					$function=$b["serverplugins"]."_"."SuspendAccount";
					if(function_exists($function)){
						$result=@$function($b,$data,$a);
						if(!is_array($result) || !isset($result['code']) || $result['code']!="1"){
							$suspendOk=false;
							$array["msg"]="停用失败: ".($result['msg'] ?? '接口异常');
						}
					}
				}
				if($suspendOk){
					Db::name('order')->where(['id'=>$id,"userid"=>session("userid")])->update(["state"=>"2"]);
					$array["code"]="1";
					$array["msg"]="主机已停用";
				}
				return json($array);
			}

			if($act=="unsuspend"){
				$array=["code"=>"-1","msg"=>"恢复失败"];
				if($data["state"]!="2"){
					$array["msg"]="当前状态不可恢复";
					return json($array);
				}
				$unsuspendOk=true;
				if($hasPlugin){
					include_once PATH."plugins/host/".$b["serverplugins"]."/".$b["serverplugins"].".php";
					$function=$b["serverplugins"]."_"."UnsuspendAccount";
					if(function_exists($function)){
						$result=@$function($b,$data,$a);
						if(!is_array($result) || !isset($result['code']) || $result['code']!="1"){
							$unsuspendOk=false;
							$array["msg"]="恢复失败: ".($result['msg'] ?? '接口异常');
						}
					}
				}
				if($unsuspendOk){
					Db::name('order')->where(['id'=>$id,"userid"=>session("userid")])->update(["state"=>"1"]);
					$array["code"]="1";
					$array["msg"]="主机已恢复";
				}
				return json($array);
			}

			// ===== 主机退款：第一步，给用户绑定邮箱发验证码 =====
			if($act=="refund_code"){
				$array=["code"=>"-1","msg"=>"发送失败"];
				if(isset($this->web['refund_enabled']) && $this->web['refund_enabled']=="0"){
					$array["msg"]="本站未开启自助退款，请提交工单联系客服";
					return json($array);
				}
				if(!in_array((string)$data["state"], ["1","2"])){
					$array["msg"]="该主机当前状态不可退款";
					return json($array);
				}
				if(floatval($data['refund_amount'] ?? 0) > 0){
					$array["msg"]="该订单已退款，不可重复申请";
					return json($array);
				}
				if(empty($this->web["email"]) || $this->web["email"] != "1"){
					$array["msg"]="本站暂未开启邮件服务，无法完成邮箱验证，请联系客服处理";
					return json($array);
				}
				$me = Db::name('user')->where('id', session('userid'))->find();
				if(empty($me['mail'])){
					$array["msg"]="请先在「个人资料」中绑定邮箱后再申请退款";
					return json($array);
				}
				$lastSend = session('refund_email_time');
				if($lastSend && (time() - intval($lastSend)) < 60){
					$array["msg"]="验证码刚刚已发送，请 60 秒后再试";
					return json($array);
				}
				$code = random(6, '0123456789');
				session('refund_email_code', $code);
				session('refund_email_time', time());
				session('refund_email_order', $id);
				$codeBody = "<p>您好，</p><p>您正在 ".$this->web["name"]." 申请主机退款，请使用以下验证码完成身份验证：</p>"
					."<p style='text-align:center;margin:28px 0;'><span style='display:inline-block;background:#eff6ff;color:#2563eb;font-size:28px;font-weight:700;padding:14px 32px;border-radius:10px;letter-spacing:4px;border:1px solid #bfdbfe;'>".$code."</span></p>"
					."<p style='color:#64748b;font-size:13px;'>验证码 10 分钟内有效。如非本人操作，请忽略此邮件并尽快修改账号密码。</p>";
				try {
					$this->email($me['mail'], "主机退款验证码", $codeBody);
				} catch (\Throwable $mailEx) {}
				$array["code"]="1";
				$array["msg"]="验证码已发送至 ".mask_email_addr($me['mail'])."，10 分钟内有效";
				return json($array);
			}

			// ===== 主机退款：第二步，校验验证码并执行退款 =====
			if($act=="refund"){
				$array=["code"=>"-1","msg"=>"退款失败"];
				if(isset($this->web['refund_enabled']) && $this->web['refund_enabled']=="0"){
					$array["msg"]="本站未开启自助退款，请提交工单联系客服";
					return json($array);
				}
				if(!in_array((string)$data["state"], ["1","2"])){
					$array["msg"]="该主机当前状态不可退款";
					return json($array);
				}
				if(floatval($data['refund_amount'] ?? 0) > 0){
					$array["msg"]="该订单已退款，不可重复申请";
					return json($array);
				}
				// 邮箱验证码校验
				$emailCode = trim((string)input("email_code"));
				$savedCode  = session('refund_email_code');
				$savedTime  = intval(session('refund_email_time'));
				$savedOrder = intval(session('refund_email_order'));
				if(empty($savedCode) || $emailCode === '' || $savedCode != $emailCode || $savedOrder != intval($id)){
					$array["msg"]="邮箱验证码错误，请重新获取";
					return json($array);
				}
				if(time() - $savedTime > 600){
					$array["msg"]="验证码已过期，请重新获取";
					return json($array);
				}

				$now   = time();
				// 统一用公共函数计算，保证与前台预览金额完全一致
				$refundInfo = function_exists('calc_order_refund')
					? calc_order_refund($data, $this->web)
					: ['can'=>false,'amount'=>0,'paid'=>0,'ratio'=>0,'msg'=>'退款模块未就绪'];
				if(empty($refundInfo['can'])){
					$array["msg"] = $refundInfo['msg'] !== '' ? $refundInfo['msg'] : '当前不可退款';
					return json($array);
				}
				$paid       = floatval($refundInfo['paid']);
				$refund     = floatval($refundInfo['amount']);
				$feePercent = max(0, min(100, floatval($this->web['refund_fee_percent'] ?? 0)));

				// 1) 终止主机（插件异常也继续退款，只在流水里留痕）
				$terminateOk = true;
				$terminateMsg = '';
				if($hasPlugin){
					include_once PATH."plugins/host/".$b["serverplugins"]."/".$b["serverplugins"].".php";
					$funcTerm = $b["serverplugins"]."_"."TerminateAccount";
					if(function_exists($funcTerm)){
						$termRes = @$funcTerm($b, $data, $a);
						if(!is_array($termRes) || !isset($termRes['code']) || $termRes['code'] != "1"){
							$terminateOk = false;
							$terminateMsg = isset($termRes['msg']) ? $termRes['msg'] : '接口异常';
						}
					}
				}

				// 2) 退款到账户余额
				$meRow = Db::name('user')->where('id', session('userid'))->find();
				$newMoney = round(floatval($meRow['money']) + $refund, 2);
				Db::name('user')->where('id', session('userid'))->update(['money' => $newMoney]);
				if (function_exists('update_user_membership')) {
					update_user_membership(session('userid'));
				}

				// 3) 订单标记为已退款（state=4）
				Db::name('order')->where(['id'=>$id, 'userid'=>session('userid')])->update([
					"state"         => "4",
					"refund_amount" => $refund,
					"refund_at"     => $now,
				]);

				// 4) 库存回滚
				if($a && isset($a['id'])){
					Db::name('cart')->where('id', $a['id'])->update(["inventory" => intval($a['inventory']) + 1]);
				}

				// 5) 资金流水
				Db::name('transaction')->insertGetId([
					"userid"  => session("userid"),
					"content" => "主机退款，订单ID:".$id."(套餐ID:".($a ? $a['id'] : 0).")，已支付:".$paid."元，退款:".$refund."元"
						.($feePercent > 0 ? "（已扣手续费".$feePercent."%）" : "")
						.($terminateOk ? "" : "；注意：主机终止接口返回异常：".$terminateMsg),
					"time"    => $now,
				]);

				// 6) 清理验证码
				session('refund_email_code', null);
				session('refund_email_time', null);
				session('refund_email_order', null);

				// 7) 邮件通知
				if($meRow && !empty($meRow['mail'])){
					try {
						$this->email($meRow['mail'], "主机退款成功", "<p>您好，</p><p>您申请的主机退款已处理完成。</p>"
							."<p>订单编号：".(!empty($data['ordernumber']) ? $data['ordernumber'] : $id)."</p>"
							."<p>退款金额：<b>¥".$refund."</b>（已退回账户余额）</p>"
							."<p>当前余额：¥".$newMoney."</p>");
					} catch (\Throwable $mailEx) {}
				}

				$array["code"]    = "1";
				$array["msg"]     = "退款成功，¥".$refund." 已退回账户余额";
				$array["refund"]  = $refund;
				$array["balance"] = $newMoney;
				return json($array);
			}

			if($act=="delete"){
				$array=["code"=>"-1","msg"=>"删除失败"];
				if($data["state"]=="1" || $data["state"]=="2"){
					if($hasPlugin){
						include_once PATH."plugins/host/".$b["serverplugins"]."/".$b["serverplugins"].".php";
						$function=$b["serverplugins"]."_"."TerminateAccount";
						if(function_exists($function)){
							@$function($b,$data,$a);
						}
					}
				}
				Db::name('order')->where(['id'=>$id,"userid"=>session("userid")])->delete();
				Db::name('cart')->where('id',$a['id'])->update(["inventory"=>intval($a['inventory'])+1]);
				$array["code"]="1";
				$array["msg"]="删除成功";
				return json($array);
			}

			// ===== Docker 容器开通 =====
			if($act=="docker_pay"){
				$array=["code"=>"-1","msg"=>"开通失败"];
				ensure_docker_order_table();
				if(!docker_is_enabled($this->web, $a, $b)){
					$array["msg"]="该产品未开放 Docker 容器开通，如需开通请联系客服";
					return json($array);
				}
				if(!in_array((string)$data["state"], ["1","2"])){
					$array["msg"]="主机当前状态不可开通 Docker，请先恢复主机";
					return json($array);
				}
				$lastRow = docker_latest_for_host($id, session("userid"));
				if($lastRow && (string)$lastRow["state"]==="2"){
					$array["msg"]="该主机已开通 Docker 容器，无需重复开通";
					return json($array);
				}
				if($lastRow && (string)$lastRow["state"]==="1"){
					$array["msg"]="您的开通申请正在处理中，请稍后再查看";
					return json($array);
				}
				if($lastRow && (string)$lastRow["state"]==="0"){
					$array["msg"]="您有一笔 Docker 开通订单尚未支付，请先完成支付（如已支付请稍候或刷新页面）";
					return json($array);
				}

			$price  = docker_price($this->web);
			$mode   = docker_mode($this->web);
			$now    = time();

			// 已付过费（或本身免费）的「重新开通」：免二次扣费，复用旧记录，不再生成新订单
			$canReopenFree = false;
			$reopenRowId   = 0;
			if($lastRow && in_array((string)$lastRow["state"], ["3","4","5"], true)){
				if(floatval($lastRow["paid"]) > 0 || $price <= 0){
					$canReopenFree = true;
					$reopenRowId   = intval($lastRow["id"]);
				}
			}
			if($canReopenFree){
				Db::name('docker_order')->where('id', $reopenRowId)->update([
					"state"       => "1",
					"payway"      => $price > 0 ? "balance" : "free",
					"mode"        => $mode,
					"fail_reason" => "",
					"utime"       => $now,
				]);
				if($mode=="auto"){
					$rowToOpen = Db::name('docker_order')->where('id',$reopenRowId)->find();
					$r = docker_provision($rowToOpen);
					$openedOk = (!empty($r["code"]) && (string)$r["code"]==="1");
					$array["code"] = $openedOk ? "1" : "-1";
					$array["msg"]  = $r["msg"] . "（重新开通，免二次扣费）";
					$array["state"]= $openedOk ? "2" : "4";
				}else{
					$array["code"]="1";
					$array["msg"] ="重新开通申请已提交，管理员会尽快为您开通（免二次扣费）";
					$array["state"]="1";
				}
				return json($array);
			}

			$payway = (input("payway") == "online") ? "online" : "balance";
			$ways   = docker_pay_ways($this->web);
			if(!in_array($payway, $ways)){
				$array["msg"]="本站未开启该支付方式，请选择其它支付方式";
				return json($array);
			}
			$dbUser = Db::name('user')->where('id', session("userid"))->find();

				// —— 余额支付（含 0 元免费）——
				if($payway=="balance"){
					if($price > 0 && floatval($dbUser["money"]) < $price){
						$array["msg"]="余额不足，需支付 ¥".$price."，当前余额 ¥".$dbUser["money"]."，请先充值";
						$array["need_recharge"]=1;
						return json($array);
					}
					$dockerId = Db::name('docker_order')->insertGetId([
						"userid"=>session("userid"),
						"orderid"=>$id,
						"cartid"=>intval($a["id"]),
						"serverid"=>intval($b && isset($b["id"]) ? $b["id"] : 0),
						"username"=>$data["user"],
						"price"=>$price,
						"paid"=>$price,
						"payway"=>$price > 0 ? "balance" : "free",
						"mode"=>$mode,
						"state"=>"1",
						"atime"=>$now,
						"utime"=>$now,
					]);
					if($price > 0){
						Db::name('user')->where('id', session("userid"))->update([
							"money"=>round(floatval($dbUser["money"]) - $price, 2),
						]);
						Db::name('transaction')->insert([
							"userid"=>session("userid"),
							"content"=>"Docker 容器开通费，扣除".$price."元（主机订单ID:".$id."）",
							"time"=>$now,
						]);
					}
					if($mode=="auto"){
						$rowToOpen = Db::name('docker_order')->where('id',$dockerId)->find();
						$r = docker_provision($rowToOpen);
						$openedOk = (!empty($r["code"]) && (string)$r["code"]==="1");
						$array["code"] = $openedOk ? "1" : "-1";
						$array["msg"]  = $r["msg"] . ($price > 0 ? "（已扣除 ¥".$price."）" : "");
						$array["state"]= $openedOk ? "2" : "4";
					}else{
						$array["code"]="1";
						$array["msg"] ="开通申请已提交，管理员会尽快为您开通".($price > 0 ? "（已扣除 ¥".$price."）" : "");
						$array["state"]="1";
					}
					return json($array);
				}

				// —— 在线支付：复用站点「充值 → 结算」链路，生成充值单，到账后自动开通 ——
				if(!function_exists('curl_init')){
					$array["msg"]="服务器未启用 cURL 扩展，无法发起在线支付";
					return json($array);
				}
				if($price <= 0){
					$array["msg"]="该开通无需支付，请选择余额支付";
					return json($array);
				}
				$payid   = intval(input("payid"));
				$payInfo = Db::name('pays')->where(['id'=>$payid,'state'=>'1'])->find();
				if(!$payInfo){
					$array["msg"]="请选择有效的支付方式";
					return json($array);
				}
				$plugin = strtolower(trim((string)$payInfo["plugins"]));
				if(!in_array($plugin, ['epay','f2fpay'], true)){
					$array["msg"]="该支付方式（".$plugin."）暂不支持在线下单，请换一种方式或使用余额支付";
					return json($array);
				}
				$ppay = json_decode($payInfo["data"], true);
				if(!is_array($ppay)){
					$array["msg"]="支付接口未配置参数，请联系管理员";
					return json($array);
				}

				$ordernumber = "DK" . date("YmdHis") . rand(100, 999);
				$domain      = Request::instance()->domain();
				$root        = Request::instance()->root();
				$notifyUrl   = $domain . $root . "/index/notify/" . $payid . "/";
				$returnUrl   = $domain . $root . "/user/return/" . $payid . "/";
				$orderName   = "Docker容器开通-主机#" . $id;
				$payResult   = [];

				try {
					if($plugin=="epay"){
						$apiurl   = isset($ppay[0]["value"]) ? trim($ppay[0]["value"]) : '';
						$pid      = isset($ppay[1]["value"]) ? trim($ppay[1]["value"]) : '';
						$key      = isset($ppay[2]["value"]) ? trim($ppay[2]["value"]) : '';
						$typeName = isset($ppay[3]["value"]) ? trim($ppay[3]["value"]) : '';
						if($apiurl === '' || $pid === '' || $key === ''){
							$array["msg"]="此站点未配置易支付接口，请改用余额支付";
							return json($array);
						}
						$typeMap = ['支付宝'=>'alipay','微信'=>'wxpay','QQ'=>'qqpay'];
						$type = isset($typeMap[$typeName]) ? $typeMap[$typeName] : 'alipay';
						$epay = new \pay\epay(['apiurl'=>$apiurl,'pid'=>$pid,'key'=>$key]);
						$payUrl = $epay->getPayLink([
							'pid'=>$pid,
							'type'=>$type,
							'notify_url'=>$notifyUrl,
							'return_url'=>$returnUrl,
							'out_trade_no'=>$ordernumber,
							'name'=>$orderName,
							'money'=>$price,
						]);
						$payResult = ['pay_url'=>$payUrl, 'type'=>$type];
					}else{
						$appid         = isset($ppay[0]["value"]) ? trim($ppay[0]["value"]) : '';
						$rsaPrivateKey = isset($ppay[1]["value"]) ? trim($ppay[1]["value"]) : '';
						if($appid === '' || $rsaPrivateKey === ''){
							$array["msg"]="此站点未配置支付宝当面付接口，请改用余额支付";
							return json($array);
						}
						$f2f    = new \pay\F2FPay(['appid'=>$appid,'rsa_private_key'=>$rsaPrivateKey]);
						$result = $f2f->precreate($ordernumber, $price, $orderName, $notifyUrl);
						if(!isset($result['code']) || $result['code'] != 1){
							$array["msg"]=isset($result['msg']) ? $result['msg'] : '支付宝下单失败';
							return json($array);
						}
						$payResult = ['qr_code'=>$result['qr_code'], 'pay_url'=>$result['qr_code'], 'type'=>'alipay_f2f'];
					}
				} catch (\Throwable $payEx) {
					$array["msg"]="发起支付失败：".$payEx->getMessage();
					return json($array);
				}

				$payId = Db::name('pay')->insertGetId([
					"name"=>"Docker容器开通",
					"ordernumber"=>$ordernumber,
					"pay"=>$payid,
					"money"=>$price,
					"userid"=>session("userid"),
					"time"=>$now,
					"state"=>"2",
				]);
				Db::name('docker_order')->insertGetId([
					"userid"=>session("userid"),
					"orderid"=>$id,
					"cartid"=>intval($a["id"]),
					"serverid"=>intval($b && isset($b["id"]) ? $b["id"] : 0),
					"username"=>$data["user"],
					"price"=>$price,
					"paid"=>"0",
					"payway"=>"online",
					"mode"=>$mode,
					"state"=>"0",
					"pay_id"=>$payId,
					"ordernumber"=>$ordernumber,
					"atime"=>$now,
					"utime"=>$now,
				]);

				$array["code"]="1";
				$array["msg"]="支付订单已创建，完成支付后将自动为您开通";
				$array["ordernumber"]=$ordernumber;
				$array["pay_url"]=isset($payResult["pay_url"]) ? $payResult["pay_url"] : '';
				$array["qr_code"]=isset($payResult["qr_code"]) ? $payResult["qr_code"] : '';
				return json($array);
			}

			// Docker 关闭（仅改面板开关，保留用户已填规格）
			if($act=="docker_close"){
				$array=["code"=>"-1","msg"=>"关闭失败"];
				ensure_docker_order_table();
				if(isset($this->web['docker_allow_close']) && (string)$this->web['docker_allow_close'] === "0"){
					$array["msg"]="本站未开启自助关闭，请联系客服";
					return json($array);
				}
				$row = docker_latest_for_host($id, session("userid"));
				if(!$row || (string)$row["state"]!=="2"){
					$array["msg"]="该主机当前没有已开通的 Docker 容器";
					return json($array);
				}
				$r = docker_shutdown($row);
				$array["code"] = !empty($r["code"]) && (string)$r["code"]==="1" ? "1" : "-1";
				$array["msg"]  = $r["msg"];
				return json($array);
			}

			// Docker 开通状态查询（在线支付后前端轮询，顺带触发一次结算）
			if($act=="docker_status"){
				$array=["code"=>"1","msg"=>"ok","state"=>"","state_text"=>"未开通"];
				ensure_docker_order_table();
				if(function_exists('docker_settle_pending')){
					try { docker_settle_pending(session("userid")); } catch (\Throwable $e) {}
				}
				$row = docker_latest_for_host($id, session("userid"));
				if($row){
					$array["state"]      = (string)$row["state"];
					$array["state_text"] = docker_state_text($row["state"]);
					$array["fail_reason"]= (string)$row["fail_reason"];
				}
				$array["balance"] = floatval(Db::name('user')->where('id', session("userid"))->value('money'));
				return json($array);
			}

		}
			// 在线支付的 Docker 订单：充值到账后自动扣款并开通（兜底结算）
			if(function_exists('docker_settle_pending')){
				try { docker_settle_pending(session("userid")); } catch (\Throwable $e) {}
			}
			$hasPlugin = ($b && !empty($b["serverplugins"]));
			if($hasPlugin){
				@include_once PATH."plugins/host/".$b["serverplugins"]."/".$b["serverplugins"].".php";
				$function=$b["serverplugins"]."_"."ClientArea";
				$function1=$b["serverplugins"]."_"."upgrade";
			}else{
				$function="";
				$function1="";
			}

			if($a["upgrade"]=="1" && $a["upgrades"] && function_exists($function1)){
			$upgrade="1";
			}else{
			$upgrade="0";
			}
if($a["upgrades"] && $a["upgrades"]!="null"){
$a["upgrades"]=json_decode($a["upgrades"],true);
for($i=0;$i<count($a["upgrades"]);$i++)  
   {
$db11=Db::name('cart')->where('id',$a["upgrades"][$i])->find();

if($a["cycle"]=="month"){
$timess="2592000";
}
if($a["cycle"]=="season"){
$timess="7879680";
}
if($a["cycle"]=="year"){
$timess="31536000";
}
if($a["cycle"]=="day"){
$timess="86400";
}
if($a["cycle"]=="unrestricted"){
$timess="315360000";
}

if($db11["cycle"]=="month"){
$times="2592000";
}
if($db11["cycle"]=="season"){
$times="7879680";
}
if($db11["cycle"]=="year"){
$times="31536000";
}
if($db11["cycle"]=="day"){
$times="86400";
}
if($db11["cycle"]=="unrestricted"){
$times="315360000";
}
$money11=(($db11["money"]/($times/86400))*(($data["ztime"]-time())/86400));
$money11=$money11-(($a["money"]/($timess/86400))*(($data["ztime"]- time())/86400));
$money11=round($money11,2);
if($money11<0){
$money11="0";
}

$upgrades[$i]["id"]=$db11["id"];
$upgrades[$i]["information"]="ID:".$db11["id"]."=>".$db11["name"]."=>所需金额:".$money11."元";

}
}else{
$upgrades="";
}
//var_dump($upgrade);
if(function_exists($function)){
$ClientArea=@$function($b,$a,$data);
}else{
$ClientArea="";
}
// Docker 开通记录（该主机最近一条）
$dockerRec = function_exists('docker_latest_for_host') ? docker_latest_for_host($id, session("userid")) : null;
		return $this->fetch('/'.$this->web["template"].'/user/panel',[
"server"=>$b,
"data"=>$data,
"cart"=>$a,
"ClientArea"=>$ClientArea,
"upgrade"=>$upgrade,
"upgrades"=>$upgrades,
// 主机退款：开关、手续费与可退金额预览（需邮箱验证后才能真正退款）
"refundEnabled"=> !(isset($this->web['refund_enabled']) && $this->web['refund_enabled']=="0"),
"refundInfo"=> function_exists('calc_order_refund') ? calc_order_refund($data, $this->web) : ['can'=>false,'amount'=>0,'paid'=>0,'ratio'=>0,'msg'=>''],
"refundFeePercent"=> floatval($this->web['refund_fee_percent'] ?? 0),
"myMail"=> (function($m){ return $m ? mask_email_addr($m) : ''; })(Db::name('user')->where('id', session('userid'))->value('mail')),
// Docker 容器开通
"dockerEnabled"=> function_exists('docker_is_enabled') ? docker_is_enabled($this->web, $a, $b) : false,
"dockerPrice"=> function_exists('docker_price') ? docker_price($this->web) : 0,
"dockerMode"=> function_exists('docker_mode') ? docker_mode($this->web) : 'manual',
"dockerPayWays"=> function_exists('docker_pay_ways') ? docker_pay_ways($this->web) : ['balance'],
"dockerPayBalance"=> (function_exists('docker_pay_ways') ? in_array('balance', docker_pay_ways($this->web)) : true),
"dockerPayOnline"=> (function_exists('docker_pay_ways') ? in_array('online', docker_pay_ways($this->web)) : false),
"dockerAllowClose"=> !(isset($this->web['docker_allow_close']) && $this->web['docker_allow_close']=="0"),
"dockerIntro"=> isset($this->web['docker_intro']) ? $this->web['docker_intro'] : '',
"dockerRecord"=> $dockerRec,
"dockerOpened"=> ($dockerRec && (string)$dockerRec['state']==='2'),
"dockerPending"=> ($dockerRec && in_array((string)$dockerRec['state'], ['0','1'], true)),
"dockerReopen"=> ($dockerRec && in_array((string)$dockerRec['state'], ['3','4'], true)),
"dockerFailReason"=> ($dockerRec && (string)$dockerRec['state']==='4') ? (string)$dockerRec['fail_reason'] : '',
"dockerStateText"=> ($dockerRec ? docker_state_text($dockerRec['state']) : ''),
"dockerPays"=> (isset($this->web['docker_pay_online']) && $this->web['docker_pay_online']=="1")
	? Db::name('pays')->where('state','1')->field('id,name,plugins')->select() : [],
"myBalance"=> floatval($this->user['money'] ?? 0),
]);

}else{
		$this->redirect('/user/order/');
}
}else{
		$data=Db::name('order')->where("userid",session("userid"))->order('id desc')->select();
	// 优化：批量查询 cart 表，避免 N+1 查询
	$cartIds = array_unique(array_filter(array_column($data, 'cartid')));
	$cartMap = [];
	if (!empty($cartIds)) {
	    $cartMap = Db::name('cart')->where('id', 'in', $cartIds)->column('name', 'id');
	}
	foreach ($data as &$dataItem) {
	    $cid = $dataItem['cartid'];
	    $dataItem['cartid'] = isset($cartMap[$cid]) ? $cartMap[$cid] : ('产品#' . $cid);
	}
	unset($dataItem);
/**
		foreach ($data as &$item) {
		$b=Db::name('cart')->where('id',$item["cartid"])->find();
		$item["name"]=$b["name"];		 
		}
		**/
	
		$totalCount = count($data);
	$runningCount = 0;
	$expiredCount = 0;
	$now = time();
	foreach ($data as $item) {
		if ($item['state'] == "1") {
			$runningCount++;
		}
		if ($item['state'] == "3" || (isset($item['ztime']) && $item['ztime'] < $now)) {
			$expiredCount++;
		}
	}
	return $this->fetch('/'.$this->web["template"].'/user/order',[
"order"=>$data,
"totalCount"=>$totalCount,
"runningCount"=>$runningCount,
"expiredCount"=>$expiredCount,
]);
}

}

	public function payrecord() {
$data=Db::name('pay')->where("userid",session("userid"))->order('id desc')->paginate(10);
return $this->fetch('/'.$this->web["template"].'/user/payrecord',[
"payrecord"=>$data,
"active"=>"payrecord",
]);
}


public function submitticket(){
	if($this->user['ban_time'] > time()){
		$array["code"]="-1";
		$array["msg"]="你的账号已被封禁至 ".date("Y-m-d H:i:s",$this->user['ban_time'])."，无法提交工单。原因：".($this->user['ban_reason'] ?: '无');
		return json($array);
	}
	$realnameCheck = check_realname_limit('ticket');
if(Request::instance()->isPost()) {
	if($realnameCheck['code'] != 1){
		$array["code"]=(string)$realnameCheck['code'];
		$array["msg"]=$realnameCheck['msg'];
		return json($array);
	}
$title=htmlspecialchars(trim(input("title")));
$content=htmlspecialchars(trim(input("content")));
if($title=="" || $content==""){
$array["code"]="-1";
$array["msg"]="必填参数不可为空!";
}else{
$array1=array(
array(
"personnel"=>"2",
"content"=>$content,
"time"=>time(),
),
);
	$data=Db::name('ticket')->insertGetId([
				"title"=>$title,
				"content"=>json_encode($array1),
				"userid"=>session("userid"),
				"time"=>time(),
				"state"=>"1",
				]);
if($data){
if($this->web["email"]=="1"){
if($this->user["mail"]){
$mailbox=$this->email($this->user["mail"],"提交工单通知","你账号:".$this->user["user"]."在时间:".date("Y-m-d H:i:s")."在本站提交工单成功!<br/>工单id:".$data."<br/>请耐心等待管理员回复!<br/><br/>");
}
$admin=Db::name('admin')->where("id","1")->find();
if($admin["mail"]){
$mailbox=$this->email($admin["mail"],"客户提交工单通知","客户账号:".$this->user["user"]."在时间:".date("Y-m-d H:i:s")."在本站提交工单<br/>工单id:".$data."<br/>请及时处理!<br/><br/>");
}
}
$array["code"]="1";
$array["msg"]="提交工单成功!";
$array["id"]=$data;
}else{
$array["code"]="-1";
$array["msg"]="提交工单失败!";
}
}
return json($array);
}
return $this->fetch('/'.$this->web["template"].'/user/submitticket',[
"realnameCheck"=>$realnameCheck,
]);
}

public function supportticket($id=null){
if($id){


$data=Db::name('ticket')->where([
"id"=>$id,
"userid"=>session("userid"),
])->find();
if($data){
$data["content"]=json_decode($data["content"],true);
$admin=Db::name('admin')->where("id","1")->find();
unset($admin["password"]);
if(Request::instance()->isPost()) {
$act=input("act");
if($act=="reply"){
	if($this->user['ban_time'] > time()){
		$array["code"]="-1";
		$array["msg"]="你的账号已被封禁，无法回复工单。";
		return json($array);
	}
$content=htmlspecialchars(trim(input("content")));
if($content==""){
$array["code"]="-1";
$array["msg"]="必填参数不可为空!";
}else{
$array1=array(
array(
"personnel"=>"2",
"content"=>$content,
"time"=>time(),
),
);
$array2=array_merge($data["content"],$array1);
$data1=Db::name('ticket')->where([
"id"=>$id,
"userid"=>session("userid"),
])->update([
'content' =>json_encode($array2),
'state'=>'2',
]);
if($data1){
if($this->web["email"]=="1"){
if($admin["mail"]){
$mailbox=$this->email($admin["mail"],"客户回复工单通知","客户账号:".$this->user["user"]."在时间:".date("Y-m-d H:i:s")."已回复工单<br/>工单ID:".$id."<br/>标题:".$data["title"]."<br/>回复内容:<br/>".nl2br(htmlspecialchars($content))."<br/><br/>请登录后台查看完整对话:".request()->domain()."/admin/ticket/".$id);
}
}
$array["code"]="1";
$array["msg"]="回复工单成功!";
}else{
$array["code"]="-1";
$array["msg"]="回复工单失败!";
}
}
return json($array);
}

if($act=="end"){
$data1=Db::name('ticket')->where([
"id"=>$id,
"userid"=>session("userid"),
])->update([
'state'=>'4',
]);
if($data["state"]=="4"){
$array["code"]="-1";
$array["msg"]="工单已关闭!";
}else{
if($data1){
$array["code"]="1";
$array["msg"]="关闭工单成功!";
}else{
$array["code"]="-1";
$array["msg"]="关闭工单失败!";
}
}
return json($array);
}
}

return $this->fetch('/'.$this->web["template"].'/user/supportticket',[
"ticket"=>$data,
"admin"=>$admin,
]);


}else{
	$this->redirect('/user/supportticket');
}




}else{
$data=Db::name('ticket')->where("userid",session("userid"))->order('id desc')->paginate(10);
return $this->fetch('/'.$this->web["template"].'/user/supporttickets',[
"ticket"=>$data,
]);
}


}

public function transferrecord(){
$data=Db::name('transferrecord')->where("userid",session("userid"))->order('id desc')->paginate(10);
return $this->fetch('/'.$this->web["template"].'/user/transferrecord',[
"data"=>$data,
]);
}


public function transaction(){
$data=Db::name('transaction')->where("userid",session("userid"))->order('id desc')->paginate(10);
return $this->fetch('/'.$this->web["template"].'/user/transaction',[
"data"=>$data,
]);
}




// 卡密兑换
public function cdkey() {
	if(Request::instance()->isPost()) {
		$array = ['code' => '-1', 'msg' => ''];
		$cdkey = trim(input('cdkey', ''));
		if(empty($cdkey)) { $array['msg'] = '请输入卡密'; return json($array); }
		ensure_cdkey_table();
		ensure_cdkey_usage_log_table();
		$key = Db::name('cdkey')->where('cdkey', $cdkey)->find();
		if(!$key) { $array['msg'] = '卡密不存在'; return json($array); }
		if($key['status'] == 2) { $array['msg'] = '该卡密已被停用'; return json($array); }

		$userId = session('userid');
		$now = time();

		// 使用限制检查
		$restrict_type = isset($key['restrict_type']) ? $key['restrict_type'] : 'all';
		$restrict_users = isset($key['restrict_users']) ? trim($key['restrict_users']) : '';
		if($restrict_type == 'single'){
			if(trim($restrict_users) != strval($userId)){
				$array['msg'] = '此卡密不适用于您的账户'; return json($array);
			}
		}elseif($restrict_type == 'multi'){
			$allowedIds = array_map('trim', explode(',', $restrict_users));
			if(!in_array(strval($userId), $allowedIds)){
				$array['msg'] = '此卡密不适用于您的账户'; return json($array);
			}
		}elseif($restrict_type == 'once_per_user'){
			$alreadyUsed = Db::name('cdkey_usage_log')->where('cdkey', $cdkey)->where('userid', $userId)->find();
			if($alreadyUsed){
				$array['msg'] = '您已经兑换过此卡密，每人限兑换一次'; return json($array);
			}
		}

		// 排除用户检查：被排除的用户不可使用此卡密
		$exclude_users = isset($key['exclude_users']) ? trim($key['exclude_users']) : '';
		if($exclude_users !== ''){
			$excludedIds = array_map('trim', explode(',', $exclude_users));
			if(in_array(strval($userId), $excludedIds, true)){
				$array['msg'] = '您已被限制使用此卡密'; return json($array);
			}
		}

		// 兑换人数上限检查（全站通用卡密常用：限前 N 名用户兑换）
		$maxUses = isset($key['max_uses']) ? intval($key['max_uses']) : 0;
		if($maxUses > 0){
			$usedTotal = Db::name('cdkey_usage_log')->where('cdkey', $cdkey)->count();
			if(intval($usedTotal) >= $maxUses){
				$array['msg'] = '该卡密兑换名额已满（限' . $maxUses . '人兑换）';
				return json($array);
			}
		}

		// 可重复使用检查
		$repeatable = isset($key['repeatable']) ? intval($key['repeatable']) : 0;
		if(!$repeatable){
			if($key['status'] == 1) { $array['msg'] = '该卡密已被使用'; return json($array); }
		}

		// 记录本次使用：一次性卡密标记已用；全站通用卡密或设置了兑换人数上限的卡密写使用日志
		// （日志表对 (cdkey,userid) 有唯一索引，同一用户同一卡密只记一条，因此"日志条数 = 兑换人数"）
		$markUsage = function() use ($key, $cdkey, $userId, $now, $repeatable, $restrict_type) {
			if(!$repeatable && $restrict_type != 'once_per_user'){
				Db::name('cdkey')->where('id', $key['id'])->update([
					'status' => 1, 'used_at' => $now, 'used_userid' => $userId
				]);
			}
			$needLog = ($restrict_type == 'once_per_user') || (isset($key['max_uses']) && intval($key['max_uses']) > 0);
			if($needLog){
				$exists = Db::name('cdkey_usage_log')->where('cdkey', $cdkey)->where('userid', $userId)->find();
				if(!$exists){
					Db::name('cdkey_usage_log')->insert([
						'cdkey' => $cdkey,
						'userid' => $userId,
						'used_at' => $now
					]);
				}
			}
		};

		if($key['type'] == 'points') {
			// 积分卡密：给用户增加积分
			$addPoints = isset($key['points']) ? intval($key['points']) : 0;
			if($addPoints <= 0) { $array['msg'] = '该卡密积分数额异常，请联系管理员'; return json($array); }
			$userInfo = Db::name('user')->where('id', $userId)->find();
			$newPoints = intval(isset($userInfo['points']) ? $userInfo['points'] : 0) + $addPoints;
			Db::name('user')->where('id', $userId)->update(['points' => $newPoints]);
			try {
				ensure_points_log_table();
				Db::name('points_log')->insert([
					'userid' => $userId,
					'type' => 'cdkey',
					'points' => $addPoints,
					'content' => '卡密兑换获得' . $addPoints . '积分，卡密ID:' . $key['id'],
					'created_at' => $now,
				]);
			} catch (\Throwable $e) {}
			$markUsage();
			Db::name('transaction')->insert([
				'userid' => $userId,
				'content' => '卡密兑换积分，卡密ID:' . $key['id'] . '，积分:' . $addPoints,
				'time' => $now,
			]);
			$array['code'] = '1';
			$array['msg'] = '兑换成功，获得' . $addPoints . '积分';
			$array['points'] = $newPoints;
			$array['reload'] = 1;
			return json($array);
		}

		if($key['type'] == 'balance') {
			// 余额充值
			$money = floatval($key['money']);
			$userInfo = Db::name('user')->where('id', $userId)->find();
			Db::name('user')->where('id', $userId)->update([
				'money' => round(floatval($userInfo['money'] ?? 0) + $money, 2),
				'total_recharge' => round(floatval($userInfo['total_recharge'] ?? 0) + $money, 2)
			]);
			if (function_exists('update_user_membership')) {
				update_user_membership($userId);
			}
			$markUsage();
			Db::name('transaction')->insert([
				'userid' => $userId,
				'content' => '卡密充值余额，卡密ID:' . $key['id'] . '，金额:' . $money . '元',
				'time' => $now,
			]);
			$array['code'] = '1';
			$array['msg'] = '兑换成功，余额增加' . $money . '元';
			return json($array);
		}

		if($key['type'] == 'host') {
			// 卡密购买主机
			$cart = Db::name('cart')->where('id', $key['cartid'])->find();
			if(!$cart) { $array['msg'] = '关联产品不存在'; return json($array); }
			if($cart['buy'] == '1') { $array['msg'] = '该产品已设置禁止购买'; return json($array); }
			if($cart['inventory'] < 1) { $array['msg'] = '该产品已售完'; return json($array); }

			$server = Db::name('server')->where('id', $cart['serverid'])->find();
			if(!$server || empty($server['serverplugins'])) { $array['msg'] = '产品未配置服务器'; return json($array); }

			// 生成主机账号密码
			$hostUser = 'u' . date('ymd') . random(4);
			$hostPass = random(10);
			$times = 2592000; // 默认1个月
			if($cart['cycle'] == 'season') $times = 7879680;
			if($cart['cycle'] == 'year') $times = 31536000;
			if($cart['cycle'] == 'day') $times = 86400;
			if($cart['cycle'] == 'unrestricted') $times = 3153600000;

			// 调用开通
			$pluginFile = PATH . 'plugins/host/' . $server['serverplugins'] . '/' . $server['serverplugins'] . '.php';
			if(!file_exists($pluginFile)) { $array['msg'] = '插件文件不存在'; return json($array); }
			include_once $pluginFile;
			$function = $server['serverplugins'] . '_CreateAccount';
			if(!function_exists($function)) { $array['msg'] = '开通接口未实现'; return json($array); }
			$result = @$function($server, ['user' => $hostUser, 'password' => $hostPass, 'time' => 1], $cart, $times);

			if(is_array($result) && isset($result['code']) && $result['code'] == '1') {
				$markUsage();
				Db::name('cart')->where('id', $cart['id'])->update(['inventory' => max(0, intval($cart['inventory']) - 1)]);
				$orderId = isset($result['id']) ? $result['id'] : 0;
				Db::name('transaction')->insert([
					'userid' => $userId,
					'content' => '卡密开通产品，卡密ID:' . $key['id'] . '，产品:' . $cart['name'] . '，订单ID:' . $orderId,
					'time' => $now,
				]);
				$array['code'] = '1';
				$array['msg'] = '产品开通成功！账号：' . $hostUser;
				return json($array);
			} else {
				// 开通失败回滚 - 卡密不退
				if(!$repeatable && $restrict_type != 'once_per_user'){
					Db::name('cdkey')->where('id', $key['id'])->update([
						'status' => 1, 'used_at' => $now, 'used_userid' => $userId
					]);
				}
				$err = is_array($result) && isset($result['msg']) ? $result['msg'] : '开通失败';
				Db::name('transaction')->insert([
					'userid' => $userId,
					'content' => '卡密开通失败，卡密ID:' . $key['id'] . '，错误:' . $err . '，请联系管理员',
					'time' => $now,
				]);
				$array['msg'] = '开通失败：' . $err . '，请联系管理员处理';
				return json($array);
			}
		}

		$array['msg'] = '未知卡密类型';
		return json($array);
	}
	return $this->fetch('/'.$this->web["template"].'/user/cdkey', [
		'active' => 'cdkey',
		'web' => $this->web,
		'user' => $this->user,
	]);
}

// 积分签到
public function checkin() {
	$array = ['code' => '-1', 'msg' => ''];
	if (!Request::instance()->isPost()) {
		$array['msg'] = '非法请求';
		return json($array);
	}
	$todayStart = strtotime(date('Y-m-d'));
	$lastCheckin = intval($this->user['last_checkin_time'] ?? 0);
	if ($lastCheckin >= $todayStart) {
		$array['msg'] = '今日已签到，请明天再来';
		return json($array);
	}
	// 随机1~30积分
	$points = rand(1, 30);
	try {
		\think\Db::name('user')->where('id', session('userid'))->update([
			'points' => intval($this->user['points'] ?? 0) + $points,
			'last_checkin_time' => time(),
		]);
		\think\Db::name('points_log')->insert([
			'userid' => session('userid'),
			'type' => 'checkin',
			'points' => $points,
			'content' => '每日签到获得' . $points . '积分',
			'created_at' => time(),
		]);
		$array['code'] = '1';
		$array['msg'] = '签到成功';
		$array['points'] = $points;
		$array['total'] = intval($this->user['points'] ?? 0) + $points;
	} catch (\Exception $e) {
		$array['msg'] = '签到失败：' . $e->getMessage();
	}
	return json($array);
}

// 积分商城首页
public function pointsShop() {
	ensure_points_products_table();
	ensure_points_log_table();
	$this->ensureUserColumns();
	$products = \think\Db::name('points_products')
		->where('status', 1)
		->order('sort asc, id asc')
		->select();
	$userPoints = intval($this->user['points'] ?? 0);
	// 兑换记录
	$exchangeLogs = [];
	try {
		$exchangeLogs = \think\Db::name('points_log')
			->where('userid', session('userid'))
			->where('type', 'exchange')
			->order('id desc')
			->limit(10)
			->select();
	} catch (\Exception $e) {}
	return $this->fetch('/'.$this->web["template"].'/user/points_shop', [
		'products' => $products,
		'userPoints' => $userPoints,
		'exchangeLogs' => $exchangeLogs,
		'active' => 'points_shop',
		'web' => $this->web,
		'user' => $this->user,
	]);
}

// 积分兑换
public function pointsExchange() {
	$array = ['code' => '-1', 'msg' => ''];
	if (!Request::instance()->isPost()) {
		$array['msg'] = '非法请求';
		return json($array);
	}
	ensure_points_products_table();
	ensure_points_log_table();
	$productId = intval(input('product_id'));
	$product = \think\Db::name('points_products')->where('id', $productId)->where('status', 1)->find();
	if (!$product) {
		$array['msg'] = '产品不存在或已下架';
		return json($array);
	}
	$userPoints = intval($this->user['points'] ?? 0);
	if ($userPoints < $product['points']) {
		$array['msg'] = '积分不足，需要' . $product['points'] . '积分，当前' . $userPoints . '积分';
		return json($array);
	}
	// 检查库存
	if ($product['stock'] == 0) {
		$array['msg'] = '该产品已兑完';
		return json($array);
	}
	try {
		$userId = session('userid');
		$now = time();
		if ($product['type'] == 'balance') {
			// 积分兑换余额
			$value = floatval($product['value']);
			\think\Db::name('user')->where('id', $userId)->update([
				'points' => $userPoints - $product['points'],
				'money' => round(floatval($this->user['money']) + $value, 2),
			]);
			\think\Db::name('transaction')->insert([
				'userid' => $userId,
				'content' => '积分兑换余额：' . $product['name'] . '，消耗' . $product['points'] . '积分，获得' . $value . '元',
				'time' => $now,
			]);
		} elseif ($product['type'] == 'host') {
			// 积分兑换主机
			$cartid = intval($product['value']);
			$cart = \think\Db::name('cart')->where('id', $cartid)->find();
			if (!$cart) {
				$array['msg'] = '关联产品不存在';
				return json($array);
			}
			if ($cart['inventory'] < 1) {
				$array['msg'] = '该产品已售完';
				return json($array);
			}
			$server = \think\Db::name('server')->where('id', $cart['serverid'])->find();
			if (!$server || empty($server['serverplugins'])) {
				$array['msg'] = '产品未配置服务器';
				return json($array);
			}
			$hostUser = 'u' . date('ymd') . random(4);
			$hostPass = random(10);
			$times = 2592000;
			if ($cart['cycle'] == 'season') $times = 7879680;
			elseif ($cart['cycle'] == 'year') $times = 31536000;
			elseif ($cart['cycle'] == 'day') $times = 86400;
			elseif ($cart['cycle'] == 'unrestricted') $times = 3153600000;
			$pluginFile = PATH . 'plugins/host/' . $server['serverplugins'] . '/' . $server['serverplugins'] . '.php';
			if (!file_exists($pluginFile)) {
				$array['msg'] = '插件文件不存在';
				return json($array);
			}
			include_once $pluginFile;
			$function = $server['serverplugins'] . '_CreateAccount';
			if (!function_exists($function)) {
				$array['msg'] = '开通接口未实现';
				return json($array);
			}
			$result = @$function($server, ['user' => $hostUser, 'password' => $hostPass, 'time' => 1], $cart, $times);
			if (is_array($result) && isset($result['code']) && $result['code'] == '1') {
				\think\Db::name('cart')->where('id', $cart['id'])->update(['inventory' => max(0, intval($cart['inventory']) - 1)]);
				$orderId = isset($result['id']) ? $result['id'] : 0;
				\think\Db::name('transaction')->insert([
					'userid' => $userId,
					'content' => '积分兑换主机：' . $product['name'] . '，消耗' . $product['points'] . '积分，订单ID:' . $orderId,
					'time' => $now,
				]);
				$array['code'] = '1';
				$array['msg'] = '兑换成功！主机已开通，账号：' . $hostUser;
			} else {
				$err = is_array($result) && isset($result['msg']) ? $result['msg'] : '开通失败';
				$array['msg'] = '开通失败：' . $err . '，积分已退还';
				return json($array);
			}
		} elseif ($product['type'] == 'renew') {
			// 积分兑换续费天数
			$renewDays = intval($product['value']);
			$array['msg'] = '请在主机管理页面选择要续费的主机，使用积分续费功能';
			return json($array);
		} elseif ($product['type'] == 'unban') {
			// 积分兑换免除法卡（解除封禁）
			if ($this->user['ban_time'] <= time()) {
				$array['msg'] = '你的账号当前未被封禁，无需使用免除法卡';
				return json($array);
			}
			\think\Db::name('user')->where('id', $userId)->update([
				'ban_time' => 0,
				'ban_reason' => '',
			]);
			\think\Db::name('transaction')->insert([
				'userid' => $userId,
				'content' => '积分兑换免除法卡：' . $product['name'] . '，消耗' . $product['points'] . '积分，账号已解除封禁',
				'time' => $now,
			]);
			$array['code'] = '1';
			$array['msg'] = '兑换成功！你的账号已解除封禁，恢复正常使用';
		}
		// 扣积分和记录
		\think\Db::name('user')->where('id', $userId)->update([
			'points' => $userPoints - $product['points'],
		]);
		\think\Db::name('points_log')->insert([
			'userid' => $userId,
			'type' => 'exchange',
			'points' => -$product['points'],
			'content' => '兑换：' . $product['name'] . '，消耗' . $product['points'] . '积分',
			'created_at' => $now,
		]);
		// 扣库存
		if ($product['stock'] > 0) {
			\think\Db::name('points_products')->where('id', $product['id'])->setDec('stock');
		}
		$this->user = \think\Db::name('user')->where('id', $userId)->find();
		$array['total'] = intval($this->user['points'] ?? 0);
		if ($array['code'] != '1') $array['code'] = '1';
		if (empty($array['msg'])) $array['msg'] = '兑换成功';
	} catch (\Exception $e) {
		$array['msg'] = '兑换失败：' . $e->getMessage();
	}
	return json($array);
}

// 积分续费主机
public function pointsRenew() {
	$array = ['code' => '-1', 'msg' => ''];
	if (!Request::instance()->isPost()) {
		$array['msg'] = '非法请求';
		return json($array);
	}
	ensure_points_products_table();
	$orderId = intval(input('order_id'));
	$productId = intval(input('product_id'));
	$order = \think\Db::name('order')->where('id', $orderId)->where('userid', session('userid'))->find();
	if (!$order) {
		$array['msg'] = '主机不存在';
		return json($array);
	}
	$renewProduct = \think\Db::name('points_products')->where('id', $productId)->where('status', 1)->where('type', 'renew')->find();
	if (!$renewProduct) {
		$array['msg'] = '续费产品不存在';
		return json($array);
	}
	$userPoints = intval($this->user['points'] ?? 0);
	if ($userPoints < $renewProduct['points']) {
		$array['msg'] = '积分不足';
		return json($array);
	}
	$renewDays = intval($renewProduct['value']);
	$cart = \think\Db::name('cart')->where('id', $order['cartid'])->find();
	$server = \think\Db::name('server')->where('id', $cart['serverid'] ?? 0)->find();
	$times = $renewDays * 86400;
	$hasPlugin = ($server && !empty($server['serverplugins']));
	try {
		if ($hasPlugin) {
			include_once PATH . "plugins/host/" . $server["serverplugins"] . "/" . $server["serverplugins"] . ".php";
			$function = $server["serverplugins"] . "_renew";
			if (function_exists($function)) {
				$result = $function($server, $order, $cart, $times, $renewDays);
				if (!is_array($result) || $result['code'] != '1') {
					$array['msg'] = '续费失败：' . ($result['msg'] ?? '未知错误');
					return json($array);
				}
			}
		}
		\think\Db::name('order')->where('id', $orderId)->update([
			'ztime' => $order['ztime'] + $times,
		]);
		\think\Db::name('user')->where('id', session('userid'))->update([
			'points' => $userPoints - $renewProduct['points'],
		]);
		\think\Db::name('points_log')->insert([
			'userid' => session('userid'),
			'type' => 'exchange',
			'points' => -$renewProduct['points'],
			'content' => '积分续费主机 #' . $orderId . '，续费' . $renewDays . '天，消耗' . $renewProduct['points'] . '积分',
			'created_at' => time(),
		]);
		$this->user = \think\Db::name('user')->where('id', session('userid'))->find();
		$array['code'] = '1';
		$array['msg'] = '续费成功！主机已延长' . $renewDays . '天';
		$array['total'] = intval($this->user['points'] ?? 0);
	} catch (\Exception $e) {
		$array['msg'] = '续费失败：' . $e->getMessage();
	}
	return json($array);
}

// ========== 公告通知 ==========
public function announcements() {
	ensure_announcements_table();
	$userId = session('userid');
	$list = Db::name('announcements')
		->where('status', 1)
		->order('created_at desc')
		->select();
	$annIds = array_column($list, 'id');
	$readMap = [];
	if(!empty($annIds)){
		$reads = Db::name('announcement_reads')
			->where('user_id', $userId)
			->where('announcement_id', 'in', $annIds)
			->column('read_at', 'announcement_id');
		if($reads){
			foreach($reads as $aid => $rat){
				$readMap[$aid] = true;
			}
		}
	}
	$unreadCount = 0;
	$forcePopup = null;
	$result = [];
	foreach($list as $item){
		$isRead = isset($readMap[$item['id']]);
		if(!$isRead) $unreadCount++;
		$result[] = [
			'id' => $item['id'],
			'title' => $item['title'],
			'content' => $item['content'],
			'notice_type' => $item['notice_type'],
			'created_at' => $item['created_at'],
			'is_read' => $isRead,
		];
		if(!$forcePopup && $item['notice_type'] == 'force' && !$isRead){
			$forcePopup = [
				'id' => $item['id'],
				'title' => $item['title'],
				'content' => $item['content'],
			];
		}
	}
	return json([
		'code' => 1,
		'data' => [
			'list' => $result,
			'unread_count' => $unreadCount,
			'force_popup' => $forcePopup,
		]
	]);
}

public function announcementRead() {
	$array = ['code' => '-1', 'msg' => ''];
	if(!Request::instance()->isPost()){
		$array['msg'] = '非法请求';
		return json($array);
	}
	$annId = intval(input('announcement_id', 0));
	if(!$annId){
		$array['msg'] = '参数错误';
		return json($array);
	}
	$userId = session('userid');
	ensure_announcements_table();
	try {
		Db::name('announcement_reads')->insert([
			'announcement_id' => $annId,
			'user_id' => $userId,
			'read_at' => time(),
		]);
	} catch (\Exception $e) {
		// 重复忽略
	}
	$array['code'] = '1';
	return json($array);
}

// 发送邮箱
// 修复：移除 register_shutdown_function 异步发送，改为同步发送 + 失败入队重试，
// 解决邮件延迟过高、信件丢失需二次发送的问题。
public static function email($email,$name,$body)
{
if (!rate_limit('email_send_' . $email, 3, 60)) { return json(['code'=>'-1','msg'=>'发送频率过快，请稍后再试']); }
$body = sanitize_email_body($body);
$web=web_config();
// 显式加载 PHPMailer，避免自动加载静默失败导致一律收不到邮件
if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
	$pmDir = PATH . 'extend/PHPMailer/PHPMailer/';
	if (file_exists($pmDir . 'PHPMailer.php')) {
		require_once $pmDir . 'Exception.php';
		require_once $pmDir . 'PHPMailer.php';
		require_once $pmDir . 'SMTP.php';
	}
}
$logDir = defined('LOG_PATH') ? LOG_PATH : (PATH . '/runtime/log/');
if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
try {
	if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
		throw new \Exception('PHPMailer 类加载失败，目录：' . PATH . 'extend/PHPMailer/');
	}
	$mail = new \PHPMailer\PHPMailer\PHPMailer(true);
	$mail->CharSet  = !empty($web['emailchar']) ? $web['emailchar'] : 'UTF-8';
	$mail->IsSMTP();
	$mail->SMTPAuth  = true;
	$mail->Timeout   = 20;
	$mail->SMTPDebug = 0;
	$mail->Host     = $web['emailhost'];
	$mail->Port     = !empty($web['emailport']) ? intval($web['emailport']) : 465;
	$mail->Username = $web['emailname'];
	$mail->Password = $web['emailpass'];
	if ($mail->Port == 465) {
		$mail->SMTPSecure = 'ssl';
	} elseif (!empty($web['emailsecure']) && $web['emailsecure'] != 'none') {
		$mail->SMTPSecure = $web['emailsecure'];
	}
	$mail->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]];
	$mail->setFrom($web['emailname'], !empty($web['name']) ? $web['name'] : '');
	$mail->addAddress($email);
	$mail->Subject = $name;
	$mail->isHTML(true);
	$mail->Body = build_email_html($name, $body);
	$mail->WordWrap = 80;
	$mail->send();
	return json(['code'=>'1','msg'=>'邮箱发送成功']);
} catch (\Throwable $e) {
	@file_put_contents($logDir . 'email_error.log', date('Y-m-d H:i:s') . " To:{$email} Subject:{$name} Error:" . $e->getMessage() . "\n", FILE_APPEND);
	if (function_exists('enqueue_email')) enqueue_email($email, $name, $body);
	return json(['code'=>'-1','msg'=>'邮件发送失败：' . $e->getMessage()]);
}
}

// ========== 主机转让功能 ==========

// 转让市场
public function transferMarket() {
	ensure_host_transfer_table();
	ensure_host_transfer_message_table();
	// 实名认证校验：未实名不可进入转让市场
	$check = check_realname_limit('transfer');
	if($check['code'] != 1){
		$msg = urlencode($check['msg']);
		$this->redirect('/user/realname?msg=' . $msg);
	}
	$myUserId = intval(session('userid'));
	$data = Db::name('host_transfer')
		->alias('t')
		->join('order o', 't.order_id = o.id')
		->join('user u', 't.userid = u.id')
		->join('cart c', 'o.cartid = c.id')
		->where('t.status', '0')
		->where(function($query) use ($myUserId) {
			$query->where('t.userid', $myUserId)
			      ->whereOr('t.target_userid', 0)
			      ->whereOr('t.target_userid', $myUserId);
		})
		->field('t.*, o.user as host_user, o.atime, o.ztime, c.name as product_name, c.money as product_money, c.serverid, c.id as cart_id, u.user as seller_name, u.qq as seller_qq, u.id as seller_id')
		->order('t.id desc')
		->paginate(10);
	return $this->fetch('/'.$this->web["template"].'/user/transfer_market', [
		"transfers" => $data,
		"myUserId" => $myUserId,
	]);
}

// 发起转让（获取自己的主机列表）
public function transferHost() {
	ensure_host_transfer_table();
	// 实名认证校验：未实名不可发起/查看转让
	$check = check_realname_limit('transfer');
	if($check['code'] != 1){
		if (Request::instance()->isPost()) {
			return json(['code' => '-2', 'msg' => $check['msg'], 'realname_required' => 1]);
		}
		$msg = urlencode($check['msg']);
		$this->redirect('/user/realname?msg=' . $msg);
	}
	if (Request::instance()->isPost()) {
		$act = input("act");
		
		// 发送邮箱验证码
		if ($act == "sendcode") {
			$userInfo = Db::name('user')->where('id', session('userid'))->find();
			if (empty($userInfo['mail'])) {
				return json(['code' => '-1', 'msg' => '请先绑定邮箱后再操作']);
			}
			$code = random(6, '0123456789');
			session('transfer_email_code', $code);
			session('transfer_email_time', time());
			$codeBody = "<p>您好，</p><p>您正在 {$this->web['name']} 发起主机转让，本次验证码为：</p><p style='text-align:center;margin:28px 0;'><span style='display:inline-block;background:#eff6ff;color:#2563eb;font-size:28px;font-weight:700;padding:14px 32px;border-radius:10px;letter-spacing:4px;border:1px solid #bfdbfe;'>{$code}</span></p><p style='color:#64748b;font-size:13px;'>验证码 10 分钟内有效。如非本人操作，请忽略此邮件。</p>";
			if ($this->web["email"] == "1") {
				$this->email($userInfo['mail'], "主机转让验证码", $codeBody);
			}
			return json(['code' => '1', 'msg' => '验证码已发送至邮箱']);
		}
		
		// 提交转让
		if ($act == "submit") {
			$orderId = intval(input("order_id"));
			$price = floatval(input("price"));
			$targetUserid = intval(input("target_userid"));
			$contactInfo = trim(input("contact_info"));
			$emailCode = input("email_code");
			
			// 验证邮箱验证码
			$savedCode = session('transfer_email_code');
			$savedTime = session('transfer_email_time');
			if (empty($savedCode) || empty($emailCode) || $savedCode != $emailCode) {
				return json(['code' => '-1', 'msg' => '邮箱验证码错误']);
			}
			if (time() - $savedTime > 600) {
				return json(['code' => '-1', 'msg' => '验证码已过期，请重新获取']);
			}
			
			if ($orderId <= 0) {
				return json(['code' => '-1', 'msg' => '请选择要转让的主机']);
			}
			if ($price < 0) {
				return json(['code' => '-1', 'msg' => '转让价格不能为负数']);
			}
			
			// 验证订单归属
			$order = Db::name('order')->where([
				'id' => $orderId,
				'userid' => session('userid'),
			])->find();
			if (!$order) {
				return json(['code' => '-1', 'msg' => '订单不存在或不属于您']);
			}
			if ($order['state'] != '1') {
				return json(['code' => '-1', 'msg' => '只有运行中的主机才能转让']);
			}
			
			// 获取原购买价格
			$cart = Db::name('cart')->where('id', $order['cartid'])->find();
			$originalPrice = $cart ? floatval($cart['money']) : 0;
			
			// 转让价格不能超过原购买价格
			if ($originalPrice > 0 && $price > $originalPrice) {
				return json(['code' => '-1', 'msg' => '转让价格不能超过原购买价格（¥' . $originalPrice . '）']);
			}
			if ($originalPrice == 0 && $price > 0) {
				return json(['code' => '-1', 'msg' => '免费主机转让价格必须为0']);
			}
			
			// 指定目标用户时验证
			if ($targetUserid > 0) {
				$targetUser = Db::name('user')->where('id', $targetUserid)->find();
				if (!$targetUser) {
					return json(['code' => '-1', 'msg' => '指定的目标用户不存在']);
				}
				if ($targetUserid == session('userid')) {
					return json(['code' => '-1', 'msg' => '不能转让给自己']);
				}
			}
			
			$now = time();
			Db::name('host_transfer')->insert([
				'order_id' => $orderId,
				'userid' => session('userid'),
				'target_userid' => $targetUserid,
				'price' => $price,
				'original_price' => $originalPrice,
				'status' => 0,
				'email_verified' => 1,
				'contact_info' => $contactInfo,
				'created_at' => $now,
				'updated_at' => $now,
			]);
			
			// 清除验证码
			session('transfer_email_code', null);
			session('transfer_email_time', null);
			
			return json(['code' => '1', 'msg' => '主机已成功发布到转让市场']);
		}
	}
	
	// GET: 获取可转让的主机列表
	$orders = Db::name('order')
		->alias('o')
		->join('cart c', 'o.cartid = c.id')
		->where('o.userid', session('userid'))
		->where('o.state', '1')
		->field('o.id, o.user, o.atime, o.ztime, c.name as product_name, c.money')
		->select();
	
	return $this->fetch('/'.$this->web["template"].'/user/transfer_host', [
		"orders" => $orders,
	]);
}

// 购买转让主机
public function transferBuy() {
	ensure_host_transfer_table();
	if (!Request::instance()->isPost()) {
		return json(['code' => '-1', 'msg' => '非法请求']);
	}
	
	$check = check_realname_limit('transfer');
	if($check['code'] != 1){
		return json(['code' => '-2', 'msg' => $check['msg'], 'realname_required' => 1]);
	}
	
	$transferId = intval(input('transfer_id'));
	$transfer = Db::name('host_transfer')->where('id', $transferId)->where('status', '0')->find();
	if (!$transfer) {
		return json(['code' => '-1', 'msg' => '该转让已失效或不存在']);
	}
	if ($transfer['userid'] == session('userid')) {
		return json(['code' => '-1', 'msg' => '不能购买自己的转让主机']);
	}
	if ($transfer['target_userid'] > 0 && $transfer['target_userid'] != session('userid')) {
		return json(['code' => '-1', 'msg' => '该主机仅限指定用户购买']);
	}
	
	$order = Db::name('order')->where('id', $transfer['order_id'])->find();
	if (!$order || $order['state'] != '1') {
		return json(['code' => '-1', 'msg' => '原主机已不可用']);
	}
	
	$buyer = Db::name('user')->where('id', session('userid'))->find();
	$price = floatval($transfer['price']);
	
	if ($price > 0 && $buyer['money'] < $price) {
		return json(['code' => '-1', 'msg' => '账户余额不足，需要 ¥' . $price . '，当前余额 ¥' . $buyer['money']]);
	}
	
	$now = time();
	
	// 扣款并转账给卖家
	if ($price > 0) {
		Db::name('user')->where('id', session('userid'))->setDec('money', $price);
		Db::name('user')->where('id', $transfer['userid'])->setInc('money', $price);
		
		// 买家交易记录
		Db::name('transaction')->insert([
			'userid' => session('userid'),
			'content' => '购买转让主机 #' . $order['id'] . '，支付 ¥' . $price,
			'time' => $now,
		]);
		// 卖家交易记录
		Db::name('transaction')->insert([
			'userid' => $transfer['userid'],
			'content' => '转让主机 #' . $order['id'] . ' 售出，收入 ¥' . $price,
			'time' => $now,
		]);
	}
	
	// 更新订单归属：转移网站用户归属，并重置主机面板密码（避免沿用卖家凭据导致登录账号密码错误）
	// 面板接口 czmm 仅支持改密码，主机账号(user)无法在线改名，故保留账号、重置密码
	$newPassword = trim(input('password'));
	if ($newPassword !== '' && (strlen($newPassword) < 6 || strlen($newPassword) > 50 || preg_match('/[\x{4e00}-\x{9fa5}]/u', $newPassword))) {
		$newPassword = '';
	}
	if ($newPassword === '') {
		$newPassword = random(10);
	}
	$orderUpdate = ['userid' => session('userid')];
	$cart = Db::name('cart')->where('id', $order['cartid'])->find();
	$server = $cart ? Db::name('server')->where('id', $cart['serverid'])->find() : null;
	if ($server && !empty($server['serverplugins'])) {
		$pluginFile = PATH . "plugins/host/" . $server['serverplugins'] . "/" . $server['serverplugins'] . ".php";
		if (file_exists($pluginFile)) {
			include_once $pluginFile;
			$fn = $server['serverplugins'] . "_ChangePassword";
			if (function_exists($fn)) {
				$res = @$fn($server, $order, $newPassword);
				if (is_array($res) && isset($res['code']) && $res['code'] == 1) {
					$orderUpdate['password'] = $newPassword;
				}
			}
		}
	}
	Db::name('order')->where('id', $transfer['order_id'])->update($orderUpdate);
	
	// 更新转让状态
	Db::name('host_transfer')->where('id', $transferId)->update([
		'status' => 1,
		'buyer_userid' => session('userid'),
		'updated_at' => $now,
	]);
	
	return json(['code' => '1', 'msg' => '主机转让成功！']);
}

// 联系卖家
public function transferContact() {
	ensure_host_transfer_table();
	$transferId = intval(input('transfer_id'));
	$transfer = Db::name('host_transfer')
		->alias('t')
		->join('user u', 't.userid = u.id')
		->where('t.id', $transferId)
		->field('t.*, u.user as seller_name, u.qq as seller_qq, u.mail as seller_mail')
		->find();
	if (!$transfer) {
		return json(['code' => '-1', 'msg' => '转让信息不存在']);
	}
	return json([
		'code' => '1',
		'msg' => 'ok',
		'seller_name' => $transfer['seller_name'],
		'seller_qq' => $transfer['seller_qq'] ?: '',
		'contact_info' => $transfer['contact_info'] ?: '卖家未填写联系方式',
	]);
}

// 获取转让主机配置详情
public function transferDetail() {
	ensure_host_transfer_table();
	$transferId = intval(input('transfer_id'));
	if ($transferId <= 0) {
		return json(['code' => '-1', 'msg' => '参数错误']);
	}
	try {
		$transfer = Db::name('host_transfer')
			->alias('t')
			->join('order o', 't.order_id = o.id')
			->join('cart c', 'o.cartid = c.id')
			->join('user u', 't.userid = u.id')
			->join('server s', 'c.serverid = s.id', 'LEFT')
			->where('t.id', $transferId)
			->where('t.status', '0')
			->field('t.*, o.user as host_user, o.atime, o.ztime, o.state, c.name as product_name, c.money as product_money, c.serverid, c.content as product_desc, u.user as seller_name, u.qq as seller_qq, s.name as server_name')
			->find();
		if (!$transfer) {
			return json(['code' => '-1', 'msg' => '转让信息不存在或已失效']);
		}
		return json([
			'code' => '1',
			'msg' => 'ok',
			'data' => $transfer,
		]);
	} catch (\Exception $e) {
		return json(['code' => '-1', 'msg' => '查询失败：' . $e->getMessage()]);
	}
}

// 取消转让
public function transferCancel() {
	ensure_host_transfer_table();
	if (!Request::instance()->isPost()) {
		return json(['code' => '-1', 'msg' => '非法请求']);
	}
	$transferId = intval(input('transfer_id'));
	$transfer = Db::name('host_transfer')->where([
		'id' => $transferId,
		'userid' => session('userid'),
		'status' => '0',
	])->find();
	if (!$transfer) {
		return json(['code' => '-1', 'msg' => '转让记录不存在或已处理']);
	}
	Db::name('host_transfer')->where('id', $transferId)->update([
		'status' => 3,
		'updated_at' => time(),
	]);
	return json(['code' => '1', 'msg' => '已取消转让']);
}

// 发送转让邮箱验证码（独立接口）
public function transferSendCode() {
	$userInfo = Db::name('user')->where('id', session('userid'))->find();
	if (empty($userInfo['mail'])) {
		return json(['code' => '-1', 'msg' => '请先绑定邮箱后再操作']);
	}
	// 60秒发送频率限制
	$lastSend = session('transfer_email_time');
	if ($lastSend && time() - $lastSend < 60) {
		return json(['code' => '-1', 'msg' => '发送频率过快，请' . (60 - (time() - $lastSend)) . '秒后再试']);
	}
	$code = random(6, '0123456789');
	session('transfer_email_code', $code);
	session('transfer_email_time', time());
	$codeBody = "<p>您好，</p><p>您正在 {$this->web['name']} 发起主机转让，本次验证码为：</p><p style='text-align:center;margin:28px 0;'><span style='display:inline-block;background:#eff6ff;color:#2563eb;font-size:28px;font-weight:700;padding:14px 32px;border-radius:10px;letter-spacing:4px;border:1px solid #bfdbfe;'>{$code}</span></p><p style='color:#64748b;font-size:13px;'>验证码 10 分钟内有效。如非本人操作，请忽略此邮件。</p>";
	if ($this->web["email"] == "1") {
		$this->email($userInfo['mail'], "主机转让验证码", $codeBody);
	}
	return json(['code' => '1', 'msg' => '验证码已发送至邮箱']);
}

// 发送对话消息
public function transferSendMsg() {
	ensure_host_transfer_message_table();
	ensure_host_transfer_table();
	if (!Request::instance()->isPost()) {
		return json(['code' => '-1', 'msg' => '非法请求']);
	}
	$transferId = intval(input('transfer_id'));
	$content = trim(input('content'));
	if ($transferId <= 0 || empty($content)) {
		return json(['code' => '-1', 'msg' => '参数错误']);
	}
	if (mb_strlen($content) > 500) {
		return json(['code' => '-1', 'msg' => '消息内容不能超过500字']);
	}
	
	$transfer = Db::name('host_transfer')->where('id', $transferId)->where('status', '0')->find();
	if (!$transfer) {
		return json(['code' => '-1', 'msg' => '该转让已失效']);
	}
	
	$myUserId = intval(session('userid'));
	$sellerId = intval($transfer['userid']);
	
	// 只有买卖双方可以发消息
	if ($myUserId != $sellerId) {
		// 买家：必须对卖家发
		$receiverId = $sellerId;
	} else {
		// 卖家：发给最后一条消息的发送方（买家）；如果还没人问过，不能主动发
		$lastMsg = Db::name('host_transfer_message')
			->where('transfer_id', $transferId)
			->where('sender_id', '<>', $sellerId)
			->order('id desc')
			->find();
		if (!$lastMsg) {
			return json(['code' => '-1', 'msg' => '暂无买家咨询，无法主动发送']);
		}
		$receiverId = intval($lastMsg['sender_id']);
	}
	
	if ($receiverId == $myUserId) {
		return json(['code' => '-1', 'msg' => '不能给自己发消息']);
	}
	
	$now = time();
	Db::name('host_transfer_message')->insert([
		'transfer_id' => $transferId,
		'sender_id' => $myUserId,
		'receiver_id' => $receiverId,
		'content' => $content,
		'is_read' => 0,
		'created_at' => $now,
	]);
	
	return json([
		'code' => '1',
		'msg' => '发送成功',
		'data' => [
			'id' => Db::name('host_transfer_message')->getLastInsID(),
			'content' => $content,
			'sender_id' => $senderId,
			'created_at' => $now,
		]
	]);
}

// 获取对话消息列表
public function transferGetMsgs() {
	ensure_host_transfer_message_table();
	$transferId = intval(input('transfer_id'));
	$lastId = intval(input('last_id'));
	if ($transferId <= 0) {
		return json(['code' => '-1', 'msg' => '参数错误']);
	}
	
	$myUserId = intval(session('userid'));
	
	$query = Db::name('host_transfer_message')
		->alias('m')
		->join('user u', 'm.sender_id = u.id')
		->where('m.transfer_id', $transferId)
		->where(function($q) use ($myUserId) {
			$q->where('m.sender_id', $myUserId)
			  ->whereOr('m.receiver_id', $myUserId);
		});
	
	if ($lastId > 0) {
		$query->where('m.id', '>', $lastId);
	}
	
	$msgs = $query->field('m.*, u.user as sender_name, u.qq as sender_qq')
		->order('m.id asc')
		->limit(50)
		->select();
	
	// 标记对方发来的消息为已读
	$unreadIds = [];
	foreach ($msgs as $msg) {
		if ($msg['receiver_id'] == $myUserId && $msg['is_read'] == 0) {
			$unreadIds[] = $msg['id'];
		}
	}
	if (!empty($unreadIds)) {
		Db::name('host_transfer_message')->where('id', 'in', $unreadIds)->update(['is_read' => 1]);
	}
	
	foreach ($msgs as &$msg) {
		$msg['is_mine'] = ($msg['sender_id'] == $myUserId);
		$msg['time_str'] = date('H:i', $msg['created_at']);
		// 兼容 QQ 聚合登录：qq 字段可能是 openid 令牌，头像统一走 get_user_avatar
		$senderUser = ['qq' => $msg['sender_qq']];
		$msg['sender_avatar'] = get_user_avatar($senderUser);
	}
	unset($msg);
	
	return json(['code' => '1', 'data' => $msgs]);
}

// 获取未读消息数量
public function transferUnreadCount() {
	ensure_host_transfer_message_table();
	$myUserId = intval(session('userid'));
	$count = Db::name('host_transfer_message')
		->where('receiver_id', $myUserId)
		->where('is_read', 0)
		->count();
	return json(['code' => '1', 'count' => $count]);
}

// 强制QQ群卡密验证
public function qqGroupVerify() {
	$array = ['code' => '-1', 'msg' => ''];
	if (!Request::instance()->isPost()) {
		$array['msg'] = '非法请求';
		return json($array);
	}
	$key = trim(input('key', ''));
	if (empty($key)) {
		$array['msg'] = '请输入卡密';
		return json($array);
	}
	$configKey = isset($this->web['force_qq_group_key']) ? trim($this->web['force_qq_group_key']) : '';
	if (empty($configKey)) {
		$array['msg'] = '系统未配置卡密，请联系管理员';
		return json($array);
	}
	if ($key === $configKey) {
		Db::name('user')->where('id', session('userid'))->update(['force_qq_group_verified' => 1]);
		$array['code'] = '1';
		$array['msg'] = '验证成功，永久有效';
	} else {
		$array['msg'] = '卡密错误，请检查后重试';
	}
	return json($array);
}

// ========== 梦娜宝塔违规通知对接 ==========
// 接收梦娜宝塔推送的违规主机通知
public function violationNotify() {
	$action = input('action', '');
	$hostUser = input('host_user', '');
	$hostId = input('host_id', 0);
	$violationType = input('violation_type', '');
	$confidence = input('confidence', 0);
	$filePath = input('file_path', '');
	$actionTaken = input('action_taken', '');
	$timestamp = input('timestamp', '');
	$secret = input('secret', '');
	
	// 验证签名
	$server = Db::name('server')->where('serverplugins', 'mnbt')->find();
	if (!$server) {
		return json(['code' => 100, 'msg' => '未找到MNBT服务器配置']);
	}
	$expectedSecret = md5($hostUser . ($server['security'] ?? '') . date('Ymd', strtotime($timestamp)));
	if ($secret !== $expectedSecret) {
		return json(['code' => 100, 'msg' => '签名验证失败']);
	}
	
	// 查找对应的主机订单
	$order = Db::name('order')
		->alias('o')
		->join('cart c', 'o.cartid = c.id')
		->join('server s', 'c.serverid = s.id')
		->where('o.user', $hostUser)
		->where('s.serverplugins', 'mnbt')
		->where('o.state', '1')
		->order('o.id desc')
		->field('o.id, o.user, o.userid, o.state')
		->find();
	
	if (!$order) {
		// 记录违规事件日志
		$logData = [
			'host_user' => $hostUser,
			'host_id' => $hostId,
			'violation_type' => $violationType,
			'confidence' => $confidence,
			'file_path' => $filePath,
			'action_taken' => $actionTaken,
			'sync_time' => $timestamp,
			'order_found' => 0,
		];
		// 确保日志表存在
		$this->ensureViolationLogTable();
		Db::name('host_violation_log')->insert($logData);
		return json(['code' => 200, 'msg' => '已记录，但未找到对应订单']);
	}
	
	// 记录违规事件
	$logData = [
		'host_user' => $hostUser,
		'host_id' => $hostId,
		'order_id' => $order['id'],
		'user_id' => $order['userid'],
		'violation_type' => $violationType,
		'confidence' => $confidence,
		'file_path' => $filePath,
		'action_taken' => $actionTaken,
		'sync_time' => $timestamp,
		'order_found' => 1,
	];
	$this->ensureViolationLogTable();
	Db::name('host_violation_log')->insert($logData);
	
	// 根据处理动作更新订单状态
	if ($actionTaken === 'suspend') {
		Db::name('order')->where('id', $order['id'])->update(['state' => '2']); // 暂停
	} elseif ($actionTaken === 'delete') {
		Db::name('order')->where('id', $order['id'])->update(['state' => '3']); // 终止
	}
	
	return json(['code' => 200, 'msg' => '同步成功']);
}

// 确保违规日志表存在
private function ensureViolationLogTable() {
	$sql = "CREATE TABLE IF NOT EXISTS `host_violation_log` (
		`id` INT(11) NOT NULL AUTO_INCREMENT,
		`host_user` VARCHAR(250) NOT NULL COMMENT '主机账号',
		`host_id` INT(11) NOT NULL DEFAULT 0 COMMENT '主机ID',
		`order_id` INT(11) NOT NULL DEFAULT 0 COMMENT '订单ID',
		`user_id` INT(11) NOT NULL DEFAULT 0 COMMENT '用户ID',
		`violation_type` VARCHAR(100) NOT NULL DEFAULT '' COMMENT '违规类型',
		`confidence` DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT '置信度',
		`file_path` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '文件路径',
		`action_taken` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '处理动作',
		`order_found` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否找到订单',
		`sync_time` DATETIME COMMENT '同步时间',
		`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY (`id`),
		KEY `idx_host_user` (`host_user`),
		KEY `idx_order_id` (`order_id`)
	) ENGINE=MyISAM DEFAULT CHARSET=utf8 COMMENT='主机违规通知日志表';";
	Db::execute($sql);
}

// ========== 主机实时状态代理（解决CORS跨域问题） ==========
public function hostStatusProxy() {
	$array = ['code' => '-1', 'msg' => ''];
	if (!Request::instance()->isPost()) {
		$array['msg'] = '非法请求';
		return json($array);
	}
	
	$orderId = intval(input('order_id'));
	$hostUser = input('host_user', '');
	
	// 从订单获取服务器信息
	$order = Db::name('order')
		->alias('o')
		->join('cart c', 'o.cartid = c.id')
		->join('server s', 'c.serverid = s.id')
		->where('o.id', $orderId)
		->where('o.userid', session('userid'))
		->field('o.id, o.user, o.userid, s.host, s.port, s.ssl, s.user as bt_bh, s.security, s.password as bt_keye, s.data1')
		->find();
	
	if (!$order) {
		$array['msg'] = '订单不存在';
		return json($array);
	}
	
	// 构建API URL
	$protocol = ($order['ssl'] == '1') ? 'https' : 'http';
	$apiUrl = $protocol . '://' . $order['host'] . ':' . $order['port'] . '/api/api.php';
	$version = $order['data1'] ?: '20';
	
	// 构建请求参数
	$postData = [
		'username' => $hostUser ?: $order['user'],
		'mn_bh' => $order['bt_bh'],
		'mn_key' => $order['security'],
		'mn_keye' => $order['bt_keye'],
		'mn_vs' => $version,
	];
	
	$queryString = 'gn=host_status';
	
	try {
		$ch = curl_init($apiUrl . '?' . $queryString);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 15);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		$response = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curlError = curl_error($ch);
		curl_close($ch);
		
		if ($curlError) {
			return json(['code' => '-1', 'msg' => '连接失败: ' . $curlError]);
		}
		
		$data = json_decode($response, true);
		if (!$data) {
			return json(['code' => '-1', 'msg' => 'API返回格式错误: ' . substr($response, 0, 200)]);
		}
		
		return json($data);
	} catch (\Exception $e) {
		return json(['code' => '-1', 'msg' => '请求异常: ' . $e->getMessage()]);
	}
}

// ==================== 用户开发者中心 ====================

// 开发者中心页面
public function developer() {
		ensure_api_keys_table();
		$keys = Db::name('api_keys')
			->where('userid', session('userid'))
			->order('id desc')
			->select();
		$apiBase = (isHTTPS() ? 'https://' : 'http://') . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost') . '/api';
		return $this->fetch('/'.$this->web["template"].'/user/developer', [
			'keys' => $keys,
			'apiBase' => $apiBase,
			'active' => 'developer',
		]);
	}

	// 申请专属密钥
	public function developerCreateKey() {
		$array = ['code' => '-1', 'msg' => ''];
		if (!Request::instance()->isPost()) {
			$array['msg'] = '非法请求';
			return json($array);
		}
		ensure_api_keys_table();
		$name = htmlspecialchars(trim(input('name', '')));
		if ($name == '') {
			$array['msg'] = '请填写密钥名称';
			return json($array);
		}
		$count = Db::name('api_keys')->where('userid', session('userid'))->count();
		if ($count >= 20) {
			$array['msg'] = '密钥数量已达上限(20个)，请先删除不再使用的密钥';
			return json($array);
		}
		// 生成唯一密钥（LHX- 前缀 + 48位随机字符）
		do {
			$apiKey = 'LHX-' . random(48);
		} while (Db::name('api_keys')->where('api_key', $apiKey)->find());
		$id = Db::name('api_keys')->insertGetId([
			'userid' => session('userid'),
			'name' => $name,
			'api_key' => $apiKey,
			'status' => 1,
			'last_used_at' => 0,
			'created_at' => time(),
		]);
		if ($id) {
			$array['code'] = '1';
			$array['msg'] = '密钥创建成功，请立即复制保存（仅完整显示一次）';
			$array['api_key'] = $apiKey;
		} else {
			$array['msg'] = '创建失败，请稍后重试';
		}
		return json($array);
	}

	// 删除密钥
	public function developerDeleteKey() {
		$array = ['code' => '-1', 'msg' => ''];
		if (!Request::instance()->isPost()) {
			$array['msg'] = '非法请求';
			return json($array);
		}
		ensure_api_keys_table();
		$id = intval(input('id', 0));
		if ($id <= 0) {
			$array['msg'] = '参数错误';
			return json($array);
		}
		$data = Db::name('api_keys')->where('id', $id)->where('userid', session('userid'))->delete();
		if ($data !== false) {
			$array['code'] = '1';
			$array['msg'] = '删除成功';
		} else {
			$array['msg'] = '删除失败';
		}
		return json($array);
	}

	// 启用/停用密钥
	public function developerToggleKey() {
		$array = ['code' => '-1', 'msg' => ''];
		if (!Request::instance()->isPost()) {
			$array['msg'] = '非法请求';
			return json($array);
		}
		ensure_api_keys_table();
		$id = intval(input('id', 0));
		$status = intval(input('status', 1));
		$key = Db::name('api_keys')->where('id', $id)->where('userid', session('userid'))->find();
		if (!$key) {
			$array['msg'] = '密钥不存在';
			return json($array);
		}
		Db::name('api_keys')->where('id', $id)->where('userid', session('userid'))->update([
			'status' => $status ? 1 : 0,
		]);
		$array['code'] = '1';
		$array['msg'] = $status ? '已启用' : '已停用';
		return json($array);
	}

}