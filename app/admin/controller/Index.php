<?php
namespace app\admin\controller;
use think\Controller;
use think\Db;
use think\Request;
use PHPMailer\PHPMailer\PHPMailer;

class Index extends Controller
{
    protected $hasFullAccess = false;

public function _initialize() {
		if(!session("adminid")) {
			// 兼容「后台登录入口自定义」：跳转到真实入口地址（默认 /admin/login 已被隐藏）
			$this->redirect(function_exists('admin_login_url') ? admin_login_url() : url('admin/login/index'));
		}
		$this->user=Db::name('admin')->where('id',session("adminid"))->find();
		$this->web=web_config();
		// 如果数据库中仍配置为旧版 layui 后台主题，强制使用已重构的 default 主题
		if($this->web["admintemplate"]=="layui"){
			$this->web["admintemplate"]="default";
		}
$file=file_exists(PATH."/app/index/view/".$this->web["template"]."/set.php");
if($file){
$templateset="1";
}else{
$templateset="0";
}
		// 确保管理员表字段完整（先于角色表，确保 admin.role_id 字段存在）
		ensure_admin_columns();
		// 确保 admin_role 表存在且为两角色体系（超级管理员/普通管理员），含旧版自动迁移
		ensure_admin_role_table();
		ensure_admin_op_log_table();
		ensure_web_bg_column();
		ensure_order_ordernumber_column();

		// 重新读取管理员信息（字段补全后）
		$this->user = Db::name('admin')->where('id', session("adminid"))->find();

		// 安全兜底：第一个管理员（ID=1）始终拥有全部权限，防止误锁后台
		if ($this->user['id'] == 1 && (!$this->user['is_super'] || $this->user['role_id'] != 1)) {
			try {
				Db::name('admin')->where('id', $this->user['id'])->update([
					'is_super' => 1,
					'role_id'  => 1,
				]);
				$this->user['is_super'] = 1;
				$this->user['role_id']  = 1;
			} catch (\Exception $e) {
				// 更新失败不影响后续流程
			}
		}

		// 自动添加 qq_group 字段（如果不存在），避免 ALTER 报错
		try {
			$webTableName = Db::name('web')->getTable();
			$cols = Db::query("SHOW COLUMNS FROM `{$webTableName}` LIKE 'qq_group'");
			if (empty($cols)) {
				Db::execute("ALTER TABLE `{$webTableName}` ADD COLUMN `qq_group` varchar(255) NOT NULL DEFAULT '' COMMENT 'QQ官方群号或链接' AFTER `telecom_license`");
			}
		} catch (\Exception $e) {
			// 字段添加失败，不影响后续流程
		}

		// 自动添加弹窗公告相关字段（如果不存在）
		try {
			$webTableName = Db::name('web')->getTable();

			$cols = Db::query("SHOW COLUMNS FROM `{$webTableName}` LIKE 'popup_notice'");
			if (empty($cols)) {
				Db::execute("ALTER TABLE `{$webTableName}` ADD COLUMN `popup_notice` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否启用弹窗公告' AFTER `global_datacenters`");
			}

			$cols = Db::query("SHOW COLUMNS FROM `{$webTableName}` LIKE 'popup_title'");
			if (empty($cols)) {
				Db::execute("ALTER TABLE `{$webTableName}` ADD COLUMN `popup_title` varchar(255) NOT NULL DEFAULT '' COMMENT '弹窗公告标题' AFTER `popup_notice`");
			}

			$cols = Db::query("SHOW COLUMNS FROM `{$webTableName}` LIKE 'popup_content'");
			if (empty($cols)) {
				Db::execute("ALTER TABLE `{$webTableName}` ADD COLUMN `popup_content` text COMMENT '弹窗公告 HTML 内容' AFTER `popup_title`");
			}
		} catch (\Exception $e) {
			// 字段添加失败，不影响后续流程
		}

		// 自动添加 disposable_email_block 字段（防临时邮箱注册）
		try {
			$webTableName = Db::name('web')->getTable();
			$cols = Db::query("SHOW COLUMNS FROM `{$webTableName}` LIKE 'disposable_email_block'");
			if (empty($cols)) {
				Db::execute("ALTER TABLE `{$webTableName}` ADD COLUMN `disposable_email_block` tinyint(1) NOT NULL DEFAULT '0' COMMENT '防临时邮箱注册' AFTER `yxdl`");
			}
		} catch (\Exception $e) {
			// 字段添加失败，不影响后续流程
		}

		// 自动将 web 表转换为 utf8mb4，以支持 Emoji 等 4 字节 UTF-8 字符
		try {
			$webTableName = Db::name('web')->getTable();
			$tableInfo = Db::query("SHOW TABLE STATUS LIKE '{$webTableName}'");
			if (!empty($tableInfo) && isset($tableInfo[0]['Collation']) && strpos($tableInfo[0]['Collation'], 'utf8mb4') === false) {
				Db::execute("ALTER TABLE `{$webTableName}` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
			}
		} catch (\Exception $e) {
			// 字符集转换失败，不影响后续流程
		}

		// 超级管理员或超级管理员角色直接放行
		$this->hasFullAccess = ($this->user['is_super'] == 1 || $this->user['role_id'] == 1);

		// 统一权限计算：超级管理员全权限；普通管理员读单个勾选权限，回退角色权限
		$adminPermissions = get_admin_permissions($this->user);
		$this->assign([
		            'webname'  => $this->web['name'],
		"user"=>$this->user,
        "templateset"=>$templateset,
        "adminPermissions"=>$adminPermissions,
        "csrf_token"=>csrf_token(),
		        ]);
	}

	protected function checkPermission($permission) {
		// 超级管理员或超级管理员角色 grant 全部权限
		if ($this->hasFullAccess) return true;

		$permissions = get_admin_permissions($this->user);
		return in_array('all', $permissions) || in_array($permission, $permissions);
	}

	/**
	 * 更新实名认证记录
	 * 优先更新该用户最新的待审核记录；如不存在则插入一条新记录，保留历史审核轨迹
	 */
	protected function updateRealnameRecord($userId, $status, $reviewerId = 0, $reviewerName = '') {
		try {
			$record = Db::name('realname_record')
				->where('user_id', $userId)
				->where('status', '3')
				->order('id desc')
				->find();
			if ($record) {
				Db::name('realname_record')->where('id', $record['id'])->update([
					'status' => $status,
					'review_time' => time(),
					'reviewer_id' => $reviewerId,
					'reviewer_name' => $reviewerName,
				]);
			} else {
				$user = Db::name('user')->where('id', $userId)->find();
				if ($user) {
					Db::name('realname_record')->insert([
						'user_id' => $userId,
						'realname' => $user['realname'] ?? '',
						'idcard' => $user['idcard'] ?? '',
						'status' => $status,
						'apply_time' => $user['last_login_time'] ?? time(),
						'review_time' => time(),
						'reviewer_id' => $reviewerId,
						'reviewer_name' => $reviewerName,
					]);
				}
			}
		} catch (\Exception $e) {
			// 记录更新失败不影响主流程
		}
	}


public function sq($id=null){
if($id){
$data1=Db::name('sq')->where("id",$id)->find();
if($data1){
if(Request::instance()->isPost()) {
$info=input("post.");
if($info["domain"]==""){
$array["code"]="-1";
$array["msg"]="必填参数不可为空!";
}else{
$data4=Db::name('sq')->where("id",$id)->update($info);
if($data4){
$array["code"]="1";
$array["msg"]="修改成功!";
}else{
$array["code"]="-1";
$array["msg"]="修改失败!";
}
}
return json($array);
}
return $this->fetch('/'.$this->web["admintemplate"]."/sqs",[
"sq"=>$data1,
]);
}else{
$this->redirect('/admin/sq');
}
}else{
if(Request::instance()->isPost()) {
$act=input("act");
if($act=="add"){
$info=input("post.");
if($info["domain"]==""){
$array["code"]="-1";
$array["msg"]="必填参数不可为空!";
}else{
unset($info["act"]);
$info["time"]=time();
$data1=Db::name('sq')->insertGetId($info);
if($data1){
$array["code"]="1";
$array["msg"]="添加成功!";
}else{
$array["code"]="-1";
$array["msg"]="添加失败!";
}
}
return json($array);
}
if($act=="delete"){
$cid=input("cid");
if($cid==""){
$array["code"]="-1";
$array["msg"]="必填参数不可为空!";
}else{
$cid=explode(",",input("cid"));
$cid=array_filter($cid);
// 优化：批量删除，避免 N+1 查询
$a="0"; $b="0";
if(!empty($cid)){
	$deleted=Db::name("sq")->where("id","in",$cid)->delete();
	$a=(string)$deleted;
	$b=(string)(count($cid)-$deleted);
}
$c="";
if($b>0){
$c="<br/>失败原因:已经删除过了!";
}
$array["code"]="1";
$array["msg"]="成功:".$a.";失败:".$b.$c;
}
return json($array);
}

if(input("act")=="qbdelete"){
// 优化：单条 SQL 查询所有 id，单条 DELETE 批量删除
$ids=Db::name("sq")->column('id');
$total=count($ids);
$a="0"; $b="0";
if($total>0){
	$deleted=Db::name("sq")->where("id","in",$ids)->delete();
	$a=(string)$deleted;
	$b=(string)($total-$deleted);
}
$c="";
if($b>0){
$c="<br/>失败原因:已删除过此条记录了!";
}
$array["code"]="1";
$array["msg"]="成功:".$a.";失败:".$b.$c;
return json($array);
}
}
$search=input("search");
if($search){
$data=Db::name('sq')->whereor("id", 'like', '%'.$search.'%')->whereor("domain", 'like', '%'.$search.'%')->whereor("ip", 'like', '%'.$search.'%')->whereor("qq", 'like', '%'.$search.'%')->order('id desc')->paginate(10,false,['query'=>request()->param()]);
}else{
$data=Db::name('sq')->order('id desc')->paginate(10);
}
return $this->fetch('/'.$this->web["admintemplate"]."/sq",[
"sq"=>$data,
]);
}
}
    public function index()
    {
        // 确保购物车表存在（兼容全新安装后未访问过用户中心）
        ensure_shopping_cart_table();

        $now = time();
        $todayStart = strtotime(date('Y-m-d 00:00:00'));
        $monthStart = strtotime(date('Y-m-01 00:00:00'));

        // 用户数量
        $usercount = Db::name('user')->count();
        // 今日新增用户
        $usercountToday = Db::name('user')->where('time', '>=', $todayStart)->count();

        // 工单统计
        $ticketcount1 = Db::name('ticket')->where("state","1")->count();
        $ticketcount2 = Db::name('ticket')->where("state","2")->count();

        // 订单统计（time 字段为 varchar 时间戳）
        $orderToday = Db::name('order')->where('atime', '>=', $todayStart)->where('atime', '<=', $now)->count();
        $orderMonth = Db::name('order')->where('atime', '>=', $monthStart)->where('atime', '<=', $now)->count();
        $hostActive = Db::name('order')->where('state', '1')->count();
        $hostCreating = Db::name('order')->where('state', '0')->count();
        $hostPending = Db::name('shopping_cart')->where('status', '0')->count();
        $hostExpired = Db::name('order')->where('state', '2')->count();

        // 收入统计（pay.time 为 varchar 时间戳，money 为 varchar）
        $paymoney = Db::name('pay')->where("state", "1")->sum('money');
        $paymoney1 = Db::name('pay')->where("state", "1")
            ->where('time', '>=', $todayStart)->where('time', '<=', $now)->sum('money');
        $paymoneyMonth = Db::name('pay')->where("state", "1")
            ->where('time', '>=', $monthStart)->where('time', '<=', $now)->sum('money');
        $paymoney = $paymoney ?: 0;
        $paymoney1 = $paymoney1 ?: 0;
        $paymoneyMonth = $paymoneyMonth ?: 0;

        // 最近7天订单趋势
        $orderTrendLabels = [];
        $orderTrendData = [];
        for ($i = 6; $i >= 0; $i--) {
            $dayStart = strtotime(date('Y-m-d 00:00:00', strtotime("-{$i} days")));
            $dayEnd = strtotime(date('Y-m-d 23:59:59', strtotime("-{$i} days")));
            $orderTrendLabels[] = date('m-d', $dayStart);
            $orderTrendData[] = Db::name('order')
                ->where('atime', '>=', $dayStart)
                ->where('atime', '<=', $dayEnd)
                ->count();
        }

        // 最近7天充值趋势与新增用户趋势
        $payTrendData = [];
        $userTrendData = [];
        for ($i = 6; $i >= 0; $i--) {
            $dayStart = strtotime(date('Y-m-d 00:00:00', strtotime("-{$i} days")));
            $dayEnd = strtotime(date('Y-m-d 23:59:59', strtotime("-{$i} days")));
            $payTrendData[] = floatval(Db::name('pay')->where('state', '1')
                ->where('time', '>=', $dayStart)->where('time', '<=', $dayEnd)->sum('money') ?: 0);
            $userTrendData[] = Db::name('user')
                ->where('time', '>=', $dayStart)->where('time', '<=', $dayEnd)->count();
        }

        // 实名认证统计（必须先补列，否则全新库缺 realname_status 会 1054）
        ensure_realname_record_table();
        ensure_user_columns();
        $realnameVerified = Db::name('user')->where('realname_status', '1')->count();
        $realnamePending = Db::name('user')->where('realname_status', '3')->count();
        $realnameUnverified = $usercount - $realnameVerified - $realnamePending;
        if ($realnameUnverified < 0) $realnameUnverified = 0;

        // 今日登录用户数
        $userLoginToday = Db::name('user')->where('last_login_time', '>=', $todayStart)->count();

        // 待处理工单 / 待审核实名
        $ticketPending = Db::name('ticket')->where('state', '1')->count();

        // 卡密统计
        $cdkeyTotal = 0; $cdkeyUsed = 0;
        try {
            $cdkeyTotal = Db::name('cdkey')->count();
            $cdkeyUsed = Db::name('cdkey')->where('state', '1')->count();
        } catch (\Exception $e) {}

        // 确保用户表包含 last_login_region 等字段
        ensure_user_columns();
        // 根据已有 IP 补全用户地区（IP 地理位置属于无关数据，默认关闭，可在「记录设置」开启）
        $geoOn = function_exists('sys_record_opt') ? sys_record_opt('rec_geo') : null;
        if ($geoOn === null) $geoOn = '0';
        if (intval($geoOn)) {
            refresh_user_regions(50);
        }
        // 每日核心数据快照（一天仅写一条）
        if (function_exists('sys_record_daily_snapshot')) {
            sys_record_daily_snapshot();
        }
        // 确保购物车表存在
        ensure_shopping_cart_table();
        // 处理待开通订单（无 cron 时作为兜底）
        process_pending_host_orders();

        // 最近10条订单
        $userTable = Db::name('user')->getTable();
        $cartTable = Db::name('cart')->getTable();
        $recentOrders = Db::name('order')
            ->alias('o')
            ->field('o.id,o.user,o.userid,o.cartid,o.atime,o.state,u.user as username,c.name as cartname')
            ->join($userTable . ' u', 'o.userid = u.id', 'LEFT')
            ->join($cartTable . ' c', 'o.cartid = c.id', 'LEFT')
            ->order('o.id desc')
            ->limit(10)
            ->select();
        if (empty($recentOrders)) {
            $recentOrders = [];
        }

        $invalidRegions = ['', '本地', '未知'];

        // 用户地区分布（用于中国地图）
        $userRegionRows = Db::name('user')
            ->field('last_login_region as region, count(*) as total')
            ->where('last_login_region', 'not in', $invalidRegions)
            ->group('last_login_region')
            ->select();
        $userRegionData = [];
        foreach ($userRegionRows as $row) {
            $region = normalize_province_name($row['region']);
            if (!isset($userRegionData[$region])) $userRegionData[$region] = 0;
            $userRegionData[$region] += intval($row['total']);
        }

        // 各地区购买量（订单数）
        $orderRegionRows = Db::name('order')
            ->alias('o')
            ->join($userTable . ' u', 'o.userid = u.id', 'LEFT')
            ->field('u.last_login_region as region, count(*) as total')
            ->where('u.last_login_region', 'not in', $invalidRegions)
            ->group('u.last_login_region')
            ->select();
        $orderRegionData = [];
        foreach ($orderRegionRows as $row) {
            $region = normalize_province_name($row['region']);
            if (!isset($orderRegionData[$region])) $orderRegionData[$region] = 0;
            $orderRegionData[$region] += intval($row['total']);
        }

        // 各地区充值金额
        $payRegionRows = Db::name('pay')
            ->alias('p')
            ->join($userTable . ' u', 'p.userid = u.id', 'LEFT')
            ->field('u.last_login_region as region, sum(p.money) as total')
            ->where('p.state', '1')
            ->where('u.last_login_region', 'not in', $invalidRegions)
            ->group('u.last_login_region')
            ->select();
        $payRegionData = [];
        foreach ($payRegionRows as $row) {
            $region = normalize_province_name($row['region']);
            if (!isset($payRegionData[$region])) $payRegionData[$region] = 0;
            $payRegionData[$region] += round(floatval($row['total']), 2);
        }

        return $this->fetch('/'.$this->web["admintemplate"]."/index",[
            "usercount"=>$usercount,
            "usercountToday"=>$usercountToday,
            "ticketcount"=>$ticketcount1+$ticketcount2,
            "orderToday"=>$orderToday,
            "orderMonth"=>$orderMonth,
            "hostActive"=>$hostActive,
            "hostCreating"=>$hostCreating,
            "hostPending"=>$hostPending,
            "hostExpired"=>$hostExpired,
            "paymoney"=>$paymoney,
            "paymoney1"=>$paymoney1,
            "paymoneyMonth"=>$paymoneyMonth,
            "orderTrendLabels"=>$orderTrendLabels,
            "orderTrendData"=>$orderTrendData,
            "payTrendData"=>$payTrendData,
            "userTrendData"=>$userTrendData,
            "realnameVerified"=>$realnameVerified,
            "realnamePending"=>$realnamePending,
            "realnameUnverified"=>$realnameUnverified,
            "userLoginToday"=>$userLoginToday,
            "ticketPending"=>$ticketPending,
            "cdkeyTotal"=>$cdkeyTotal,
            "cdkeyUsed"=>$cdkeyUsed,
            "recentOrders"=>$recentOrders,
            "userRegionData"=>$userRegionData,
            "orderRegionData"=>$orderRegionData,
            "payRegionData"=>$payRegionData,
            "chinaMapUrl"=>\think\Request::instance()->root() . '/admin/chinamapjson',
            "ajaxMapUrl"=>\think\Request::instance()->root() . '/admin/ajaxmapdata',
            "chinaMapGeoJson"=>file_exists(PATH . 'public/static/map/china.json') ? json_decode(file_get_contents(PATH . 'public/static/map/china.json'), true) : [],
        ]);
    }

public function chinaMapJson() {
    $file = PATH . 'public/static/map/china.json';
    if (file_exists($file)) {
        $content = file_get_contents($file);
        return json(json_decode($content, true), 200, ['Content-Type' => 'application/json']);
    }
    return json(['error' => 'Map file not found'], 404);
}

public function ajaxMapData() {
    $userTable = Db::name('user')->getTable();
    $invalidRegions = ['', '本地', '未知'];

    $userRegionRows = Db::name('user')
        ->field('last_login_region as region, count(*) as total')
        ->where('last_login_region', 'not in', $invalidRegions)
        ->group('last_login_region')
        ->select();
    $userRegionData = [];
    foreach ($userRegionRows as $row) {
        $region = normalize_province_name($row['region']);
        if (!isset($userRegionData[$region])) $userRegionData[$region] = 0;
        $userRegionData[$region] += intval($row['total']);
    }

    $orderRegionRows = Db::name('order')
        ->alias('o')
        ->join($userTable . ' u', 'o.userid = u.id', 'LEFT')
        ->field('u.last_login_region as region, count(*) as total')
        ->where('u.last_login_region', 'not in', $invalidRegions)
        ->group('u.last_login_region')
        ->select();
    $orderRegionData = [];
    foreach ($orderRegionRows as $row) {
        $region = normalize_province_name($row['region']);
        if (!isset($orderRegionData[$region])) $orderRegionData[$region] = 0;
        $orderRegionData[$region] += intval($row['total']);
    }

    $payRegionRows = Db::name('pay')
        ->alias('p')
        ->join($userTable . ' u', 'p.userid = u.id', 'LEFT')
        ->field('u.last_login_region as region, sum(p.money) as total')
        ->where('p.state', '1')
        ->where('u.last_login_region', 'not in', $invalidRegions)
        ->group('u.last_login_region')
        ->select();
    $payRegionData = [];
    foreach ($payRegionRows as $row) {
        $region = normalize_province_name($row['region']);
        if (!isset($payRegionData[$region])) $payRegionData[$region] = 0;
        $payRegionData[$region] += round(floatval($row['total']), 2);
    }

    return json([
        'code' => 1,
        'userRegionData' => $userRegionData,
        'orderRegionData' => $orderRegionData,
        'payRegionData' => $payRegionData,
    ]);
}

// 官网在线实时人数接口
public function ajaxOnlineCount() {
    $online = function_exists('get_online_count') ? get_online_count() : 0;
    $onlineUsers = function_exists('get_online_user_count') ? get_online_user_count() : 0;
    return json(['code' => 1, 'online' => $online, 'online_users' => $onlineUsers]);
}

public function info(){
if(Request::instance()->isPost()) {
$name=input("name");
$mail=input("mail");
$qq=input("qq");
if($name=="" || $mail=="" || $qq==""){
$array["code"]="-1";
$array["msg"]="必填参数不可为空!";
}else{
$data=Db::name('admin')->where('id',$this->user["id"])->update([
"name" =>$name,
"mail"=>$mail,
"qq"=>$qq,
]);
if($data){
$array["code"]="1";
$array["msg"]="修改信息成功!";
}else{
$array["code"]="-1";
$array["msg"]="修改信息失败!";
}
}
return json($array);
}
	
return $this->fetch('/'.$this->web["admintemplate"]."/info");
}



public function password(){
if(Request::instance()->isPost()) {
$oldpassword=input("oldpassword");
$password=input("password");
$newpassword=input("newpassword");
if($oldpassword=="" || $password=="" || $newpassword==""){
$array["code"]="-1";
$array["msg"]="必填参数不可为空!";
}else{
if(!password_verify($oldpassword,$this->user["password"])){
$array["code"]="-1";
$array["msg"]="旧密码错误!";
}else{
if($password!=$newpassword){
$array["code"]="-1";
$array["msg"]="两次输入的新密码不一致!";
}else{
$data=Db::name('admin')->where('id',$this->user["id"])->update([
"password" =>password_hash($password,PASSWORD_DEFAULT),
]);
if($data){
$array["code"]="1";
$array["msg"]="修改密码成功!";
}else{
$array["code"]="-1";
$array["msg"]="修改密码失败!";
}
}
}
}
return json($array);
}
	
return $this->fetch('/'.$this->web["admintemplate"]."/password");
}

public function logout() {
		session("adminid",null);
		// ?out=1：登录页顶部显示「您已安全退出登录」提示条
		$this->redirect(function_exists('admin_login_url') ? admin_login_url('?out=1') : '/admin/login?out=1');
	}


public function set() {
if (!$this->checkPermission('set')) {
    $this->error('您没有权限访问此页面', '/admin/index');
}
if(Request::instance()->isPost()) {
try {
$webinput=input("post.");
// 未提交（未勾选/无选项）的字段补默认值，避免 PHP8 未定义键告警导致保存失败
$webinput['template'] = isset($webinput['template']) ? $webinput['template'] : $this->web['template'];
$webinput['zcyxyz'] = isset($webinput['zcyxyz']) ? $webinput['zcyxyz'] : '0';
$webinput['email'] = isset($webinput['email']) ? $webinput['email'] : '0';
$webinput['yxdl'] = isset($webinput['yxdl']) ? $webinput['yxdl'] : '0';
// 全球数据中心：仅支持 JSON 格式
// 绕过 ThinkPHP default_filter 的 htmlspecialchars 过滤
$webinput['global_datacenters'] = isset($_POST['global_datacenters']) ? trim($_POST['global_datacenters']) : '';

// 弹窗公告标题与内容支持 HTML，使用 $_POST 原始值避免被转义
$webinput['popup_title'] = isset($_POST['popup_title']) ? trim($_POST['popup_title']) : '';
$webinput['popup_content'] = isset($_POST['popup_content']) ? trim($_POST['popup_content']) : '';

// 加载动画内容：使用 $_POST 原始值，避免 default_filter 转义导致双重转义
$loadingKeys = ['loading_logo', 'loading_brand', 'loading_text', 'loading_subtext'];
foreach ($loadingKeys as $lk) {
    $webinput[$lk] = isset($_POST[$lk]) ? trim($_POST[$lk]) : '';
}

// 实名限制开关：未勾选时不提交，需显式置 0
$realnameLimitKeys = ['realname_limit_pay', 'realname_limit_buy', 'realname_limit_ticket', 'realname_limit_renew', 'realname_limit_transfer', 'realname_first_free'];
foreach ($realnameLimitKeys as $rk) {
    if (!isset($webinput[$rk])) {
        $webinput[$rk] = '0';
    }
}

// 三种实名认证方式开关（身份证上传/手机三要素/人工审核），未勾选默认开启
$realnameMethodKeys = ['realname_method_idcard', 'realname_method_phone', 'realname_method_manual'];
foreach ($realnameMethodKeys as $mk) {
    if (!isset($webinput[$mk])) {
        $webinput[$mk] = '1';
    }
}

// 背景音乐设置
$webinput['bg_music_url'] = isset($_POST['bg_music_url']) ? trim($_POST['bg_music_url']) : '';
$webinput['bg_music_enabled'] = isset($_POST['bg_music_enabled']) ? intval($_POST['bg_music_enabled']) : 0;
$webinput['bg_music_volume'] = isset($_POST['bg_music_volume']) ? intval($_POST['bg_music_volume']) : 50;
$webinput['bg_music_volume'] = max(0, min(100, $webinput['bg_music_volume']));

// 高德地图定位 Key（数据大屏用）
$webinput['amap_key'] = isset($_POST['amap_key']) ? trim($_POST['amap_key']) : '';

// 身份证 OCR 配置
$webinput['idcard_ocr_provider'] = isset($_POST['idcard_ocr_provider']) ? trim($_POST['idcard_ocr_provider']) : '0';
$webinput['idcard_ocr_appcode'] = isset($_POST['idcard_ocr_appcode']) ? trim($_POST['idcard_ocr_appcode']) : '';
$webinput['idcard_ocr_secret_id'] = isset($_POST['idcard_ocr_secret_id']) ? trim($_POST['idcard_ocr_secret_id']) : '';
$webinput['idcard_ocr_secret_key'] = isset($_POST['idcard_ocr_secret_key']) ? trim($_POST['idcard_ocr_secret_key']) : '';
$webinput['idcard_ocr_api_url'] = isset($_POST['idcard_ocr_api_url']) ? trim($_POST['idcard_ocr_api_url']) : '';
// 云市场实名认证接口地址：字段不存在时保留原值，避免旧表单把它清空
$webinput['realname_api_url'] = isset($_POST['realname_api_url'])
    ? trim($_POST['realname_api_url'])
    : (isset($this->web['realname_api_url']) ? $this->web['realname_api_url'] : '');

// 域名商城配置
$webinput['domain_market_enabled'] = isset($_POST['domain_market_enabled']) ? intval($_POST['domain_market_enabled']) : 0;

// Docker 容器开通设置
$webinput['docker_enabled']     = (isset($_POST['docker_enabled']) && $_POST['docker_enabled'] == '1') ? '1' : '0';
$webinput['docker_mode']        = (isset($_POST['docker_mode']) && $_POST['docker_mode'] == 'auto') ? 'auto' : 'manual';
$webinput['docker_pay_balance'] = (isset($_POST['docker_pay_balance']) && $_POST['docker_pay_balance'] == '0') ? '0' : '1';
$webinput['docker_pay_online']  = (isset($_POST['docker_pay_online']) && $_POST['docker_pay_online'] == '1') ? '1' : '0';
$webinput['docker_allow_close'] = (isset($_POST['docker_allow_close']) && $_POST['docker_allow_close'] == '0') ? '0' : '1';
$webinput['docker_price']       = round(max(0, floatval(isset($_POST['docker_price']) ? $_POST['docker_price'] : 0)), 2);
$webinput['docker_intro']       = isset($_POST['docker_intro'])
    ? trim($_POST['docker_intro'])
    : (isset($this->web['docker_intro']) ? $this->web['docker_intro'] : '');
// 两种支付方式都不允许时无意义，强制保留余额支付
if ($webinput['docker_pay_balance'] === '0' && $webinput['docker_pay_online'] === '0') {
    $webinput['docker_pay_balance'] = '1';
}

// 液态玻璃总开关（表单同时提交 hidden 0 和 checkbox 1，取最后提交的 checkbox 值）
$webinput['glass_enabled'] = isset($_POST['glass_enabled']) ? intval($_POST['glass_enabled']) : 0;
// 液态玻璃主题配色方案
$webinput['glass_theme'] = isset($_POST['glass_theme']) && in_array($_POST['glass_theme'], ['default', 'pink_blue']) ? $_POST['glass_theme'] : 'default';
// 液态玻璃透明度（30-100，默认72）
$glassOpacity = isset($_POST['glass_opacity']) ? intval($_POST['glass_opacity']) : 72;
$webinput['glass_opacity'] = max(30, min(100, $glassOpacity));
// 轮播背景图清理空行
if (isset($webinput['bg_images'])) {
    $lines = array_filter(array_map('trim', explode("\n", $webinput['bg_images'])));
    $webinput['bg_images'] = implode("\n", $lines);
}
// 背景类型与视频开关
$webinput['bg_type'] = isset($webinput['bg_type']) && in_array($webinput['bg_type'], ['image','video','gif']) ? $webinput['bg_type'] : 'image';
$webinput['bg_video_loop'] = isset($webinput['bg_video_loop']) ? intval($webinput['bg_video_loop']) : 1;
$webinput['bg_video_muted'] = isset($webinput['bg_video_muted']) ? intval($webinput['bg_video_muted']) : 1;

// 校验 JSON 格式
$dcTrimmed = trim($webinput['global_datacenters']);
if ($dcTrimmed !== '') {
    json_decode($dcTrimmed);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return json(['code' => -1, 'msg' => '全球数据中心 JSON 格式错误：' . json_last_error_msg()]);
    }
}
if($this->web["template"]!=$webinput["template"]){
$file1=file_exists(PATH."/app/index/view/".$webinput["template"]."/set.php");
if($file1){
$wj=include_once(PATH."/app/index/view/".$webinput["template"]."/set.php");
$webinput["templateset"]=json_encode($wj);
}else{
$webinput["templateset"]="";
}
}
if($webinput["zcyxyz"]=="1" && $webinput["email"]!="1"){
$array["code"]="-1";
$array["msg"]="修改失败,开启注册邮箱验证需要先开启邮箱通知!";
}else{
if($webinput["yxdl"]=="1" && $webinput["email"]!="1"){
$array["code"]="-1";
$array["msg"]="修改失败,开启邮箱登录需要先开启邮箱通知!";
}else{
unset($webinput['__token__']);
// 后台入口路径校验：仅允许字母数字，禁止与前台/系统路由冲突
if (isset($webinput['admin_path'])) {
    $adminPath = trim((string)$webinput['admin_path']);
    $reserved = ['admin', 'user', 'login', 'register', 'pay', 'api', 'install', 'index', 'product', 'order', 'ticket', 'cart', 'captcha', 'oauth', 'cron', 'sq', 'live2d', 'rankings', 'help', 'announcement', 'announcements', 'pwreset', 'datav', 'server', 'domain', 'aff', 'qrlogin', 'verify_email', 'static', 'upload', 'favicon', 'robots'];
    if ($adminPath !== '' && !preg_match('/^[a-zA-Z0-9]+$/', $adminPath)) {
        return json(['code' => -1, 'msg' => '后台入口路径只能包含字母和数字']);
    }
    if ($adminPath !== '' && in_array($adminPath, $reserved)) {
        return json(['code' => -1, 'msg' => '后台入口路径与现有功能冲突，请更换']);
    }
    $webinput['admin_path'] = $adminPath === '' ? 'admin' : $adminPath;
}
// 确保 Live2D AI 相关列存在
$live2dAiCols = [
    'live2d_ai_enabled' => "ALTER TABLE " . config('database.prefix') . "web ADD COLUMN `live2d_ai_enabled` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'AI聊天开关'",
    'live2d_ai_api_url' => "ALTER TABLE " . config('database.prefix') . "web ADD COLUMN `live2d_ai_api_url` varchar(500) NOT NULL DEFAULT '' COMMENT 'AI API地址'",
    'live2d_ai_api_key' => "ALTER TABLE " . config('database.prefix') . "web ADD COLUMN `live2d_ai_api_key` varchar(500) NOT NULL DEFAULT '' COMMENT 'AI API密钥'",
    'live2d_ai_model' => "ALTER TABLE " . config('database.prefix') . "web ADD COLUMN `live2d_ai_model` varchar(100) NOT NULL DEFAULT 'deepseek-v4-flash' COMMENT 'AI模型'",
    'live2d_ai_persona' => "ALTER TABLE " . config('database.prefix') . "web ADD COLUMN `live2d_ai_persona` TEXT NULL COMMENT 'AI人设（自定义）'",
];
try {
    $existingCols = Db::query("SHOW COLUMNS FROM " . config('database.prefix') . "web");
    $existingNames = array_column($existingCols, 'Field');
    foreach ($live2dAiCols as $colName => $alterSql) {
        if (!in_array($colName, $existingNames)) {
            Db::execute($alterSql);
        }
    }
    // 刷新列列表，确保后续 update 不会因列不存在而失败
    $existingCols = Db::query("SHOW COLUMNS FROM " . config('database.prefix') . "web");
    $existingNames = array_column($existingCols, 'Field');
    foreach (array_keys($live2dAiCols) as $colName) {
        if (!in_array($colName, $existingNames)) {
            unset($webinput[$colName]);
        }
    }
} catch (\Exception $e) {
    // 列创建失败，从表单数据中移除这些字段，避免 update 报错
    foreach (array_keys($live2dAiCols) as $colName) {
        unset($webinput[$colName]);
    }
}
// 确保 glass_theme 列存在
try {
    $columns = Db::query("SHOW COLUMNS FROM " . config('database.prefix') . "web LIKE 'glass_theme'");
    if (empty($columns)) {
        Db::execute("ALTER TABLE " . config('database.prefix') . "web ADD COLUMN `glass_theme` varchar(20) NOT NULL DEFAULT 'default' COMMENT '配色方案:default/pink_blue'");
    }
} catch (\Throwable $e) {}
// 确保聚合登录（QQ登录）相关字段存在，避免回调地址等配置被静默丢弃导致保存失败
$oauthCols = [
    'oauth_enabled'  => "ALTER TABLE " . config('database.prefix') . "web ADD COLUMN `oauth_enabled` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否启用聚合登录'",
    'oauth_appid'    => "ALTER TABLE " . config('database.prefix') . "web ADD COLUMN `oauth_appid` varchar(100) DEFAULT '' COMMENT 'API应用ID'",
    'oauth_appkey'   => "ALTER TABLE " . config('database.prefix') . "web ADD COLUMN `oauth_appkey` varchar(100) DEFAULT '' COMMENT 'API应用密钥'",
    'oauth_callback' => "ALTER TABLE " . config('database.prefix') . "web ADD COLUMN `oauth_callback` varchar(255) DEFAULT '' COMMENT '回调URL'",
];
try {
    $existingCols = Db::query("SHOW COLUMNS FROM " . config('database.prefix') . "web");
    $existingNames = array_column($existingCols, 'Field');
    foreach ($oauthCols as $colName => $alterSql) {
        if (!in_array($colName, $existingNames)) {
            Db::execute($alterSql);
        }
    }
} catch (\Exception $e) {
    // 列创建失败，从表单数据中移除这些字段，避免 update 报错
    foreach (array_keys($oauthCols) as $colName) {
        unset($webinput[$colName]);
    }
}
// 确保看板娘相关字段存在（后台"启用看板娘"开关依赖 live2d_enabled）
ensure_web_bg_column();
// 确保"本站稳定运行起始时间"字段存在
try {
    $runCols = Db::query("SHOW COLUMNS FROM " . config('database.prefix') . "web LIKE 'run_start_time'");
    if (empty($runCols)) {
        Db::execute("ALTER TABLE " . config('database.prefix') . "web ADD COLUMN `run_start_time` varchar(20) NOT NULL DEFAULT '' COMMENT '本站稳定运行起始时间 Y-m-d H:i:s'");
    }
    // 读取并保存起始时间
    $runStart = isset($_POST['run_start_time']) ? trim($_POST['run_start_time']) : '';
    if ($runStart) {
        // datetime-local 返回 2025-01-01T00:00，转成 Y-m-d H:i:s
        $runStart = str_replace('T', ' ', $runStart) . ':00';
    }
    $webinput['run_start_time'] = $runStart;
} catch (\Throwable $e) {}
// 确保极验行为验证配置字段存在并保存
$gtMissing = array();
try {
    $gtCols = array(
        'captcha_type' => "ALTER TABLE " . config('database.prefix') . "web ADD COLUMN `captcha_type` tinyint(1) NOT NULL DEFAULT '0' COMMENT '验证方式:0内置滑块1极验GT3 2极验GT4'",
        'geetest_id'   => "ALTER TABLE " . config('database.prefix') . "web ADD COLUMN `geetest_id` varchar(64) NOT NULL DEFAULT '' COMMENT '极验GT3 验证ID'",
        'geetest_key'  => "ALTER TABLE " . config('database.prefix') . "web ADD COLUMN `geetest_key` varchar(64) NOT NULL DEFAULT '' COMMENT '极验GT3 验证KEY'",
        'geetest4_id'  => "ALTER TABLE " . config('database.prefix') . "web ADD COLUMN `geetest4_id` varchar(64) NOT NULL DEFAULT '' COMMENT '极验GT4 验证ID'",
        'geetest4_key' => "ALTER TABLE " . config('database.prefix') . "web ADD COLUMN `geetest4_key` varchar(64) NOT NULL DEFAULT '' COMMENT '极验GT4 验证KEY'",
    );
    $existCols = Db::query("SHOW COLUMNS FROM " . config('database.prefix') . "web");
    $existNames = array_column($existCols, 'Field');
    foreach ($gtCols as $gn => $gSql) {
        if (!in_array($gn, $existNames)) {
            try {
                Db::execute($gSql);
            } catch (\Throwable $ce) {
                $gtMissing[] = $gn . '(' . $ce->getMessage() . ')';
            }
        }
    }
    $webinput['captcha_type'] = isset($_POST['captcha_type']) ? intval($_POST['captcha_type']) : 0;
    $webinput['geetest_id']   = isset($_POST['geetest_id']) ? trim($_POST['geetest_id']) : '';
    $webinput['geetest_key']  = isset($_POST['geetest_key']) ? trim($_POST['geetest_key']) : '';
    $webinput['geetest4_id']  = isset($_POST['geetest4_id']) ? trim($_POST['geetest4_id']) : '';
    $webinput['geetest4_key'] = isset($_POST['geetest4_key']) ? trim($_POST['geetest4_key']) : '';
} catch (\Throwable $e) {
    $gtMissing[] = 'ALL:' . $e->getMessage();
}
// 通用字段过滤：移除 web 表中不存在的字段，防止 fields_strict 导致"字段不存在"报错、保存失败
$droppedFields = array();
try {
    $webColumns = Db::query("SHOW COLUMNS FROM " . config('database.prefix') . "web");
    $webColumnNames = array_column($webColumns, 'Field');
    foreach (array_keys($webinput) as $webFieldName) {
        if (!in_array($webFieldName, $webColumnNames)) {
            unset($webinput[$webFieldName]);
            $droppedFields[] = $webFieldName;
        }
    }
} catch (\Exception $e) {}
// 诊断：记录本次保存的关键信息，便于排查"点了保存没效果"
$saveDiag = array();
$saveDiag[] = 'time=' . date('Y-m-d H:i:s');
$saveDiag[] = 'POST字段数=' . count($_POST);
$saveDiag[] = 'captcha_type=' . (isset($webinput['captcha_type']) ? $webinput['captcha_type'] : 'null');
$saveDiag[] = 'geetest_id长度=' . (isset($webinput['geetest_id']) ? strlen($webinput['geetest_id']) : 0);
$saveDiag[] = 'geetest_key长度=' . (isset($webinput['geetest_key']) ? strlen($webinput['geetest_key']) : 0);
$saveDiag[] = 'geetest4_id长度=' . (isset($webinput['geetest4_id']) ? strlen($webinput['geetest4_id']) : 0);
$saveDiag[] = 'geetest4_key长度=' . (isset($webinput['geetest4_key']) ? strlen($webinput['geetest4_key']) : 0);
$saveDiag[] = '待写入字段数=' . count($webinput);
$saveDiag[] = '被丢弃字段=' . (empty($droppedFields) ? '无' : implode(',', $droppedFields));
$saveDiag[] = '建列失败=' . (empty($gtMissing) ? '无' : implode(';', $gtMissing));
try {
    $logDir = PATH . 'runtime/log/';
    if (!is_dir($logDir)) { @mkdir($logDir, 0755, true); }
    @file_put_contents($logDir . 'admin_set_debug.log', '[' . date('Y-m-d H:i:s') . '] ' . implode(' | ', $saveDiag) . "\n", FILE_APPEND);
} catch (\Throwable $e) {}
$data=Db::name('web')->where('id',"1")->update($webinput);
if($data!==false){
$array["code"]="1";
$array["msg"]="修改成功!";
$diagLines = array('代码版本=save-fix-2026-09-18');
$diagLines[] = '已写入字段 ' . count($webinput) . ' 个';
$diagLines[] = '验证方式(captcha_type)=' . (isset($webinput['captcha_type']) ? $webinput['captcha_type'] : '未提交');
$diagLines[] = 'GT3 ID长度=' . (isset($webinput['geetest_id']) ? strlen($webinput['geetest_id']) : 0)
    . '，GT3 KEY长度=' . (isset($webinput['geetest_key']) ? strlen($webinput['geetest_key']) : 0);
$diagLines[] = 'GT4 ID长度=' . (isset($webinput['geetest4_id']) ? strlen($webinput['geetest4_id']) : 0)
    . '，GT4 KEY长度=' . (isset($webinput['geetest4_key']) ? strlen($webinput['geetest4_key']) : 0);
if (!empty($droppedFields)) {
    $diagLines[] = '警告：以下字段因数据库缺列被丢弃 → ' . implode(',', $droppedFields);
}
if (!empty($gtMissing)) {
    $diagLines[] = '警告：极验配置列创建失败 → ' . implode(';', $gtMissing);
}
$array["detail"] = implode("\n", $diagLines);
// 后台入口路径可能已变更：删除入口缓存，下次请求立即按新入口生效
try {
    $entranceCache = PATH . 'runtime/admin_entrance.json';
    if (is_file($entranceCache)) { @unlink($entranceCache); }
} catch (\Throwable $e) {}
admin_op_log('set_update', '修改网站设置');
}else{
$array["code"]="-1";
$array["msg"]="修改失败!";
}
}
}

return json($array);
} catch (\Throwable $e) {
    return json(['code' => -1, 'msg' => '保存失败：' . $e->getMessage()]);
}
}
	
return $this->fetch('/'.$this->web["admintemplate"]."/set",[
"web"=>$this->web,
"cronurl"=>(isHTTPS() ? 'https://' : 'http://').$_SERVER['HTTP_HOST']."/cron",
"template"=>my_dir(PATH."/app/index/view/"),
"admintemplate"=>my_dir(PATH."/app/admin/view/"),
]);
	}

