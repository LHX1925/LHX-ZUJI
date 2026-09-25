<?php
namespace app\admin\controller;

use think\Controller;
use think\Db;
use think\Request;

/**
 * 开发者 API 管理
 * 功能：接口总开关 / 频率限流与超限封禁设置 / 各功能开关 / 密钥管理（查看用户密钥、禁用用户）/
 *       调用统计（功能调用频率、用户调用统计）/ 自动封禁 IP 管理
 */
class Api extends Controller
{
    protected $hasFullAccess = false;

    public function _initialize()
    {
        if (!session("adminid")) {
            $this->redirect(url('admin/login/index'));
        }
        $this->user = Db::name('admin')->where('id', session("adminid"))->find();
        $this->web  = web_config();

        $this->hasFullAccess = ($this->user['is_super'] == 1 || $this->user['role_id'] == 1);
        $adminPermissions = get_admin_permissions($this->user);

        $this->assign([
            'webname'          => $this->web['name'],
            'web'     => $this->web,
            'user'             => $this->user,
            'adminPermissions' => $adminPermissions,
            'csrf_token'       => csrf_token(),
            'templateset'      => file_exists(PATH . "/app/index/view/" . $this->web["template"] . "/set.php") ? "1" : "0",
        ]);
    }

    protected function checkPermission($permission)
    {
        if ($this->hasFullAccess) return true;
        $permissions = get_admin_permissions($this->user);
        return in_array($permission, $permissions) || in_array('all', $permissions);
    }

    /**
     * API 管理主页（设置 / 密钥 / 统计 / 封禁 四 Tab）
     */
    public function index()
    {
        if (!$this->checkPermission('set') && !$this->hasFullAccess) {
            $this->error('您没有权限访问此页面', '/admin/index');
        }
        ensure_api_keys_table();
        ensure_api_call_log_table();
        if (function_exists('ensure_user_columns')) {
            ensure_user_columns();
        }

        $tab = input('tab', 'settings');

        // ===== 设置数据 =====
        $functionGroups = function_exists('api_function_list') ? api_function_list() : [];
        $disabledMap = function_exists('api_function_disabled_map') ? api_function_disabled_map() : [];
        $settings = [
            'api_enabled'       => isset($this->web['api_enabled']) ? $this->web['api_enabled'] : '1',
            'api_rate'          => isset($this->web['api_rate']) ? $this->web['api_rate'] : '0',
            'api_rate_ban'      => isset($this->web['api_rate_ban']) ? $this->web['api_rate_ban'] : '3600',
            'realname_encrypt'  => isset($this->web['realname_encrypt']) ? $this->web['realname_encrypt'] : '1',
        ];

        // ===== 密钥数据 =====
        $search = input('search', '');
        $keysQuery = Db::name('api_keys')->alias('k')
            ->join('user u', 'k.userid = u.id', 'LEFT')
            ->field('k.*, u.user as username, u.name as nickname, u.api_disabled');
        if ($search !== '') {
            $keysQuery->where(function ($q) use ($search) {
                $q->where('k.name', 'like', '%' . $search . '%')
                  ->whereOr('k.api_key', 'like', '%' . $search . '%')
                  ->whereOr('u.user', 'like', '%' . $search . '%')
                  ->whereOr('u.name', 'like', '%' . $search . '%');
            });
        }
        $keys = $keysQuery->order('k.id desc')->paginate(20, false, ['query' => request()->param()]);

        // ===== 统计数据 =====
        $range = input('range', '7'); // 1/7/30/0(全部)
        $since = 0;
        if ($range == '1') $since = time() - 86400;
        elseif ($range == '7') $since = time() - 7 * 86400;
        elseif ($range == '30') $since = time() - 30 * 86400;

        $stats = $this->buildStats($since);

        // ===== 自动封禁 IP 数据（API 相关） =====
        $autoBans = [];
        try {
            if (function_exists('ensure_ip_ban_table')) ensure_ip_ban_table();
            $autoBans = Db::name('ip_ban')
                ->where('ban_type', 'auto')
                ->order('banned_at desc')
                ->limit(100)
                ->select();
        } catch (\Throwable $e) {}

        return $this->fetch('/' . $this->web["admintemplate"] . '/api', [
            'tab'            => $tab,
            'settings'       => $settings,
            'functionGroups' => $functionGroups,
            'disabledMap'    => $disabledMap,
            'keys'           => $keys,
            'search'         => $search,
            'stats'          => $stats,
            'range'          => $range,
            'autoBans'       => $autoBans,
        ]);
    }

