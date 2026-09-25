<?php
namespace app\behavior;

/**
 * 全局安装状态守卫（app_begin 行为，在所有控制器执行前触发）
 *
 * 满足任一条件即视为「未安装」：
 *   1. 未添加数据库连接信息（app/database.php 缺失 / 关键项为空）
 *   2. 数据库连接失败
 *   3. 数据库中没有专属的已安装标记（{prefix}install 表）
 *
 * 此时除「安装模块」外的所有请求一律重定向到 /install，
 * 防止 index/admin 等控制器里的数据库查询（web_config、ensure_user_columns、
 * ip_ban、访客日志等）在未安装状态下抛出 PDO 异常（如 SQLSTATE[1045]）。
 */
class InstallCheck
{
    public function run()
    {
        // 命令行（cron 等计划任务）不拦截
        if (PHP_SAPI === 'cli' || !defined('PATH')) {
            return;
        }

        require_once PATH . 'app/install_check.php';

        // 当前请求路径（支持子目录部署与常见重写变量）
        $scriptName = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/index.php';
        // 依次尝试常见的重写变量，取第一个能解析出真实路径的来源
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
        $candidates[] = '/';

        $path = '/';
        foreach ($candidates as $raw) {
            $tmp = parse_url((string) $raw, PHP_URL_PATH);
            if ($tmp === null || $tmp === false || $tmp === '') {
                continue;
            }
            $tmp = str_replace('\\', '/', $tmp);
            if ($tmp === '' || $tmp === '/') {
                continue;
            }
            // 跳过 /index.php 这类「入口文件自身」的路径，避免把安装页误判为首页
            if (rtrim($tmp, '/') === rtrim(str_replace('\\', '/', $scriptName), '/')) {
                continue;
            }
            $path = $tmp;
            break;
        }

        $scriptName = str_replace('\\', '/', $scriptName);
        $baseDir   = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
        if ($baseDir === '' || $baseDir === '/') {
            $baseDir = '';
        }

        // 安装模块自身放行，避免重定向死循环
        if (mnbt_is_install_uri($path, $baseDir)) {
            return;
        }

        // 已安装 → 放行（正向结果有 60 秒文件缓存，开销可忽略）
        $state = mnbt_install_state();
        if ($state['ok']) {
            return;
        }

        // 未安装：AJAX 返回 JSON，其余一律 302 到安装向导
        $installUrl = $baseDir . '/install';
        $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'code'     => -1,
                'msg'      => '系统尚未安装或数据库连接失败，请先完成系统安装',
                'redirect' => $installUrl,
            ]);
            exit;
        }
        // mnbt_install_goto 内含自跳防御与循环熔断，避免形成 302 死循环
        mnbt_install_goto($installUrl);
        exit;
    }
}
