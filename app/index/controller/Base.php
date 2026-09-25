<?php
namespace app\index\controller;
use think\Controller;

class Base extends Controller {

    /**
     * 注入前台公共模板变量（header/user/header.html 依赖）
     */
    protected function assignCommonVars() {
        $web = isset($this->web) ? $this->web : (function_exists('web_config') ? web_config() : []);
        if (!isset($this->web) && !empty($web)) {
            $this->web = $web;
        }
        // 主题兼容
        if (isset($this->web["template"]) && $this->web["template"] == "layui") {
            $this->web["template"] = "default";
        }
        // templateset
        $templateset = array(""=>"");
        if (!empty($this->web["templateset"])) {
            $tempset = json_decode($this->web["templateset"], true);
            if (is_array($tempset)) {
                for ($i = 0; $i < count($tempset); $i++) {
                    $templateset[$tempset[$i]["name"]] = $tempset[$i]["value"];
                }
            }
        }
        // 统一处理 LOGO / favicon 路径：相对路径补全为完整 URL，避免活动中心等页面 LOGO 不显示
        $this->web['logo'] = $this->normalizeAssetUrl($this->web['logo'] ?? '');
        $this->web['favicon'] = $this->normalizeAssetUrl($this->web['favicon'] ?? '');
        // 公共变量默认值兜底，避免子模板未注入时报「未定义变量」
        $commonAssign = [
            'webname'     => $this->web['name'] ?? '',
            'description' => $this->web['description'] ?? '',
            'keywords'    => $this->web['keywords'] ?? '',
            'favicon'     => $this->web['favicon'] ?? '',
            'web'         => $this->web,
            'userstate'   => session('userid') ? '1' : '0',
            'templateset' => $templateset,
            'user'        => isset($this->user) && is_array($this->user) ? $this->user : [],
            'membershipLevel' => isset($this->user['membership_level']) ? intval($this->user['membership_level']) : 0,
            'membershipInfo'  => null,
        ];
        $this->assign($commonAssign);
        // 同时显式注入到 View 引擎，确保 include 子模板也能看到
        if (isset($this->view) && is_object($this->view)) {
            try {
                $engine = isset($this->view->engine) ? $this->view->engine : null;
                if ($engine && method_exists($engine, 'assign')) {
                    foreach ($commonAssign as $k => $v) {
                        $engine->assign($k, $v);
                    }
                }
            } catch (\Throwable $e) {}
        }
        // 兼容：部分 controller 直接调用 fetch() 时，可能未经过 assignCommonVars()，
        // 这里再次强制把基础变量挂到模板渲染引擎上
        if (method_exists($this, 'view') && is_object($this->view)) {
            try {
                $this->view->assign([
                    'webname' => $this->web['name'] ?? '',
                    'web'     => $this->web,
                    'userstate' => session('userid') ? '1' : '0',
                ]);
            } catch (\Throwable $e) {}
        }
    }

    /**
     * 归一化资源 URL：把相对路径（如 uploads/logo.png 或 ./logo.png）补全为完整可访问 URL
     * - 空值原样返回
     * - 完整 http(s):// 或 // 协议相对 URL 原样返回
     * - 以 / 开头的站点根相对路径原样返回（浏览器会自动解析）
     * - __ROOT__/ 前缀替换为站点根路径
     * - 其它相对路径补全为当前域名前缀
     */
    protected function normalizeAssetUrl($url) {
        $url = trim((string)$url);
        if ($url === '') return '';
        // 已是完整 URL 或协议相对 URL
        if (preg_match('#^(https?:)?//#i', $url)) return $url;
        // __ROOT__ 占位符替换为站点根
        if (strpos($url, '__ROOT__') === 0) {
            $root = \think\Request::instance()->root();
            $url = $root . substr($url, 9);
            if ($url === '') $url = '/';
            return $url;
        }
        // 站点根相对路径
        if ($url[0] === '/') return $url;
        // 其它相对路径：补全当前域名
        $scheme = (function_exists('isHTTPS') && isHTTPS()) ? 'https' : 'http';
        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
        return $scheme . '://' . $host . '/' . ltrim($url, '/');
    }

    /**
     * 统一使用电脑端模板（手机端不再单独切换视图，与电脑端通用界面）
     */
    protected function fetch($template = '', $vars = [], $replace = [], $config = []) {
        return parent::fetch($template, $vars, $replace, $config);
    }
}