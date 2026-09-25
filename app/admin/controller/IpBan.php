<?php
namespace app\admin\controller;
use think\Controller;
use think\Db;
use think\Request;

class IpBan extends Controller
{
    protected $hasFullAccess = false;

    public function _initialize() {
        if(!session("adminid")) {
            $this->redirect(url('admin/login/index'));
        }
        $this->user = Db::name('admin')->where('id', session("adminid"))->find();
        $this->web = web_config();

        $this->hasFullAccess = ($this->user['is_super'] == 1 || $this->user['role_id'] == 1);
        $adminPermissions = get_admin_permissions($this->user);

        $this->assign([
            'webname'  => $this->web['name'],
            'web'     => $this->web,
            'user'     => $this->user,
            'adminPermissions' => $adminPermissions,
            'csrf_token'=> csrf_token(),
            'templateset' => file_exists(PATH . "/app/index/view/" . $this->web["template"] . "/set.php") ? "1" : "0",
        ]);
    }

    protected function checkPermission($permission) {
        if ($this->hasFullAccess) return true;
        $permissions = get_admin_permissions($this->user);
        return in_array($permission, $permissions) || in_array('all', $permissions);
    }

    // IP封禁列表
    public function index() {
        if (!$this->checkPermission('server') && !$this->checkPermission('admin_manager') && !$this->hasFullAccess) {
            $this->error('您没有权限访问此页面', '/admin/index');
        }
        // 确保ip_ban表存在
        if (function_exists('ensure_ip_ban_table')) {
            ensure_ip_ban_table();
        }

        $tab    = input('tab', 'normal');
        $search = input('search', '');
        $status = input('status', '');

        // 已封禁IP查询
        $bannedQuery = Db::name('ip_ban')->order('banned_at desc');
        if ($search) {
            $bannedQuery->where('ip|reason|note', 'like', '%'.$search.'%');
        }
        if ($status !== '') {
            $bannedQuery->where('status', intval($status));
        }
        try {
            $bannedIps = $bannedQuery->paginate(15, false, ['query' => request()->param()]);
        } catch (\Throwable $e) {
            $bannedIps = null;
        }

        // 访客日志数据（可选，建表失败不影响）
        $visitors = null;
        try {
            if (function_exists('ensure_visitor_log_table')) {
                ensure_visitor_log_table();
            }
            $vQuery = Db::name('visitor_log');
            if ($tab === 'normal') {
                // 正常用户区：真实访客（含未登录游客），按疑似机器人标识过滤
                $vQuery->where('is_bot', 0);
            } else {
                // 非正常用户区：疑似机器人/脚本
                $vQuery->where('is_bot', 1);
            }
            if ($search) {
                $vQuery->where('ip', 'like', '%'.$search.'%');
            }
            $visitors = $vQuery
                ->field('ip, MAX(visit_time) as max_visit_time, COUNT(id) as visit_count, MAX(is_bot) as is_bot, MAX(bot_reason) as bot_reason, GROUP_CONCAT(DISTINCT uri ORDER BY visit_time DESC SEPARATOR "\n") as uris')
                ->group('ip')
                ->order('max_visit_time', 'desc')
                ->paginate(15, false, ['query' => request()->param()]);

            if ($visitors && $visitors->count() > 0) {
                $visitors->each(function ($v) {
                    $v['paths'] = array_slice(array_filter(array_map('trim', explode("\n", $v['uris'] ?? ''))), 0, 5);
                    $last = Db::name('visitor_log')->where('ip', $v['ip'])->order('visit_time desc')->find();
                    $v['last_ua'] = $last ? mb_substr((string)$last['user_agent'], 0, 100) : '-';
                    $v['last_uri'] = $last ? (string)$last['uri'] : '-';
                    $v['last_user_id'] = $last ? (int)$last['user_id'] : 0;
                    $v['user_name'] = '';
                    if ($v['last_user_id'] > 0) {
                        $u = Db::name('user')->where('id', $v['last_user_id'])->field('name,user')->find();
                        if ($u) $v['user_name'] = $u['name'] ?: $u['user'];
                    }
                    $ban = Db::name('ip_ban')->where('ip', $v['ip'])->where('status', 1)->find();
                    $v['is_banned'] = !empty($ban);
                    $v['ban_id'] = $ban['id'] ?? 0;
                    return $v;
                });
            }
        } catch (\Throwable $e) {
            $visitors = null;
        }

        // 辅助：获取IP探测路径
        $bannedItems = $bannedIps ? $bannedIps->items() : [];
        if (function_exists('get_banned_ip_paths')) {
            foreach ($bannedItems as &$row) {
                $row['paths'] = get_banned_ip_paths($row['ip'], 5);
            }
        }
        unset($row);

        return $this->fetch('/'.$this->web["admintemplate"].'/ip_ban', [
            'tab'       => $tab,
            'visitors'  => $visitors,
            'bannedIps' => $bannedIps,
            'search'    => $search,
            'status'    => $status,
        ]);
    }

    public function unban() {
        if (!$this->checkPermission('server') && !$this->checkPermission('admin_manager') && !$this->hasFullAccess) {
            return json(['code' => -1, 'msg' => '无权限']);
        }
        if (!Request::instance()->isPost()) {
            return json(['code' => -1, 'msg' => '非法请求']);
        }
        if (!csrf_verify(input('__token__'))) {
            return json(['code' => -1, 'msg' => '安全验证失败，请刷新页面重试']);
        }
        $id = input('id', 0);
        if (!$id) {
            return json(['code' => -1, 'msg' => '参数错误']);
        }
        if (function_exists('unban_ip')) {
            unban_ip($id);
        } else {
            Db::name('ip_ban')->where('id', $id)->update(['status' => 0]);
        }
        admin_op_log('ip_unban', '解除IP封禁 ID:'.$id, ['id' => $id]);
        return json(['code' => 1, 'msg' => '已解除封禁']);
    }

    public function ban() {
        if (!$this->checkPermission('server') && !$this->checkPermission('admin_manager') && !$this->hasFullAccess) {
            return json(['code' => -1, 'msg' => '无权限']);
        }
        if (!Request::instance()->isPost()) {
            return json(['code' => -1, 'msg' => '非法请求']);
        }
        if (!csrf_verify(input('__token__'))) {
            return json(['code' => -1, 'msg' => '安全验证失败，请刷新页面重试']);
        }
        $ip = trim(input('ip', ''));
        $reason = trim(input('reason', '管理员手动封禁'));
        $duration = intval(input('duration', 0));

        if (!$ip || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return json(['code' => -1, 'msg' => '请输入有效的IP地址']);
        }

        $durSec = $duration > 0 ? ($duration * 86400) : 0;
        $banType = $duration > 0 ? 'timeout' : 'permanent';
        if (function_exists('ban_ip')) {
            ban_ip($ip, $reason, $durSec, $banType);
        } else {
            Db::name('ip_ban')->insert([
                'ip'            => $ip,
                'reason'        => $reason,
                'ban_type'      => $banType,
                'status'        => 1,
                'banned_at'     => time(),
                'unban_at'      => $durSec > 0 ? (time() + $durSec) : 0,
                'trigger_count' => 1,
                'first_seen'    => time(),
                'last_seen'     => time(),
            ]);
        }
        admin_op_log('ip_ban_manual', '手动封禁IP: '.$ip, ['ip' => $ip, 'reason' => $reason, 'duration' => $duration]);

        if (function_exists('security_log')) {
            security_log('ip_manual_banned', '管理员手动封禁: ' . $ip . ' -> ' . $reason);
        }

        return json(['code' => 1, 'msg' => '封禁成功']);
    }
}
