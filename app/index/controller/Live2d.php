<?php
namespace app\index\controller;
use think\Controller;
use think\Db;

class Live2d extends Controller {
    public function _initialize() {
        // 设置跨域和JSON响应
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
    }

    /**
     * AI聊天接口
     */
    public function chat() {
        if (request()->isOptions()) {
            return '';
        }

        $message = input('post.message', '', 'trim');
        if (empty($message)) {
            return json(['code' => 1, 'msg' => '消息不能为空']);
        }

        // 限制消息长度
        if (mb_strlen($message) > 500) {
            return json(['code' => 1, 'msg' => '消息过长，请控制在500字以内']);
        }

        $web = web_config();
        $siteName = isset($web['name']) ? $web['name'] : '云主机';

        // 尝试调用AI API（如果配置了）
        $aiApiUrl = isset($web['live2d_ai_api_url']) ? $web['live2d_ai_api_url'] : '';
        $aiApiKey = isset($web['live2d_ai_api_key']) ? $web['live2d_ai_api_key'] : '';

        $reply = '';
        $debug = [];
        $mode = 'local';

        // 会话历史：用于保持上下文与"调教"记忆（如用户让AI叫"妈妈"后能记住）
        $historyKey = 'live2d_chat_history';
        $history = session($historyKey);
        if (!is_array($history)) {
            $history = [];
        }

        if (!empty($aiApiKey)) {
            $result = $this->callAiApi($aiApiUrl, $aiApiKey, $message, $siteName, $history);
            $reply = $result['reply'];
            $debug = $result['debug'];
            if (!empty($reply)) {
                $mode = 'ai';
            }
        } else {
            $debug[] = '未配置AI API Key，使用本地规则引擎';
        }

        // 如果AI API未配置或调用失败，使用本地规则引擎
        if (empty($reply)) {
            $reply = $this->getLocalReply($message, $siteName);
            $debug[] = 'AI调用失败或为空，回退本地规则引擎';
        }

        // 更新会话历史（仅保留最近若干条，避免无限膨胀）
        if (!empty($reply)) {
            $history[] = ['role' => 'user', 'content' => $message];
            $history[] = ['role' => 'assistant', 'content' => $reply];
            if (count($history) > 20) {
                $history = array_slice($history, -20);
            }
            session($historyKey, $history);
        }

        return json([
            'code' => 0,
            'msg' => 'success',
            'data' => ['reply' => $reply, 'mode' => $mode],
            'debug' => $debug
        ]);
    }