	// 测试实名认证 API 配置（仅管理员）
	public function testRealnameApi()
	{
		if (!$this->checkPermission('set')) {
			return json(['code' => -1, 'msg' => '无权限']);
		}
		if (!Request::instance()->isPost()) {
			return json(['code' => -1, 'msg' => '非法请求']);
		}
		$apiType = input('api_type', '1');
		$apiUrl = trim((string) input('api_url', ''));
		if ($apiType == '2') {
			$secretId = input('secret_id', '');
			$secretKey = input('secret_key', '');
			if (empty($secretId) || empty($secretKey)) {
				return json(['code' => -1, 'msg' => 'SecretId 和 SecretKey 不能为空']);
			}
			$result = cloudmarket_realname_verify('测试', '110101199001010000', $secretId, $secretKey, $apiUrl);
			if ($result['code'] == 1) {
				return json(['code' => 1, 'msg' => '云市场 二要素 API 账号校验通过']);
			}
			if (stripos($result['msg'], '请求参数错误') !== false || stripos($result['msg'], 'idcard') !== false) {
				return json(['code' => 1, 'msg' => '云市场 二要素 API 账号校验通过（测试身份证不合法，属于预期结果）']);
			}
			return json(['code' => -1, 'msg' => '云市场 二要素 API 测试失败：' . $result['msg']]);
		}
		if ($apiType == '3') {
			$secretId = input('secret_id', '');
			$secretKey = input('secret_key', '');
			if (empty($secretId) || empty($secretKey)) {
				return json(['code' => -1, 'msg' => 'SecretId 和 SecretKey 不能为空']);
			}
			$result = phone3element_verify('测试', '110101199001010000', '13800138000', $secretId, $secretKey, $apiUrl);
			if ($result['code'] == 1) {
				return json(['code' => 1, 'msg' => '云市场 手机三要素 API 账号校验通过']);
			}
			if (stripos($result['msg'], '不匹配') !== false || stripos($result['msg'], '无记录') !== false) {
				return json(['code' => 1, 'msg' => '云市场 手机三要素 API 账号校验通过（测试数据不匹配，属于预期结果）']);
			}
			if (stripos($result['msg'], 'error_code=10025') !== false) {
				return json(['code' => 1, 'msg' => '云市场 手机三要素 API 账号校验通过（测试手机号库无记录，属于预期结果）']);
			}
			return json(['code' => -1, 'msg' => '云市场 手机三要素 API 测试失败：' . $result['msg']]);
		}
		$appid = input('appid', '');
		$appkey = input('appkey', '');
		if (empty($appid) || empty($appkey)) {
			return json(['code' => -1, 'msg' => 'AppID 和 AppKey 不能为空']);
		}
		// 使用一组固定测试数据调用接口（不会通过，仅验证账号可用性）
		$result = realname_api_verify_with_key('测试', '110101199001010000', $appid, $appkey);
		if ($result['code'] == 1) {
			return json(['code' => 1, 'msg' => '花迹数据 API 账号校验通过']);
		}
		if (stripos($result['msg'], '4000') !== false || stripos($result['msg'], '账号或密钥错误') !== false) {
			return json(['code' => -1, 'msg' => '花迹数据 API 账号或密钥错误（错误码：4000），请检查 AppID / AppKey']);
		}
		if (stripos($result['msg'], '余额不足') !== false) {
			return json(['code' => -1, 'msg' => '花迹数据 API 账号余额不足，请充值后再试']);
		}
		// 其它错误（如参数错误）说明账号可用，只是测试身份证不合法
		if (stripos($result['msg'], 'idcard') !== false || stripos($result['msg'], '参数') !== false) {
			return json(['code' => 1, 'msg' => '花迹数据 API 账号校验通过（测试身份证不合法，属于预期结果）']);
		}
		return json(['code' => -1, 'msg' => '花迹数据 API 测试失败：' . $result['msg']]);
	}

	/**
	 * 测试看板娘 AI 大模型连接（支持官方直连与 OpenAI 兼容中转站）
	 * 后台可只填 Base URL，例如 https://你的中转站/v1，系统自动补 /chat/completions
	 */
	public function testLive2dApi()
	{
		if (!$this->checkPermission('set')) {
			return json(['code' => -1, 'msg' => '无权限']);
		}
		if (!Request::instance()->isPost()) {
			return json(['code' => -1, 'msg' => '非法请求']);
		}
		$web = web_config();
		// 优先使用表单里当前填写的值（方便未保存就测试）；未填则用已保存配置
		$apiUrl = trim((string)input('api_url', ''));
		$apiKey = trim((string)input('api_key', ''));
		$model  = trim((string)input('model', ''));
		if ($apiUrl === '' && isset($web['live2d_ai_api_url'])) { $apiUrl = trim((string)$web['live2d_ai_api_url']); }
		if ($apiKey === '' && isset($web['live2d_ai_api_key'])) { $apiKey = trim((string)$web['live2d_ai_api_key']); }
		if ($model === '' && isset($web['live2d_ai_model']))  { $model  = trim((string)$web['live2d_ai_model']); }
		if ($model === '') { $model = 'deepseek-v4-flash'; }

		if ($apiKey === '') {
			return json(['code' => -1, 'msg' => '请先填写 AI API 密钥']);
		}

		$endpoint = live2d_ai_endpoint($apiUrl);
		if ($endpoint === '') {
			$endpoint = 'https://api.deepseek.com/chat/completions';
		}

		$payload = [
			'model' => $model,
			'messages' => [
				['role' => 'user', 'content' => '你好，请用一句话回复：连接正常'],
			],
			'max_tokens' => 60,
			'temperature' => 0.7,
			'stream' => false,
		];

		$ch = curl_init();
		curl_setopt_array($ch, [
			CURLOPT_URL => $endpoint,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
			CURLOPT_HTTPHEADER => [
				'Content-Type: application/json',
				'Authorization: Bearer ' . $apiKey,
			],
			CURLOPT_TIMEOUT => 30,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_SSL_VERIFYPEER => false,
		]);
		$response = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curlErr  = curl_error($ch);
		curl_close($ch);

		if ($response === false || $curlErr) {
			return json(['code' => -1, 'msg' => '连接失败：' . ($curlErr ? $curlErr : '无响应') . '（接口地址：' . $endpoint . '）']);
		}
		$result = json_decode($response, true);
		if ($httpCode !== 200) {
			$errMsg = 'HTTP ' . $httpCode;
			if (is_array($result)) {
				if (isset($result['error']['message'])) { $errMsg .= '：' . $result['error']['message']; }
				elseif (isset($result['message'])) { $errMsg .= '：' . $result['message']; }
			}
			if ($errMsg === 'HTTP ' . $httpCode) { $errMsg .= '：' . mb_substr((string)$response, 0, 300); }
			return json(['code' => -1, 'msg' => '接口返回错误 ' . $errMsg . '（接口地址：' . $endpoint . '，模型：' . $model . '）']);
		}
		$reply = '';
		if (is_array($result)) {
			if (isset($result['choices'][0]['message']['content'])) {
				$reply = trim((string)$result['choices'][0]['message']['content']);
			} elseif (isset($result['choices'][0]['text'])) {
				$reply = trim((string)$result['choices'][0]['text']);
			}
		}
		if ($reply === '') {
			return json(['code' => -1, 'msg' => '接口连通，但返回结构无法识别（接口地址：' . $endpoint . '）：' . mb_substr((string)$response, 0, 300)]);
		}
		return json([
			'code' => 1,
			'msg'  => '连接成功！模型 ' . $model . ' 回复：' . mb_substr($reply, 0, 120),
			'detail' => '接口地址：' . $endpoint,
		]);
	}

