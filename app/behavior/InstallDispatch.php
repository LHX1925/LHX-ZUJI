<?php
namespace app\behavior;

/**
 * 安装向导强制调度（app_dispatch 行为，在路由检测之前执行）
 *
 * 为什么要这一层：
 *   服务器伪静态若没把 PATH_INFO 传进来（如 try_files $uri $uri/ /index.php?$query_string），
 *   ThinkPHP 的 Request::path() 会退化成 "/"，/install 会被当成首页路由
 *   （静态路由 "/" -> index/index/index），于是访问 /install 得到的是前台首页，
 *   再由前台控制器裸查数据库抛 SQLSTATE[1045]。
 *
 *   本行为在「请求路径为安装向导」时直接指定调度目标为 install 模块，
 *   完全绕过路由表与伪静态，保证域名后输入 install 一定能进安装向导。
 *
 * 调度规则：
 *   /install                 -> install/index/index
 *   /install/index           -> install/index/index
 *   /install/index/step2~4   -> install/index/stepN
 *   /install/index/done      -> install/index/done
 *   其余未知段位一律回落到 index（许可协议首页）
 */
class InstallDispatch
{
    /**
     * @param array|null $dispatch 引用传递，赋值即可覆盖后续路由检测
     */
    public function appDispatch(&$dispatch)
    {
        if (PHP_SAPI === 'cli' || !defined('PATH')) {
            return;
        }

        $action = $this->matchInstallAction();
        if ($action === null) {
            return;
        }

        $dispatch = [
            'type'    => 'module',
            'module'  => ['install', 'index', $action],
            'convert' => false,
        ];

        // 记录一条诊断日志（runtime/log/install_redirect.log），便于线上确认是否命中
        if (is_file(PATH . 'app/install_check.php')) {
            require_once PATH . 'app/install_check.php';
            if (function_exists('mnbt_install_log')) {
                $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
                mnbt_install_log($uri, 'install/index/' . $action, 'force_module', 'app_dispatch');
            }
        }
    }

    /**
     * 判断当前请求是否属于安装向导，并返回对应的操作名
     * @return string|null 非安装路径返回 null
     */
    private function matchInstallAction()
    {
        $scriptName = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', (string) $_SERVER['SCRIPT_NAME']) : '/index.php';
        $baseDir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
        if ($baseDir === '' || $baseDir === '/' || $baseDir === '.') {
            $baseDir = '';
        }

        // 候选路径来源（部分重写组件会用 HTTP_X_REWRITE_URL）
        $candidates = [];
        if (!empty($_SERVER['HTTP_X_REWRITE_URL'])) {
            $candidates[] = (string) $_SERVER['HTTP_X_REWRITE_URL'];
        }
        if (!empty($_SERVER['REQUEST_URI'])) {
            $candidates[] = (string) $_SERVER['REQUEST_URI'];
        }
        if (!empty($_SERVER['PATH_INFO'])) {
            $candidates[] = (string) $_SERVER['PATH_INFO'];
        }

        $matched = null;
        $restAfter = '';
        foreach ($candidates as $raw) {
            $candidate = (string) parse_url((string) $raw, PHP_URL_PATH);
            if ($candidate === '') {
                continue;
            }
            $candidate = str_replace('\\', '/', $candidate);
            if ($scriptName !== '' && stripos($candidate, $scriptName) === 0) {
                $candidate = substr($candidate, strlen($scriptName));
            } elseif ($baseDir !== '' && stripos($candidate, $baseDir) === 0) {
                $candidate = substr($candidate, strlen($baseDir));
            }
            if ($candidate === '' || $candidate[0] !== '/') {
                $candidate = '/' . $candidate;
            }
            if (preg_match('#^/install(/|$)#i', $candidate) === 1) {
                $matched    = $candidate;
                $restAfter  = trim(substr($candidate, strlen('/install')), '/');
                break;
            }
        }

        if ($matched === null) {
            return null;
        }

        $rest = $restAfter;
        if ($rest === '') {
            return 'index';
        }

        $segments = explode('/', $rest);
        // 去掉多余的 index 段（/install/index/step2 与 /install/step2 都支持）
        if (isset($segments[0]) && strtolower($segments[0]) === 'index') {
            array_shift($segments);
        }

        $map = [
            ''     => 'index',
            'index' => 'index',
            'license' => 'license',
            'step2' => 'step2',
            'step3' => 'step3',
            'step4' => 'step4',
            'done'  => 'done',
        ];

        $name = isset($segments[0]) ? strtolower($segments[0]) : '';
        return isset($map[$name]) ? $map[$name] : 'index';
    }
}
