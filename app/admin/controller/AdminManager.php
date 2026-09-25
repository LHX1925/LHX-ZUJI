<?php
namespace app\admin\controller;
use think\Controller;
use think\Db;
use think\Request;

class AdminManager extends Controller
{
    public function _initialize() {
        if(!session("adminid")) {
            $this->redirect(url('admin/login/index'));
        }
        $this->user = Db::name('admin')->where('id', session("adminid"))->find();
		$this->web = web_config();

		// 确保 admin 表字段完整（role_id/is_super/status/created_at/permissions）
		ensure_admin_columns();

		// 确保 admin_role 表存在且为两角色体系（超级管理员/普通管理员），含旧版自动迁移
		ensure_admin_role_table();

		// 字段补全后重新读取当前管理员信息
		$this->user = Db::name('admin')->where('id', session("adminid"))->find();

		// 如果数据库中仍配置为旧版 layui 后台主题，强制使用已重构的 default 主题
		if($this->web["admintemplate"]=="layui"){
			$this->web["admintemplate"]="default";
		}

		// 计算当前管理员权限
        $this->isFounder = ($this->user['is_super'] == 1); // 创始人（超级管理员）
        $this->hasFullAccess = ($this->isFounder || $this->user['role_id'] == 1); // 超级管理员
        $adminPermissions = get_admin_permissions($this->user);

        // 仅超级管理员或拥有 admin_manager 权限的角色可访问
        if (!$this->hasFullAccess && !in_array('admin_manager', $adminPermissions)) {
            $this->error('您没有权限访问此页面', '/admin/index');
        }
        
        $file = file_exists(PATH."/app/index/view/".$this->web["template"]."/set.php");
        $templateset = $file ? "1" : "0";
        
        $this->assign([
            'webname' => $this->web['name'],
            'web'     => $this->web,
            'user' => $this->user,
            'templateset' => $templateset,
            'adminPermissions' => $adminPermissions,
            'isFounder' => $this->isFounder,
        ]);
    }

    // List all admins
    public function index()
    {
        $admins = Db::name('admin')->order('id asc')->paginate(10);
        $roles = Db::name('admin_role')->select();
        $roleMap = [];
        foreach ($roles as $role) {
            $roleMap[$role['id']] = $role['name'];
        }
        
        return $this->fetch('/'.$this->web["admintemplate"]."/admin_list", [
            'admins'   => $admins,
            'roleMap'  => $roleMap,
            'isFounder'=> $this->isFounder,
        ]);
    }

    // Add new admin
    public function add()
    {
        if (Request::instance()->isPost()) {
            $array = ["code" => "-1", "msg" => ""];
            $user = input('user', '');
            $password = input('password', '');
            $name = input('name', '');
            $mail = input('mail', '');
            $qq = input('qq', '');
            $role_id = intval(input('role_id', 2));
            $permissions = input('permissions/a', []);
            
            if (empty($user) || empty($password) || empty($name)) {
                $array['code'] = '-1';
                $array['msg'] = '必填参数不可为空';
                return json($array);
            }
            
            // 仅两个角色：1=超级管理员 2=普通管理员
            if (!in_array($role_id, [1, 2])) {
                $role_id = 2;
            }
            
            // 非超级管理员不可创建超级管理员
            if (!$this->hasFullAccess && $role_id == 1) {
                $array['code'] = '-1';
                $array['msg'] = '仅超级管理员可以创建超级管理员';
                return json($array);
            }
            
            $exists = Db::name('admin')->where('user', $user)->find();
            if ($exists) {
                $array['code'] = '-1';
                $array['msg'] = '管理员账号已存在';
                return json($array);
            }
            
            // 超级管理员固定全权限；普通管理员存单个勾选的权限
            $permsJson = ($role_id == 1) ? '' : json_encode(array_values($permissions));
            
            $data = [
                'user' => $user,
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'name' => $name,
                'mail' => $mail,
                'qq' => $qq,
                'role_id' => $role_id,
                'permissions' => $permsJson,
                'is_super' => 0,
                'status' => 1,
                'created_at' => time(),
            ];
            
            $id = Db::name('admin')->insertGetId($data);
            if ($id) {
                $array['code'] = '1';
                $array['msg'] = '添加成功';
                admin_op_log('admin_add', '添加管理员：' . $user, ['role_id' => $role_id, 'name' => $name]);
                return json($array);
            }
            $array['code'] = '-1';
            $array['msg'] = '添加失败';
            return json($array);
        }
        
        $roles = Db::name('admin_role')->order('id asc')->select();
        return $this->fetch('/'.$this->web["admintemplate"]."/admin_add", [
            'roles' => $roles,
            'isCurrentSuper' => $this->hasFullAccess,
        ]);
    }