	/**
	 * 发送测试邮件：用当前已保存的 SMTP 配置真实发一封，并把每一步错误返还网页上
	 */
	public function test_email()
	{
		if (!$this->checkPermission('set')) {
			return json(['code' => -1, 'msg' => '无权限']);
		}
		$web = Db::name('web')->where('id', 1)->find();
		$to = trim(input('to', ''));
		if (empty($to)) {
			$to = isset($web['emailname']) ? trim($web['emailname']) : '';
		}
		if (empty($to)) {
			return json(['code' => -1, 'msg' => '请填写收件邮箱']);
		}
		if (empty($web['emailhost']) || empty($web['emailname']) || empty($web['emailpass'])) {
			return json(['code' => -1, 'msg' => 'SMTP 未配置完整（服务器/账号/授权码不能为空），请先保存上方的邮件设置']);
		}

		// 显式加载 PHPMailer（避免依赖自动加载失败导致静默失败）
		$pmDir = PATH . 'extend/PHPMailer/PHPMailer/';
		if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
			if (!file_exists($pmDir . 'PHPMailer.php')) {
				return json(['code' => -1, 'msg' => 'PHPMailer 文件缺失：' . $pmDir]);
			}
			require_once $pmDir . 'Exception.php';
			require_once $pmDir . 'PHPMailer.php';
			require_once $pmDir . 'SMTP.php';
		}

		try {
			$mail = new \PHPMailer\PHPMailer\PHPMailer(true);
			$mail->CharSet  = isset($web['emailchar']) && $web['emailchar'] ? $web['emailchar'] : 'UTF-8';
			$mail->IsSMTP();
			$mail->Host     = $web['emailhost'];
			$mail->Port     = !empty($web['emailport']) ? intval($web['emailport']) : 465;
			$mail->SMTPAuth = true;
			$mail->Username = $web['emailname'];
			$mail->Password = $web['emailpass'];
			$mail->Timeout  = 20;
			// 465=ssl，587/25=非加密或STARTTLS
			if ($mail->Port == 465) {
				$mail->SMTPSecure = 'ssl';
			} elseif (!empty($web['emailsecure']) && $web['emailsecure'] != 'none') {
				$mail->SMTPSecure = $web['emailsecure'];
			}
			$mail->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]];
			$mail->setFrom($web['emailname'], isset($web['name']) ? $web['name'] : '');
			$mail->addAddress($to);
			$mail->Subject = '【' . (isset($web['name']) ? $web['name'] : '站点') . '】SMTP 发送测试';
			$mail->isHTML(true);
			$mail->Body = '<div style="font-family:Arial;padding:24px;">这是一封 SMTP 测试邮件。如果你在收件箱看到此邮件，说明邮件配置已生效。<br><br>发送时间：' . date('Y-m-d H:i:s') . '</div>';
			$mail->send();
			return json(['code' => 1, 'msg' => '发送成功，已投递到 ' . $to . '（如收不到请检查垃圾箱）']);
		} catch (\Throwable $e) {
			return json(['code' => -1, 'msg' => '发送失败：' . $e->getMessage()]);
		}
	}

	/**
	 * 极验连通性自检：直接在服务器上请求极验初始化接口，
	 * 把「能不能连通 / 返回什么 / challenge 是否合法」如实返回，便于排查前端一直"加载中"
	 */
	public function gt_test()
	{
		if (!$this->checkPermission('set')) {
			return json(['code' => -1, 'msg' => '无权限']);
		}
		$web = Db::name('web')->where('id', 1)->find();
		// 优先使用前端表单当前填写的值（未保存也能测试），为空时回退到数据库已保存值
		$type = isset($_POST['captcha_type']) ? trim((string)$_POST['captcha_type']) : '';
		if ($type === '') $type = isset($web['captcha_type']) ? trim((string)$web['captcha_type']) : '';
		$isGt4 = ($type === '2');

		$idField  = $isGt4 ? 'geetest4_id'  : 'geetest_id';
		$keyField = $isGt4 ? 'geetest4_key' : 'geetest_key';
		$id  = isset($_POST[$idField]) ? trim((string)$_POST[$idField]) : '';
		$key = isset($_POST[$keyField]) ? trim((string)$_POST[$keyField]) : '';
		$fromForm = ($id !== '' || $key !== '' || isset($_POST['captcha_type']));
		if ($id === '')  $id  = isset($web[$idField]) ? trim($web[$idField]) : '';
		if ($key === '') $key = isset($web[$keyField]) ? trim($web[$keyField]) : '';

		$verName = $isGt4 ? '极验GT4（第四代）' : ($type === '1' ? '极验GT3' : '内置滑块');
		$detail = [];
		$detail[] = '验证方式：' . $verName . ($fromForm ? '（按当前表单值检测）' : '（数据库已保存值）');
		$detail[] = 'ID：' . ($id !== '' ? substr($id, 0, 10) . '…（长度' . strlen($id) . '）' : '（空）');
		$detail[] = 'KEY：' . ($key !== '' ? '（长度' . strlen($key) . '）' : '（空）');

		if ($id === '' || $key === '') {
			return json(['code' => -1, 'msg' => '极验 ID 或 KEY 为空：请先在上方填写对应的 ID 与 KEY（填完直接点本按钮即可测试，正式生效需点页面底部「保存」），或已保存的值为空', 'detail' => implode("\n", $detail)]);
		}
		if ($type !== '1' && $type !== '2') {
			$detail[] = '提示：上方「验证方式」未选择极验，当前前台用的是内置滑块。';
		}

		// ---------- GT4 分支：探测 gcaptcha4 的 validate 接口与 gt4.js ----------
		// 注意：GT4 服务端没有 register 初始化接口（那是 GT3 的概念），
		// 官方文档只提供 /validate 二次校验接口，这里用一次"假数据"请求探测连通性，
		// 只要返回 JSON（无论 result 成功失败）即说明服务器能连上极验。
		if ($isGt4) {
			$reachable = false;
			$nodeBlocked = false;
			foreach (['https://gcaptcha4.geetest.com/validate', 'http://gcaptcha4.geetest.com/validate'] as $api) {
				$url = $api . '?captcha_id=' . urlencode($id);
				$t0 = microtime(true);
				$ch = curl_init($url);
				curl_setopt_array($ch, [
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_POST => true,
					CURLOPT_POSTFIELDS => http_build_query([
						'lot_number'     => 'connectivity_test',
						'captcha_output' => 'connectivity_test',
						'pass_token'     => 'connectivity_test',
						'gen_time'       => (string)time(),
						'sign_token'     => hash_hmac('sha256', 'connectivity_test', $key),
					]),
					CURLOPT_CONNECTTIMEOUT => 3,
					CURLOPT_TIMEOUT => 6,
					CURLOPT_SSL_VERIFYPEER => false,
					CURLOPT_SSL_VERIFYHOST => false,
					CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; GeeTestGT4Probe)',
				]);
				$resp = curl_exec($ch);
				$err = curl_error($ch);
				$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
				curl_close($ch);
				$ms = round((microtime(true) - $t0) * 1000);
				$detail[] = '请求 ' . $url . ' → HTTP ' . $code . '，耗时 ' . $ms . 'ms'
					. ($err ? '，错误：' . $err : '') . '，返回：' . mb_substr((string)$resp, 0, 200);
				$obj = json_decode((string)$resp, true);
				if (is_array($obj)) {
					$reachable = true;
					if (isset($obj['msg']) && stripos((string)$obj['msg'], 'unsupported node') !== false) {
						$nodeBlocked = true;
					}
					break;
				}
			}

			// gt4.js 可达性（浏览器加载不到就会一直"加载中"）
			$ch = curl_init('https://static.geetest.com/v4/gt4.js');
			curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 6, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false]);
			$js = curl_exec($ch);
			$jsCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			curl_close($ch);
			$detail[] = 'gt4.js 探测：HTTP ' . $jsCode . '，大小 ' . strlen((string)$js) . ' 字节';

			// 顺便演示一次 sign_token 计算方式，便于核对密钥
			$demoLot = md5('gt4selftest' . time());
			$detail[] = 'sign_token 算法自检：HMAC-SHA256(key=KEY, msg=lot_number) = ' . substr(hash_hmac('sha256', $demoLot, $key), 0, 16) . '…';

			if ($nodeBlocked) {
				return json(['code' => -1, 'msg' => '服务器能连上极验，但极验拒绝当前服务器 IP 所在地区（unsupported node），GT4 在此服务器上无法完成二次验证。请更换服务器地区或改用内置滑块 / GT3', 'detail' => implode("\n", $detail)]);
			}
			if ($reachable) {
				return json(['code' => 1, 'msg' => 'GT4 连通正常（validate 接口返回 JSON，服务器可访问极验），前端可正常使用', 'detail' => implode("\n", $detail)]);
			}
			return json(['code' => -1, 'msg' => 'GT4 连通失败：服务器无法访问 gcaptcha4.geetest.com/validate（可能被防火墙拦截或返回了非 JSON 内容）', 'detail' => implode("\n", $detail)]);
		}

		$qs = http_build_query([
			'gt' => $id, 'json_format' => 1, 'digestmod' => 'md5',
			'new_captcha' => 1, 'client_type' => 'web',
		]);

		$result = null;
		$lastErr = '';
		foreach (['https://api.geetest.com/register.php', 'http://api.geetest.com/register.php'] as $api) {
			$t0 = microtime(true);
			$ch = curl_init($api . '?' . $qs);
			curl_setopt_array($ch, [
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_CONNECTTIMEOUT => 3,
				CURLOPT_TIMEOUT => 6,
				CURLOPT_SSL_VERIFYPEER => false,
				CURLOPT_SSL_VERIFYHOST => false,
			]);
			$resp = curl_exec($ch);
			$err  = curl_error($ch);
			$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			curl_close($ch);
			$ms = round((microtime(true) - $t0) * 1000);
			$detail[] = '请求 ' . $api . ' → HTTP ' . $code . '，耗时 ' . $ms . 'ms'
				. ($err ? '，错误：' . $err : '') . '，返回：' . mb_substr((string)$resp, 0, 160);
			if ($resp !== false && $resp !== '') {
				$result = json_decode($resp, true);
				if (is_array($result)) break;
			}
			$lastErr = $err;
		}

		if (!is_array($result)) {
			return json(['code' => -1, 'msg' => '服务器无法访问极验接口（可能被防火墙拦截或网络不通），前台会走降级模式', 'detail' => implode("\n", $detail)]);
		}

		$challenge = isset($result['challenge']) ? $result['challenge'] : '';
		$ok = is_string($challenge) && strlen($challenge) >= 32;
		$detail[] = 'challenge：' . $challenge . '（长度 ' . strlen($challenge) . '）';
		$detail[] = '签名后下发值：' . md5($challenge . $key);

		// 顺便探测前端 gt.js 是否可达（浏览器加载不到 gt.js 就会一直"加载中"）
		$ch = curl_init('https://static.geetest.com/static/tools/gt.js');
		curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 6, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false, CURLOPT_NOBODY => false]);
		$js = curl_exec($ch);
		$jsCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		$detail[] = 'gt.js 探测：HTTP ' . $jsCode . '，大小 ' . strlen((string)$js) . ' 字节';

		if ($ok) {
			return json(['code' => 1, 'msg' => '极验初始化正常（challenge 获取成功），ID/KEY 有效', 'detail' => implode("\n", $detail)]);
		}
		return json(['code' => -1, 'msg' => '极验返回异常：未拿到合法 challenge，请核对 ID 是否为 GT3 行为验证的应用ID', 'detail' => implode("\n", $detail)]);
	}

	// 背景文件上传（支持图片/视频/GIF）
	public function bg_upload() {
		if (!$this->checkPermission('set')) {
			return json(['code' => -1, 'msg' => '无权限']);
		}
		try {
			// 尝试提高上传限制（部分主机支持运行时设置）
			@ini_set('upload_max_filesize', '200M');
			@ini_set('post_max_size', '200M');
			@ini_set('max_execution_time', '300');
			@ini_set('max_input_time', '300');
			@ini_set('memory_limit', '256M');

			// 检查 PHP 层面是否拒绝上传（upload_max_filesize 过小）
			$phpError = isset($_FILES['file']) ? intval($_FILES['file']['error']) : 0;
			if ($phpError === 1) {
				return json(['code' => -1, 'msg' => '文件大小超过服务器限制，请联系管理员调整 PHP upload_max_filesize / post_max_size（当前服务器限制可能小于 50MB）']);
			}
			if ($phpError === 2) {
				return json(['code' => -1, 'msg' => '文件大小超过表单限制']);
			}
			if ($phpError === 3) {
				return json(['code' => -1, 'msg' => '文件部分上传失败，请重试']);
			}
			if ($phpError === 4) {
				return json(['code' => -1, 'msg' => '请选择要上传的文件']);
			}
			if ($phpError !== 0 && $phpError !== 4) {
				return json(['code' => -1, 'msg' => '上传错误代码：' . $phpError . '，请检查服务器上传限制配置']);
			}

			$file = request()->file('file');
			if(!$file) {
				return json(['code' => -1, 'msg' => '请选择文件（可能是文件大小超过服务器 upload_max_filesize 限制，请联系主机商调整）']);
			}

			// 从文件扩展名自动判断类型，不再依赖前端传参（更稳健）
			$ext = strtolower(pathinfo($file->getInfo('name'), PATHINFO_EXTENSION));
			$videoExts = ['mp4', 'webm', 'mov', 'avi', 'mkv'];
			$gifExts = ['gif'];
			$imageExts = ['jpg', 'jpeg', 'png', 'webp', 'bmp'];

			if (in_array($ext, $videoExts)) {
				$bgType = 'video';
				$maxSize = 209715200; // 200MB
				$allowedExts = 'mp4,webm,mov,avi,mkv';
				$allowedMimes = ['video/mp4', 'video/webm', 'video/quicktime', 'video/x-msvideo', 'video/x-matroska'];
			} elseif (in_array($ext, $gifExts)) {
				$bgType = 'gif';
				$maxSize = 209715200; // 200MB
				$allowedExts = 'gif';
				$allowedMimes = ['image/gif'];
			} else {
				$bgType = 'image';
				$maxSize = 209715200; // 200MB
				$allowedExts = 'jpg,jpeg,png,webp,bmp';
				$allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/bmp'];
			}

			$uploadDir = PATH . 'public/uploads/bg/';
			if(!is_dir($uploadDir)) {
				@mkdir($uploadDir, 0755, true);
			}
			if(!is_dir($uploadDir) || !is_writable($uploadDir)) {
				$uploadDir = PATH . 'public/uploads/';
			}

			$info = $file->validate([
				'size' => $maxSize,
				'ext'  => $allowedExts,
			])->move($uploadDir);
			if(!$info) {
				return json(['code' => -1, 'msg' => $file->getError() ?: '上传失败']);
			}
			// 二次校验 MIME
			$realPath = $info->getRealPath();
			if(function_exists('finfo_open')) {
				$finfo = finfo_open(FILEINFO_MIME_TYPE);
				$mime = finfo_file($finfo, $realPath);
				finfo_close($finfo);
				if(!in_array($mime, $allowedMimes)) {
					@unlink($realPath);
					return json(['code' => -1, 'msg' => '文件类型不允许：' . $mime]);
				}
			}
			$url = '/uploads/bg/' . $info->getSaveName();
			Db::name('web')->where('id', '1')->update([
				'bg_image' => $url,
				'bg_type' => $bgType,
			]);
			return json(['code' => 1, 'msg' => '上传成功', 'url' => $url, 'type' => $bgType]);
		} catch (\Exception $e) {
			return json(['code' => -1, 'msg' => '上传异常：' . $e->getMessage()]);
		}
	}

	// 重置背景图
	public function bg_reset() {
		if (!$this->checkPermission('set')) {
			return json(['code' => -1, 'msg' => '无权限']);
		}
		Db::name('web')->where('id', '1')->update(['bg_image' => '', 'bg_images' => '', 'bg_type' => 'image']);
		return json(['code' => 1, 'msg' => '背景图已重置']);
	}

	// 上传 LOGO / 网站图标（favicon），上传后直接写入 web 表对应字段
	public function logo_upload() {
		if (!$this->checkPermission('set')) {
			return json(['code' => -1, 'msg' => '无权限']);
		}
		try {
			$type = input('type', 'logo');
			if (!in_array($type, ['logo', 'favicon'])) {
				return json(['code' => -1, 'msg' => '非法上传类型']);
			}
			$file = request()->file('file');
			if(!$file) {
				return json(['code' => -1, 'msg' => '请选择文件']);
			}
			$uploadDir = PATH . 'public/uploads/brand/';
			if(!is_dir($uploadDir)) {
				@mkdir($uploadDir, 0755, true);
			}
			$usedBrandDir = is_dir($uploadDir) && is_writable($uploadDir);
			if(!$usedBrandDir) {
				$uploadDir = PATH . 'public/uploads/';
			}
			// favicon 允许 ico/svg，logo 主要图片格式
			$ext = strtolower(pathinfo($file->getInfo('name'), PATHINFO_EXTENSION));
			$allowedExts = ($type == 'favicon') ? 'jpg,jpeg,png,webp,gif,ico,svg' : 'jpg,jpeg,png,webp,gif,svg';
			$info = $file->validate([
				'size' => 5242880, // 5MB
				'ext'  => $allowedExts,
			])->move($uploadDir);
			if(!$info) {
				return json(['code' => -1, 'msg' => $file->getError() ?: '上传失败']);
			}
			// 二次校验 MIME
			$realPath = $info->getRealPath();
			if(function_exists('finfo_open')) {
				$finfo = finfo_open(FILEINFO_MIME_TYPE);
				$mime = finfo_file($finfo, $realPath);
				finfo_close($finfo);
				$allowedMimes = ['image/jpeg','image/png','image/webp','image/gif','image/x-icon','image/vnd.microsoft.icon','image/svg+xml'];
				if(!in_array($mime, $allowedMimes)) {
					@unlink($realPath);
					return json(['code' => -1, 'msg' => '文件类型不允许：' . $mime]);
				}
			}
			$saveName = $info->getSaveName();
			$url = $usedBrandDir ? ('/uploads/brand/' . $saveName) : ('/uploads/' . $saveName);
			$field = ($type == 'favicon') ? 'favicon' : 'logo';
			Db::name('web')->where('id', '1')->update([$field => $url]);
			admin_op_log('logo_upload', ($type == 'favicon' ? '上传网站图标' : '上传 Logo'), ['url' => $url]);
			return json(['code' => 1, 'msg' => '上传成功', 'url' => $url]);
		} catch (\Exception $e) {
			return json(['code' => -1, 'msg' => '上传异常：' . $e->getMessage()]);
		}
	}

	// 轮播背景图多图上传（仅上传文件并返回URL，不直接写入数据库）
	public function bg_multi_upload() {
		if (!$this->checkPermission('set')) {
			return json(['code' => -1, 'msg' => '无权限']);
		}
		try {
			$file = request()->file('file');
			if(!$file) {
				return json(['code' => -1, 'msg' => '请选择文件']);
			}
			$uploadDir = PATH . 'public/uploads/bg/';
			if(!is_dir($uploadDir)) {
				@mkdir($uploadDir, 0755, true);
			}
			if(!is_dir($uploadDir) || !is_writable($uploadDir)) {
				$uploadDir = PATH . 'public/uploads/';
			}
			$info = $file->validate([
				'size' => 52428800,
				'ext'  => 'jpg,jpeg,png,webp,gif',
			])->move($uploadDir);
			if(!$info) {
				return json(['code' => -1, 'msg' => $file->getError() ?: '上传失败']);
			}
			$realPath = $info->getRealPath();
			if(function_exists('finfo_open')) {
				$finfo = finfo_open(FILEINFO_MIME_TYPE);
				$mime = finfo_file($finfo, $realPath);
				finfo_close($finfo);
				$allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
				if(!in_array($mime, $allowedMimes)) {
					@unlink($realPath);
					return json(['code' => -1, 'msg' => '文件类型不允许：' . $mime]);
				}
			}
			$url = '/uploads/bg/' . $info->getSaveName();
			return json(['code' => 1, 'msg' => '上传成功', 'url' => $url]);
		} catch (\Exception $e) {
			return json(['code' => -1, 'msg' => '上传异常：' . $e->getMessage()]);
		}
	}

	// 实名审核
	public function realnameReview() {
		if (!$this->checkPermission('user')) {
			$this->error('您没有权限访问此页面', '/admin/index');
		}
		ensure_user_columns();
		ensure_realname_record_table();
		if(Request::instance()->isPost()) {
			$act = input('act');
			$id = input('id', 0);
			$array = ['code' => '-1', 'msg' => ''];
			$user = Db::name('user')->where('id', $id)->find();
			if(!$user) {
				$array['msg'] = '用户不存在';
				return json($array);
			}
			$reviewerId = session('adminid');
			$reviewerName = $this->user['user'] ?? '';
			if($act == 'approve') {
				Db::name('user')->where('id', $id)->update(['realname_status' => 1]);
				$this->updateRealnameRecord($id, 1, $reviewerId, $reviewerName);
				// ── 写入「系统重要记录」：实名认证通过 ──
				try {
					if (function_exists('sys_record')) {
						sys_record('user', '实名认证通过：' . ($user['user'] ?? ('#' . $id)), [
							'用户'   => $user['user'] ?? '',
							'姓名'   => $user['realname'] ?? '',
							'审核人' => $reviewerName,
							'时间'   => date('Y-m-d H:i:s'),
						], [
							'operator_type' => 'admin',
							'operator_id'   => intval($reviewerId),
							'operator_name' => $reviewerName,
							'target_type'   => 'user',
							'target_id'     => intval($id),
							'level'         => 2,
							'summary'       => '用户：' . ($user['user'] ?? '') . ' · 审核人：' . $reviewerName,
						]);
					}
				} catch (\Exception $e) {}
				// 发送实名认证通过邮件通知
				if($this->web["email"]=="1" && !empty($user["mail"])){
					$realname = $user['realname'] ?: $user['name'];
					try {
						self::email($user["mail"], "实名认证通过通知", '<p>您好 '.htmlspecialchars($realname).'，</p><p>恭喜！您的实名认证已审核通过。</p><p style="color:#64748b;font-size:13px;">认证姓名：'.htmlspecialchars($realname).'<br>认证时间：'.date("Y-m-d H:i:s").'</p>');
					} catch (\Exception $mailEx) {}
				}
				$array['code'] = '1';
				$array['msg'] = '已通过实名认证';
				return json($array);
			}
			if($act == 'reject') {
				Db::name('user')->where('id', $id)->update(['realname_status' => 2]);
				$this->updateRealnameRecord($id, 2, $reviewerId, $reviewerName);
				// ── 写入「系统重要记录」：实名认证驳回 ──
				try {
					if (function_exists('sys_record')) {
						sys_record('user', '实名认证驳回：' . ($user['user'] ?? ('#' . $id)), [
							'用户'   => $user['user'] ?? '',
							'姓名'   => $user['realname'] ?? '',
							'审核人' => $reviewerName,
							'时间'   => date('Y-m-d H:i:s'),
						], [
							'operator_type' => 'admin',
							'operator_id'   => intval($reviewerId),
							'operator_name' => $reviewerName,
							'target_type'   => 'user',
							'target_id'     => intval($id),
							'level'         => 3,
							'summary'       => '用户：' . ($user['user'] ?? '') . ' · 审核人：' . $reviewerName,
						]);
					}
				} catch (\Exception $e) {}
				$array['code'] = '1';
				$array['msg'] = '已驳回实名认证';
			return json($array);
		}

		return json($array);
	}
	$list = Db::name('user')
			->where('realname_status', '3')
			->order('id desc')
			->paginate(15);
	// 查询每个待审核用户最新上传的证件照片与认证方式，供管理员核对
	$idcardFrontMap = [];
	$idcardMethodMap = [];
	try {
		ensure_realname_idcard_table();
		$items = $list->items();
		if (!empty($items)) {
			$ids = array_column($items, 'id');
			$rows = Db::name('realname_idcard')
				->where('userid', 'in', $ids)
				->order('id desc')
				->select();
			foreach ($rows as $r) {
				if (!isset($idcardFrontMap[$r['userid']])) {
					$idcardFrontMap[$r['userid']] = isset($r['idcard_front']) ? $r['idcard_front'] : '';
					$methodName = '身份证上传';
					if (isset($r['method']) && $r['method'] == 'manual') $methodName = '人工审核';
					elseif (isset($r['method']) && $r['method'] == 'phone') $methodName = '手机三要素';
					$idcardMethodMap[$r['userid']] = $methodName;
				}
			}
		}
	} catch (\Exception $e) {}
	return $this->fetch('/'.$this->web["admintemplate"].'/realname_review', [
		'list' => $list,
		'idcardFrontMap' => $idcardFrontMap,
		'idcardMethodMap' => $idcardMethodMap,
	]);
}

	// 全局实时搜索：根据关键词同时检索用户与订单
	public function search() {
		$q = trim(input('q', ''));
		$result = ['users' => [], 'orders' => []];
		if ($q === '') {
			return json($result);
		}
		// 转义 LIKE 通配符，避免 % _ 被当作通配导致意外命中
		$q = str_replace(['%', '_'], ['\\%', '\\_'], $q);
		$like = '%' . $q . '%';
		$limit = 6;

		// 用户：有 user 权限或超级管理员可搜
		if ($this->checkPermission('user')) {
			$users = Db::name('user')
				->where('id', 'like', $like)
				->whereOr('user', 'like', $like)
				->whereOr('name', 'like', $like)
				->whereOr('mail', 'like', $like)
				->whereOr('qq', 'like', $like)
				->order('id desc')
				->limit($limit)
				->field('id,user,name,qq,mail,state')
				->select();
			$result['users'] = $users ?: [];
		}

		// 订单：有 order 权限或超级管理员可搜
		if ($this->checkPermission('order')) {
			$orders = Db::name('order')
				->where('id', 'like', $like)
				->whereOr('user', 'like', $like)
				->whereOr('userid', 'like', $like)
				->order('id desc')
				->limit($limit)
				->field('id,user,userid,password,state,money,time')
				->select();
			$result['orders'] = $orders ?: [];
		}

		return json($result);
	}

	public function user($id=null,$orderid=null) {
if (!$this->checkPermission('user')) {
    $this->error('您没有权限访问此页面', '/admin/index');
}
if(!$id){
if(Request::instance()->isPost()) {

if(input("act")=="login"){
$userid=input("userid");
if($userid==""){
$this->redirect('/user/index');
}else{
// 标记为管理员代看用户面板，前台应跳过 IP/地域更新，避免记录管理员 IP
// 同时写 cookie 兜底：避免 SESSION 因浏览器/Cookie 设置异常丢失导致前台无法识别
session("admin_view_userid", $userid);
session("admin_view_mode", 1);
session("userid",$userid);
cookie("admin_view_userid", $userid, 86400);
cookie("admin_view_mode", 1, 86400);
$this->redirect('/user/index');
}
}

if(input("act")=="delete"){
if(input("userid")){
$userid=explode(",",input("userid"));
$a="0";
$b="0";
// 优化：一次性查询所有有订单的 userid, 避免 N+1 查询
$usersWithOrders = Db::name("order")->where("userid", "in", $userid)->column("userid");
$usersWithOrders = array_unique($usersWithOrders);
for($i=0;$i<count($userid);$i++){
if(in_array($userid[$i], $usersWithOrders)){
$b=$b+1;
}else{
$data1=Db::name("user")->where("id",$userid[$i])->delete();
if($data1){
$a=$a+1;
}else{
$b=$b+1;
}
}
}
$c="";
if($b>0){
$c="<br/>失败原因:已删除或者账户下还有未删除的产品!";
}
$array["code"]="1";
$array["msg"]="成功:".$a.";失败:".$b.$c;
}else{
$array["code"]="-1";
$array["msg"]="必填参数不可为空!!";
}
return json($array);
}


if(input("act")=="banUser"){
$userid=input("userid");
$ban_duration=input("ban_duration");
$ban_reason=input("ban_reason");
if($userid=="" || $ban_duration==""){
$array["code"]="-1";
$array["msg"]="必填参数不可为空!";
}else{
if(!is_numeric($ban_duration) || $ban_duration<1 || $ban_duration>87600){
$array["code"]="-1";
$array["msg"]="封禁时长必须为1-87600小时之间的数字!";
}else{
$user=Db::name('user')->where('id',$userid)->find();
if(!$user){
$array["code"]="-1";
$array["msg"]="用户不存在!";
}else{
$ban_time=time()+$ban_duration*3600;
$data=Db::name('user')->where('id',$userid)->update([
"ban_time"=>$ban_time,
"ban_reason"=>$ban_reason,
]);
// 自动停用该用户所有活跃主机
$activeOrders=Db::name('order')->where([
"userid"=>$userid,
"state"=>"1",
])->select();
if(!empty($activeOrders)){
$cartIds=array_unique(array_column($activeOrders,'cartid'));
$cartMap=Db::name('cart')->where('id','in',$cartIds)->column('serverid','id');
$serverIds=array_unique(array_values($cartMap));
$serverMap=Db::name('server')->where('id','in',$serverIds)->column('serverplugins','id');
foreach($activeOrders as $order){
$serverId=isset($cartMap[$order['cartid']])?$cartMap[$order['cartid']]:null;
if($serverId && isset($serverMap[$serverId])){
$pluginFile=PATH."plugins/host/".$serverMap[$serverId]."/".$serverMap[$serverId].".php";
if(file_exists($pluginFile)){
include_once $pluginFile;
$function=$serverMap[$serverId]."_"."SuspendAccount";
if(function_exists($function)){
$cart=Db::name('cart')->where('id',$order['cartid'])->find();
$server=Db::name('server')->where('id',$serverId)->find();
@$function($server,$order,$cart);
}
}
}
Db::name('order')->where('id',$order['id'])->update(["state"=>"2"]);
}
}
if($data!==false){
$array["code"]="1";
$array["msg"]="封禁成功!已自动停用该用户所有主机";
}else{
$array["code"]="-1";
$array["msg"]="封禁失败!";
}
}
}
}
return json($array);
}

if(input("act")=="unbanUser"){
$userid=input("userid");
if($userid==""){
$array["code"]="-1";
$array["msg"]="必填参数不可为空!";
}else{
$data=Db::name('user')->where('id',$userid)->update([
"ban_time"=>"0",
"ban_reason"=>"",
]);
// 自动恢复该用户所有被暂停的主机
$suspendedOrders=Db::name('order')->where([
"userid"=>$userid,
"state"=>"2",
])->select();
$recovered=0;
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
$recovered++;
}
}
if($data!==false){
$array["code"]="1";
$array["msg"]="解封成功！已恢复 ".$recovered." 台主机";
}else{
$array["code"]="-1";
$array["msg"]="解封失败!";
}
}
return json($array);
}

if(input("act")=="qbdelete"){
// 优化：批量查询所有用户 id 和有订单的 userid，单条 DELETE 批量删除
$ids=Db::name("user")->column('id');
$a="0"; $b="0";
if(!empty($ids)){
	$usersWithOrders=Db::name("order")->where("userid","in",$ids)->column("userid");
	$usersWithOrders=array_unique($usersWithOrders);
	$deleteIds=[];
	foreach($ids as $uid){
		if(!in_array($uid,$usersWithOrders)){
			$deleteIds[]=$uid;
		}
	}
	if(!empty($deleteIds)){
		$deleted=Db::name("user")->where("id","in",$deleteIds)->delete();
		$a=(string)$deleted;
		$b=(string)(count($ids)-$deleted);
	}else{
		$b=(string)count($ids);
	}
}
$c="";
if($b>0){
$c="<br/>失败原因:已删除或者账户下还有未删除的产品!";
}
$array["code"]="1";
$array["msg"]="成功:".$a.";失败:".$b.$c;
return json($array);
}

if(input("act")=="adduser"){
$name=input("name");
$user=input("user");
$qq=input("qq");
$mail=input("mail");
$password=input("password");
if($name=="" || $user=="" || $qq=="" || $password==""){
$array["code"]="-1";
$array["msg"]="必填参数不可为空!";
}else{
$credErr = validate_user_credential($user, $password);
if($credErr !== ''){
$array["code"]="-1";
$array["msg"]=$credErr;
}else{
if($mail){
$data4=is_valid_email($mail);
if($data4){
	$data=Db::name('user')->where("user",$user)->find();
			if($data) {
				$array["code"]="-1";
				$array["msg"]="账号已存在!";
			} else {
$data2=Db::name('user')->where("mail",$mail)->find();
if($data2){
				$array["code"]="-1";
				$array["msg"]="邮箱已存在!";
}else{
	$data3=Db::name('user')->insertGetId([
				"name"=>$name,
				"user"=>$user,
				"password"=>password_hash($password,PASSWORD_DEFAULT),
				"mail"=>$mail,
				"time"=>time(),
                "qq"=>$qq,
                "address"=>"",
                "aff"=>"",
                "upperid"=>"",
				]);
if($data3){
$array["code"]="1";
$array["msg"]="添加成功!";
}else{
$array["code"]="-1";
$array["msg"]="添加失败!";
}
}
}
}else{
$array["code"]="-1";
$array["msg"]="邮箱格式错误!";
}
}else{






	$data=Db::name('user')->where("user",$user)->find();
			if($data) {
				$array["code"]="-1";
				$array["msg"]="账号已存在!";
			} else {
	$data3=Db::name('user')->insertGetId([
				"name"=>$name,
				"user"=>$user,
				"password"=>password_hash($password,PASSWORD_DEFAULT),
				"mail"=>$mail,
				"time"=>time(),
                "qq"=>$qq,
                "address"=>"",
                "aff"=>"",
                "upperid"=>"",
				]);
if($data3){
$array["code"]="1";
$array["msg"]="添加成功!";
}else{
$array["code"]="-1";
$array["msg"]="添加失败!";
}
}



}
}
}
return json($array);
}


}
$search = input("search", '');
$realnameFilter = input("realname_filter", '');  // 1=已实名 0=未实名
$vipFilter = input("vip_filter", '');            // 1-6 VIP等级
$oauthFilter = input("oauth_filter", '');        // 1=QQ快捷登录
$stateFilter = input("state_filter", '');        // 1=正常 0=冻结