    /**
     * 组装调用统计数据
     */
    private function buildStats($since)
    {
        $stats = [
            'total'      => 0,
            'today'      => 0,
            'success'    => 0,
            'fail'       => 0,
            'blocked'    => 0,
            'denied'     => 0,
            'by_action'  => [],
            'by_user'    => [],
            'total_shown'=> 0,
        ];
        try {
            $query = Db::name('api_call_log');
            if ($since > 0) {
                $query->where('created_at', '>=', $since);
            }
            $stats['total_shown'] = $query->count();
            $stats['total'] = Db::name('api_call_log')->count();
            $todayStart = strtotime(date('Y-m-d'));
            $stats['today'] = Db::name('api_call_log')->where('created_at', '>=', $todayStart)->count();

            // 按状态统计
            $statusRows = Db::name('api_call_log')
                ->field('status, COUNT(*) as cnt');
            if ($since > 0) $statusRows->where('created_at', '>=', $since);
            $statusRows = $statusRows->group('status')->select();
            foreach ($statusRows as $s) {
                $stats[$s['status']] = intval($s['cnt']);
            }

            // 功能调用频率（action 维度）
            $actionQuery = Db::name('api_call_log')
                ->field('action, COUNT(*) as cnt, SUM(CASE WHEN status="success" THEN 1 ELSE 0 END) as ok, AVG(cost_ms) as avg_ms')
                ->where('action', '<>', 'unknown');
            if ($since > 0) $actionQuery->where('created_at', '>=', $since);
            $actions = $actionQuery->group('action')->order('cnt', 'desc')->limit(100)->select();
            $totalShown = max(1, $stats['total_shown']);
            foreach ($actions as &$a) {
                $a['percent'] = round(intval($a['cnt']) / $totalShown * 100, 1);
                $a['cn_name'] = $this->actionCnName($a['action']);
            }
            unset($a);
            $stats['by_action'] = $actions;

            // 调用用户统计（userid 维度）
            $userQuery = Db::name('api_call_log')->alias('l')
                ->join('user u', 'l.userid = u.id', 'LEFT')
                ->field('l.userid, COUNT(*) as cnt, SUM(CASE WHEN l.status="success" THEN 1 ELSE 0 END) as ok, MAX(l.created_at) as last_time, u.user as username, u.name as nickname');
            if ($since > 0) $userQuery->where('l.created_at', '>=', $since);
            $users = $userQuery->group('l.userid')->order('cnt', 'desc')->limit(100)->select();
            foreach ($users as &$u) {
                $u['name'] = $u['nickname'] ?: ($u['username'] ?: ('用户#' . $u['userid']));
                $u['last_time'] = intval($u['last_time']);
            }
            unset($u);
            $stats['by_user'] = $users;
        } catch (\Throwable $e) {
            // 统计异常不影响页面
        }
        return $stats;
    }

    /**
     * action 中文名映射（用于统计展示）
     */
    private function actionCnName($action)
    {
        // 登录类接口（无需 API 密钥，不在功能开关清单中，仅用于统计展示）
        $loginMap = [
            'login'           => '账号密码登录',
            'qrlogin.create'  => '扫码登录-生成二维码',
            'qrlogin.status'  => '扫码登录-轮询状态',
        ];
        if (isset($loginMap[$action])) return $loginMap[$action];
        if (function_exists('api_function_list')) {
            foreach (api_function_list() as $group) {
                if (isset($group[$action])) return $group[$action];
            }
        }
        return $action;
    }