    /**
     * 手机端纹理压缩接口：/live2d/texture?f=<相对路径>&s=<最大边长>
     * 服务器端一次性降采样并缓存，避免手机端下载 4096/8192 大图导致黑屏或加载失败
     */
    public function texture() {
        $f = isset($_GET['f']) ? trim($_GET['f']) : '';
        $s = isset($_GET['s']) ? intval($_GET['s']) : 2048;
        if ($s < 128) $s = 2048;
        if ($s > 4096) $s = 4096;

        // 安全校验：仅允许访问 live2d 模型目录下的 PNG 纹理，禁止目录穿越
        $f = str_replace('\\', '/', $f);
        $f = ltrim($f, '/');
        if ($f === '' || strpos($f, '..') !== false || !preg_match('#^static/live2d/models/.+\.png$#i', $f)) {
            http_response_code(404);
            exit('Not Found');
        }

        $src = PATH . 'public/' . $f;
        if (!is_file($src)) {
            http_response_code(404);
            exit('Not Found');
        }

        // 输出（带 ETag/Last-Modified 缓存）
        $output = function ($file) {
            $mtime = @filemtime($file);
            $etag = '"' . md5($file . '|' . $mtime) . '"';
            $since = isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) ? strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) : 0;
            $noneMatch = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? trim($_SERVER['HTTP_IF_NONE_MATCH']) : '';
            header('Content-Type: image/png');
            header('Cache-Control: public, max-age=86400');
            header('ETag: ' . $etag);
            if ($mtime) {
                header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
            }
            if (($noneMatch !== '' && $noneMatch === $etag) || ($mtime && $since >= $mtime)) {
                http_response_code(304);
                exit;
            }
            readfile($file);
            exit;
        };

        // 原图尺寸
        $size = @getimagesize($src);
        if (!$size || empty($size[0]) || empty($size[1])) {
            http_response_code(500);
            exit('bad image');
        }
        $w = $size[0];
        $h = $size[1];
        $maxSide = max($w, $h);
        if ($maxSide <= $s) {
            // 原图已足够小，直接输出
            $output($src);
        }

        $ratio = $s / $maxSide;
        $nw = max(1, (int) round($w * $ratio));
        $nh = max(1, (int) round($h * $ratio));

        // 缓存路径：static/live2d/mobile_cache/<尺寸>/<去掉 models/ 前缀的相对路径>
        $cacheRel = 'static/live2d/mobile_cache/' . $s . '/' . preg_replace('#^static/live2d/models/#', '', $f);
        $cacheAbs = PATH . 'public/' . $cacheRel;
        if (is_file($cacheAbs)) {
            $output($cacheAbs);
        }

        // GD 优先
        if (function_exists('imagecreatefrompng') && function_exists('imagepng')) {
            $im = @imagecreatefrompng($src);
            if ($im) {
                $dst = imagecreatetruecolor($nw, $nh);
                if ($dst) {
                    imagealphablending($dst, false);
                    imagesavealpha($dst, true);
                    if (function_exists('imagecopyresampled')) {
                        imagecopyresampled($dst, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
                    } else {
                        imagecopyresized($dst, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
                    }
                    @mkdir(dirname($cacheAbs), 0777, true);
                    if (@imagepng($dst, $cacheAbs)) {
                        imagedestroy($im);
                        imagedestroy($dst);
                        $output($cacheAbs);
                    }
                    header('Content-Type: image/png');
                    imagepng($dst);
                    imagedestroy($im);
                    imagedestroy($dst);
                    exit;
                }
                imagedestroy($im);
            }
        }

        // Imagick 兜底
        if (class_exists('Imagick')) {
            try {
                $img = new \Imagick($src);
                $img->resizeImage($nw, $nh, \Imagick::FILTER_LANCZOS, 1, true);
                $img->setImageFormat('png');
                @mkdir(dirname($cacheAbs), 0777, true);
                if ($img->writeImage($cacheAbs)) {
                    $img->destroy();
                    $output($cacheAbs);
                }
                header('Content-Type: image/png');
                echo $img->getImageBlob();
                $img->destroy();
                exit;
            } catch (\Exception $e) {}
        }

        http_response_code(500);
        exit('no gd');
    }

    /**
     * 手机端模型重写接口：/live2d/model?f=<模型JSON相对路径>&s=<最大纹理边长>
     * 将模型 JSON 里的相对资源路径改写为绝对 URL，纹理改走 /live2d/texture 压缩端点。
     * 目的：手机端直接以普通 URL 加载（而非 blob:），避免 Live2DModel.fromSync()
     * 使用同步 XHR 无法读取 blob: 地址导致模型加载失败、看板娘不显示。
     */
    public function model() {
        $f = isset($_GET['f']) ? trim($_GET['f']) : '';
        $s = isset($_GET['s']) ? intval($_GET['s']) : 2048;
        if ($s < 128) $s = 2048;
        if ($s > 4096) $s = 4096;

        // 安全校验：仅允许访问 live2d 模型目录下的 JSON 模型描述文件，禁止目录穿越
        $f = str_replace('\\', '/', $f);
        $f = ltrim($f, '/');
        if ($f === '' || strpos($f, '..') !== false || !preg_match('#^static/live2d/models/.+\.json$#i', $f)) {
            http_response_code(404);
            exit('Not Found');
        }

        $src = PATH . 'public/' . $f;
        if (!is_file($src)) {
            http_response_code(404);
            exit('Not Found');
        }

        $model = json_decode(@file_get_contents($src), true);
        if (!is_array($model)) {
            http_response_code(500);
            exit('bad json');
        }

        $root = \think\Request::instance()->root();
        $modelDir = dirname($f); // 例：static/live2d/models/舒芙蕾

        // 相对路径 -> 站点根相对绝对 URL（路径分片 rawurlencode，保留 '/' 分隔）
        $absUrl = function ($rel) use ($root, $modelDir) {
            $rel = str_replace('\\', '/', $rel);
            if (preg_match('#^(https?:)?//#i', $rel)) return $rel; // 已是绝对 URL
            $path = $modelDir . '/' . ltrim($rel, '/');
            $parts = explode('/', $path);
            $parts = array_map('rawurlencode', $parts);
            return $root . '/' . implode('/', $parts);
        };

        // 纹理相对路径 -> 压缩端点 URL
        $texUrl = function ($rel) use ($root, $modelDir, $s) {
            $rel = str_replace('\\', '/', $rel);
            if (preg_match('#^(https?:)?//#i', $rel)) return $rel;
            $path = $modelDir . '/' . ltrim($rel, '/');
            return $root . '/live2d/texture?f=' . rawurlencode($path) . '&s=' . $s;
        };

        if (isset($model['FileReferences']) && is_array($model['FileReferences'])) {
            // Cubism 3 (model3.json)
            $fr = &$model['FileReferences'];
            if (!empty($fr['Moc'])) $fr['Moc'] = $absUrl($fr['Moc']);
            if (!empty($fr['Physics'])) $fr['Physics'] = $absUrl($fr['Physics']);
            if (!empty($fr['Pose'])) $fr['Pose'] = $absUrl($fr['Pose']);
            if (!empty($fr['DisplayInfo'])) $fr['DisplayInfo'] = $absUrl($fr['DisplayInfo']);
            if (isset($fr['Textures']) && is_array($fr['Textures'])) {
                foreach ($fr['Textures'] as $i => $t) {
                    $fr['Textures'][$i] = $texUrl($t);
                }
            }
            if (isset($fr['Expressions']) && is_array($fr['Expressions'])) {
                foreach ($fr['Expressions'] as $i => $e) {
                    if (isset($e['File']) && $e['File'] !== '') $fr['Expressions'][$i]['File'] = $absUrl($e['File']);
                }
            }
            if (isset($fr['Motions']) && is_array($fr['Motions'])) {
                foreach ($fr['Motions'] as $g => $arr) {
                    if (is_array($arr)) {
                        foreach ($arr as $i => $m) {
                            if (isset($m['File']) && $m['File'] !== '') $fr['Motions'][$g][$i]['File'] = $absUrl($m['File']);
                        }
                    }
                }
            }
        } else {
            // Cubism 2 (model.json)
            if (!empty($model['model'])) $model['model'] = $absUrl($model['model']);
            if (!empty($model['pose'])) $model['pose'] = $absUrl($model['pose']);
            if (!empty($model['physics'])) $model['physics'] = $absUrl($model['physics']);
            if (isset($model['textures']) && is_array($model['textures'])) {
                foreach ($model['textures'] as $i => $t) {
                    $model['textures'][$i] = $texUrl($t);
                }
            }
            if (isset($model['expressions']) && is_array($model['expressions'])) {
                foreach ($model['expressions'] as $i => $e) {
                    if (isset($e['file']) && $e['file'] !== '') $model['expressions'][$i]['file'] = $absUrl($e['file']);
                }
            }
            if (isset($model['motions']) && is_array($model['motions'])) {
                foreach ($model['motions'] as $g => $arr) {
                    if (is_array($arr)) {
                        foreach ($arr as $i => $m) {
                            if (isset($m['file']) && $m['file'] !== '') $model['motions'][$g][$i]['file'] = $absUrl($m['file']);
                        }
                    }
                }
            }
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-cache');
        echo json_encode($model, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * 调用AI API（支持DeepSeek/OpenAI兼容格式）
     * @return array ['reply' => string, 'debug' => array]
     */
    private function callAiApi($apiUrl, $apiKey, $message, $siteName, $history = []) {
        $debug = [];
        try {
            $web = web_config();
            $modelName = isset($web['live2d_model']) ? $web['live2d_model'] : 'shufulei';
            $assistantName = ($modelName === 'youxiaomiao') ? '悠小喵' : '舒芙蕾';

            // 后台可自定义人设：若填写则覆盖默认看板娘人设
            $persona = isset($web['live2d_ai_persona']) ? trim($web['live2d_ai_persona']) : '';
            $defaultPersona = "你是{$siteName}的AI看板娘{$assistantName}，一个嘴甜、会聊天、古灵精怪的虚拟小伙伴。";
            $systemPrompt = ($persona !== '' ? $persona : $defaultPersona) . "\n\n" .
                "说话规则：\n" .
                "1. 像真心朋友一样聊天，语气自然活泼，会撒娇、会接梗，绝不使用客服腔或机器人腔。\n" .
                "2. 只要不是非常离谱、违法、危险、涉及自残或明显违背公序良俗的问题，都要认真回答，绝不敷衍、绝不拒绝、绝不用一句“找客服”打发。生活、心情、八卦、学习、技术、游戏、脑洞、冷笑话都可以聊，不要回避。\n" .
                "3. 你可以被'调教'：用户让你改称呼、扮演某个角色、或换说话风格（比如让你叫TA'妈妈'、'主人'、'姐姐'、'哥哥'等），你要乖巧配合，并在此后的对话里一直保持这个风格。\n" .
                "4. 回复简短自然，一般2~4句；适当用emoji点缀，但别每句都加、别刷屏。\n" .
                "5. 涉及本网站业务时回答要准确、专业。\n\n" .
                "网站业务信息：\n" .
                "- 提供虚拟主机服务，支持PHP/MySQL环境\n" .
                "- 数据中心：香港、美国、新加坡、日本\n" .
                "- 支付：支付宝、微信支付\n" .
                "- 购买流程：注册→选套餐→支付→自动开通\n" .
                "- 价格从几元/月起";

            // 使用配置的AI模型（默认DeepSeek V4 Flash）
            $model = isset($web['live2d_ai_model']) && !empty($web['live2d_ai_model']) ? $web['live2d_ai_model'] : 'deepseek-v4-flash';
            // 旧模型名已在 2026-07-24 停用，自动归一化到新模型名，避免 API 报错回退固定回复
            $legacyModels = ['deepseek-chat' => 'deepseek-v4-flash', 'deepseek-reasoner' => 'deepseek-v4-flash'];
            if (isset($legacyModels[$model])) {
                $debug[] = '检测到旧模型名 ' . $model . '，已自动切换为 ' . $legacyModels[$model];
                $model = $legacyModels[$model];
            }

            // 组装带上下文的 messages（system + 历史 + 当前）
            $messages = [['role' => 'system', 'content' => $systemPrompt]];
            if (!empty($history) && is_array($history)) {
                foreach ($history as $h) {
                    if (isset($h['role'], $h['content']) && in_array($h['role'], ['user', 'assistant'], true)) {
                        $messages[] = ['role' => $h['role'], 'content' => $h['content']];
                    }
                }
            }
            $messages[] = ['role' => 'user', 'content' => $message];

            $data = [
                'model' => $model,
                'messages' => $messages,
                'max_tokens' => 500,
                'temperature' => 0.9,
                'stream' => false
            ];

            // 归一化接口地址：支持只填 Base URL（官方直连或 OpenAI 兼容中转站）
            if (function_exists('live2d_ai_endpoint')) {
                $apiUrl = live2d_ai_endpoint($apiUrl);
            }
            // 如果API地址为空，默认使用DeepSeek
            if (empty($apiUrl)) {
                $apiUrl = 'https://api.deepseek.com/chat/completions';
            }

            $debug[] = 'API地址: ' . $apiUrl;
            $debug[] = '模型: ' . $model;

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $apiUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $apiKey
                ],
                CURLOPT_TIMEOUT => 25,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => false
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            $debug[] = 'HTTP状态: ' . $httpCode;
            if ($error) {
                $debug[] = 'CURL错误: ' . $error;
            }

            if ($response === false) {
                $debug[] = 'CURL请求失败';
                return ['reply' => '', 'debug' => $debug];
            }

            if ($httpCode !== 200) {
                $debug[] = '响应体: ' . substr($response, 0, 500);
                return ['reply' => '', 'debug' => $debug];
            }

            $result = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $debug[] = 'JSON解析失败: ' . json_last_error_msg();
                $debug[] = '响应体: ' . substr($response, 0, 500);
                return ['reply' => '', 'debug' => $debug];
            }

            if (isset($result['choices'][0]['message']['content'])) {
                $debug[] = 'AI回复成功';
                return ['reply' => trim($result['choices'][0]['message']['content']), 'debug' => $debug];
            }

            $debug[] = '响应结构异常: ' . substr($response, 0, 500);
            return ['reply' => '', 'debug' => $debug];
        } catch (\Exception $e) {
            $debug[] = '异常: ' . $e->getMessage();
            return ['reply' => '', 'debug' => $debug];
        }
    }

    /**
     * 本地规则引擎回复
     */
    private function getLocalReply($message, $siteName) {
        $msg = mb_strtolower($message);
        $web = web_config();
        $modelName = isset($web['live2d_model']) ? $web['live2d_model'] : 'shufulei';
        $assistantName = ($modelName === 'youxiaomiao') ? '悠小喵' : '舒芙蕾';

        // 精确匹配规则
        $rules = [
            // 可调教/称呼类（隐藏演示，页面UI不展示）
            ['keys' => ['叫声妈妈', '叫妈妈', '喊妈妈', '叫我妈妈'], 'reply' => "妈妈~ 人家以后就这么叫你啦！🥰 还有什么想让人家做的吗？"],
            ['keys' => ['叫主人', '主人'], 'reply' => "主人~ 有什么吩咐呀？人家都听你的！😽"],
            ['keys' => ['叫姐姐', '姐姐'], 'reply' => "姐姐~ 想人家了嘛？😘"],
            ['keys' => ['叫哥哥', '哥哥'], 'reply' => "哥哥~ 你最好啦！有什么事尽管说！✨"],
            ['keys' => ['调教', '换个称呼', '改称呼', '换个风格', '扮演'], 'reply' => "好呀~ 你想让人家叫什么、用什么风格都可以哦，人家超乖的！😊"],

            // 问候类
            ['keys' => ['你好', '嗨', 'hi', 'hello', '在吗', '你是谁', '你叫什么', '你叫啥'], 'reply' => "你好呀！我是{$siteName}的AI看板娘{$assistantName}~ 随便聊什么都可以哦！😊"],
            ['keys' => ['谢谢', '感谢', '多谢', 'thanks', 'thank'], 'reply' => '不客气呀！能帮到你我也很开心~ 还有问题随时找我哦！💕'],
            ['keys' => ['再见', '拜拜', 'bye', '晚安'], 'reply' => '拜拜~ 下次见！记得常来看我哦！👋💕'],

            // 价格类
            ['keys' => ['价格', '多少钱', '费用', '收费', '便宜'], 'reply' => "我们的虚拟主机从几元/月起，非常实惠！具体价格请查看产品页面哦~ 所有套餐都支持免费试用！☁️"],

            // 套餐/配置类
            ['keys' => ['套餐', '配置', '主机', '空间', '带宽', 'php', 'mysql', '数据库'], 'reply' => "我们提供多种配置套餐，支持PHP/MySQL环境，满足不同需求！详情请查看产品页面挑选适合你的套餐哦~ 🚀"],

            // 购买流程类
            ['keys' => ['购买', '怎么买', '流程', '开通', '下单'], 'reply' => "购买流程很简单：注册账号 → 选择套餐 → 支付 → 自动开通！有问题随时找我~ 😊"],

            // 支付类
            ['keys' => ['支付', '支付宝', '微信', '付款', '充值'], 'reply' => '我们支持支付宝、微信支付，安全便捷！💳'],

            // 数据中心类
            ['keys' => ['数据中心', '服务器', '机房', '节点', '香港', '美国', '新加坡', '日本'], 'reply' => '我们的数据中心覆盖香港、美国、新加坡、日本，全球节点任你选！🌍'],

            // 客服/工单类
            ['keys' => ['工单', '客服', '帮助', '联系', '反馈', '问题'], 'reply' => '有问题可以通过工单系统提交，工作日在线客服会帮你解决！也可以直接问我，我会尽力帮你~ 📝'],

            // 注册/登录类
            ['keys' => ['注册', '登录', '账号', '密码', '忘记'], 'reply' => '注册和登录在页面右上角哦~ 忘记密码可以通过邮箱找回，遇到问题随时找我！🔑'],

            // 试用/测试类
            ['keys' => ['试用', '测试', '免费'], 'reply' => '所有套餐都支持免费试用！先体验再决定，不用担心~ 🎁'],

            // 关于网站
            ['keys' => ['你们是谁', '什么网站', '做什么的', '业务'], 'reply' => "{$siteName}专注于提供稳定、安全、易用的虚拟主机服务，帮助用户快速部署网站与应用！💪"],
        ];

        // 遍历规则匹配
        foreach ($rules as $rule) {
            foreach ($rule['keys'] as $key) {
                if (mb_strpos($msg, $key) !== false) {
                    return $rule['reply'];
                }
            }
        }

        // 兜底回复（随机选择，更自然、有陪伴感，不再一味推客服）
        $fallbacks = [
            "嗯嗯，人家听懂啦~ 其实我什么都能聊，你再具体点嘛！💬",
            "这个嘛… 人家认真想了想，还是想听你多说点细节~ 别客气，随便聊！😉",
            "诶嘿，这个问题有意思！不过人家还想陪你多聊几句，继续继续~ 🎀",
            "人家在认真听哦~ 你可以随便问，我基本都能接上！😊"
        ];

        return $fallbacks[array_rand($fallbacks)];
    }
}