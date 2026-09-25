<?php
namespace app\admin\controller;
use think\Controller;
use think\Db;
use think\Request;

class AdminHost extends Controller
{
    protected $hasFullAccess = false;

    public function _initialize() {
        if(!session("adminid")) {
            $this->redirect(url('admin/login/index'));
        }
        $this->user = Db::name('admin')->where('id', session("adminid"))->find();
        $this->web = web_config();

        // 权限判断
        $this->hasFullAccess = ($this->user['is_super'] == 1 || $this->user['role_id'] == 1);
        $adminPermissions = get_admin_permissions($this->user);

        $this->assign([
            'webname'  => $this->web['name'],
            'web'     => $this->web,
            'user'     => $this->user,
            'adminPermissions' => $adminPermissions,
            'templateset' => file_exists(PATH . "/app/index/view/" . $this->web["template"] . "/set.php") ? "1" : "0",
            'csrf_token'=> csrf_token(),
        ]);
    }

    protected function checkPermission($permission) {
        if ($this->hasFullAccess) return true;
        $permissions = get_admin_permissions($this->user);
        return in_array($permission, $permissions) || in_array('all', $permissions);
    }

    // 主机列表
    public function index() {
        if (!$this->checkPermission('server') && !$this->hasFullAccess) {
            $this->error('您没有权限访问此页面', '/admin/index');
        }

        $search = input('search', '');
        $stateFilter = input('state', '');

        $query = Db::name('order')->alias('o')
            ->join('cart c', 'o.cartid = c.id', 'LEFT')
            ->join('server s', 'c.serverid = s.id', 'LEFT')
            ->join('user u', 'o.userid = u.id', 'LEFT')
            ->field('o.*, c.name as product_name, s.name as server_name, s.serverplugins, u.user as user_name')
            ->order('o.id desc');

        if ($search) {
            $query->where(function($q) use ($search) {
                $q->where('o.user', 'like', '%'.$search.'%')
                  ->whereOr('o.id', 'like', '%'.$search.'%')
                  ->whereOr('o.userid', 'like', '%'.$search.'%')
                  ->whereOr('c.name', 'like', '%'.$search.'%')
                  ->whereOr('u.user', 'like', '%'.$search.'%');
            });
        }

        if ($stateFilter !== '') {
            $query->where('o.state', intval($stateFilter));
        }

        $data = $query->paginate(15, false, ['query' => request()->param()]);

        return $this->fetch('/'.$this->web["admintemplate"].'/host_manager', [
            'hosts' => $data,
            'search' => $search,
            'stateFilter' => $stateFilter,
        ]);
    }

    // 操作单个主机
    public function operate() {
        if (!$this->checkPermission('server') && !$this->hasFullAccess) {
            return json(['code' => -1, 'msg' => '无权限']);
        }
        if (!Request::instance()->isPost()) {
            return json(['code' => -1, 'msg' => '非法请求']);
        }
        if (!csrf_verify(input('__token__'))) {
            return json(['code' => -1, 'msg' => '安全验证失败，请刷新页面重试']);
        }
        $id = input('id', 0);
        $act = input('act', '');
        if (!$id || !in_array($act, ['stop', 'stopoff', 'delete'])) {
            return json(['code' => -1, 'msg' => '参数错误']);
        }

        $order = Db::name('order')->where('id', $id)->find();
        if (!$order) {
            return json(['code' => -1, 'msg' => '订单不存在']);
        }

        if ($act == 'delete') {
            Db::name('order')->where('id', $id)->delete();
            admin_op_log('host_delete', '删除主机：' . $order['user'], ['order_id' => $id]);
            return json(['code' => 1, 'msg' => '删除成功']);
        }

        // 暂停/解除暂停需要调用插件
        $cart = Db::name('cart')->where('id', $order['cartid'])->find();
        $server = Db::name('server')->where('id', $cart['serverid'])->find();

        if ($act == 'stop') {
            if ($order['state'] == '3') {
                return json(['code' => -1, 'msg' => '产品已终止，禁止修改此状态']);
            }
            if ($order['state'] == '2') {
                return json(['code' => -1, 'msg' => '产品已暂停']);
            }
            $pluginFile = PATH . "plugins/host/" . $server["serverplugins"] . "/" . $server["serverplugins"] . ".php";
            if (file_exists($pluginFile)) {
                include_once $pluginFile;
                $function = $server["serverplugins"] . "_SuspendAccount";
                if (function_exists($function)) {
                    @$function($server, $order, $cart);
                }
            }
            Db::name('order')->where('id', $id)->update(['state' => '2']);
            admin_op_log('host_suspend', '暂停主机：' . $order['user'], ['order_id' => $id]);
            return json(['code' => 1, 'msg' => '暂停成功']);
        }

        if ($act == 'stopoff') {
            if ($order['state'] == '3') {
                return json(['code' => -1, 'msg' => '产品已终止，禁止修改此状态']);
            }
            if ($order['state'] == '1') {
                return json(['code' => -1, 'msg' => '产品已是正常运行状态']);
            }
            $pluginFile = PATH . "plugins/host/" . $server["serverplugins"] . "/" . $server["serverplugins"] . ".php";
            if (file_exists($pluginFile)) {
                include_once $pluginFile;
                $function = $server["serverplugins"] . "_UnsuspendAccount";
                if (function_exists($function)) {
                    @$function($server, $order, $cart);
                }
            }
            Db::name('order')->where('id', $id)->update(['state' => '1']);
            admin_op_log('host_unsuspend', '开启主机：' . $order['user'], ['order_id' => $id]);
            return json(['code' => 1, 'msg' => '解除暂停成功']);
        }

        return json(['code' => -1, 'msg' => '未知操作']);
    }

