<?php
namespace app\common;

use think\exception\Handle;
use think\exception\HttpException;
use Exception;

/**
 * 自定义异常处理 - 隐藏所有服务器路径和敏感信息
 */
class SecurityExceptionHandle extends Handle
{
    public function render(Exception $e)
    {
        // 记录真实错误到日志（供管理员查看）
        $this->logException($e);

        // 判断是否为AJAX请求
        $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

        // AJAX/POST 请求统一返回 JSON，避免 app_debug=true 时输出 HTML 错误页导致前端进入 error 分支显示"提交失败"
        if ($isAjax || $isPost) {
            // 数据库异常且系统未安装 → 提示先完成安装（而不是抛出 SQL 错误）
            if ($this->isDbException($e) && !$this->isInstalled()) {
                return json(['code' => -1, 'msg' => '系统尚未安装或数据库连接失败，请先完成系统安装', 'redirect' => '/install'], 500);
            }
            $msg = \think\App::$debug ? $e->getMessage() : '系统繁忙，请稍后再试';
            return json(['code' => -1, 'msg' => $msg], 500);
        }

        // 数据库异常且系统未安装（未配置/连库失败/无已安装标记）→ 引导进入安装向导
        // 注意：此判断须在 debug 分支之前，否则 app_debug=true 时会直接显示 SQL 错误页
        if ($this->isDbException($e) && !$this->isInstalled()) {
            $baseDir = rtrim(str_replace('\\', '/', dirname(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/index.php')), '/');
            $installUrl = ($baseDir === '/' || $baseDir === '') ? '/install' : $baseDir . '/install';
            if (!headers_sent()) {
                header('Location: ' . $installUrl, true, 302);
            } else {
                echo '<meta http-equiv="refresh" content="0;url=' . htmlspecialchars($installUrl) . '">';
            }
            exit;
        }

        // 调试模式：直接展示 ThinkPHP 原生详细错误页（含堆栈与源码），便于快速定位问题。
        // 生产环境请将 app_debug 设为 false，届时仍走下方统一的"系统维护中"页面，不泄露任何信息。
        if (\think\App::$debug) {
            return parent::render($e);
        }

        // HTTP异常（404/403等）
        if ($e instanceof HttpException) {
            $statusCode = $e->getStatusCode();
            return $this->httpError($statusCode);
        }

        // 数据库异常
        $className = get_class($e);
        if (strpos($className, 'PDO') !== false || strpos($className, 'Db') !== false) {
            // 不向用户暴露SQL错误
            $this->logCritical('Database error detected', $e);
            return $this->genericError(500);
        }

        // 通用500错误 - 绝对不显示路径
        return $this->genericError(500);
    }

    /**
     * 判断是否为数据库相关异常
     */
    private function isDbException(Exception $e)
    {
        $className = get_class($e);
        return strpos($className, 'PDO') !== false
            || strpos($className, 'Db') !== false
            || stripos($e->getMessage(), 'SQLSTATE') !== false;
    }

    /**
     * 系统是否已完成安装（依据数据库中的专属标记，不依赖 install.lock）
     */
    private function isInstalled()
    {
        try {
            require_once dirname(dirname(__DIR__)) . '/app/install_check.php';
            if (!defined('PATH')) {
                return false;
            }
            return mnbt_install_state(false)['ok'] === true;
        } catch (\Throwable $e2) {
            return false;
        }
    }

    /**
     * 记录错误到安全日志
     */
    private function logException(Exception $e)
    {
        try {
            $logDir = (defined('LOG_PATH') ? LOG_PATH : (dirname(__DIR__) . '/runtime/log/')) . 'security/';
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0755, true);
            }
            $log = [
                'time'    => date('Y-m-d H:i:s'),
                'type'    => get_class($e),
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'url'     => isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '',
                'method'  => isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '',
                'ip'      => $_SERVER['REMOTE_ADDR'] ?? ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? 'unknown'),
                'ua'      => isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr($_SERVER['HTTP_USER_AGENT'], 0, 200) : '',
            ];
            file_put_contents($logDir . 'error_' . date('Y-m-d') . '.log',
                json_encode($log, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);

            // 异常IP检测：同一IP在短时间内频繁触发异常 → 自动封禁1小时
            $this->checkExceptionAbuse($log['ip'], $logDir);
        } catch (\Exception $e2) {
            // 静默处理
        }
    }

    /**
     * 检测异常IP滥用并自动封禁
     * 同一IP在10分钟内触发≥10次异常 → 封禁1小时
     * 同一IP在1小时内触发≥30次异常 → 封禁24小时
     */
    private function checkExceptionAbuse($ip, $logDir)
    {
        try {
            $now = time();
            $counterFile = $logDir . 'exception_counter_' . md5($ip) . '.json';
            $data = ['count' => 0, 'first_time' => $now, 'total_hour' => 0];

            if (file_exists($counterFile)) {
                $data = json_decode(file_get_contents($counterFile), true) ?: $data;
            }

            $data['count']++;
            $data['total_hour']++;

            // 10分钟内≥10次 → 记录异常（自动封禁已禁用，IP封禁为手动管理）
            if (($now - $data['first_time']) <= 600 && $data['count'] >= 10) {
                if (function_exists('security_log')) {
                    security_log('exception_abuse_detected', '频繁异常IP检测: ' . $ip . ' 10分钟内' . $data['count'] . '次（未自动封禁）');
                }
                // 重置5分钟计数器
                $data['count'] = 0;
                $data['first_time'] = $now;
            }

            // 每小时超额 → 记录严重异常（自动封禁已禁用）
            if ($data['total_hour'] >= 30) {
                if (function_exists('security_log')) {
                    security_log('exception_severe_detected', '严重异常IP检测: ' . $ip . ' 1小时内' . $data['total_hour'] . '次（未自动封禁）');
                }
                $data['total_hour'] = 0;
            }

            // 重置10分钟窗口
            if (($now - $data['first_time']) > 600) {
                $data['count'] = 1;
                $data['first_time'] = $now;
            }

            @file_put_contents($counterFile, json_encode($data), LOCK_EX);
        } catch (\Exception $e2) {}
    }

    /**
     * 记录严重安全事件
     */
    private function logCritical($reason, Exception $e)
    {
        try {
            $logDir = (defined('LOG_PATH') ? LOG_PATH : (dirname(__DIR__) . '/runtime/log/')) . 'security/';
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0755, true);
            }
            $log = [
                'time'    => date('Y-m-d H:i:s'),
                'reason'  => $reason,
                'type'    => get_class($e),
                'message' => $e->getMessage(),
                'url'     => isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '',
                'ip'      => $_SERVER['REMOTE_ADDR'] ?? ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? 'unknown'),
                'ua'      => isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr($_SERVER['HTTP_USER_AGENT'], 0, 200) : '',
            ];
            file_put_contents($logDir . 'critical_' . date('Y-m-d') . '.log',
                json_encode($log, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
        } catch (\Exception $e2) {}
    }