    /**
     * 保存 API 设置
     */
    public function saveSettings()
    {
        if (!$this->checkPermission('set') && !$this->hasFullAccess) {
            return json(['code' => -1, 'msg' => '无权限']);
        }
        if (!Request::instance()->isPost()) {
            return json(['code' => -1, 'msg' => '非法请求']);
        }
        if (!csrf_verify(input('__token__'))) {
            return json(['code' => -1, 'msg' => '安全验证失败，请刷新页面重试']);
        }

        $apiEnabled = isset($_POST['api_enabled']) ? '1' : '0';
        $realnameEncrypt = isset($_POST['realname_encrypt']) ? '1' : '0';
        $rate = intval(input('api_rate', 0));
        $ban = intval(input('api_rate_ban', 3600));
        if ($rate < 0) $rate = 0;
        if ($ban < 0) $ban = 0;

        // 功能开关：提交为 action => 0(关闭)；未提交的 action 视为开启
        $functionStatus = [];
        if (function_exists('api_function_list')) {
            foreach (api_function_list() as $group) {
                foreach ($group as $action => $cn) {
                    // 复选框：勾选=开启，不勾选=关闭。这里读取原始 POST 判断是否勾选
                    $enabled = isset($_POST['fn_' . md5($action)]) && $_POST['fn_' . md5($action)] === '1';
                    if (!$enabled) {
                        $functionStatus[$action] = 0;
                    }
                }
            }
        }

        $update = [
            'api_enabled'       => $apiEnabled,
            'api_rate'          => (string)$rate,
            'api_rate_ban'      => (string)$ban,
            'api_function_status' => json_encode($functionStatus, JSON_UNESCAPED_UNICODE),
            'realname_encrypt'  => $realnameEncrypt,
        ];

        try {
            Db::name('web')->where('id', 1)->update($update);
        } catch (\Throwable $e) {
            return json(['code' => -1, 'msg' => '保存失败：' . $e->getMessage()]);
        }

        admin_op_log('api_settings', '修改开发者API设置', [
            'api_enabled' => $apiEnabled, 'api_rate' => $rate, 'api_rate_ban' => $ban,
            'realname_encrypt' => $realnameEncrypt, 'disabled' => count($functionStatus),
        ]);
        return json(['code' => 1, 'msg' => '保存成功']);
    }

    /**
     * 禁用/启用 用户的开发者 API
     */
    public function disableUser()
    {
        if (!$this->checkPermission('set') && !$this->checkPermission('user') && !$this->hasFullAccess) {
            return json(['code' => -1, 'msg' => '无权限']);
        }
        if (!Request::instance()->isPost()) {
            return json(['code' => -1, 'msg' => '非法请求']);
        }
        if (!csrf_verify(input('__token__'))) {
            return json(['code' => -1, 'msg' => '安全验证失败，请刷新页面重试']);
        }
        $userid = intval(input('userid', 0));
        $disabled = intval(input('disabled', 1));
        if ($userid <= 0) {
            return json(['code' => -1, 'msg' => '参数错误']);
        }
        ensure_user_columns();
        Db::name('user')->where('id', $userid)->update(['api_disabled' => $disabled ? 1 : 0]);
        admin_op_log('api_disable_user', ($disabled ? '禁用' : '启用') . '用户开发者API', ['userid' => $userid]);
        return json(['code' => 1, 'msg' => $disabled ? '已禁用该用户的开发者 API' : '已启用该用户的开发者 API']);
    }

    /**
     * 启用/停用 某个 API 密钥
     */
    public function toggleKey()
    {
        if (!$this->checkPermission('set') && !$this->checkPermission('user') && !$this->hasFullAccess) {
            return json(['code' => -1, 'msg' => '无权限']);
        }
        if (!Request::instance()->isPost()) {
            return json(['code' => -1, 'msg' => '非法请求']);
        }
        if (!csrf_verify(input('__token__'))) {
            return json(['code' => -1, 'msg' => '安全验证失败，请刷新页面重试']);
        }
        $id = intval(input('id', 0));
        $status = intval(input('status', 1));
        if ($id <= 0) {
            return json(['code' => -1, 'msg' => '参数错误']);
        }
        ensure_api_keys_table();
        $key = Db::name('api_keys')->where('id', $id)->find();
        if (!$key) {
            return json(['code' => -1, 'msg' => '密钥不存在']);
        }
        Db::name('api_keys')->where('id', $id)->update(['status' => $status ? 1 : 0]);
        admin_op_log('api_toggle_key', ($status ? '启用' : '停用') . 'API密钥', ['key_id' => $id, 'userid' => $key['userid']]);
        return json(['code' => 1, 'msg' => $status ? '已启用' : '已停用']);
    }

    /**
     * 解除自动封禁的 IP（API 频率超限）
     */
    public function unban()
    {
        if (!$this->checkPermission('set') && !$this->hasFullAccess) {
            return json(['code' => -1, 'msg' => '无权限']);
        }
        if (!Request::instance()->isPost()) {
            return json(['code' => -1, 'msg' => '非法请求']);
        }
        if (!csrf_verify(input('__token__'))) {
            return json(['code' => -1, 'msg' => '安全验证失败，请刷新页面重试']);
        }
        $id = intval(input('id', 0));
        if ($id <= 0) {
            return json(['code' => -1, 'msg' => '参数错误']);
        }
        if (function_exists('unban_ip')) {
            unban_ip($id);
        } else {
            Db::name('ip_ban')->where('id', $id)->update(['status' => 0]);
        }
        admin_op_log('api_unban', '解除API自动封禁IP ID:' . $id, ['id' => $id]);
        return json(['code' => 1, 'msg' => '已解除封禁']);
    }
}