    // 一键暂停所有主机
    public function suspendAll() {
        if (!$this->checkPermission('server') && !$this->hasFullAccess) {
            return json(['code' => -1, 'msg' => '无权限']);
        }
        if (!Request::instance()->isPost()) {
            return json(['code' => -1, 'msg' => '非法请求']);
        }
        if (!csrf_verify(input('__token__'))) {
            return json(['code' => -1, 'msg' => '安全验证失败，请刷新页面重试']);
        }
        $orders = Db::name('order')->where('state', '1')->select();
        if (empty($orders)) {
            return json(['code' => 1, 'msg' => '没有需要暂停的主机']);
        }

        $success = 0;
        $fail = 0;
        foreach ($orders as $order) {
            try {
                $cart = Db::name('cart')->where('id', $order['cartid'])->find();
                $server = $cart ? Db::name('server')->where('id', $cart['serverid'])->find() : null;
                if ($server && !empty($server['serverplugins'])) {
                    $pluginFile = PATH . "plugins/host/" . $server["serverplugins"] . "/" . $server["serverplugins"] . ".php";
                    if (file_exists($pluginFile)) {
                        include_once $pluginFile;
                        $function = $server["serverplugins"] . "_SuspendAccount";
                        if (function_exists($function)) {
                            @$function($server, $order, $cart);
                        }
                    }
                }
                Db::name('order')->where('id', $order['id'])->update(['state' => '2']);
                $success++;
            } catch (\Exception $e) {
                $fail++;
            }
        }
        admin_op_log('host_suspend_all', '一键暂停所有主机', ['success' => $success, 'fail' => $fail]);
        return json(['code' => 1, 'msg' => "成功暂停 {$success} 个主机" . ($fail > 0 ? "，失败 {$fail} 个" : "")]);
    }

    // 一键开启所有主机
    public function unsuspendAll() {
        if (!$this->checkPermission('server') && !$this->hasFullAccess) {
            return json(['code' => -1, 'msg' => '无权限']);
        }
        if (!Request::instance()->isPost()) {
            return json(['code' => -1, 'msg' => '非法请求']);
        }
        if (!csrf_verify(input('__token__'))) {
            return json(['code' => -1, 'msg' => '安全验证失败，请刷新页面重试']);
        }
        $orders = Db::name('order')->where('state', '2')->select();
        if (empty($orders)) {
            return json(['code' => 1, 'msg' => '没有需要开启的主机']);
        }

        $success = 0;
        $fail = 0;
        foreach ($orders as $order) {
            try {
                $cart = Db::name('cart')->where('id', $order['cartid'])->find();
                $server = $cart ? Db::name('server')->where('id', $cart['serverid'])->find() : null;
                if ($server && !empty($server['serverplugins'])) {
                    $pluginFile = PATH . "plugins/host/" . $server["serverplugins"] . "/" . $server["serverplugins"] . ".php";
                    if (file_exists($pluginFile)) {
                        include_once $pluginFile;
                        $function = $server["serverplugins"] . "_UnsuspendAccount";
                        if (function_exists($function)) {
                            @$function($server, $order, $cart);
                        }
                    }
                }
                Db::name('order')->where('id', $order['id'])->update(['state' => '1']);
                $success++;
            } catch (\Exception $e) {
                $fail++;
            }
        }
        admin_op_log('host_unsuspend_all', '一键开启所有主机', ['success' => $success, 'fail' => $fail]);
        return json(['code' => 1, 'msg' => "成功开启 {$success} 个主机" . ($fail > 0 ? "，失败 {$fail} 个" : "")]);
    }