$query = Db::name('user');

// 关键词搜索
if ($search) {
    $query->where(function($q) use ($search) {
        $q->where("id", 'like', '%'.$search.'%')
          ->whereOr("user", 'like', '%'.$search.'%')
          ->whereOr("name", 'like', '%'.$search.'%')
          ->whereOr("mail", 'like', '%'.$search.'%')
          ->whereOr("qq", 'like', '%'.$search.'%')
          ->whereOr("address", 'like', '%'.$search.'%')
          ->whereOr("aff", 'like', '%'.$search.'%');
    });
}

// 实名状态筛选
if ($realnameFilter !== '') {
    if ($realnameFilter == '1') {
        $query->where('realname_status', 1);  // 已实名
    } else {
        $query->where('realname_status', '<>', 1)->whereOr('realname_status', null);  // 未实名
    }
}

// VIP等级筛选
if ($vipFilter !== '') {
    $query->where('membership_level', intval($vipFilter));
}

// QQ快捷登录筛选
if ($oauthFilter == '1') {
    $query->where('oauth_qq', '<>', '');
}

// 状态筛选
if ($stateFilter !== '') {
    $query->where('state', $stateFilter);
}

$users = $query->order('id desc')->paginate(10, false, ['query' => request()->param()]);

$levels = Db::name('membership_levels')->where('status', 1)->order('level asc')->select();
$levelMap = [];
foreach ($levels as $lv) { $levelMap[$lv['level']] = $lv; }

return $this->fetch('/'.$this->web["admintemplate"]."/user", [
    "users"          => $users,
    "levelMap"       => $levelMap,
    "search"         => $search,
    "realnameFilter" => $realnameFilter,
    "vipFilter"      => $vipFilter,
    "oauthFilter"    => $oauthFilter,
    "stateFilter"    => $stateFilter,
]);
}else{
$data=Db::name("user")->where("id",$id)->find();
if($data){
if($orderid){
$data1=Db::name("order")->where([
"id"=>$orderid,
"userid"=>$id,
])->find();
if($data1){
//用户产品
if(Request::instance()->isPost()) {
$act=input("act");
if($act=="edit"){
$post=input("post.");
$post["atime"]=strtotime($post["atime"]);
if($post["atime"]==""){
$post["atime"]="1";
}
$post["ztime"]=strtotime($post["ztime"]);
if($post["ztime"]==""){
$post["ztime"]="1";
}
unset($post["act"]);
$db=Db::name("order")->where([
"id"=>$orderid,
"userid"=>$id,
])->update($post);
if($db){
	$array["code"]="1";
	$array["msg"]="修改成功!";
}else{
	$array["code"]="-1";
	$array["msg"]="修改失败!";
}
return json($array);
}
//暂停
if($act=="stop"){
if($data1["state"]=="3"){
	$array["code"]="-1";
	$array["msg"]="产品已终止,禁止修改此状态!";
}else{
$da1=Db::name('cart')->where("id",$data1["cartid"])->find();
$da2=Db::name('server')->where("id",$da1["serverid"])->find();
include_once PATH."plugins/host/".$da2["serverplugins"]."/".$da2["serverplugins"].".php";
$function=$da2["serverplugins"]."_"."SuspendAccount";
if(function_exists($function)){
$da4=@$function($da2,$data1,$da1);
}
$da5=Db::name("order")->where("id",$data1["id"])->update([
"state"=>"2",
]);
	$array["code"]="1";
	$array["msg"]="暂停成功!";
}
return json($array);

}
//解除暂停
if($act=="stopoff"){
if($data1["state"]=="3"){
	$array["code"]="-1";
	$array["msg"]="产品已终止,禁止修改此状态!";
}else{
$da1=Db::name('cart')->where("id",$data1["cartid"])->find();
$da2=Db::name('server')->where("id",$da1["serverid"])->find();
include_once PATH."plugins/host/".$da2["serverplugins"]."/".$da2["serverplugins"].".php";
$function=$da2["serverplugins"]."_"."UnsuspendAccount";
if(function_exists($function)){
$da3=@$function($da2,$data1,$da1);
}
$da5=Db::name("order")->where("id",$data1["id"])->update([
"state"=>"1",
]);
	$array["code"]="1";
	$array["msg"]="解除暂停成功!";
}
return json($array);

}
//终止
if($act=="end"){
$da1=Db::name('cart')->where("id",$data1["cartid"])->find();
$da2=Db::name('server')->where("id",$da1["serverid"])->find();
include_once PATH."plugins/host/".$da2["serverplugins"]."/".$da2["serverplugins"].".php";
$function=$da2["serverplugins"]."_"."TerminateAccount";
if(function_exists($function)){
$da4=@$function($da2,$data1,$da1);
}
$da5=Db::name("order")->where("id",$data1["id"])->update([
"state"=>"3",
]);
$da6=Db::name("cart")->where("id",$data1["cartid"])->update([
"inventory"=>$da1["inventory"]+1,
]);
	$array["code"]="1";
	$array["msg"]="终止成功!";
return json($array);

}
//删除
if($act=="delete"){
$da5=Db::name("order")->where("id",$data1["id"])->delete();
	$array["code"]="1";
	$array["msg"]="删除成功!";
return json($array);
}


}



$userorder=Db::name("order")->where([
"id"=>$orderid,
"userid"=>$id,
])->find();


$da6=Db::name('cart')->where("id",$userorder["cartid"])->find();
$da5=Db::name('server')->where("id",$da6["serverid"])->find();
include_once PATH."plugins/host/".$da5["serverplugins"]."/".$da5["serverplugins"].".php";
$function=$da5["serverplugins"]."_"."OrderConfigOptions";
if(function_exists($function)){
$da4=@$function();
for($i=0;$i<count($da4);$i++){
foreach ($userorder as $key => $value){
if($da4[$i]["name"]==$key){
if($value!=""){
$da4[$i]["value"]=$value;
}
}
}
}
$data7=$da4;
}else{
$data7="";
}

return $this->fetch('/'.$this->web["admintemplate"]."/userorder",[
"userorder"=>$userorder,
"data7"=>$data7,
]);



}else{
$this->redirect('/admin/user/'.$id);
}
}else{
//用户信息
			if(Request::instance()->isPost()) {
			$act = input("act");
			// 禁用实名信息
			if($act == "clearRealname"){
				$userid = input("userid", 0);
				if(!$userid){
					$array["code"]="-1";
					$array["msg"]="参数错误";
					return json($array);
				}
				$user = Db::name('user')->where('id', $userid)->find();
				if(!$user){
					$array["code"]="-1";
					$array["msg"]="用户不存在";
					return json($array);
				}
				Db::name('user')->where('id', $userid)->update([
					'realname' => '',
					'idcard' => '',
					'realname_status' => 0,
				]);
				$reviewerId = session('adminid');
				$reviewerName = $this->user['user'] ?? '';
				ensure_realname_record_table();
				Db::name('realname_record')->insert([
					'user_id' => $userid,
					'realname' => $user['realname'] ?? '',
					'idcard' => $user['idcard'] ?? '',
					'status' => 0,
					'apply_time' => $user['last_login_time'] ?? time(),
					'review_time' => time(),
					'reviewer_id' => $reviewerId,
					'reviewer_name' => $reviewerName,
				]);
				$array["code"]="1";
				$array["msg"]="已禁用实名信息";
				return json($array);
			}

			$name=input("name");
			$user=input("user");
			$money=input("money");
			$qq=input("qq");
			$mail=input("mail");
			$address=input("address");
			$password=input("password");
			$aff=input("aff");
			$affmoney=input("affmoney");
			$upperid=input("upperid");
			$state=input("state");
			$realname=input("realname", '');
			$idcard=input("idcard", '');
			$realname_status=input("realname_status", 0);
			$membership_level=intval(input("membership_level", 0));
			$points=intval(input("points", 0));
			if($name=="" || $user=="" || $money=="" || $qq=="" || $state=="" || $affmoney==""){
				$array["code"]="-1";
				$array["msg"]="必填项不可为空!";
			}else{
			$update = [
				"name"=>$name,
				"user"=>$user,
				"money"=>$money,
				"qq"=>$qq,
				"mail"=>$mail,
				"address"=>$address,
				"aff"=>$aff,
				"affmoney"=>$affmoney,
				"upperid"=>$upperid,
				"state"=>$state,
				"realname"=>$realname,
				"idcard"=>$idcard,
				"realname_status"=>$realname_status,
				"membership_level"=>$membership_level,
				"points"=>$points,
			];
			if($password){
				$update["password"] = password_hash($password,PASSWORD_DEFAULT);
			}
			$data3=Db::name('user')->where('id',$data["id"])->update($update);
			// 如果余额增加，更新累计充值
			$oldMoney = floatval($data['money'] ?? 0);
			$newMoney = floatval($money);
			if ($newMoney > $oldMoney) {
				$diff = round($newMoney - $oldMoney, 2);
				Db::name('user')->where('id', $data['id'])->setInc('total_recharge', $diff);
				if (function_exists('update_user_membership')) {
					update_user_membership($data['id']);
				}
			}
			if($data3){
				$array["code"]="1";
				$array["msg"]="修改成功!";
			}else{
				$array["code"]="-1";
				$array["msg"]="修改失败!";
			}
			}
			return json($array);
			}

$data2=Db::name("order")->where([
"userid"=>$id,
])->order('id desc')->select();
// 优化：批量查询 cart 表，避免 N+1 查询
$cartIds=array_unique(array_filter(array_column($data2,'cartid')));
$cartMap=[];
if(!empty($cartIds)){
	$cartMap=Db::name('cart')->where('id','in',$cartIds)->column('name','id');
}
for($i=0;$i<count($data2);$i++){
	$cid=$data2[$i]['cartid'];
	$data2[$i]['cartid']=isset($cartMap[$cid])?$cartMap[$cid]:('产品#'.$cid);
}


	
$realnameRecords = Db::name('realname_record')
	->where('user_id', $id)
	->order('id desc')
	->select();

// 手机三要素：仅当用户完成「手机三要素」认证（method=phone 且 status=1）才取手机号展示
$userPhone = '';
if (function_exists('ensure_realname_idcard_table')) {
	ensure_realname_idcard_table();
	$phoneRec = Db::name('realname_idcard')
		->where(['userid' => $id, 'method' => 'phone', 'status' => 1])
		->order('id desc')
		->find();
	if (!empty($phoneRec) && !empty($phoneRec['mobile'])) {
		$userPhone = $phoneRec['mobile'];
	}
}

return $this->fetch('/'.$this->web["admintemplate"]."/userinfo",[
"userinfo"=>$data,
"userorder"=>$data2,
"userid"=>$id,
"realnameRecords"=>$realnameRecords,
"userPhone"=>$userPhone,
]);

}
}else{
$this->redirect('/admin/user');
}


}
}



