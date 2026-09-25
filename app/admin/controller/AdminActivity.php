<?php
namespace app\admin\controller;
use think\Controller;
use think\Db;
use think\Request;

/**
 * 后台：活动中心 / 每日抽奖 / 域名商城 管理控制器
 */
class AdminActivity extends Controller {
    protected $user = null;
    protected $web = null;

    public function _initialize() {
        $this->web = web_config();
        if ($this->web["admintemplate"] == "layui") {
            $this->web["admintemplate"] = "default";
        }
        // 登录校验
        $adminid = session("adminid");
        if (!$adminid) {
            $this->redirect(function_exists('admin_login_url') ? admin_login_url() : '/admin/login');
        }
        $this->user = Db::name('admin')->where('id', $adminid)->find();
        if (!$this->user) {
            session("adminid", null);
            $this->redirect(function_exists('admin_login_url') ? admin_login_url() : '/admin/login');
        }
        // 确保表存在
        if (function_exists('ensure_activity_tables')) ensure_activity_tables();
        if (function_exists('ensure_email_queue_table')) ensure_email_queue_table();

        // 注入后台公共模板变量，避免 header 中 $webname / $user / $adminPermissions 等未定义
        $file = file_exists(PATH . "/app/index/view/" . $this->web["template"] . "/set.php");
        $templateset = $file ? "1" : "0";
        $adminPermissions = function_exists('get_admin_permissions') ? get_admin_permissions($this->user) : [];
        $csrfToken = function_exists('csrf_token') ? csrf_token() : '';
        $this->assign([
            'webname' => $this->web['name'] ?? '',
            'web'     => $this->web,
            'user' => $this->user,
            'templateset' => $templateset,
            'adminPermissions' => $adminPermissions,
            'csrf_token' => $csrfToken,
        ]);
    }

    protected function checkPermission($perm) {
        // 超级管理员或超级管理员角色 grant 全部权限
        if (isset($this->user['is_super']) && $this->user['is_super'] == 1) return true;
        if (isset($this->user['role_id']) && $this->user['role_id'] == 1) return true;
        if (function_exists('get_admin_permissions')) {
            $perms = get_admin_permissions($this->user);
            return in_array('all', $perms) || in_array($perm, $perms);
        }
        return true;
    }

    // ========== 答题活动管理 ==========
    public function quiz() {
        if (!$this->checkPermission('set') && !$this->user['is_super']) {
            $this->error('您没有权限访问此页面', '/admin/index');
        }
        if (Request::instance()->isPost()) {
            $act = input('act', '');
            if ($act == 'save') {
                $id = intval(input('id', 0));
                $data = [
                    'name' => trim(input('name', '')),
                    'type' => input('type', 'points'),
                    'question_count' => intval(input('question_count', 10)),
                    'pass_count' => intval(input('pass_count', 8)),
                    'prize_points' => intval(input('prize_points', 0)),
                    'prize_balance' => floatval(input('prize_balance', 0)),
                    'prize_cartid' => intval(input('prize_cartid', 0)),
                    'prize_host_days' => intval(input('prize_host_days', 30)),
                    'daily_limit' => intval(input('daily_limit', 1)),
                    'status' => intval(input('status', 1)),
                    'sort' => intval(input('sort', 0)),
                ];
                if (empty($data['name'])) return json(['code' => '-1', 'msg' => '活动名称不能为空']);
                if ($id > 0) {
                    Db::name('quiz_activity')->where('id', $id)->update($data);
                } else {
                    $data['create_time'] = time();
                    Db::name('quiz_activity')->insert($data);
                }
                admin_op_log('quiz_activity_save', '保存答题活动', $data);
                return json(['code' => '1', 'msg' => '保存成功']);
            }
            if ($act == 'delete') {
                $id = intval(input('id', 0));
                Db::name('quiz_activity')->where('id', $id)->delete();
                admin_op_log('quiz_activity_delete', '删除答题活动', ['id' => $id]);
                return json(['code' => '1', 'msg' => '删除成功']);
            }
            return json(['code' => '-1', 'msg' => '无效操作']);
        }
        $activities = Db::name('quiz_activity')->order('sort asc, id desc')->select();
        $carts = Db::name('cart')->field('id,name')->select();
        $this->assign('activities', $activities);
        $this->assign('carts', $carts);
        return $this->fetch('/'.$this->web["admintemplate"].'/admin_activity');
    }