    /**
     * HTTP错误页面（不泄露任何信息）
     */
    private function httpError($code)
    {
        $titles = [
            400 => '请求错误',
            403 => '禁止访问',
            404 => '页面未找到',
            405 => '方法不允许',
            500 => '服务器错误',
            502 => '网关错误',
            503 => '服务维护中',
        ];
        $title = isset($titles[$code]) ? $titles[$code] : '访问错误';
        $msg = $code == 404 ? '您访问的页面不存在或已被移除' :
               ($code == 403 ? '您没有权限访问此资源' : '服务器暂时无法处理您的请求');

        $html = '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8">';
        $html .= '<meta name="viewport" content="width=device-width,initial-scale=1">';
        $html .= '<title>' . $code . ' - ' . $title . '</title>';
        $html .= '<style>*{margin:0;padding:0;box-sizing:border-box}';
        $html .= 'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f8fafc;display:flex;align-items:center;justify-content:center;min-height:100vh;color:#334155}';
        $html .= '.container{text-align:center;padding:40px 20px}';
        $html .= '.code{font-size:120px;font-weight:900;color:#e2e8f0;line-height:1}';
        $html .= '.title{font-size:24px;font-weight:700;color:#1a1a2e;margin:16px 0 8px}';
        $html .= '.msg{font-size:14px;color:#94a3b8;margin-bottom:24px}';
        $html .= '.link{display:inline-block;padding:10px 24px;background:#6366f1;color:#fff;border-radius:10px;text-decoration:none;font-size:14px;font-weight:600;transition:all .2s}';
        $html .= '.link:hover{background:#4f46e5;transform:translateY(-1px)}';
        $html .= '</style></head><body>';
        $html .= '<div class="container"><div class="code">' . $code . '</div>';
        $html .= '<div class="title">' . $title . '</div>';
        $html .= '<div class="msg">' . $msg . '</div>';
        $html .= '<a href="/" class="link">返回首页</a></div>';
        $html .= '</body></html>';

        return function_exists('response') ? response($html, $code) : \think\Response::create($html, '', $code);
    }

    /**
     * 通用500错误
     */
    private function genericError($code)
    {
        $html = '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8">';
        $html .= '<meta name="viewport" content="width=device-width,initial-scale=1">';
        $html .= '<title>系统维护中</title>';
        $html .= '<style>*{margin:0;padding:0;box-sizing:border-box}';
        $html .= 'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f8fafc;display:flex;align-items:center;justify-content:center;min-height:100vh;color:#334155}';
        $html .= '.container{text-align:center;padding:40px 20px}';
        $html .= '.icon{width:80px;height:80px;border-radius:50%;background:#fef2f2;display:flex;align-items:center;justify-content:center;margin:0 auto 20px}';
        $html .= '.icon svg{width:40px;height:40px;color:#ef4444}';
        $html .= '.title{font-size:20px;font-weight:700;color:#1a1a2e;margin-bottom:8px}';
        $html .= '.msg{font-size:14px;color:#94a3b8;line-height:1.6}';
        $html .= '</style></head><body>';
        $html .= '<div class="container">';
        $html .= '<div class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></div>';
        $html .= '<div class="title">系统维护中</div>';
        $html .= '<div class="msg">服务器暂时无法处理您的请求，请稍后重试。<br>如问题持续存在，请联系管理员。</div>';
        $html .= '</div></body></html>';

        return function_exists('response') ? response($html, $code) : \think\Response::create($html, '', $code);
    }
}