    // Edit admin
    public function edit($id = null)
    {
        if (!$id) {
            $this->redirect('/admin/admin_manager');
        }
        
        $admin = Db::name('admin')->where('id', $id)->find();
        if (!$admin) {
            $this->redirect('/admin/admin_manager');
        }
        
        // 当前操作者是否为超级管理员
        $isCurrentSuper = $this->hasFullAccess;
        
        if (Request::instance()->isPost()) {
            $array = ["code" => "-1", "msg" => ""];
            $user = input('user', '');
            $name = input('name', '');
            $mail = input('mail', '');
            $qq = input('qq', '');
            $password = input('password', '');
            $role_id = intval(input('role_id', 2));
            $permissions = input('permissions/a', []);
            $status = input('status', 1);
            
            if (empty($user) || empty($name)) {
                $array['code'] = '-1';
                $array['msg'] = '用户名和姓名不能为空';
                return json($array);
            }
            
            // 检查用户名是否已被其他管理员使用
            $exists = Db::name('admin')->where('user', $user)->where('id', '<>', $id)->find();
            if ($exists) {
                $array['code'] = '-1';
                $array['msg'] = '该用户名已被使用';
                return json($array);
            }
            
            // 仅两个角色：1=超级管理员 2=普通管理员
            if (!in_array($role_id, [1, 2])) {
                $role_id = 2;
            }
            
            // 非超级管理员不可设置他人为超级管理员
            if (!$this->hasFullAccess && $role_id == 1) {
                $array['code'] = '-1';
                $array['msg'] = '仅超级管理员可以设置超级管理员';
                return json($array);
            }
            
            // 创始人（is_super=1）不可被降级，固定为超级管理员
            if (intval($admin['is_super']) == 1) {
                $role_id = 1;
            }
            
            // 禁止非超级管理员修改自己的角色（防止自我降权导致无法管理）
            if (!$this->hasFullAccess && $id == $this->user['id']) {
                $role_id = $this->user['role_id'];
            }

            // 超级管理员固定全权限；普通管理员存单个勾选的权限
            $permsJson = ($role_id == 1) ? '' : json_encode(array_values($permissions));

            $update = [
                'user' => $user,
                'name' => $name,
                'mail' => $mail,
                'qq' => $qq,
                'role_id' => $role_id,
                'permissions' => $permsJson,
                'status' => $status,
            ];
            
            if (!empty($password)) {
                $update['password'] = password_hash($password, PASSWORD_DEFAULT);
            }
            
            $result = Db::name('admin')->where('id', $id)->update($update);
            if ($result !== false) {
                $array['code'] = '1';
                $array['msg'] = '修改成功';
                admin_op_log('admin_edit', '编辑管理员：' . $user, ['role_id' => $role_id, 'status' => $status, 'pwd_changed' => !empty($password)]);
                return json($array);
            }
            $array['code'] = '-1';
            $array['msg'] = '修改失败';
            return json($array);
        }
        
        $roles = Db::name('admin_role')->order('id asc')->select();
        $admin['perm_list'] = json_decode(isset($admin['permissions']) ? $admin['permissions'] : '', true);
        if (!is_array($admin['perm_list'])) $admin['perm_list'] = [];
        return $this->fetch('/'.$this->web["admintemplate"]."/admin_edit", [
            'admin' => $admin,
            'roles' => $roles,
            'isCurrentSuper' => $this->hasFullAccess,
        ]);
    }

    // Delete admin
    public function delete()
    {
        if (Request::instance()->isPost()) {
            $array = ["code" => "-1", "msg" => ""];
            $id = input('id', 0);
            if (!$id) {
                $array['code'] = '-1';
                $array['msg'] = '参数错误';
                return json($array);
            }
            
            $admin = Db::name('admin')->where('id', $id)->find();
            if (!$admin) {
                $array['code'] = '-1';
                $array['msg'] = '管理员不存在';
                return json($array);
            }
            
            // 仅超级管理员可以删除管理员，但不能删除自己
            if (!$this->hasFullAccess) {
                $array['code'] = '-1';
                $array['msg'] = '仅超级管理员可以删除管理员';
                return json($array);
            }
            if ($id == session('adminid')) {
                $array['code'] = '-1';
                $array['msg'] = '不能删除自己';
                return json($array);
            }
            
            $result = Db::name('admin')->where('id', $id)->delete();
            if ($result) {
                $array['code'] = '1';
                $array['msg'] = '删除成功';
                admin_op_log('admin_delete', '删除管理员：' . $admin['user'], ['id' => $id]);
                return json($array);
            }
            $array['code'] = '-1';
            $array['msg'] = '删除失败';
            return json($array);
        }
    }