    // ========== 题库管理 ==========
    public function questions() {
        if (!$this->checkPermission('set') && !$this->user['is_super']) {
            $this->error('您没有权限访问此页面', '/admin/index');
        }
        if (Request::instance()->isPost()) {
            $act = input('act', '');
            if ($act == 'save') {
                $id = intval(input('id', 0));
                $options = input('options', '');
                $data = [
                    'category' => input('category', 'history'),
                    'question' => trim(input('question', '')),
                    'options' => $options,
                    'answer' => intval(input('answer', 0)),
                    'analysis' => trim(input('analysis', '')),
                    'status' => intval(input('status', 1)),
                ];
                if (empty($data['question'])) return json(['code' => '-1', 'msg' => '题目不能为空']);
                if (empty($data['options'])) return json(['code' => '-1', 'msg' => '选项不能为空']);
                if ($id > 0) {
                    Db::name('quiz_question')->where('id', $id)->update($data);
                } else {
                    $data['create_time'] = time();
                    Db::name('quiz_question')->insert($data);
                }
                admin_op_log('quiz_question_save', '保存题目', $data);
                return json(['code' => '1', 'msg' => '保存成功']);
            }
            if ($act == 'delete') {
                $id = intval(input('id', 0));
                Db::name('quiz_question')->where('id', $id)->delete();
                return json(['code' => '1', 'msg' => '删除成功']);
            }
            if ($act == 'import_default') {
                // 导入默认红色/历史/现代知识题库
                $count = import_default_quiz_questions();
                return json(['code' => '1', 'msg' => '已导入 ' . $count . ' 道默认题目']);
            }
            return json(['code' => '-1', 'msg' => '无效操作']);
        }
        $category = input('category', '');
        $query = Db::name('quiz_question')->order('id desc');
        if ($category) {
            $query->where('category', $category);
        }
        $questions = $query->paginate(20);
        $this->assign('questions', $questions);
        $this->assign('category', $category);
        return $this->fetch('/'.$this->web["admintemplate"].'/admin_quiz_questions');
    }