    // 一键删除所有主机
    public function deleteAll() {
        if (!$this->checkPermission('server') && !$this->hasFullAccess) {
            return json(['code' => -1, 'msg' => '无权限']);
        }
        if (!Request::instance()->isPost()) {
            return json(['code' => -1, 'msg' => '非法请求']);
        }
        if (!csrf_verify(input('__token__'))) {
            return json(['code' => -1, 'msg' => '安全验证失败，请刷新页面重试']);
        }
        $count = Db::name('order')->count();
        if ($count == 0) {
            return json(['code' => 1, 'msg' => '没有需要删除的主机']);
        }
        $result = Db::name('order')->where('1=1')->delete();
        admin_op_log('host_delete_all', '一键删除所有主机', ['count' => $result]);
        return json(['code' => 1, 'msg' => "成功删除 {$result} 个主机"]);
    }

    /* ==================== Docker 容器开通管理 ==================== */

    // Docker 开通记录列表
    public function docker() {
        if (!$this->checkPermission('server') && !$this->hasFullAccess) {
            $this->error('您没有权限访问此页面', '/admin/index');
        }
        if (function_exists('ensure_docker_order_table')) {
            ensure_docker_order_table();
        }

        $search = input('search', '');
        $stateFilter = input('state', '');

        $query = Db::name('docker_order')->alias('d')
            ->join('user u', 'd.userid = u.id', 'LEFT')
            ->field('d.*, u.user as user_name, u.mail as user_mail')
            ->order('d.id desc');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('d.username', 'like', '%' . $search . '%')
                  ->whereOr('d.orderid', 'like', '%' . $search . '%')
                  ->whereOr('d.id', 'like', '%' . $search . '%')
                  ->whereOr('u.user', 'like', '%' . $search . '%');
            });
        }
        if ($stateFilter !== '') {
            $query->where('d.state', $stateFilter);
        }

        $data = $query->paginate(15, false, ['query' => request()->param()]);

        $stat = [
            'total'   => Db::name('docker_order')->count(),
            'pending' => Db::name('docker_order')->where('state', '1')->count(),
            'active'  => Db::name('docker_order')->where('state', '2')->count(),
            'failed'  => Db::name('docker_order')->where('state', '4')->count(),
        ];

        return $this->fetch('/' . $this->web["admintemplate"] . '/docker_manager', [
            'list'        => $data,
            'search'      => $search,
            'stateFilter' => $stateFilter,
            'stat'        => $stat,
            'dockerPrice' => function_exists('docker_price') ? docker_price($this->web) : 0,
            'dockerMode'  => function_exists('docker_mode') ? docker_mode($this->web) : 'manual',
            'globalOn'    => (isset($this->web['docker_enabled']) && $this->web['docker_enabled'] == '1'),
            'payBalance'  => !(isset($this->web['docker_pay_balance']) && $this->web['docker_pay_balance'] == '0'),
            'payOnline'   => (isset($this->web['docker_pay_online']) && $this->web['docker_pay_online'] == '1'),
        ]);
    }

    // Docker 开通记录操作：open / close / delete
    public function dockerOperate() {
        if (!$this->checkPermission('server') && !$this->hasFullAccess) {
            return json(['code' => -1, 'msg' => '无权限']);
        }
        if (!Request::instance()->isPost()) {
            return json(['code' => -1, 'msg' => '非法请求']);
        }
        if (!csrf_verify(input('__token__'))) {
            return json(['code' => -1, 'msg' => '安全验证失败，请刷新页面重试']);
        }

        $id  = intval(input('id', 0));
        $act = input('act', '');
        if (!$id || !in_array($act, ['open', 'close', 'delete'], true)) {
            return json(['code' => -1, 'msg' => '参数错误']);
        }

        if (function_exists('ensure_docker_order_table')) {
            ensure_docker_order_table();
        }
        $row = Db::name('docker_order')->where('id', $id)->find();
        if (!$row) {
            return json(['code' => -1, 'msg' => '开通记录不存在']);
        }

        if ($act === 'delete') {
            Db::name('docker_order')->where('id', $id)->delete();
            admin_op_log('docker_record_delete', '删除 Docker 开通记录：' . $row['username'], ['id' => $id]);
            return json(['code' => 1, 'msg' => '记录已删除']);
        }

        if ($act === 'close') {
            if ((string) $row['state'] !== '2') {
                return json(['code' => -1, 'msg' => '该记录当前不是「已开通」状态，无需关闭']);
            }
            $r = function_exists('docker_shutdown') ? docker_shutdown($row) : ['code' => -1, 'msg' => '系统未加载 Docker 模块'];
            admin_op_log('docker_close', '关闭 Docker：' . $row['username'], ['id' => $id, 'result' => $r['msg']]);
            return json(['code' => (!empty($r['code']) && (string) $r['code'] === '1') ? 1 : -1, 'msg' => $r['msg']]);
        }

        // open（含失败重试）
        if ((string) $row['state'] === '2') {
            return json(['code' => -1, 'msg' => '该主机 Docker 已开通，无需重复操作']);
        }
        Db::name('docker_order')->where('id', $id)->update([
            'opened_by' => 'admin',
            'admin_id'  => intval(session('adminid')),
        ]);
        $row['opened_by'] = 'admin';
        $r = function_exists('docker_provision') ? docker_provision($row) : ['code' => -1, 'msg' => '系统未加载 Docker 模块'];
        admin_op_log('docker_open', '开通 Docker：' . $row['username'], ['id' => $id, 'result' => $r['msg']]);
        return json(['code' => (!empty($r['code']) && (string) $r['code'] === '1') ? 1 : -1, 'msg' => $r['msg']]);
    }

    // 后台手动为指定主机开通 Docker（不涉及支付）
    public function dockerCreate() {
        if (!$this->checkPermission('server') && !$this->hasFullAccess) {
            return json(['code' => -1, 'msg' => '无权限']);
        }
        if (!Request::instance()->isPost()) {
            return json(['code' => -1, 'msg' => '非法请求']);
        }
        if (!csrf_verify(input('__token__'))) {
            return json(['code' => -1, 'msg' => '安全验证失败，请刷新页面重试']);
        }
        if (function_exists('ensure_docker_order_table')) {
            ensure_docker_order_table();
        }

        $orderid = intval(input('orderid', 0));
        $remark  = trim((string) input('remark', ''));
        if ($orderid <= 0) {
            return json(['code' => -1, 'msg' => '请填写主机订单 ID']);
        }

        $order = Db::name('order')->where('id', $orderid)->find();
        if (!$order) {
            return json(['code' => -1, 'msg' => '主机订单不存在（订单 ID：' . $orderid . '）']);
        }
        if (empty($order['user'])) {
            return json(['code' => -1, 'msg' => '该主机尚未开通面板账号，无法开通 Docker']);
        }
        $cart = Db::name('cart')->where('id', $order['cartid'])->find();
        if (!$cart) {
            return json(['code' => -1, 'msg' => '该订单对应的产品已删除']);
        }
        $server = Db::name('server')->where('id', $cart['serverid'])->find();
        if (!$server || empty($server['serverplugins'])) {
            return json(['code' => -1, 'msg' => '该产品的服务器未配置控制面板插件']);
        }
        if (!function_exists('docker_plugin_supported') || !docker_plugin_supported($server)) {
            return json(['code' => -1, 'msg' => '当前控制面板插件（' . $server['serverplugins'] . '）不支持 Docker 容器开通']);
        }
        $exist = Db::name('docker_order')->where('orderid', $orderid)->where('state', '2')->find();
        if ($exist) {
            return json(['code' => -1, 'msg' => '该主机已开通 Docker 容器（记录 ID：' . $exist['id'] . '）']);
        }

        $now = time();
        $id = Db::name('docker_order')->insertGetId([
            'userid'    => intval($order['userid']),
            'orderid'   => $orderid,
            'cartid'    => intval($cart['id']),
            'serverid'  => intval($cart['serverid']),
            'username'  => $order['user'],
            'price'     => 0,
            'paid'      => 0,
            'payway'    => 'admin',
            'mode'      => 'auto',
            'state'     => '1',
            'opened_by' => 'admin',
            'admin_id'  => intval(session('adminid')),
            'remark'    => mb_substr($remark, 0, 250),
            'atime'     => $now,
            'utime'     => $now,
        ]);
        $row = Db::name('docker_order')->where('id', $id)->find();
        $r = docker_provision($row);
        admin_op_log('docker_create', '后台手动开通 Docker：' . $order['user'], ['order_id' => $orderid, 'result' => $r['msg']]);
        return json([
            'code' => (!empty($r['code']) && (string) $r['code'] === '1') ? 1 : -1,
            'msg'  => $r['msg'],
            'id'   => $id,
        ]);
    }
}