    // List roles
    public function roles()
    {
        $roles = Db::name('admin_role')->select();
        return $this->fetch('/'.$this->web["admintemplate"]."/admin_roles", [
            'roles'    => $roles,
            'isFounder'=> $this->isFounder,
        ]);
    }

    // Add role
    public function addRole()
    {
        if (Request::instance()->isPost()) {
            $array = ["code" => "-1", "msg" => ""];
            $name = input('name', '');
            $description = input('description', '');
            $permissions = input('permissions/a', []);
            
            if (empty($name)) {
                $array['code'] = '-1';
                $array['msg'] = '角色名称不能为空';
                return json($array);
            }
            
            $data = [
                'name' => $name,
                'description' => $description,
                'permissions' => json_encode($permissions),
                'created_at' => time(),
            ];
            
            $id = Db::name('admin_role')->insertGetId($data);
            if ($id) {
                $array['code'] = '1';
                $array['msg'] = '添加成功';
                admin_op_log('role_add', '添加角色：' . $name, ['permissions' => $permissions]);
                return json($array);
            }
            $array['code'] = '-1';
            $array['msg'] = '添加失败';
            return json($array);
        }
        
        return $this->fetch('/'.$this->web["admintemplate"]."/admin_role_add");
    }

    // Edit role
    public function editRole($id = null)
    {
        if (!$id) {
            $this->redirect('/admin/admin_manager/roles');
        }
        
        $role = Db::name('admin_role')->where('id', $id)->find();
        if (!$role) {
            $this->redirect('/admin/admin_manager/roles');
        }
        
        // 仅超级管理员可编辑系统默认角色
        $isCurrentSuper = ($this->user['is_super'] == 1 || $this->user['role_id'] == 1);
        if (in_array($id, [1, 2]) && !$isCurrentSuper) {
            $this->error('您没有权限编辑系统默认角色', '/admin/admin_manager/roles');
        }
        
        if (Request::instance()->isPost()) {
            $array = ["code" => "-1", "msg" => ""];
            $name = input('name', '');
            $description = input('description', '');
            $permissions = input('permissions/a', []);
            
            if (empty($name)) {
                $array['code'] = '-1';
                $array['msg'] = '角色名称不能为空';
                return json($array);
            }
            
            $data = [
                'name' => $name,
                'description' => $description,
                'permissions' => json_encode($permissions),
            ];
            
            $result = Db::name('admin_role')->where('id', $id)->update($data);
            if ($result !== false) {
                $array['code'] = '1';
                $array['msg'] = '修改成功';
                admin_op_log('role_edit', '编辑角色：' . $name, ['permissions' => $permissions]);
                return json($array);
            }
            $array['code'] = '-1';
            $array['msg'] = '修改失败';
            return json($array);
        }
        
        $role['permissions'] = json_decode($role['permissions'], true);
        return $this->fetch('/'.$this->web["admintemplate"]."/admin_role_edit", [
            'role' => $role,
        ]);
    }

    // Delete role
    public function deleteRole()
    {
        if (Request::instance()->isPost()) {
            $array = ["code" => "-1", "msg" => ""];
            $id = input('id', 0);
            if (in_array($id, [1, 2])) {
                $array['code'] = '-1';
                $array['msg'] = '不能删除系统默认角色';
                return json($array);
            }
            
            $role = Db::name('admin_role')->where('id', $id)->find();
            $roleName = $role ? $role['name'] : ('ID:' . $id);
            $result = Db::name('admin_role')->where('id', $id)->delete();
            if ($result) {
                $array['code'] = '1';
                $array['msg'] = '删除成功';
                admin_op_log('role_delete', '删除角色：' . $roleName, ['id' => $id]);
                return json($array);
            }
            $array['code'] = '-1';
            $array['msg'] = '删除失败';
            return json($array);
        }
    }
}