    // ========== 每日抽奖管理 ==========
    public function lottery() {
        if (!$this->checkPermission('set') && !$this->user['is_super']) {
            $this->error('您没有权限访问此页面', '/admin/index');
        }
        if (Request::instance()->isPost()) {
            $act = input('act', '');
            if ($act == 'save_config') {
                $config = Db::name('lottery_config')->where('id', 1)->find();
                $data = [
                    'daily_times' => intval(input('daily_times', 1)),
                    'need_realname' => intval(input('need_realname', 0)),
                    'status' => intval(input('status', 1)),
                    'notice' => trim(input('notice', '')),
                ];
                if ($config) {
                    Db::name('lottery_config')->where('id', 1)->update($data);
                } else {
                    $data['id'] = 1;
                    Db::name('lottery_config')->insert($data);
                }
                admin_op_log('lottery_config_save', '保存抽奖配置', $data);
                return json(['code' => '1', 'msg' => '保存成功']);
            }
            if ($act == 'save_prize') {
                $id = intval(input('id', 0));
                $data = [
                    'name' => trim(input('name', '')),
                    'type' => input('type', 'points'),
                    'prize_points' => intval(input('prize_points', 0)),
                    'prize_balance' => floatval(input('prize_balance', 0)),
                    'prize_cartid' => intval(input('prize_cartid', 0)),
                    'prize_host_days' => intval(input('prize_host_days', 30)),
                    'probability' => floatval(input('probability', 0)),
                    'icon' => trim(input('icon', '')),
                    'stock' => intval(input('stock', -1)),
                    'status' => intval(input('status', 1)),
                    'sort' => intval(input('sort', 0)),
                ];
                if (empty($data['name'])) return json(['code' => '-1', 'msg' => '奖品名称不能为空']);
                if ($id > 0) {
                    Db::name('lottery_prize')->where('id', $id)->update($data);
                } else {
                    Db::name('lottery_prize')->insert($data);
                }
                admin_op_log('lottery_prize_save', '保存抽奖奖品', $data);
                return json(['code' => '1', 'msg' => '保存成功']);
            }
            if ($act == 'delete_prize') {
                $id = intval(input('id', 0));
                Db::name('lottery_prize')->where('id', $id)->delete();
                return json(['code' => '1', 'msg' => '删除成功']);
            }
            return json(['code' => '-1', 'msg' => '无效操作']);
        }
        $config = Db::name('lottery_config')->where('id', 1)->find();
        if (!$config) {
            Db::name('lottery_config')->insert(['id' => 1, 'daily_times' => 1, 'need_realname' => 0, 'status' => 1, 'notice' => '']);
            $config = Db::name('lottery_config')->where('id', 1)->find();
        }
        $prizes = Db::name('lottery_prize')->order('sort asc, id asc')->select();
        $records = Db::name('lottery_record')->order('id desc')->limit(50)->select();
        // 关联用户名
        $userIds = array_unique(array_filter(array_column($records, 'userid')));
        $userNames = [];
        if (!empty($userIds)) {
            $userNames = Db::name('user')->where('id', 'in', $userIds)->column('name', 'id');
        }
        $carts = Db::name('cart')->field('id,name')->select();
        $this->assign('config', $config);
        $this->assign('prizes', $prizes);
        $this->assign('records', $records);
        $this->assign('userNames', $userNames);
        $this->assign('carts', $carts);
        return $this->fetch('/'.$this->web["admintemplate"].'/admin_lottery');
    }

    // ========== 域名商城管理 ==========
    public function domain() {
        if (!$this->checkPermission('set') && !$this->user['is_super']) {
            $this->error('您没有权限访问此页面', '/admin/index');
        }
        if (Request::instance()->isPost()) {
            $act = input('act', '');
            if ($act == 'save_api') {
                $provider = input('provider', 'aliyun');
                $appid = trim(input('appid', ''));
                $appkey = trim(input('appkey', ''));
                $existing = Db::name('domain_api_config')->where('provider', $provider)->find();
                if ($existing) {
                    Db::name('domain_api_config')->where('id', $existing['id'])->update([
                        'appid' => $appid, 'appkey' => $appkey, 'status' => intval(input('status', 0)),
                    ]);
                } else {
                    Db::name('domain_api_config')->insert([
                        'provider' => $provider, 'appid' => $appid, 'appkey' => $appkey, 'status' => intval(input('status', 0)),
                    ]);
                }
                admin_op_log('domain_api_save', '保存域名API配置', ['provider' => $provider]);
                return json(['code' => '1', 'msg' => '保存成功']);
            }
            if ($act == 'toggle_status') {
                $id = intval(input('id', 0));
                $status = intval(input('status', 0));
                Db::name('domain_product')->where('id', $id)->update(['status' => $status]);
                return json(['code' => '1', 'msg' => '操作成功']);
            }
            if ($act == 'delete') {
                $id = intval(input('id', 0));
                Db::name('domain_product')->where('id', $id)->delete();
                return json(['code' => '1', 'msg' => '删除成功']);
            }
            return json(['code' => '-1', 'msg' => '无效操作']);
        }
        $domains = Db::name('domain_product')->order('id desc')->paginate(20);
        $apiConfigs = Db::name('domain_api_config')->select();
        $this->assign('domains', $domains);
        $this->assign('apiConfigs', $apiConfigs);
        return $this->fetch('/'.$this->web["admintemplate"].'/admin_domain');
    }
}