public function ticket($id=null){
if (!$this->checkPermission('ticket')) {
    $this->error('您没有权限访问此页面', '/admin/index');
}
if($id){
$data=Db::name('ticket')->where("id",$id)->find();
if($data){
if(Request::instance()->isPost()) {
$act=input("act");
if($act=="submit"){
$content=htmlspecialchars(trim(input("content")));
if(!csrf_verify(input('__token__'))) {
	$array["code"]="-1";
	$array["msg"]="安全验证失败，请刷新页面重试!";
	return json($array);
}
if($content==""){
	$array["code"]="-1";
	$array["msg"]="必填参数不可为空!";
}else{
$array1=array(
array(
"personnel"=>"1",
"content"=>$content,
"time"=>time(),
),
);
$array2=array_merge(json_decode($data["content"],true),$array1);
$data1=Db::name('ticket')->where([
"id"=>$id,
])->update([
'content' =>json_encode($array2),
'state'=>'3',
]);
if($data1){
			if($this->web["email"]=="1"){
			$user=Db::name('user')->where("id",$data["userid"])->find();
			if($user["mail"]){
			$mailbox=$this->email($user["mail"],"回复工单通知","管理员在时间:".date("Y-m-d H:i:s")."已回复工单<br/>工单ID:".$id."<br/>标题:".$data["title"]."<br/>回复内容:<br/>".nl2br(htmlspecialchars($content))."<br/><br/>请登录查看完整对话:".request()->domain()."/user/supportticket/".$id);
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
if($data["state"]=="4"){
$array["code"]="-1";
$array["msg"]="工单已关闭!";
}else{
$data1=Db::name('ticket')->where([
"id"=>$id,
])->update([
'state'=>'4',
]);
if($data1){
if($this->web["email"]=="1"){
$user=Db::name('user')->where("id",$data["userid"])->find();
if($user["mail"]){
$mailbox=$this->email($user["mail"],"关闭工单通知","管理员在时间:".date("Y-m-d H:i:s")."已关闭工单<br/>工单id:".$id."<br/><br/>");
}
}
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

$user=Db::name('user')->where('id',$data["userid"])->find();
$data["username"]=$user["name"];
$data["userqq"]=$user["qq"];
$data["content"]=json_decode($data["content"],true);
return $this->fetch('/'.$this->web["admintemplate"]."/tickets",[
"tickets"=>$data,
]);
}else{
$this->redirect('/admin/ticket');
}
}else{
if(Request::instance()->isPost()) {
$act=input("act");
if($act=="delete"){
$cid=input("cid");
if($cid==""){
$array["code"]="-1";
$array["msg"]="必填参数不可为空!";
}else{
$cid=explode(",",input("cid"));
$a="0";
$b="0";
for($i=0;$i<count($cid);$i++){
$data1=Db::name("ticket")->where("id",$cid[$i])->delete();
if($data1){
$a=$a+1;
}else{
$b=$b+1;
}
}
$c="";
if($b>0){
$c="<br/>失败原因:已经删除过了!";
}
$array["code"]="1";
$array["msg"]="成功:".$a.";失败:".$b.$c;
}
return json($array);
}


if($act=="off"){
$cid=input("cid");
if($cid==""){
$array["code"]="-1";
$array["msg"]="必填参数不可为空!";
}else{
$cid=explode(",",input("cid"));
$a="0";
$b="0";
for($i=0;$i<count($cid);$i++){
$data2=Db::name('ticket')->where("id",$cid[$i])->find();
$data1=Db::name("ticket")->where("id",$cid[$i])->update([
"state"=>"4",
]);
if($this->web["email"]=="1"){
$data3=Db::name('user')->where("id",$data2["userid"])->find();
if($data3["mail"]){
$mailbox=$this->email($data3["mail"],"你的工单已被管理员关闭","管理员在时间:".date("Y-m-d H:i:s")."已关闭你的工单<br/>工单ID:".$data2["id"]."<br/><br/>");
}
}
if($data2["state"]!="4"){
$a=$a+1;
}else{
$b=$b+1;
}
}
$c="";
if($b>0){
$c="<br/>失败原因:已经关闭过了!";
}
$array["code"]="1";
$array["msg"]="成功:".$a.";失败:".$b.$c;
}
return json($array);
}

if(input("act")=="qbdelete"){
$data11=Db::name("ticket")->select();
$a="0";
$b="0";
for($i=0;$i<count($data11);$i++){
$data1=Db::name("ticket")->where("id",$data11[$i]["id"])->delete();
if($data1){
$a=$a+1;
}else{
$b=$b+1;
}
}
$c="";
if($b>0){
$c="<br/>失败原因:已删除过此条记录了!";
}
$array["code"]="1";
$array["msg"]="成功:".$a.";失败:".$b.$c;
return json($array);
}
}
$search=input("search");
if($search){
$data=Db::name('ticket')->whereor("id", 'like', '%'.$search.'%')->whereor("userid", 'like', '%'.$search.'%')->whereor("title", 'like', '%'.$search.'%')->order('id desc')->paginate(10,false,['query'=>request()->param()]);
}else{
$data=Db::name('ticket')->order('id desc')->paginate(10);
}
return $this->fetch('/'.$this->web["admintemplate"]."/ticket",[
"ticket"=>$data,
]);
}
}


public function classification($id=null){
if (!$this->checkPermission('classification')) {
    $this->error('您没有权限访问此页面', '/admin/index');
}
if($id){
$data2=$data=Db::name('product')->where("id",$id)->find();
if($data2){
if(Request::instance()->isPost()) {
$name=input("name");
$introduce=input("introduce");
$hide=input("hide");
$sort=input("sort");
if($name==""){
$array["code"]="-1";
$array["msg"]="必填参数不可为空!";
}else{
$data3=Db::name('product')->where("id",$id)->update([
"name"=>$name,
"introduce"=>$introduce,
"hide"=>$hide,
"sort"=>$sort,
]);
if($data3){
$array["code"]="1";
$array["msg"]="修改成功!";
}else{
$array["code"]="-1";
$array["msg"]="修改失败!";
}
}
return json($array);
}

return $this->fetch('/'.$this->web["admintemplate"]."/classifications",[
"product"=>$data2,
]);
}else{
$this->redirect('/admin/classification');
}

}else{
if(Request::instance()->isPost()) {
$act=input("act");
if($act=="add"){
$name=input("name");
$introduce=input("introduce");
$hide=input("hide");
$sort=input("sort");
if($name==""){
$array["code"]="-1";
$array["msg"]="必填参数不可为空!";
}else{
$data1=Db::name('product')->insertGetId([
				"name"=>$name,
				"introduce"=>$introduce,
                "hide"=>$hide,
                "sort"=>$sort,
				]);
if($data1){
$array["code"]="1";
$array["msg"]="添加成功!";
}else{
$array["code"]="-1";
$array["msg"]="添加失败!";
}
}
return json($array);
}

if($act=="delete"){
$cid=input("cid");
if($cid==""){
$array["code"]="-1";
$array["msg"]="必填参数不可为空!";
}else{
$cid=explode(",",input("cid"));
$a="0";
$b="0";
for($i=0;$i<count($cid);$i++){
$data1=Db::name("product")->where("id",$cid[$i])->delete();
if($data1){
$a=$a+1;
}else{
$b=$b+1;
}
}
$c="";
if($b>0){
$c="<br/>失败原因:已经删除过了!";
}
$array["code"]="1";
$array["msg"]="成功:".$a.";失败:".$b.$c;
}
return json($array);
}

if(input("act")=="qbdelete"){
$data11=Db::name("product")->select();
$a="0";
$b="0";
for($i=0;$i<count($data11);$i++){
$data1=Db::name("product")->where("id",$data11[$i]["id"])->delete();
if($data1){
$a=$a+1;
}else{
$b=$b+1;
}
}
$c="";
if($b>0){
$c="<br/>失败原因:已删除过此条记录了!";
}
$array["code"]="1";
$array["msg"]="成功:".$a.";失败:".$b.$c;
return json($array);
}


}
$search=input("search");
if($search){
$data=Db::name('product')->whereor("id", 'like', '%'.$search.'%')->whereor("name", 'like', '%'.$search.'%')->whereor("introduce", 'like', '%'.$search.'%')->paginate(10,false,['query'=>request()->param()]);
}else{
$data=Db::name('product')->paginate(10);
}
return $this->fetch('/'.$this->web["admintemplate"]."/classification",[
"product"=>$data,
]);
}
}


public function server($id=null){
if (!$this->checkPermission('server')) {
    // AJAX 请求不能返回跳转页，否则前端 dataType:json 解析失败会表现为「点了没反应」
    if (Request::instance()->isPost()) {
        return json(['code' => '-1', 'msg' => '您没有服务器管理权限，请联系超级管理员分配权限']);
    }
    $this->error('您没有权限访问此页面', '/admin/index');
}
if($id){
$data2=$data=Db::name('server')->where("id",$id)->find();
if($data2){
if(Request::instance()->isPost()) {
try {
$info=input("post.");
if(!isset($info["name"]) || $info["name"]==""){
$array=["code"=>"-1","msg"=>"必填参数不可为空!"];
}else{
// 过滤掉 server 表中不存在的字段，防止 fields_strict 报错
$serverColumns = Db::query("SHOW COLUMNS FROM " . config('database.prefix') . "server");
$serverColumnNames = array_column($serverColumns, 'Field');
foreach (array_keys($info) as $fieldName) {
if (!in_array($fieldName, $serverColumnNames)) {
unset($info[$fieldName]);
}
}
if(empty($info)){
$array=["code"=>"-1","msg"=>"没有可保存的数据!"];
}else{
$data3=Db::name('server')->where("id",$id)->update($info);
if($data3!==false){
$array=["code"=>"1","msg"=>"修改成功!"];
}else{
$array=["code"=>"-1","msg"=>"修改失败!"];
}
}
}
return json($array);
} catch (\Throwable $e) {
return json(['code'=>'-1','msg'=>'保存失败：'.$e->getMessage()]);
}
}

if(file_exists(PATH."plugins/host/".$data2["serverplugins"]."/".$data2["serverplugins"].".php")){
include_once PATH."plugins/host/".$data2["serverplugins"]."/".$data2["serverplugins"].".php";
$function=$data2["serverplugins"]."_"."AdminConfigOptions";
if(function_exists($function)){
$da4=@$function();

for($i=0;$i<count($da4);$i++){
foreach ($data2 as $key => $value){
if($da4[$i]["name"]==$key){
if($value!=""){
$da4[$i]["value"]=$value;
}
}
}
}
$data7=$da4;
}else{
$data7="";
}
}else{
$data7="";
}
return $this->fetch('/'.$this->web["admintemplate"]."/servers",[
"server"=>$data2,
"plugins"=>my_dir(PATH."/plugins/host"),
"data7"=>$data7,
]);
}else{
$this->redirect('/admin/server');
}

}else{
if(Request::instance()->isPost()) {
$act=input("act");

if($act=="add"){
$info=input("post.");
if($info["name"]==""){
$array["code"]="-1";
$array["msg"]="必填参数不可为空!";
}else{
unset($info["act"]);
$data1=Db::name('server')->insertGetId($info);
if($data1){
$array["code"]="1";
$array["msg"]="添加成功!";
}else{
$array["code"]="-1";
$array["msg"]="添加失败!";
}
}
return json($array);
}

if($act=="delete"){
$cidRaw=trim((string)input("cid"));
if($cidRaw===""){
$array["code"]="-1";
$array["msg"]="请先勾选要删除的服务器!";
return json($array);
}
// 只保留合法 ID，避免 "1,,2" 或非数字内容造成误判
$cidList=[];
foreach(explode(",",$cidRaw) as $cidOne){
$cidOne=intval(trim($cidOne));
if($cidOne>0){ $cidList[]=$cidOne; }
}
if(empty($cidList)){
$array["code"]="-1";
$array["msg"]="未获取到有效的服务器 ID，请刷新页面后重试!";
return json($array);
}
$okIds=[];
$failReasons=[];
$blocked=[];
$force=input("force")=="1";
foreach($cidList as $cidOne){
$srv=Db::name('server')->where("id",$cidOne)->find();
if(empty($srv)){
$failReasons[]="ID:".$cidOne."（服务器不存在，可能已被删除）";
continue;
}
// 被套餐引用时默认不允许删除，否则购买页会指向不存在的线路
$cartRefs=Db::name('cart')->where("serverid",$cidOne)->column('name');
if(!empty($cartRefs)){
if(!$force){
$failReasons[]="ID:".$cidOne."「".$srv['name']."」被 ".count($cartRefs)." 个产品引用（".implode("、",array_slice($cartRefs,0,5)).(count($cartRefs)>5?" 等":"")."）";
$blocked[]=["id"=>$cidOne,"name"=>$srv['name'],"count"=>count($cartRefs)];
continue;
}
// 强制删除：先把产品的默认服务器清空（产品保留，购买页可自由改选其它线路）
Db::name('cart')->where("serverid",$cidOne)->update(["serverid"=>0]);
}
try{
$res=Db::name('server')->where("id",$cidOne)->delete();
}catch(\Throwable $e){
$failReasons[]="ID:".$cidOne."「".$srv['name']."」数据库异常：".$e->getMessage();
continue;
}
if($res){
$okIds[]=$cidOne;
}else{
$failReasons[]="ID:".$cidOne."「".$srv['name']."」（未删除任何数据）";
}
}
$okCount=count($okIds);
$failCount=count($failReasons);
$msg="成功删除 ".$okCount." 台服务器".($okCount>0?"（ID:".implode(",",$okIds)."）":"");
if($failCount>0){
$msg.="<br>失败 ".$failCount." 台：<br>· ".implode("<br>· ",$failReasons);
if(!empty($blocked)){
$msg.="<br><br>被产品引用的服务器需要先解除引用，或使用「强制删除」。";
}
}else{
$msg.="，失败 0 台";
}
$array["code"]="1";
$array["msg"]=$msg;
$array["ok"]=$okCount;
$array["fail"]=$failCount;
$array["blocked"]=$blocked;
return json($array);
}

if($act=="qbdelete"){
$data18=Db::name("server")->select();
if(empty($data18)){
$array["code"]="-1";
$array["msg"]="当前没有可删除的服务器!";
return json($array);
}
$okIds=[];
$failReasons=[];
$blocked=[];
$force=input("force")=="1";
foreach($data18 as $srvRow){
$cidOne=intval($srvRow["id"]);
$cartRefs=Db::name('cart')->where("serverid",$cidOne)->column('name');
if(!empty($cartRefs)){
if(!$force){
$failReasons[]="ID:".$cidOne."「".$srvRow['name']."」被 ".count($cartRefs)." 个产品引用（".implode("、",array_slice($cartRefs,0,5)).(count($cartRefs)>5?" 等":"")."）";
$blocked[]=["id"=>$cidOne,"name"=>$srvRow['name'],"count"=>count($cartRefs)];
continue;
}
Db::name('cart')->where("serverid",$cidOne)->update(["serverid"=>0]);
}
try{
$res=Db::name('server')->where("id",$cidOne)->delete();
}catch(\Throwable $e){
$failReasons[]="ID:".$cidOne."「".$srvRow['name']."」数据库异常：".$e->getMessage();
continue;
}
if($res){
$okIds[]=$cidOne;
}else{
$failReasons[]="ID:".$cidOne."「".$srvRow['name']."」（未删除任何数据）";
}
}
$okCount=count($okIds);
$failCount=count($failReasons);
$msg="成功删除 ".$okCount." 台服务器".($okCount>0?"（ID:".implode(",",$okIds)."）":"");
if($failCount>0){
$msg.="<br>失败 ".$failCount." 台：<br>· ".implode("<br>· ",$failReasons);
if(!empty($blocked)){
$msg.="<br><br>被产品引用的服务器需要先解除引用，或改用「删除选中」时选择强制删除。";
}
}else{
$msg.="，失败 0 台";
}
$array["code"]="1";
$array["msg"]=$msg;
$array["ok"]=$okCount;
$array["fail"]=$failCount;
$array["blocked"]=$blocked;
return json($array);
}
}
$search=input("search");
if($search){
$data=Db::name('server')->whereor("id", 'like', '%'.$search.'%')->whereor("name", 'like', '%'.$search.'%')->whereor("host", 'like', '%'.$search.'%')->whereor("ip", 'like', '%'.$search.'%')->whereor("security", 'like', '%'.$search.'%')->whereor("port", 'like', '%'.$search.'%')->whereor("user", 'like', '%'.$search.'%')->whereor("password", 'like', '%'.$search.'%')->whereor("serverplugins", 'like', '%'.$search.'%')->paginate(10,false,['query'=>request()->param()]);
}else{
$data=Db::name('server')->paginate(10);
}
return $this->fetch('/'.$this->web["admintemplate"]."/server",[
"server"=>$data,
"plugins"=>my_dir(PATH."/plugins/host"),
]);
}
}

public function product($id=null){
if (!$this->checkPermission('product')) {
    $this->error('您没有权限访问此页面', '/admin/index');
}
ensure_cart_table();
if($id){
$data1=Db::name('cart')->where("id",$id)->find();
if($data1){
if(Request::instance()->isPost()) {
$info=input("post.");
if(!is_numeric($info["inventory"]) || !is_numeric($info["money"])){
$array["code"]="-1";
$array["msg"]="库存或价格必须是数字!";
}else{
if(floor($info["inventory"])!=$info["inventory"]){
$array["code"]="-1";
$array["msg"]="库存必须是整数!";
}else{
$upgrades=@json_encode($info["upgrades"]);
if($upgrades=="null"){
$upgrades="";
}
$info["upgrades"]=$upgrades;
if($info["name"]==""){
$array["code"]="-1";
$array["msg"]="必填参数不可为空!";
}else{
if($info["serverid"]!=$data1["serverid"]){
$info["upgrade"]="0";
$info["upgrades"]="";
}
// 各周期自定义价格：必须是合法对象，键为时长倍数、值为正数；空值统一存空串
// （先清洗：下面的「首次购买免费」校验要用它判断套餐是否已经有定价）
$cpClean = [];
if (isset($info['cycle_prices'])) {
$cpRaw = json_decode((string)$info['cycle_prices'], true);
if (is_array($cpRaw)) {
foreach ($cpRaw as $cpK => $cpV) {
$cpK = intval($cpK);
$cpV = round(floatval($cpV), 2);
if ($cpK > 0 && $cpV > 0) { $cpClean[$cpK] = $cpV; }
}
}
$info['cycle_prices'] = $cpClean ? json_encode($cpClean) : '';
}
if($info["firstmo"]=="1" && $info["cycle"]=="unrestricted"){
$array["code"]="-1";
$array["msg"]="一次性产品不可设置首次购买免费!";
}else if($info["firstmo"]=="1" && floatval($info["money"])<=0 && empty($cpClean)){
$array["code"]="-1";
$array["msg"]="基础单价为 0 且未配置「各周期自定义价格」时，产品本身就是免费的，无需再设置首次购买免费！如需按周期分别定价，请在下方「各周期自定义价格」里为时长填价。";
}else{
// 自定义配置加价单价：必须是数字，否则按 0 处理
foreach (["price_space_mb","price_db_mb","price_traffic_mb","price_traffic_gb","price_domain"] as $priceField) {
if (isset($info[$priceField]) && !is_numeric($info[$priceField])) {
$info[$priceField] = 0;
}
}
// 积分价：必须是非负整数
if (isset($info['points_price']) && (!is_numeric($info['points_price']) || intval($info['points_price']) < 0)) {
$info['points_price'] = 0;
}
// Docker 开关：仅允许 0/1
if (isset($info['docker_enabled'])) {
$info['docker_enabled'] = ($info['docker_enabled'] == '1') ? '1' : '0';
}
$data4=Db::name('cart')->where("id",$id)->update($info);
if($data4 !== false){
$array["code"]="1";
$array["msg"]="修改成功!";
}else{
$array["code"]="-1";
$array["msg"]="修改失败!";
}
}
}
}
}
return json($array);
}
$data1["upgrades"]=json_decode($data1["upgrades"],true);
$data11=Db::name('cart')->where("serverid",$data1["serverid"])->select();


$data2=Db::name('product')->select();
$data3=Db::name('server')->select();


$da5=Db::name('server')->where("id",$data1["serverid"])->find();
// PHP 8 兼容：find() 可能返回 null，需先判断再访问数组下标
if(!empty($da5) && isset($da5["serverplugins"]) && file_exists(PATH."plugins/host/".$da5["serverplugins"]."/".$da5["serverplugins"].".php")){
include_once PATH."plugins/host/".$da5["serverplugins"]."/".$da5["serverplugins"].".php";
$function=$da5["serverplugins"]."_"."ConfigOptions";
if(function_exists($function)){
$da4=@$function();
for($i=0;$i<count($da4);$i++){
foreach ($data1 as $key => $value){
if($da4[$i]["name"]==$key){
if($value!=""){
$da4[$i]["value"]=$value;
}
}
}
}
$data7=$da4;
}else{
$data7="";
}
}else{
$data7="";
}
return $this->fetch('/'.$this->web["admintemplate"]."/products",[
"product"=>$data1,//产品信息
"upgrade"=>$data11,//全局升级产品数据
"data2"=>$data2,//全部分类数据
"data3"=>$data3,//全部服务器数据
"data7"=>$data7,
// 各周期自定义价格（JSON 键为时长倍数）
"cyclePrices"=>function_exists('cart_cycle_prices') ? cart_cycle_prices($data1) : [],
]);
}else{
$this->redirect('/admin/product');
}
}else{
if(Request::instance()->isPost()) {
$act=input("act");

if($act=="add"){
$info=input("post.");
if($info["name"]==""){
$array["code"]="-1";
$array["msg"]="必填参数不可为空!";
}else{
if(!is_numeric($info["inventory"]) || !is_numeric($info["money"])){
$array["code"]="-1";
$array["msg"]="库存或价格必须是数字!";
}else{
unset($info["act"]);
try{
$data1=Db::name('cart')->insertGetId($info);
if($data1){
$array["code"]="1";
$array["msg"]="添加成功!";
}else{
$array["code"]="-1";
$array["msg"]="添加失败!";
}
}catch(\Throwable $e){
$array["code"]="-1";
$array["msg"]="添加失败：".$e->getMessage();
}
}
}
return json($array);
}

if($act=="delete"){
$cid=input("cid");
if($cid==""){
$array["code"]="-1";
$array["msg"]="必填参数不可为空!";
}else{
$cid=explode(",",input("cid"));
$a="0";
$b="0";
for($i=0;$i<count($cid);$i++){
$data4=Db::name('order')->where("cartid",$cid[$i])->find();
if($data4){
$b=$b+1;
}else{
$data1=Db::name('cart')->where("id",$cid[$i])->delete();
if($data1){
$a=$a+1;
}else{
$b=$b+1;
}
}
}
$c="";
if($b>0){
$c="<br/>失败原因:已删除过了或者该产品下还有未删除的订单!";
}
$array["code"]="1";
$array["msg"]="成功:".$a.";失败:".$b.$c;
}
return json($array);
}


if($act=="qbdelete"){
$data12=Db::name("cart")->select();
$a="0";
$b="0";
for($i=0;$i<count($data12);$i++){
$data4=Db::name('order')->where("cartid",$data12[$i]["id"])->find();
if($data4){
$b=$b+1;
}else{
$data1=Db::name('cart')->where("id",$data12[$i]["id"])->delete();
if($data1){
$a=$a+1;
}else{
$b=$b+1;
}
}
}
$c="";
if($b>0){
$c="<br/>失败原因:已删除过了或者该产品下还有未删除的订单!";
}
$array["code"]="1";
$array["msg"]="成功:".$a.";失败:".$b.$c;
return json($array);
}

}
$search=input("search");
if($search){
$data=Db::name('cart')->whereor("id", 'like', '%'.$search.'%')->whereor("name", 'like', '%'.$search.'%')->whereor("content", 'like', '%'.$search.'%')->whereor("money", 'like', '%'.$search.'%')->whereor("inventory", 'like', '%'.$search.'%')->paginate(10,false,['query'=>request()->param()]);
}else{
$data=Db::name('cart')->paginate(10);
}
$data2=Db::name('product')->select();
$data3=Db::name('server')->select();
return $this->fetch('/'.$this->web["admintemplate"]."/product",[
"product"=>$data,
"data2"=>$data2,
"data3"=>$data3,
]);
}
}


public function announcement($id=null){
if (!$this->checkPermission('announcement')) {
    $this->error('您没有权限访问此页面', '/admin/index');
}
if($id){
$data1=Db::name('announcement')->where("id",$id)->find();
if($data1){
if(Request::instance()->isPost()) {
$info=input("post.");
if($info["name"]==""){
$array["code"]="-1";
$array["msg"]="必填参数不可为空!";
}else{
$info["time"]=strtotime($info["time"]);
if($info["time"]==""){
$info["time"]="1";
}
$data4=Db::name('announcement')->where("id",$id)->update($info);
if($data4){
$array["code"]="1";
$array["msg"]="修改成功!";
}else{
$array["code"]="-1";
$array["msg"]="修改失败!";
}
}
return json($array);
}
return $this->fetch('/'.$this->web["admintemplate"]."/announcements",[
"announcement"=>$data1,
]);
}else{
$this->redirect('/admin/announcement');
}
}else{
if(Request::instance()->isPost()) {
$act=input("act");
if($act=="add"){
$info=input("post.");
if($info["name"]==""){
$array["code"]="-1";
$array["msg"]="必填参数不可为空!";
}else{
unset($info["act"]);
$info["time"]=time();
$data1=Db::name('announcement')->insertGetId($info);
if($data1){
$array["code"]="1";
$array["msg"]="添加成功!";
}else{
$array["code"]="-1";
$array["msg"]="添加失败!";
}
}
return json($array);
}
if($act=="delete"){
$cid=input("cid");
if($cid==""){
$array["code"]="-1";
$array["msg"]="必填参数不可为空!";
}else{
$cid=explode(",",input("cid"));
$a="0";
$b="0";
for($i=0;$i<count($cid);$i++){
$data1=Db::name("announcement")->where("id",$cid[$i])->delete();
if($data1){
$a=$a+1;
}else{
$b=$b+1;
}
}
$c="";
if($b>0){
$c="<br/>失败原因:已经删除过了!";
}
$array["code"]="1";
$array["msg"]="成功:".$a.";失败:".$b.$c;
}
return json($array);
}

if(input("act")=="qbdelete"){
$data11=Db::name("announcement")->select();
$a="0";
$b="0";
for($i=0;$i<count($data11);$i++){
$data1=Db::name("announcement")->where("id",$data11[$i]["id"])->delete();
if($data1){
$a=$a+1;
}else{
$b=$b+1;
}
}
$c="";
if($b>0){
$c="<br/>失败原因:已删除过此条记录了!";
}
$array["code"]="1";
$array["msg"]="成功:".$a.";失败:".$b.$c;
return json($array);
}
}
$search=input("search");
if($search){
$data=Db::name('announcement')->whereor("id", 'like', '%'.$search.'%')->whereor("name", 'like', '%'.$search.'%')->whereor("information", 'like', '%'.$search.'%')->order('id desc')->paginate(10,false,['query'=>request()->param()]);
}else{
$data=Db::name('announcement')->order('id desc')->paginate(10);
}
return $this->fetch('/'.$this->web["admintemplate"]."/announcement",[
"announcement"=>$data,
]);
}
}


public function aff(){
if (!$this->checkPermission('aff')) {
    $this->error('您没有权限访问此页面', '/admin/index');
}
if(Request::instance()->isPost()) {
$act=input("act");
if($act=="ok"){
$cid=input("cid");
if($cid==""){
$array["code"]="-1";
$array["msg"]="必填参数不可为空!";
}else{
$data3=Db::name('afftxjl')->where("id",$cid)->find();
if($data3["state"]=="1"){
$array["code"]="-1";
$array["msg"]="已经处理过了!";
}else{
$data4=Db::name('afftxjl')->where("id",$cid)->update([
"state"=>"1",
]);
if($data4){
if($this->web["email"]=="1"){
$user=Db::name('user')->where("id",$data3["userid"])->find();
if($user["mail"]){
$mailbox=$this->email($user["mail"],"你的提现申请已处理","管理员在时间:".date("Y-m-d H:i:s")."已处理你的提现申请<br/>提现记录ID:".$cid."<br/>请及时查看!<br/><br/>");
}
}
$array["code"]="1";
$array["msg"]="成功!";
}else{
$array["code"]="-1";
$array["msg"]="失败!";
}
}
}
return json($array);
}
if($act=="delete"){
$cid=input("cid");
if($cid==""){
$array["code"]="-1";
$array["msg"]="必填参数不可为空!";
}else{
$cid=explode(",",input("cid"));
$a="0";
$b="0";
for($i=0;$i<count($cid);$i++){
$data1=Db::name("afftxjl")->where("id",$cid[$i])->delete();
if($data1){
$a=$a+1;
}else{
$b=$b+1;
}
}
$c="";
if($b>0){
$c="<br/>失败原因:已经删除过了!";
}
$array["code"]="1";
$array["msg"]="成功:".$a.";失败:".$b.$c;
}
return json($array);
}

if(input("act")=="qbdelete"){
$data11=Db::name("afftxjl")->select();
$a="0";
$b="0";
for($i=0;$i<count($data11);$i++){
$data1=Db::name("afftxjl")->where("id",$data11[$i]["id"])->delete();
if($data1){
$a=$a+1;
}else{
$b=$b+1;
}
}
$c="";
if($b>0){
$c="<br/>失败原因:已删除过此条记录了!";
}
$array["code"]="1";
$array["msg"]="成功:".$a.";失败:".$b.$c;
return json($array);
}
}
$search=input("search");
if($search){
$data=Db::name('afftxjl')->whereor("id", 'like', '%'.$search.'%')->whereor("information", 'like', '%'.$search.'%')->whereor("money", 'like', '%'.$search.'%')->whereor("state", 'like', '%'.$search.'%')->whereor("userid", 'like', '%'.$search.'%')->order('id desc')->paginate(10,false,['query'=>request()->param()]);
}else{
$data=Db::name('afftxjl')->order('id desc')->paginate(10);
}
return $this->fetch('/'.$this->web["admintemplate"]."/aff",[
"aff"=>$data,
]);
}

public function order($id=null){
if (!$this->checkPermission('order')) {
    $this->error('您没有权限访问此页面', '/admin/index');
}
if($id){
$data1=Db::name('order')->where("id",$id)->find();
if($data1){
$da6=Db::name('cart')->where("id",$data1["cartid"])->find();
$da5=Db::name('server')->where("id",$da6["serverid"])->find();
include_once PATH."plugins/host/".$da5["serverplugins"]."/".$da5["serverplugins"].".php";
$function=$da5["serverplugins"]."_"."OrderConfigOptions";
if(function_exists($function)){
$da4=@$function();
for($i=0;$i<count($da4);$i++){
foreach ($data1 as $key => $value){
if($da4[$i]["name"]==$key){
if($value!=""){
$da4[$i]["value"]=$value;
}
}
}
}
$data7=$da4;
}else{
$data7="";
}

if(Request::instance()->isPost()) {
$act=input("act");


//暂停
if($act=="stop"){
if($data1["state"]=="3"){
	$array["code"]="-1";
	$array["msg"]="产品已终止,禁止修改此状态!";
}else{
// 优化：复用已加载的 $da6(cart) 和 $da5(server), 避免重复查询
$da1=$da6;
$da2=$da5;
include_once PATH."plugins/host/".$da2["serverplugins"]."/".$da2["serverplugins"].".php";
$function=$da2["serverplugins"]."_"."SuspendAccount";
if(function_exists($function)){
$da4=@$function($da2,$data1,$da1);
}
$da5=Db::name("order")->where("id",$data1["id"])->update([
"state"=>"2",
]);
	$array["code"]="1";
	$array["msg"]="暂停成功!";
}
return json($array);

}
//解除暂停
if($act=="stopoff"){
if($data1["state"]=="3"){
	$array["code"]="-1";
	$array["msg"]="产品已终止,禁止修改此状态!";
}else{
// 优化：复用已加载的 $da6(cart) 和 $da5(server), 避免重复查询
$da1=$da6;
$da2=$da5;
include_once PATH."plugins/host/".$da2["serverplugins"]."/".$da2["serverplugins"].".php";
$function=$da2["serverplugins"]."_"."UnsuspendAccount";
if(function_exists($function)){
$da3=@$function($da2,$data1,$da1);
}
$da5=Db::name("order")->where("id",$data1["id"])->update([
"state"=>"1",
]);
	$array["code"]="1";
	$array["msg"]="解除暂停成功!";
}
return json($array);

}
//终止
if($act=="end"){
// 优化：复用已加载的 $da6(cart) 和 $da5(server), 避免重复查询
$da1=$da6;
$da2=$da5;
include_once PATH."plugins/host/".$da2["serverplugins"]."/".$da2["serverplugins"].".php";
$function=$da2["serverplugins"]."_"."TerminateAccount";
if(function_exists($function)){
$da4=@$function($da2,$data1,$da1);
}
$da5=Db::name("order")->where("id",$data1["id"])->update([
"state"=>"3",
]);
$da6=Db::name("cart")->where("id",$data1["cartid"])->update([
"inventory"=>$da1["inventory"]+1,
]);
	$array["code"]="1";
	$array["msg"]="终止成功!";
return json($array);

}
//删除
if($act=="delete"){
$da5=Db::name("order")->where("id",$data1["id"])->delete();
	$array["code"]="1";
	$array["msg"]="删除成功!";
return json($array);
}

if($act=="edit"){
$post=input("post.");
$post["atime"]=strtotime($post["atime"]);
if($post["atime"]==""){
$post["atime"]="1";
}
$post["ztime"]=strtotime($post["ztime"]);
if($post["ztime"]==""){
$post["ztime"]="1";
}
unset($post["act"]);
$db=Db::name("order")->where([
"id"=>$id,
])->update($post);
if($db){
	$array["code"]="1";
	$array["msg"]="修改成功!";
}else{
	$array["code"]="-1";
	$array["msg"]="修改失败!";
}
return json($array);
}
// 手动开通待开通订单
if($act=="createhost"){
if($data1["state"]!="0"){
	$array["code"]="-1";
	$array["msg"]="该订单不是待开通状态!";
	return json($array);
}
$cart=$da6;
$server=$da5;
// 如果订单账号密码为空，自动生成随机凭据
$hostUser = $data1["user"];
$hostPass = $data1["password"];
if(empty($hostUser) || strlen($hostUser) < 3){
	$hostUser = 'user' . rand(10000, 99999);
}
if(empty($hostPass) || strlen($hostPass) < 6){
	$hostPass = substr(md5(uniqid(mt_rand(), true)), 0, 12);
}
// 将生成的凭据更新到订单
if($hostUser != $data1["user"] || $hostPass != $data1["password"]){
	Db::name('order')->where('id', $data1["id"])->update([
		"user" => $hostUser,
		"password" => $hostPass,
	]);
	$data1["user"] = $hostUser;
	$data1["password"] = $hostPass;
}
$times=intval($data1["ztime"])-intval($data1["atime"]);
if($times<0){ $times=0; }
$cycleTime=1;
if($cart["cycle"]=="month") $cycleTime=2592000;
elseif($cart["cycle"]=="season") $cycleTime=7879680;
elseif($cart["cycle"]=="year") $cycleTime=31536000;
elseif($cart["cycle"]=="day") $cycleTime=86400;
elseif($cart["cycle"]=="unrestricted") $cycleTime=3153600000;
$buyTime=($cycleTime>0) ? intval($times/$cycleTime) : 1;
if($buyTime<1){ $buyTime=1; }
$pluginFile=PATH."plugins/host/".$server["serverplugins"]."/".$server["serverplugins"].".php";
if(!file_exists($pluginFile)){
	$array["code"]="-1";
	$array["msg"]="插件文件不存在!";
	return json($array);
}
include_once $pluginFile;
$function=$server["serverplugins"]."_CreateAccount";
if(!function_exists($function)){
	$array["code"]="-1";
	$array["msg"]="未实现开通接口!";
	return json($array);
}
// 应用用户自定义主机配置（存储于订单 data6）
if(function_exists('host_config_from_order') && function_exists('apply_host_custom_config')){
	$customConfig=host_config_from_order($data1);
	if($customConfig){ $cart=apply_host_custom_config($cart, $customConfig); }
}
$result=@$function($server, ["user"=>$data1["user"],"password"=>$data1["password"],"time"=>$buyTime], $cart, $times, $data1["id"]);
if(is_array($result) && isset($result["code"]) && $result["code"]=="1"){
	$array["code"]="1";
	$array["msg"]="开通成功！账号:".$data1["user"]."，密码:".$data1["password"];
	$array["id"]=$data1["id"];
	// 主机开通成功：按 docker_mode 自动/人工开通该主机名下的 Docker 容器
	if(function_exists('docker_provision_pending_for_order')){
		docker_provision_pending_for_order($data1["id"]);
	}
}else{
	$array["code"]="-1";
	$array["msg"]="开通失败：".($result["msg"] ?? '未知错误');
}
return json($array);
}
}

return $this->fetch('/'.$this->web["admintemplate"]."/orders",[
"order"=>$data1,
"data7"=>$data7,
]);
}else{
$this->redirect('/admin/order');
}
}else{
if(Request::instance()->isPost()) {
$act=input("act");
if($act=="delete"){
$cid=input("cid");
if($cid==""){
$array["code"]="-1";
$array["msg"]="必填参数不可为空!";
}else{
$cid=explode(",",input("cid"));
$a="0";
$b="0";
for($i=0;$i<count($cid);$i++){
$data1=Db::name('order')->where("id",$cid[$i])->delete();
if($data1){
$a=$a+1;
}else{
$b=$b+1;
}
}
$c="";
if($b>0){
$c="<br/>失败原因:已删除过了!";
}
$array["code"]="1";
$array["msg"]="成功:".$a.";失败:".$b.$c;
}
return json($array);
}


if($act=="qbdelete"){
$data12=Db::name("order")->select();
$a="0";
$b="0";
for($i=0;$i<count($data12);$i++){
$data1=Db::name('order')->where("id",$data12[$i]["id"])->delete();
if($data1){
$a=$a+1;
}else{
$b=$b+1;
}
}
$c="";
if($b>0){
$c="<br/>失败原因:已删除过了!";
}
$array["code"]="1";
$array["msg"]="成功:".$a.";失败:".$b.$c;
return json($array);
}

}
$search=input("search");
if($search){
$data=Db::name('order')->whereor("id", 'like', '%'.$search.'%')->whereor("user", 'like', '%'.$search.'%')->whereor("password", 'like', '%'.$search.'%')->whereor("userid", 'like', '%'.$search.'%')->whereor("cartid", 'like', '%'.$search.'%')->order('id desc')->paginate(10,false,['query'=>request()->param()]);
}else{
$data=Db::name('order')->order('id desc')->paginate(10);
}
return $this->fetch('/'.$this->web["admintemplate"]."/order",[
"order"=>$data,
]);
}
}


public function pay(){
if (!$this->checkPermission('pay')) {
    $this->error('您没有权限访问此页面', '/admin/index');
}
if(Request::instance()->isPost()) {
$act=input("act");
if($act=="delete"){
$cid=input("cid");
if($cid==""){
$array["code"]="-1";
$array["msg"]="必填参数不可为空!";
}else{
$cid=explode(",",input("cid"));
$a="0";
$b="0";
for($i=0;$i<count($cid);$i++){
$data1=Db::name('pay')->where("id",$cid[$i])->delete();
if($data1){
$a=$a+1;
}else{
$b=$b+1;
}
}
$c="";
if($b>0){
$c="<br/>失败原因:已删除过了!";
}
$array["code"]="1";
$array["msg"]="成功:".$a.";失败:".$b.$c;
}
return json($array);
}


if($act=="qbdelete"){
$data12=Db::name("pay")->select();
$a="0";
$b="0";
for($i=0;$i<count($data12);$i++){
$data1=Db::name('pay')->where("id",$data12[$i]["id"])->delete();
if($data1){
$a=$a+1;
}else{
$b=$b+1;
}
}
$c="";
if($b>0){
$c="<br/>失败原因:已删除过了!";
}
$array["code"]="1";
$array["msg"]="成功:".$a.";失败:".$b.$c;
return json($array);
}
}
$search=input("search");
if($search){
$data=Db::name('pay')->whereor("id", 'like', '%'.$search.'%')->whereor("name", 'like', '%'.$search.'%')->whereor("ordernumber", 'like', '%'.$search.'%')->whereor("pay", 'like', '%'.$search.'%')->whereor("money", 'like', '%'.$search.'%')->whereor("userid", 'like', '%'.$search.'%')->whereor("state", 'like', '%'.$search.'%')->order('id desc')->paginate(10,false,['query'=>request()->param()]);
}else{
$data=Db::name('pay')->order('id desc')->paginate(10);
}
return $this->fetch('/'.$this->web["admintemplate"]."/pay",[
"pay"=>$data,
]);
}

public function templateset(){
if (!$this->checkPermission('set')) {
    $this->error('您没有权限访问此页面', '/admin/index');
}
if(Request::instance()->isPost()) {
$input=input("post.");
if($input){
$wj=include_once(PATH."/app/index/view/".$this->web["template"]."/set.php");
for($i=0;$i<count($wj);$i++){
foreach ($input as $key => $value){
if($wj[$i]["name"]==$key){
$wj[$i]["value"]=$value;
}
}
}
$data=Db::name('web')->where('id',"1")->update([
"templateset"=>json_encode($wj),
]);
if($data){
$array["code"]="1";
$array["msg"]="修改成功!";
}else{
$array["code"]="-1";
$array["msg"]="修改失败!";
}
}else{
$array["code"]="-1";
$array["msg"]="没有数据!";
}
return json($array);
}
$tyyy=@json_decode($this->web['templateset'],true);
if($tyyy=="null"){
$tyyy="";
}
return $this->fetch('/'.$this->web["admintemplate"]."/templateset",[
"tempset"=>$tyyy,
]);
}

public function transferrecord(){
if (!$this->checkPermission('aff')) {
    $this->error('您没有权限访问此页面', '/admin/index');
}
if(Request::instance()->isPost()) {
$act=input("act");
if($act=="delete"){
$cid=input("cid");
if($cid==""){
$array["code"]="-1";
$array["msg"]="必填参数不可为空!";
}else{
$cid=explode(",",input("cid"));
$a="0";
$b="0";
for($i=0;$i<count($cid);$i++){
$data1=Db::name('transferrecord')->where("id",$cid[$i])->delete();
if($data1){
$a=$a+1;
}else{
$b=$b+1;
}
}
$c="";
if($b>0){
$c="<br/>失败原因:已删除过了!";
}
$array["code"]="1";
$array["msg"]="成功:".$a.";失败:".$b.$c;
}
return json($array);
}


if($act=="qbdelete"){
$data12=Db::name("transferrecord")->select();
$a="0";
$b="0";
for($i=0;$i<count($data12);$i++){
$data1=Db::name('transferrecord')->where("id",$data12[$i]["id"])->delete();
if($data1){
$a=$a+1;
}else{
$b=$b+1;
}
}
$c="";
if($b>0){
$c="<br/>失败原因:已删除过了!";
}
$array["code"]="1";
$array["msg"]="成功:".$a.";失败:".$b.$c;
return json($array);
}
}
$search=input("search");
if($search){
$data=Db::name('transferrecord')->whereor("id", 'like', '%'.$search.'%')->whereor("userid", 'like', '%'.$search.'%')->whereor("record", 'like', '%'.$search.'%')->order('id desc')->paginate(10,false,['query'=>request()->param()]);
}else{
$data=Db::name('transferrecord')->order('id desc')->paginate(10);
}
return $this->fetch('/'.$this->web["admintemplate"].'/transferrecord',[
"data"=>$data,
]);
}

public function transferHostRecord(){
	ensure_host_transfer_table();
	if (!$this->checkPermission('aff')) {
		$this->error('您没有权限访问此页面', '/admin/index');
	}
	if(Request::instance()->isPost()) {
		$act=input("act");
		if($act=="reject"){
			$id=intval(input("id"));
			$reason=trim(input("reason"));
			if($id<=0){
				return json(['code'=>'-1','msg'=>'参数错误']);
			}
			if(empty($reason)){
				return json(['code'=>'-1','msg'=>'请填写驳回原因']);
			}
			$transfer=Db::name('host_transfer')->where('id',$id)->where('status','0')->find();
			if(!$transfer){
				return json(['code'=>'-1','msg'=>'该转让记录不存在或已处理']);
			}
			Db::name('host_transfer')->where('id',$id)->update([
				'status'=>2,
				'reject_reason'=>$reason,
				'updated_at'=>time(),
			]);
			return json(['code'=>'1','msg'=>'已成功驳回该转让']);
		}
	}
	// 修复：使用正确的表名前缀
	$userTable = Db::name('user')->getTable();
	$search=input("search");
	if($search){
		$data=Db::name('host_transfer')
			->alias('t')
			->join($userTable.' s','t.userid=s.id','LEFT')
			->join($userTable.' b','t.buyer_userid=b.id','LEFT')
			->join($userTable.' tg','t.target_userid=tg.id','LEFT')
			->where(function($q) use($search){
				$q->where('t.order_id','like','%'.$search.'%')
				  ->whereOr('s.user','like','%'.$search.'%')
				  ->whereOr('b.user','like','%'.$search.'%');
			})
			->field('t.*, s.user as seller_name, b.user as buyer_name, tg.user as target_name')
			->order('t.id desc')
			->paginate(10,false,['query'=>request()->param()]);
	}else{
		$data=Db::name('host_transfer')
			->alias('t')
			->join($userTable.' s','t.userid=s.id','LEFT')
			->join($userTable.' b','t.buyer_userid=b.id','LEFT')
			->join($userTable.' tg','t.target_userid=tg.id','LEFT')
			->field('t.*, s.user as seller_name, b.user as buyer_name, tg.user as target_name')
			->order('t.id desc')
			->paginate(10);
	}
	return $this->fetch('/'.$this->web["admintemplate"].'/transfer_host_record',[
		"data"=>$data,
		"search"=>$search,
	]);
}

public function transaction(){
if (!$this->checkPermission('aff')) {
    $this->error('您没有权限访问此页面', '/admin/index');
}
if(Request::instance()->isPost()) {
$act=input("act");
if($act=="delete"){
$cid=input("cid");
if($cid==""){
$array["code"]="-1";
$array["msg"]="必填参数不可为空!";
}else{
$cid=explode(",",input("cid"));
$a="0";
$b="0";
for($i=0;$i<count($cid);$i++){
$data1=Db::name('transaction')->where("id",$cid[$i])->delete();
if($data1){
$a=$a+1;
}else{
$b=$b+1;
}
}
$c="";
if($b>0){
$c="<br/>失败原因:已删除过了!";
}
$array["code"]="1";
$array["msg"]="成功:".$a.";失败:".$b.$c;
}
return json($array);
}


if($act=="qbdelete"){
$data12=Db::name("transaction")->select();
$a="0";
$b="0";
for($i=0;$i<count($data12);$i++){
$data1=Db::name('transaction')->where("id",$data12[$i]["id"])->delete();
if($data1){
$a=$a+1;
}else{
$b=$b+1;
}
}
$c="";
if($b>0){
$c="<br/>失败原因:已删除过了!";
}
$array["code"]="1";
$array["msg"]="成功:".$a.";失败:".$b.$c;
return json($array);
}

}

$search=input("search");
if($search){
$data=Db::name('transaction')->whereor("id", 'like', '%'.$search.'%')->whereor("userid", 'like', '%'.$search.'%')->whereor("content", 'like', '%'.$search.'%')->order('id desc')->paginate(10,false,['query'=>request()->param()]);
}else{
$data=Db::name('transaction')->order('id desc')->paginate(10);
}
return $this->fetch('/'.$this->web["admintemplate"].'/transaction',[
"data"=>$data,
]);
}



public function pays($id=null){
if (!$this->checkPermission('pays')) {
    $this->error('您没有权限访问此页面', '/admin/index');
}
if($id){
$data1=Db::name('pays')->where("id",$id)->find();
if($data1){
if(Request::instance()->isPost()) {
$input=input("post.");
if($input){
if(file_exists(PATH."/plugins/pay/".$data1["plugins"]."/set.php")){
$wj=include_once(PATH."/plugins/pay/".$data1["plugins"]."/set.php");
for($i=0;$i<count($wj);$i++){
foreach ($input as $key => $value){
if($wj[$i]["name"]==$key){
$wj[$i]["value"]=$value;
}
}
}
$sj=json_encode($wj);
}else{
$sj="";
}
$data=Db::name('pays')->where('id',$id)->update([
"name"=>$input["nname"],
"state"=>$input["nstate"],
"data"=>$sj,
]);
if($data){
$array["code"]="1";
$array["msg"]="修改成功!";
}else{
$array["code"]="-1";
$array["msg"]="修改失败!";
}
}else{
$array["code"]="-1";
$array["msg"]="没有数据!";
}
return json($array);
}
$tyyy=@json_decode($data1["data"],true);
if($tyyy=="null"){
$tyyy="";
}
return $this->fetch('/'.$this->web["admintemplate"]."/payss",[
"nname"=>$data1["name"],
"nstate"=>$data1["state"],
"payss"=>$tyyy,
]);
}else{
$this->redirect('/admin/pays');
}
}else{
if(Request::instance()->isPost()) {
$act=input("act");
if($act=="add"){
$info=input("post.");
if($info["name"]=="" || !$info["plugins"]){
$array["code"]="-1";
$array["msg"]="必填参数不可为空!";
}else{
unset($info["act"]);
if(file_exists(PATH."/plugins/pay/".$info["plugins"]."/set.php")){
$wj=include_once(PATH."/plugins/pay/".$info["plugins"]."/set.php");
$info["data"]=json_encode($wj);
}else{
$info["data"]="";
}
// 只保留 pays 表允许的字段，避免多余字段导致 SQL 错误
$allowed = ['name','plugins','state','data'];
foreach ($info as $k => $v) {
    if (!in_array($k, $allowed)) unset($info[$k]);
}
try{
$data1=Db::name('pays')->insertGetId($info);
if($data1){
$array["code"]="1";
$array["msg"]="添加成功!";
}else{
$array["code"]="-1";
$array["msg"]="添加失败!";
}
}catch(\Throwable $e){
$array["code"]="-1";
$array["msg"]="添加失败：".$e->getMessage();
}
}
return json($array);
}





if($act=="delete"){
$cid=input("cid");
if($cid==""){
$array["code"]="-1";
$array["msg"]="必填参数不可为空!";
}else{
$cid=explode(",",input("cid"));
$a="0";
$b="0";
for($i=0;$i<count($cid);$i++){
$data1=Db::name("pays")->where("id",$cid[$i])->delete();
if($data1){
$a=$a+1;
}else{
$b=$b+1;
}
}
$c="";
if($b>0){
$c="<br/>失败原因:已经删除过了!";
}
$array["code"]="1";
$array["msg"]="成功:".$a.";失败:".$b.$c;
}
return json($array);
}

if(input("act")=="qbdelete"){
$data11=Db::name("pays")->select();
$a="0";
$b="0";
for($i=0;$i<count($data11);$i++){
$data1=Db::name("pays")->where("id",$data11[$i]["id"])->delete();
if($data1){
$a=$a+1;
}else{
$b=$b+1;
}
}
$c="";
if($b>0){
$c="<br/>失败原因:已删除过此条通道了!";
}
$array["code"]="1";
$array["msg"]="成功:".$a.";失败:".$b.$c;
return json($array);
}
}
$search=input("search");
if($search){
$data=Db::name('pays')->whereor("id", 'like', '%'.$search.'%')->whereor("name", 'like', '%'.$search.'%')->whereor("plugins", 'like', '%'.$search.'%')->whereor("state", 'like', '%'.$search.'%')->order('id desc')->paginate(10,false,['query'=>request()->param()]);
}else{
$data=Db::name('pays')->order('id desc')->paginate(10);
}
return $this->fetch('/'.$this->web["admintemplate"]."/pays",[
"pays"=>$data,
"payss"=>my_dir(PATH."/plugins/pay"), 
]);
}
}


public function affsy(){
if (!$this->checkPermission('aff')) {
    $this->error('您没有权限访问此页面', '/admin/index');
}
if(Request::instance()->isPost()) {
$act=input("act");
if($act=="delete"){
$cid=input("cid");
if($cid==""){
$array["code"]="-1";
$array["msg"]="必填参数不可为空!";
}else{
$cid=explode(",",input("cid"));
$a="0";
$b="0";
for($i=0;$i<count($cid);$i++){
$data1=Db::name("affsymoney")->where("id",$cid[$i])->delete();
if($data1){
$a=$a+1;
}else{
$b=$b+1;
}
}
$c="";
if($b>0){
$c="<br/>失败原因:已经删除过了!";
}
$array["code"]="1";
$array["msg"]="成功:".$a.";失败:".$b.$c;
}
return json($array);
}

if(input("act")=="qbdelete"){
$data11=Db::name("affsymoney")->select();
$a="0";
$b="0";
for($i=0;$i<count($data11);$i++){
$data1=Db::name("affsymoney")->where("id",$data11[$i]["id"])->delete();
if($data1){
$a=$a+1;
}else{
$b=$b+1;
}
}
$c="";
if($b>0){
$c="<br/>失败原因:已删除过此条记录了!";
}
$array["code"]="1";
$array["msg"]="成功:".$a.";失败:".$b.$c;
return json($array);
}

}
$search=input("search");
if($search){
$data=Db::name('affsymoney')->whereor("id", 'like', '%'.$search.'%')->whereor("information", 'like', '%'.$search.'%')->whereor("money", 'like', '%'.$search.'%')->whereor("userid", 'like', '%'.$search.'%')->order('id desc')->paginate(10,false,['query'=>request()->param()]);
}else{
$data=Db::name('affsymoney')->order('id desc')->paginate(10);
}
return $this->fetch('/'.$this->web["admintemplate"]."/affsy",[
"affsy"=>$data,
]);
}

//发送邮箱
		public static function email($email,$name,$body)
		{
if (!rate_limit('email_send_' . $email, 3, 60)) { return ['code'=>'-1','msg'=>'发送频率过快，请稍后再试']; }
$body = sanitize_email_body($body);
$web=web_config();
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
try {
	$mail = new PHPMailer();
	$mail->IsSMTP();
	$mail->CharSet = $webData['emailchar'];
	$mail->SMTPAuth = $webData['emailauth'];
	$mail->Timeout = 15;
	$mail->SMTPDebug = 0;
	if($webData['emailsecure']){
		$mail->SMTPSecure = $webData['emailsecure'];
	}
	$mail->Port = intval($webData['emailport']);
	$mail->Host = $webData['emailhost'];
	$mail->Username = $webData['emailname'];
	$mail->Password = $webData['emailpass'];
	$mail->From = $webData['emailname'];
	$mail->FromName = $webData['webname'];
	$mail->AddAddress($email);
	$mail->Subject = $name;
	$mail->Body = build_email_html($name, $body);
	$mail->WordWrap = 80;
	$mail->isHTML(true);
	if (!$mail->Send()) {
		$errMsg = $mail->ErrorInfo ?: '未知错误';
		$logDir = defined('LOG_PATH') ? LOG_PATH : (PATH . '/runtime/log/');
		if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
		@file_put_contents($logDir . 'email_error.log', date('Y-m-d H:i:s') . " To:{$email} Subject:{$name} Error:" . $errMsg . "\n", FILE_APPEND);
		if (function_exists('enqueue_email')) enqueue_email($email, $name, $body);
	}
} catch (\Exception $e) {
	$logDir = defined('LOG_PATH') ? LOG_PATH : (PATH . '/runtime/log/');
	if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
	@file_put_contents($logDir . 'email_error.log', date('Y-m-d H:i:s') . " To:{$email} Subject:{$name} Error:" . $e->getMessage() . "\n", FILE_APPEND);
	if (function_exists('enqueue_email')) enqueue_email($email, $name, $body);
}
$array["code"]="1";
$array["msg"]="邮箱发送成功";
return json($array);

}

// 卡密管理
public function cdkey() {
	if (!$this->checkPermission('set')) {
		$this->error('您没有权限访问此页面', '/admin/index');
	}
	ensure_cdkey_table();
	ensure_cdkey_usage_log_table();
	if(Request::instance()->isPost()) {
		$act = input('act');
		$array = ['code' => '-1', 'msg' => ''];

		// 批量添加卡密
		if($act == 'add') {
			$type = input('type', 'balance');
			$count = intval(input('count', 1));
			$money = floatval(input('money', 0));
			$points = intval(input('points', 0));
			$cartid = intval(input('cartid', 0));
			$remark = trim(input('remark', ''));
			$repeatable = intval(input('repeatable', 0));
			$restrict_type = input('restrict_type', 'all');
			$restrict_users = trim(input('restrict_users', ''));
			$exclude_users = trim(input('exclude_users', ''));
			$max_uses = intval(input('max_uses', 0));
			if($max_uses < 0) $max_uses = 0;
			if($count < 1) $count = 1;
			if($count > 500) { $array['msg'] = '单次最多生成500个卡密'; return json($array); }
			if($type == 'balance' && $money <= 0) { $array['msg'] = '余额充值金额必须大于0'; return json($array); }
			if($type == 'points' && $points <= 0) { $array['msg'] = '积分卡密的积分数额必须大于0'; return json($array); }
			if($type == 'host' && $cartid <= 0) { $array['msg'] = '请选择关联产品'; return json($array); }
			$prefix = 'CD' . strtoupper(substr(md5(time().mt_rand()), 0, 4));
			$insertData = [];
			$now = time();
			for($i = 0; $i < $count; $i++) {
				$key = $prefix . strtoupper(substr(md5(mt_rand().uniqid()), 0, 16));
				$insertData[] = [
					'cdkey' => $key,
					'type' => $type,
					'money' => $money,
					'points' => $points,
					'cartid' => $cartid,
					'status' => 0,
					'created_at' => $now,
					'remark' => $remark,
					'repeatable' => $repeatable,
					'restrict_type' => $restrict_type,
					'restrict_users' => $restrict_users,
					'exclude_users' => $exclude_users,
					'max_uses' => $max_uses,
				];
			}
			Db::name('cdkey')->insertAll($insertData);
			$array['code'] = '1';
			$array['msg'] = "成功生成 {$count} 个卡密";
			return json($array);
		}

		// 追缴（停用）卡密
		if($act == 'disable') {
			$ids = input('ids', '');
			if(empty($ids)) { $array['msg'] = '请选择要追缴的卡密'; return json($array); }
			$idArr = explode(',', $ids);
			Db::name('cdkey')->where('id', 'in', $idArr)->where('status', '0')->update(['status' => 2]);
			$array['code'] = '1';
			$array['msg'] = '已追缴所选卡密';
			return json($array);
		}

		// 回收（重新启用）卡密
		if($act == 'enable') {
			$ids = input('ids', '');
			if(empty($ids)) { $array['msg'] = '请选择要回收的卡密'; return json($array); }
			$idArr = explode(',', $ids);
			Db::name('cdkey')->where('id', 'in', $idArr)->where('status', '2')->update(['status' => 0]);
			$array['code'] = '1';
			$array['msg'] = '已回收所选卡密，可重新使用';
			return json($array);
		}

		// 删除卡密
		if($act == 'delete') {
			$ids = input('ids', '');
			if(empty($ids)) { $array['msg'] = '请选择要删除的卡密'; return json($array); }
			$idArr = explode(',', $ids);
			Db::name('cdkey')->where('id', 'in', $idArr)->delete();
			$array['code'] = '1';
			$array['msg'] = '已删除所选卡密';
		return json($array);
	}

	// 导出卡密（支持仅导出选中）
	if($act == 'export') {
		$expIds = trim((string)input('ids', ''));
		$expType = input('type', 'all');
		$expStatus = input('status', '');
		$expKeyword = input('keyword', '');
		$expMap = [];
		if($expIds !== ''){
			// 选中导出：以选中的 ID 为准，忽略筛选条件
			$idArr = array_values(array_filter(array_map('intval', explode(',', $expIds))));
			if(empty($idArr)) { $array['msg'] = '请选择要导出的卡密'; return json($array); }
			$expMap['id'] = ['in', $idArr];
		} else {
			if($expType != 'all' && $expType != '') $expMap['type'] = $expType;
			if($expStatus !== '' && $expStatus !== 'all') $expMap['status'] = intval($expStatus);
			if($expKeyword) $expMap['cdkey'] = ['like', "%{$expKeyword}%"];
		}
		$expList = Db::name('cdkey')->where($expMap)->order('id desc')->limit(5000)->column('cdkey');
		if (empty($expList)) {
			$array['msg'] = '没有可导出的卡密';
			return json($array);
		}
		$array['code'] = '1';
		$array['cdkeys'] = $expList;
		return json($array);
	}

	return json($array);
}
$type = input('type', 'all');
	$status = input('status', '');
	$keyword = input('keyword', '');
	$map = [];
	if($type != 'all') $map['type'] = $type;
	if($status !== '' && $status !== 'all') $map['status'] = intval($status);
	if($keyword) $map['cdkey'] = ['like', "%{$keyword}%"];
	$list = Db::name('cdkey')->where($map)->order('id desc')->paginate(15);
	// 为 once_per_user（全站通用）卡密附加：使用次数 + 已兑换用户列表（含头像）
	$oncePerUserCdkeys = [];
	foreach ($list as $item) {
		if (isset($item['restrict_type']) && $item['restrict_type'] == 'once_per_user') {
			$oncePerUserCdkeys[] = $item['cdkey'];
		}
	}
	$usageCounts = [];
	$usageUsers  = []; // cdkey => [ ['id','name','account','avatar','used_at'], ... ]
	if (!empty($oncePerUserCdkeys)) {
		$logs = Db::name('cdkey_usage_log')->where('cdkey', 'in', $oncePerUserCdkeys)->order('id asc')->select();
		$uidArr = [];
		foreach ($logs as $lg) { $uidArr[] = intval($lg['userid']); }
		$uidArr = array_values(array_unique($uidArr));
		$userMap = [];
		if (!empty($uidArr)) {
			try {
				$userRows = Db::name('user')->where('id', 'in', $uidArr)->field('id,name,user,avatar,qq')->select();
				foreach ($userRows as $uRow) { $userMap[intval($uRow['id'])] = $uRow; }
			} catch (\Throwable $e) { $userMap = []; }
		}
		$showLimit = 200; // 单张卡密最多展示 200 个兑换用户，避免页面过大
		foreach ($logs as $lg) {
			$ck  = $lg['cdkey'];
			$uid = intval($lg['userid']);
			$uRow = isset($userMap[$uid]) ? $userMap[$uid] : null;
			if (!isset($usageUsers[$ck])) { $usageUsers[$ck] = []; }
			if (count($usageUsers[$ck]) < $showLimit) {
				$usageUsers[$ck][] = [
					'id'      => $uid,
					'name'    => $uRow && !empty($uRow['name']) ? $uRow['name'] : ('用户#' . $uid),
					'account' => $uRow && isset($uRow['user']) ? $uRow['user'] : '',
					'avatar'  => $uRow ? get_user_avatar($uRow) : '',
					'used_at' => intval($lg['used_at']),
				];
			}
			$usageCounts[$ck] = isset($usageCounts[$ck]) ? $usageCounts[$ck] + 1 : 1;
		}
	}
	// 注意：Paginator 的 getIterator() 返回的是内部数组副本，用 foreach 引用修改不会生效，
	// 必须用 each()（它会把回调返回值写回内部 Collection）。
	$list->each(function ($item, $key) use ($usageCounts, $usageUsers) {
		if (isset($item['restrict_type']) && $item['restrict_type'] == 'once_per_user') {
			$item['usage_count'] = isset($usageCounts[$item['cdkey']]) ? intval($usageCounts[$item['cdkey']]) : 0;
			$item['used_users']  = isset($usageUsers[$item['cdkey']]) ? $usageUsers[$item['cdkey']] : [];
			// 兼容旧数据：无 usage_log 但 status=1 时，用 used_userid 兜底展示
			if (empty($item['used_users']) && !empty($item['used_userid'])) {
				$fb = Db::name('user')->where('id', intval($item['used_userid']))->field('id,name,user,avatar,qq')->find();
				$item['used_users'] = [[
					'id'      => intval($item['used_userid']),
					'name'    => $fb && !empty($fb['name']) ? $fb['name'] : ('用户#' . intval($item['used_userid'])),
					'account' => $fb && isset($fb['user']) ? $fb['user'] : '',
					'avatar'  => $fb ? get_user_avatar($fb) : '',
					'used_at' => intval($item['used_at']),
				]];
				$item['usage_count'] = 1;
			}
		}
		return $item;
	});
	$cartList = Db::name('cart')->where('hide', '0')->field('id,name')->order('id desc')->select();
	return $this->fetch('/'.$this->web["admintemplate"].'/cdkey', [
		'list' => $list,
		'cartList' => $cartList,
		'type' => $type,
		'status' => $status,
		'keyword' => $keyword,
	]);
}

// ========== 管理员登录日志 ==========
public function loginLog() {
	if (!$this->checkPermission('set') && !$this->user['is_super']) {
		$this->error('您没有权限访问此页面', '/admin/index');
	}
	// 确保表存在
	try {
		$tableName = Db::name('admin_login_log')->getTable();
		Db::execute("CREATE TABLE IF NOT EXISTS `{$tableName}` (
			`id` int(11) NOT NULL AUTO_INCREMENT,
			`admin_id` int(11) DEFAULT 0 COMMENT '管理员ID',
			`username` varchar(50) DEFAULT '' COMMENT '登录用户名',
			`ip` varchar(50) DEFAULT '' COMMENT '登录IP',
			`status` tinyint(1) DEFAULT 0 COMMENT '1=成功 0=失败',
			`msg` varchar(255) DEFAULT '' COMMENT '备注',
			`create_time` int(11) DEFAULT 0,
			PRIMARY KEY (`id`),
			KEY `idx_admin_id` (`admin_id`),
			KEY `idx_create_time` (`create_time`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8");
	} catch (\Exception $e) {}
	$search = input('search', '');
	if($search) {
		$data = Db::name('admin_login_log')
			->where('username', 'like', '%'.$search.'%')
			->whereOr('ip', 'like', '%'.$search.'%')
			->order('id desc')->paginate(15, false, ['query' => request()->param()]);
	} else {
		$data = Db::name('admin_login_log')->order('id desc')->paginate(15);
	}
	return $this->fetch('/'.$this->web["admintemplate"].'/login_log', [
		'logs' => $data,
		'search' => $search,
	]);
}

// 操作日志
public function opLog() {
	if (!$this->checkPermission('op_log') && !$this->user['is_super']) {
		$this->error('您没有权限访问此页面', '/admin/index');
	}
	$action = input('action', '');
	$search = input('search', '');
	$query = Db::name('admin_op_log')->order('id desc');
	if ($action) {
		$query->where('action', $action);
	}
	if ($search) {
		$query->where('admin_name|target|ip', 'like', '%' . $search . '%');
	}
	$data = $query->paginate(15, false, ['query' => request()->param()]);
	return $this->fetch('/'.$this->web["admintemplate"].'/op_log', [
		'logs' => $data,
		'action' => $action,
		'search' => $search,
	]);
}

// ========== 违规用户通报系统 ==========
public function violation($id = null) {
	if (!$this->checkPermission('user')) {
		$this->error('您没有权限访问此页面', '/admin/index');
	}
	// 确保表存在
	try {
		$tableName = Db::name('violation')->getTable();
		Db::execute("CREATE TABLE IF NOT EXISTS `{$tableName}` (
			`id` int(11) NOT NULL AUTO_INCREMENT,
			`user_id` int(11) DEFAULT 0 COMMENT '用户ID',
			`username` varchar(100) DEFAULT '' COMMENT '用户名',
			`title` varchar(255) DEFAULT '' COMMENT '违规标题',
			`content` text COMMENT '违规内容描述',
			`reason` text COMMENT '处罚原因',
			`punishment` varchar(255) DEFAULT '' COMMENT '处罚措施',
			`images` text COMMENT '证据图片',
			`status` tinyint(1) DEFAULT 1 COMMENT '1=公示 0=隐藏',
			`create_time` int(11) DEFAULT 0,
			`update_time` int(11) DEFAULT 0,
			PRIMARY KEY (`id`),
			KEY `idx_user_id` (`user_id`),
			KEY `idx_status` (`status`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8");
	} catch (\Exception $e) {}

	// 统一处理所有 POST 请求（upload_image 列表页和编辑页通用）
	if(Request::instance()->isPost()) {
		$act = input('act');
		$array = ['code' => '-1', 'msg' => ''];

		// CSRF 验证（图片上传使用 FormData，单独验证）
		if($act != 'upload_image') {
			$csrf = input('csrf_token', '');
			if(!csrf_verify($csrf)) {
				$array['msg'] = '安全验证失败，请刷新页面重试';
				return json($array);
			}
		}

		// 图片上传（列表页和编辑页通用）
		if($act == 'upload_image') {
			try {
				$file = request()->file('file');
				if(!$file) { $array['msg'] = '请选择文件'; return json($array); }
				// 确保上传目录存在
				$uploadDir = PATH . 'public/uploads/violation/';
				if(!is_dir($uploadDir)) {
					@mkdir($uploadDir, 0755, true);
				}
				// 如果子目录创建失败，回退到 uploads 根目录
				if(!is_dir($uploadDir) || !is_writable($uploadDir)) {
					$uploadDir = PATH . 'public/uploads/';
				}
				// 安全校验：扩展名 + MIME 类型双重验证
				$info = $file->validate([
					'size' => 5242880, // 5MB
					'ext'  => 'jpg,jpeg,png,webp',
				])->move($uploadDir);
				if(!$info) {
					$array['msg'] = $file->getError() ?: '文件上传失败';
					return json($array);
				}
				// 二次校验：真实 MIME 类型
				$realPath = $info->getRealPath();
				if(function_exists('finfo_open')) {
					$finfo = finfo_open(FILEINFO_MIME_TYPE);
					$mime = finfo_file($finfo, $realPath);
					finfo_close($finfo);
					$allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
					if(!in_array($mime, $allowedMimes)) {
						@unlink($realPath);
						$array['msg'] = '文件类型不允许（仅支持 JPG/PNG/WebP）';
						return json($array);
					}
				}
				// 构建 URL：如果回退到了 uploads 根目录，URL 前缀相应调整
				$saveName = $info->getSaveName();
				$url = (strpos($uploadDir, 'violation') !== false) 
					? '/uploads/violation/' . $saveName 
					: '/uploads/' . $saveName;
				$array['code'] = '1';
				$array['url'] = $url;
				$array['msg'] = '上传成功';
				return json($array);
			} catch (\Exception $e) {
				$array['msg'] = '上传异常：' . $e->getMessage();
				return json($array);
			}
		}

		// 编辑页 POST
		if($id && $act == 'edit') {
			$data = Db::name('violation')->where('id', $id)->find();
			if(!$data) { $array['msg'] = '记录不存在'; return json($array); }
			$title = input('title', '');
			$content = input('content', '');
			$reason = input('reason', '');
			$punishment = input('punishment', '');
			$status = input('status', 1);
			$images = input('images', '');
			if(empty($title)) { $array['msg'] = '标题不能为空'; return json($array); }
			Db::name('violation')->where('id', $id)->update([
				'title' => xss_clean($title),
				'content' => $content,
				'reason' => $reason,
				'punishment' => xss_clean($punishment),
				'images' => $images,
				'status' => $status,
				'update_time' => time(),
			]);
			security_log('violation_edit', "Admin edited violation #{$id} title: {$title}");
			$array['code'] = '1';
			$array['msg'] = '修改成功';
			return json($array);
		}

		// 列表页 POST
		if(!$id) {
			if($act == 'add') {
				$userId = input('user_id', 0);
				$username = input('username', '');
				$title = input('title', '');
				$content = input('content', '');
				$reason = input('reason', '');
				$punishment = input('punishment', '');
				$images = input('images', '');
				if(empty($title)) { $array['msg'] = '标题不能为空'; return json($array); }
				Db::name('violation')->insert([
					'user_id' => $userId,
					'username' => xss_clean($username),
					'title' => xss_clean($title),
					'content' => $content,
					'reason' => $reason,
					'punishment' => xss_clean($punishment),
					'images' => $images,
					'status' => 1,
					'create_time' => time(),
					'update_time' => time(),
				]);
				security_log('violation_add', "Admin added violation for user {$username} title: {$title}");
				$array['code'] = '1';
				$array['msg'] = '添加成功';
				return json($array);
			}
			if($act == 'delete') {
				$ids = input('ids', '');
				if(empty($ids)) { $array['msg'] = '请选择记录'; return json($array); }
				$idArr = explode(',', $ids);
				Db::name('violation')->where('id', 'in', $idArr)->delete();
				security_log('violation_delete', "Admin deleted violations: {$ids}");
				$array['code'] = '1';
				$array['msg'] = '删除成功';
				return json($array);
			}
			if($act == 'toggle') {
				$vid = input('id', 0);
				$v = Db::name('violation')->where('id', $vid)->find();
				if(!$v) { $array['msg'] = '记录不存在'; return json($array); }
				$newStatus = $v['status'] == 1 ? 0 : 1;
				Db::name('violation')->where('id', $vid)->update(['status' => $newStatus, 'update_time' => time()]);
				$array['code'] = '1';
				$array['msg'] = $newStatus ? '已公示' : '已隐藏';
				return json($array);
			}
		}

		return json($array);
	}

	// GET 请求：编辑页
	if($id) {
		$data = Db::name('violation')->where('id', $id)->find();
		if(!$data) {
			$this->error('记录不存在', '/admin/violation');
		}
		return $this->fetch('/'.$this->web["admintemplate"].'/violation_edit', [
			'v' => $data,
			'csrf_token' => csrf_token(),
		]);
	}

	// GET 请求：列表页
	$search = input('search', '');
	if($search) {
		$data = Db::name('violation')
			->where('title', 'like', '%'.$search.'%')
			->whereOr('username', 'like', '%'.$search.'%')
			->order('id desc')->paginate(15, false, ['query' => request()->param()]);
	} else {
		$data = Db::name('violation')->order('id desc')->paginate(15);
	}
	return $this->fetch('/'.$this->web["admintemplate"].'/violation', [
		'list' => $data,
		'search' => $search,
		'csrf_token' => csrf_token(),
	]);
}

// ========== 邮件审核实名认证 ==========
public function emailAudit() {
	$token = input('token', '');
	$action = input('action', ''); // approve / reject
	$id = input('id', 0);
	if(empty($token) || empty($action) || !$id) {
		return '<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>审核失败</title><style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;background:#f8fafc;}.box{text-align:center;background:#fff;padding:48px;border-radius:16px;box-shadow:0 10px 40px rgba(0,0,0,0.08);}h2{color:#dc2626;}</style></head><body><div class="box"><h2>审核链接无效</h2><p>参数不完整</p></div></body></html>';
	}
	// 验证 token（用 user_id + realname_status + 密钥 的简单签名）
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
		$this->updateRealnameRecord($id, 1, 0, '邮件审核');
		// 通知用户
		if($this->web["email"]=="1" && !empty($user["mail"])){
			$realname = $user['realname'] ?: $user['name'];
			try { self::email($user["mail"], "实名认证通过通知", '<p>您好 '.htmlspecialchars($realname).'，</p><p>恭喜！您的实名认证已审核通过。</p>'); } catch (\Exception $e) {}
		}
		return '<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>审核成功</title><style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;background:#f8fafc;}.box{text-align:center;background:#fff;padding:48px;border-radius:16px;box-shadow:0 10px 40px rgba(0,0,0,0.08);}.icon{font-size:48px;color:#059669;margin-bottom:16px;}h2{color:#0f172a;}</style></head><body><div class="box"><div class="icon">✓</div><h2>已通过实名认证</h2><p>用户 '.htmlspecialchars($user['realname'] ?: $user['name']).' 的实名认证已审核通过</p></div></body></html>';
	} elseif($action == 'reject') {
		Db::name('user')->where('id', $id)->update(['realname_status' => 2]);
		$this->updateRealnameRecord($id, 2, 0, '邮件审核');
		return '<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>审核成功</title><style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;background:#f8fafc;}.box{text-align:center;background:#fff;padding:48px;border-radius:16px;box-shadow:0 10px 40px rgba(0,0,0,0.08);}.icon{font-size:48px;color:#f59e0b;margin-bottom:16px;}h2{color:#0f172a;}</style></head><body><div class="box"><div class="icon">✕</div><h2>已驳回实名认证</h2><p>用户 '.htmlspecialchars($user['realname'] ?: $user['name']).' 的实名认证已驳回</p></div></body></html>';
	}
	return '<html><head><meta charset="utf-8"><title>错误</title></head><body><h2>未知操作</h2></body></html>';
}

// ========== 公告管理（新） ==========
// ===== 邮件推送（紧急事件通知）：支持 HTML 正文、定向发送、定时发送、手动发送 =====
public function mailPush() {
	if (!$this->checkPermission('set') && !$this->user['is_super']) {
		$this->error('您没有权限访问此页面', '/admin/index');
	}
	if (function_exists('ensure_email_tasks_table')) ensure_email_tasks_table();
	if (function_exists('ensure_email_queue_table')) ensure_email_queue_table();

	$request = Request::instance();
	if ($request->isPost()) {
		$act = $request->post('act');
		$array = ['code' => '-1', 'msg' => ''];

		if ($act == 'save') {
			$title = trim($request->post('title', ''));
			$content = (string) $request->post('content', '');
			$targetType = $request->post('target_type', 'all');
			$targetValue = trim($request->post('target_value', ''));
			$sendMode = $request->post('send_mode', 'now');
			$scheduleAt = trim($request->post('schedule_at', ''));
			if ($title === '') { $array['msg'] = '请填写邮件标题'; return json($array); }
			if (trim(strip_tags($content)) === '') { $array['msg'] = '请填写邮件正文'; return json($array); }
			if (!in_array($targetType, ['all', 'level', 'users', 'emails'], true)) $targetType = 'all';
			if ($targetType !== 'all' && $targetValue === '') { $array['msg'] = '请填写发送对象参数'; return json($array); }
			$scheduleTime = 0;
			if ($sendMode === 'schedule') {
				if ($scheduleAt === '') { $array['msg'] = '请选择定时发送时间'; return json($array); }
				$ts = strtotime($scheduleAt);
				if (!$ts) { $array['msg'] = '定时发送时间格式不正确'; return json($array); }
				if ($ts <= time() + 5) { $array['msg'] = '定时发送时间需要晚于当前时间'; return json($array); }
				$scheduleTime = $ts;
			}
			$taskId = Db::name('email_tasks')->insertGetId([
				'title' => $title,
				'content' => $content,
				'target_type' => $targetType,
				'target_value' => $targetValue,
				'schedule_time' => $scheduleTime,
				'status' => 0,
				'admin_id' => intval(session('adminid')),
				'created_at' => time(),
			]);
			$array['code'] = '1';
			if ($scheduleTime > 0) {
				$array['msg'] = '已创建定时任务，将于 ' . date('Y-m-d H:i', $scheduleTime) . ' 自动发送';
			} else {
				$n = function_exists('run_email_task') ? run_email_task($taskId) : -1;
				if ($n > 0) {
					$array['msg'] = '已立即发送，共 ' . $n . ' 位收件人进入发送队列';
				} elseif ($n === 0) {
					$array['msg'] = '未匹配到任何收件人邮箱，请检查发送对象';
				} else {
					$array['msg'] = '任务已创建，但投递失败：' . (Db::name('email_tasks')->where('id', $taskId)->value('last_error') ?: '未知错误');
				}
			}
			return json($array);
		}

		if ($act == 'send') {
			$tid = intval($request->post('id', 0));
			if (!$tid) { $array['msg'] = '参数错误'; return json($array); }
			$n = function_exists('run_email_task') ? run_email_task($tid) : -1;
			if ($n > 0) {
				$array['code'] = '1';
				$array['msg'] = '已发送，共 ' . $n . ' 位收件人进入发送队列';
			} elseif ($n === -2) {
				$array['msg'] = '该任务已发送过，如需重发请新建一条';
			} elseif ($n === 0) {
				$array['msg'] = '未匹配到任何收件人邮箱';
			} else {
				$array['msg'] = '发送失败，请检查邮件设置';
			}
			return json($array);
		}

		if ($act == 'cancel') {
			$tid = intval($request->post('id', 0));
			if (!$tid) { $array['msg'] = '参数错误'; return json($array); }
			Db::name('email_tasks')->where('id', $tid)->update(['status' => 2, 'finish_time' => time()]);
			$array['code'] = '1';
			$array['msg'] = '已取消';
			return json($array);
		}

		if ($act == 'delete') {
			$tid = intval($request->post('id', 0));
			$ids = $request->post('ids', '');
			if (empty($ids) && $tid) $ids = (string) $tid;
			if (empty($ids)) { $array['msg'] = '请选择任务'; return json($array); }
			Db::name('email_tasks')->where('id', 'in', explode(',', $ids))->delete();
			$array['code'] = '1';
			$array['msg'] = '删除成功';
			return json($array);
		}

		$array['msg'] = '未知操作';
		return json($array);
	}

	$list = Db::name('email_tasks')->order('id desc')->paginate(15, false, ['query' => request()->param()]);
	$queuePending = 0; $queueFail = 0; $queueSent = 0;
	try {
		$queuePending = Db::name('email_queue')->where('status', 0)->count();
		$queueSent = Db::name('email_queue')->where('status', 1)->count();
		$queueFail = Db::name('email_queue')->where('status', 2)->count();
	} catch (\Exception $e) {}
	$levels = [];
	if (function_exists('get_membership_levels')) {
		$levels = get_membership_levels();
	}
	return $this->fetch('/' . $this->web["admintemplate"] . '/mail_push', [
		'list' => $list,
		'queuePending' => $queuePending,
		'queueSent' => $queueSent,
		'queueFail' => $queueFail,
		'levels' => $levels,
		'smtpReady' => (trim((string) ($this->web['emailhost'] ?? '')) !== '' && trim((string) ($this->web['emailname'] ?? '')) !== ''),
	]);
}

public function announcements($id = null) {
	if (!$this->checkPermission('announcement')) {
		$this->error('您没有权限访问此页面', '/admin/index');
	}
	ensure_announcements_table();

	$request = Request::instance();

	// 统一处理所有 POST 请求（增删改查操作）
	// 使用 request()->post() 直接读取 $_POST，避免 input() 走 param() 合并路由参数的问题
	if ($request->isPost()) {
		$act  = $request->post('act');
		$array = ['code' => '-1', 'msg' => ''];

		if ($act == 'add') {
			$title       = trim($request->post('title', ''));
			$content     = $request->post('content', '');
			$notice_type = $request->post('notice_type', 'silent');
			$status      = intval($request->post('status', 1));
			$send_email  = intval($request->post('send_email', 0));
			if (empty($title)) { $array['msg'] = '标题不能为空'; return json($array); }
			Db::name('announcements')->insert([
				'title'       => $title,
				'content'     => $content,
				'notice_type' => $notice_type,
				'status'      => $status,
				'send_email'  => $send_email,
				'created_at'  => time(),
				'updated_at'  => time(),
			]);
			$newAnnId = Db::name('announcements')->getLastInsID();
			$array['code'] = '1';
			$array['msg']  = '发布成功';
			// 勾选了「发送邮件通知所有用户」→ 真正入队群发（由 cron 投递）
			if ($send_email == 1 && $newAnnId) {
				$sendCount = function_exists('send_announcement_mail') ? send_announcement_mail($newAnnId) : 0;
				if ($sendCount > 0) {
					$array['msg'] = '发布成功，已向 ' . $sendCount . ' 位用户发送邮件通知';
				} elseif ($sendCount === 0) {
					$array['msg'] = '发布成功，但没有找到填写了邮箱的用户，未发送邮件';
				} else {
					$array['msg'] = '发布成功，邮件入队失败，请检查邮件设置';
				}
			}
			return json($array);
		}

		if ($act == 'edit') {
			$editId = intval($request->post('id', 0));
			if (!$editId) { $array['msg'] = '参数错误'; return json($array); }
			$title       = trim($request->post('title', ''));
			$content     = $request->post('content', '');
			$notice_type = $request->post('notice_type', 'silent');
			$status      = intval($request->post('status', 1));
			$send_email  = intval($request->post('send_email', 0));
			if (empty($title)) { $array['msg'] = '标题不能为空'; return json($array); }
			Db::name('announcements')->where('id', $editId)->update([
				'title'       => $title,
				'content'     => $content,
				'notice_type' => $notice_type,
				'status'      => $status,
				'send_email'  => $send_email,
				'updated_at'  => time(),
			]);
			$array['code'] = '1';
			$array['msg']  = '修改成功';
			// 本次新勾选了发邮件且尚未发送过 → 补发一次
			if ($send_email == 1) {
				$row0 = Db::name('announcements')->where('id', $editId)->find();
				if ($row0 && intval($row0['email_sent']) !== 1) {
					$n = function_exists('send_announcement_mail') ? send_announcement_mail($editId) : -1;
					if ($n > 0) $array['msg'] = '修改成功，已向 ' . $n . ' 位用户发送邮件通知';
				}
			}
			return json($array);
		}

		if ($act == 'delete') {
			$delId = intval($request->post('id', 0));
			$ids   = $request->post('ids', '');
			if (empty($ids) && $delId) $ids = (string)$delId;
			if (empty($ids)) { $array['msg'] = '请选择公告'; return json($array); }
			$idArr = explode(',', $ids);
			Db::name('announcements')->where('id', 'in', $idArr)->delete();
			$array['code'] = '1';
			$array['msg']  = '删除成功';
			return json($array);
		}

		if ($act == 'toggle') {
			$toggleId = intval($request->post('id', 0));
			if (!$toggleId) { $array['msg'] = '参数错误'; return json($array); }
			$row = Db::name('announcements')->where('id', $toggleId)->find();
			if (!$row) { $array['msg'] = '公告不存在'; return json($array); }
			$newStatus = $row['status'] == 1 ? 0 : 1;
			Db::name('announcements')->where('id', $toggleId)->update(['status' => $newStatus, 'updated_at' => time()]);
			$array['code'] = '1';
			$array['msg']  = $newStatus ? '已显示' : '已隐藏';
			return json($array);
		}

		if ($act == 'sendmail') {
			// 手动把某条公告再次群发给全体用户
			$mailId = intval($request->post('id', 0));
			if (!$mailId) { $array['msg'] = '参数错误'; return json($array); }
			$n = function_exists('send_announcement_mail') ? send_announcement_mail($mailId) : -1;
			if ($n > 0) {
				$array['code'] = '1';
				$array['msg']  = '已向 ' . $n . ' 位用户发送邮件通知';
			} elseif ($n === 0) {
				$array['msg'] = '没有找到填写了邮箱的用户';
			} else {
				$array['msg'] = '发送失败，请检查邮件设置或公告是否存在';
			}
			return json($array);
		}

		$array['msg'] = '未知操作';
		return json($array);
	}

	// GET 请求带 id 参数：返回 JSON 供编辑弹窗加载
	// 使用 request()->get('id') 直接读取 $_GET，不依赖 input()/isAjax()
	$ajaxId = intval($id ?: $request->get('id'));
	if ($ajaxId && $request->isGet()) {
		$data = Db::name('announcements')->where('id', $ajaxId)->find();
		if (!$data) {
			return json(['code' => '-1', 'msg' => '公告不存在']);
		}
		return json(['code' => '1', 'data' => $data]);
	}

	// 列表页
	$search = $request->get('search', '');
	if ($search) {
		$list = Db::name('announcements')
			->where('title', 'like', '%'.$search.'%')
			->order('id desc')
			->paginate(10, false, ['query' => request()->param()]);
	} else {
		$list = Db::name('announcements')->order('id desc')->paginate(10);
	}
	return $this->fetch('/'.$this->web["admintemplate"].'/announcements', [
		'list'   => $list,
		'search' => $search,
		'web'    => $this->web,
	]);
}

// ========== 积分商城管理 ==========
public function pointsProducts($id = null) {
	if (!$this->checkPermission('user')) {
		$this->error('您没有权限访问此页面', '/admin/index');
	}
	ensure_points_products_table();
	if ($id) {
		// 编辑/查看单个产品
		$product = Db::name('points_products')->where('id', $id)->find();
		if (!$product) {
			$this->error('产品不存在');
		}
		if (Request::instance()->isPost()) {
			$csrf = input('csrf_token', '');
			if (!csrf_verify($csrf)) {
				return json(['code' => '-1', 'msg' => '安全验证失败']);
			}
			$data = [
				'name' => input('name', ''),
				'type' => input('type', 'balance'),
				'points' => intval(input('points', 0)),
				'value' => floatval(input('value', 0)),
				'stock' => intval(input('stock', -1)),
				'description' => input('description', ''),
				'status' => intval(input('status', 1)),
				'sort' => intval(input('sort', 0)),
			];
			Db::name('points_products')->where('id', $id)->update($data);
			return json(['code' => '1', 'msg' => '保存成功']);
		}
		$this->assign('product', $product);
		return $this->fetch('/'.$this->web["admintemplate"].'/points_product_edit');
	}
	if (Request::instance()->isPost()) {
		$csrf = input('csrf_token', '');
		if (!csrf_verify($csrf)) {
			return json(['code' => '-1', 'msg' => '安全验证失败']);
		}
		$act = input('act');
		if ($act == 'add') {
			$name = trim(input('name', ''));
			$type = input('type', 'balance');
			$points = intval(input('points', 0));
			$value = input('value', '');
			if ($name === '') {
				return json(['code' => '-1', 'msg' => '请输入产品名称']);
			}
			if ($points <= 0) {
				return json(['code' => '-1', 'msg' => '所需积分必须大于0']);
			}
			if ($value === '' || $value === null) {
				if ($type !== 'unban') {
					return json(['code' => '-1', 'msg' => '请填写产品价值']);
				}
				$value = '0';
			}
			$data = [
				'name' => $name,
				'type' => $type,
				'points' => $points,
				'value' => floatval($value),
				'stock' => intval(input('stock', -1)),
				'description' => input('description', ''),
				'status' => intval(input('status', 1)),
				'sort' => intval(input('sort', 0)),
				'created_at' => time(),
			];
			try {
				Db::name('points_products')->insert($data);
				return json(['code' => '1', 'msg' => '添加成功']);
			} catch (\Exception $e) {
				return json(['code' => '-1', 'msg' => '添加失败：' . $e->getMessage()]);
			}
		}
		if ($act == 'delete') {
			$delId = intval(input('del_id'));
			try {
				Db::name('points_products')->where('id', $delId)->delete();
				return json(['code' => '1', 'msg' => '删除成功']);
			} catch (\Exception $e) {
				return json(['code' => '-1', 'msg' => '删除失败：' . $e->getMessage()]);
			}
		}
	}
	$products = Db::name('points_products')->order('sort asc, id asc')->select();
	$cartList = Db::name('cart')->field('id,name')->where('buy', '<>', '1')->order('id asc')->select();
	$this->assign('products', $products);
	$this->assign('cartList', $cartList);
	return $this->fetch('/'.$this->web["admintemplate"].'/points_products');
}

// ========== 会员等级管理 ==========
public function membershipLevels($id = null) {
	if (!$this->checkPermission('user')) {
		$this->error('您没有权限访问此页面', '/admin/index');
	}
	ensure_membership_levels_table();
	if ($id) {
		$level = Db::name('membership_levels')->where('id', $id)->find();
		if (!$level) {
			$this->error('会员等级不存在');
		}
		if (Request::instance()->isPost()) {
			$csrf = input('csrf_token', '');
			if (!csrf_verify($csrf)) {
				return json(['code' => '-1', 'msg' => '安全验证失败']);
			}
			$data = [
				'name' => input('name', ''),
				'min_recharge' => floatval(input('min_recharge', 0)),
				'discount' => floatval(input('discount', 1.00)),
				'renew_discount' => floatval(input('renew_discount', 1.00)),
				'status' => intval(input('status', 1)),
			];
			Db::name('membership_levels')->where('id', $id)->update($data);
			return json(['code' => '1', 'msg' => '保存成功']);
		}
		$this->assign('level', $level);
		return $this->fetch('/'.$this->web["admintemplate"].'/membership_level_edit');
	}
	$levels = Db::name('membership_levels')->order('level asc')->select();
	$this->assign('levels', $levels);
	return $this->fetch('/'.$this->web["admintemplate"].'/membership_levels');
}

// ========== 访客访问统计 ==========
public function visitorStats() {
	if (!$this->checkPermission('set') && !$this->user['is_super']) {
		$this->error('您没有权限访问此页面', '/admin/index');
	}
	// 确保表存在
	try {
		$tableName = Db::name('visitor_log')->getTable();
		Db::execute("CREATE TABLE IF NOT EXISTS `{$tableName}` (
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
	} catch (\Exception $e) {}

	// POST 重置：清理30天前的记录
	if (Request::instance()->isPost()) {
		$act = input('act', '');
		if ($act == 'reset') {
			$cutoff = time() - 30 * 86400;
			Db::name('visitor_log')->where('visit_time', '<', $cutoff)->delete();
			return json(['code' => '1', 'msg' => '已清理30天前的访问记录']);
		}
		return json(['code' => '-1', 'msg' => '无效操作']);
	}

	$today = date('Y-m-d');

	// 今日统计
	$todayTotal = Db::name('visitor_log')->where('date', $today)->count();
	$todayUniqueIp = Db::name('visitor_log')->where('date', $today)->group('ip')->count();
	$todayUniqueIps = Db::name('visitor_log')->where('date', $today)->field('ip')->group('ip')->column('ip');
	$todayUniqueIpCount = count($todayUniqueIps);

	// 本周统计（周一至周日）
	$weekStart = date('Y-m-d', strtotime('monday this week'));
	$weekEnd = date('Y-m-d', strtotime('sunday this week'));
	$weekTotal = Db::name('visitor_log')->where('date', '>=', $weekStart)->where('date', '<=', $weekEnd)->count();
	$weekUniqueIp = Db::name('visitor_log')->where('date', '>=', $weekStart)->where('date', '<=', $weekEnd)->group('ip')->count();
	$weekUniqueIps = Db::name('visitor_log')->where('date', '>=', $weekStart)->where('date', '<=', $weekEnd)->field('ip')->group('ip')->column('ip');
	$weekUniqueIpCount = count($weekUniqueIps);

	// 近7天每日访问量
	$days7 = [];
	$days7Pv = [];
	$days7Uv = [];
	for ($i = 6; $i >= 0; $i--) {
		$d = date('Y-m-d', strtotime("-{$i} days"));
		$days7[] = date('m/d', strtotime("-{$i} days"));
		$pv = Db::name('visitor_log')->where('date', $d)->count();
		$uv = Db::name('visitor_log')->where('date', $d)->group('ip')->count();
		$days7Pv[] = (int)$pv;
		$days7Uv[] = (int)$uv;
	}

	// 今日24小时分布
	$hours24 = [];
	$hours24Count = [];
	for ($h = 0; $h < 24; $h++) {
		$hours24[] = sprintf('%02d:00', $h);
		$hours24Count[] = (int)Db::name('visitor_log')->where('date', $today)->where('hour', $h)->count();
	}

	// 最近访问记录（最近50条）
	$recentVisits = Db::name('visitor_log')->order('id desc')->limit(50)->select();

	// Top 10 页面
	$topPages = Db::name('visitor_log')
		->field('url, COUNT(*) as cnt')
		->group('url')
		->order('cnt desc')
		->limit(10)
		->select();

	return $this->fetch('/'.$this->web["admintemplate"].'/visitor_stats', [
		'todayTotal'       => $todayTotal,
		'todayUniqueIp'    => $todayUniqueIpCount,
		'weekTotal'        => $weekTotal,
		'weekUniqueIp'     => $weekUniqueIpCount,
		'days7'            => $days7,
		'days7Pv'          => $days7Pv,
		'days7Uv'          => $days7Uv,
		'hours24'          => $hours24,
		'hours24Count'     => $hours24Count,
		'recentVisits'     => $recentVisits,
		'topPages'         => $topPages,
	]);
}

// ========== 用户访问详细统计 ==========
public function userAccessStats() {
	if (!$this->checkPermission('set') && !$this->user['is_super']) {
		$this->error('您没有权限访问此页面', '/admin/index');
	}
	ensure_user_columns();
	if (function_exists('ensure_visitor_log_table')) ensure_visitor_log_table();

	$search = trim(input('search', ''));
	$mobileFilter = input('mobile', ''); // 1=仅移动端

	$query = Db::name('user');
	if ($search !== '') {
		$query->where(function ($q) use ($search) {
			$q->where('user', 'like', '%' . $search . '%')->whereOr('name', 'like', '%' . $search . '%')->whereOr('id', 'like', '%' . $search . '%');
		});
	}
	$users = $query->field('id,user,name,last_login_ip,last_login_time,last_logout_time,state')->order('id desc')->paginate(20, false, ['query' => request()->param()]);
	$items = $users->items();

	// 批量获取每个用户最近一次登录记录（含 UA）
	$ids = array_column($items, 'id');
	$loginMap = [];
	$visitMap = [];
	if (!empty($ids)) {
		try {
			$logins = Db::name('user_login_log')
				->where('user_id', 'in', $ids)
				->where('status', 1)
				->order('id desc')
				->select();
			foreach ($logins as $lg) {
				if (!isset($loginMap[$lg['user_id']])) {
					$loginMap[$lg['user_id']] = $lg;
				}
			}
		} catch (\Exception $e) {}

		try {
			$visits = Db::name('visitor_log')
				->where('user_id', 'in', $ids)
				->order('id desc')
				->limit(2000)
				->select();
			foreach ($visits as $v) {
				if (!isset($visitMap[$v['user_id']])) {
					$visitMap[$v['user_id']] = ['last_time' => intval($v['visit_time']), 'paths' => []];
				}
				if (count($visitMap[$v['user_id']]['paths']) < 5) {
					$visitMap[$v['user_id']]['paths'][] = $v['uri'];
				}
			}
		} catch (\Exception $e) {}
	}

	// 组装展示数据
	$rows = [];
	foreach ($items as $u) {
		$uid = $u['id'];
		$login = isset($loginMap[$uid]) ? $loginMap[$uid] : null;
		$visit = isset($visitMap[$uid]) ? $visitMap[$uid] : null;

		$ua = $login ? (isset($login['user_agent']) ? $login['user_agent'] : '') : '';
		$device = function_exists('parse_device_ua') ? parse_device_ua($ua) : ['brand' => '', 'brand_cn' => '', 'model' => '', 'os' => '', 'is_mobile' => false];

		// 移动端筛选
		if ($mobileFilter === '1' && !$device['is_mobile']) {
			continue;
		}

		$rows[] = [
			'id'          => $uid,
			'user'        => $u['user'],
			'name'        => $u['name'] ?: $u['user'],
			'brand'       => $device['brand'],
			'brand_cn'    => $device['brand_cn'] ?: ($device['brand'] ?: '未知'),
			'model'       => $device['model'],
			'os'          => $device['os'],
			'is_mobile'   => $device['is_mobile'],
			'login_ip'    => $login ? $login['ip'] : $u['last_login_ip'],
			'last_login_ip' => $u['last_login_ip'],
			'login_time'  => $login ? intval($login['create_time']) : intval($u['last_login_time']),
			'last_visit'  => $visit ? $visit['last_time'] : 0,
			'last_logout' => intval($u['last_logout_time']),
			'paths'       => $visit ? $visit['paths'] : [],
			'state'       => $u['state'],
		];
	}

	return $this->fetch('/'.$this->web["admintemplate"].'/user_access_stats', [
		'rows'         => $rows,
		'search'       => $search,
		'mobileFilter' => $mobileFilter,
		'paginator'    => $users,
	]);
}

// ========== 未实名用户统计（支持一键删除） ==========
public function unverifiedUsers() {
	if (!$this->checkPermission('user') && !$this->user['is_super']) {
		$this->error('您没有权限访问此页面', '/admin/index');
	}
	ensure_user_columns();

	$total    = Db::name('user')->count();
	$verified = Db::name('user')->where('realname_status', 1)->count();
	$pending  = Db::name('user')->where('realname_status', 3)->count();
	$unverified = $total - $verified - $pending;
	if ($unverified < 0) $unverified = 0;

	// POST 操作
	if (Request::instance()->isPost()) {
		$act = input('act', '');
		if ($act == 'delete_one') {
			$id = intval(input('id', 0));
			if ($id > 0) {
				$u = Db::name('user')->where('id', $id)->find();
				if ($u && intval($u['realname_status']) != 1) {
					Db::name('user')->where('id', $id)->delete();
					admin_op_log('user_delete_unverified', '删除未实名用户：' . ($u['user'] ?: $id), ['id' => $id]);
					return json(['code' => '1', 'msg' => '已删除']);
				}
			}
			return json(['code' => '-1', 'msg' => '删除失败']);
		}
		if ($act == 'delete_all') {
			if (!csrf_verify(input('__token__'))) {
				return json(['code' => '-1', 'msg' => '安全验证失败，请刷新页面重试']);
			}
			// 删除所有未实名用户，但排除名下仍有订单/主机的用户
			$ids = Db::name('user')
				->where('realname_status', 'neq', 1)
				->where('realname_status', 'neq', 3)
				->column('id');
			$usersWithOrders = [];
			if (!empty($ids)) {
				$usersWithOrders = array_unique(Db::name('order')->where('userid', 'in', $ids)->column('userid'));
			}
			$deleteIds = array_values(array_diff($ids, $usersWithOrders));
			$deleted = 0;
			if (!empty($deleteIds)) {
				$deleted = Db::name('user')->where('id', 'in', $deleteIds)->delete();
			}
			$skipped = count($usersWithOrders);
			admin_op_log('user_delete_unverified_all', '批量删除未实名用户', ['deleted' => $deleted, 'skipped' => $skipped]);
			$msg = '已删除 ' . $deleted . ' 个未实名用户';
			if ($skipped > 0) $msg .= '，跳过 ' . $skipped . ' 个名下仍有订单的用户';
			return json(['code' => '1', 'msg' => $msg]);
		}
		return json(['code' => '-1', 'msg' => '无效操作']);
	}

	$search = trim(input('search', ''));
	$q = Db::name('user')
		->where('realname_status', 'neq', 1)
		->where('realname_status', 'neq', 3);
	if ($search !== '') {
		$q->where('user|name|mail', 'like', '%' . $search . '%');
	}
	$users = $q->field('id,user,name,mail,realname_status,time,last_login_time')->order('id desc')->paginate(20, false, ['query' => request()->param()]);

	return $this->fetch('/'.$this->web["admintemplate"].'/unverified_users', [
		'total'      => $total,
		'verified'   => $verified,
		'pending'    => $pending,
		'unverified' => $unverified,
		'users'      => $users,
		'search'     => $search,
	]);
}

}
