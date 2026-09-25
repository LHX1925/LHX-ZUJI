<?php
namespace app\index\controller;
use think\Controller;

class Captcha extends Controller
{
    // Generate sliding captcha puzzle
    public function generate()
    {
        // IP 频率限制：每分钟最多生成 15 次
        if (!$this->checkIpRateLimit('captcha_gen', 15, 60)) {
            return json(['code' => -1, 'msg' => '操作过于频繁，请稍后再试']);
        }

        $bgWidth = 300;
        $bgHeight = 150;
        $puzzleWidth = 50;
        $puzzleHeight = 50;
        $cornerRadius = 8;

        // Random position for the puzzle (leave margins)
        $posX = rand(30, $bgWidth - $puzzleWidth - 30);
        $posY = rand(20, $bgHeight - $puzzleHeight - 20);

        // 生成唯一 token，防止伪造验证请求
        $captchaToken = md5(uniqid('captcha_', true) . $posX . $posY . request()->ip());

        // Store in session（token、坐标、尝试次数、过期时间）
        session('slide_captcha_token', $captchaToken);
        session('slide_captcha_x', $posX);
        session('slide_captcha_y', $posY);
        session('slide_captcha_attempts', 0);
        session('slide_captcha_expire', time() + 120); // 120秒过期

        // Fallback gradient backgrounds
        $gradients = [
            'linear-gradient(160deg, #f0f4ff 0%, #e8eefc 100%)',
            'linear-gradient(160deg, #f5f0ff 0%, #ede8f8 100%)',
            'linear-gradient(160deg, #f0f9ff 0%, #e8f4fc 100%)',
            'linear-gradient(160deg, #fff5f0 0%, #f8e8e4 100%)',
            'linear-gradient(160deg, #f0fff4 0%, #e8f8ec 100%)',
        ];
        $bgGradient = $gradients[array_rand($gradients)];

        $logDir = defined('LOG_PATH') ? LOG_PATH : (PATH . 'runtime/log/');
        if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
        $logFile = $logDir . 'captcha.log';

        // Try to load a random image from LKJ folder
        $bgImage = null;
        $puzzleBase64 = '';
        $bgBase64 = '';
        $useImage = false;
        $failReason = '';

        try {
            $lkjDir = rtrim(PATH, '/\\') . '/public/static/captcha/LKJ/';
            @file_put_contents($logFile, date('Y-m-d H:i:s') . " captcha generate start, dir={$lkjDir}\n", FILE_APPEND);

            if (!is_dir($lkjDir)) {
                $failReason = 'LKJ目录不存在';
            } else {
                $files = $this->scanImageFiles($lkjDir);
                @file_put_contents($logFile, date('Y-m-d H:i:s') . ' found images: ' . count($files) . ' ' . json_encode($files) . "\n", FILE_APPEND);

                if (empty($files)) {
                    $failReason = '目录中没有可识别的图片';
                } else {
                    $randomFile = $files[array_rand($files)];
                    @file_put_contents($logFile, date('Y-m-d H:i:s') . ' selected image: ' . $randomFile . "\n", FILE_APPEND);
                    $sourceImage = $this->loadImage($randomFile);
                    if (!$sourceImage) {
                        $failReason = '图片加载失败（格式不支持或文件损坏）';
                    } else {
                        $srcW = imagesx($sourceImage);
                        $srcH = imagesy($sourceImage);

                        // Create background canvas with white fill (handle transparent PNGs)
                        $bgImage = imagecreatetruecolor($bgWidth, $bgHeight);
                        $white = imagecolorallocate($bgImage, 255, 255, 255);
                        imagefill($bgImage, 0, 0, $white);
                        imagealphablending($bgImage, true);

                        // Resize and copy source image to fill captcha canvas
                        imagecopyresampled($bgImage, $sourceImage, 0, 0, 0, 0, $bgWidth, $bgHeight, $srcW, $srcH);
                        imagedestroy($sourceImage);

                        // Create puzzle piece from background region
                        $puzzle = imagecreatetruecolor($puzzleWidth, $puzzleHeight);
                        imagealphablending($puzzle, false);
                        imagesavealpha($puzzle, true);
                        $transparent = imagecolorallocatealpha($puzzle, 0, 0, 0, 127);
                        imagefill($puzzle, 0, 0, $transparent);
                        imagealphablending($puzzle, true);

                        // Copy the region from background
                        imagecopy($puzzle, $bgImage, 0, 0, $posX, $posY, $puzzleWidth, $puzzleHeight);

                        // Apply rounded mask
                        $this->applyRoundedMask($puzzle, $puzzleWidth, $puzzleHeight, $cornerRadius);

                        // Glass-like semi-transparent fill overlay
                        $glassColor = imagecolorallocatealpha($puzzle, 255, 255, 255, 55);
                        $this->roundedRect($puzzle, 0, 0, $puzzleWidth, $puzzleHeight, $cornerRadius, $glassColor, true);

                        // White outer border
                        $whiteBorder = imagecolorallocatealpha($puzzle, 255, 255, 255, 20);
                        imagesetthickness($puzzle, 2);
                        $this->roundedRect($puzzle, 1, 1, $puzzleWidth - 1, $puzzleHeight - 1, max(1, $cornerRadius - 1), $whiteBorder, false);

                        // Blue accent border
                        $accentColor = imagecolorallocatealpha($puzzle, 37, 99, 235, 50);
                        imagesetthickness($puzzle, 1);
                        $this->roundedRect($puzzle, 2, 2, $puzzleWidth - 2, $puzzleHeight - 2, max(1, $cornerRadius - 2), $accentColor, false);

                        // Arrow icon
                        $arrowColor = imagecolorallocatealpha($puzzle, 37, 99, 235, 10);
                        imagesetthickness($puzzle, 3);
                        $cx = $puzzleWidth / 2;
                        $cy = $puzzleHeight / 2;
                        imageline($puzzle, $cx - 7, $cy, $cx + 5, $cy, $arrowColor);
                        imageline($puzzle, $cx + 1, $cy - 5, $cx + 6, $cy, $arrowColor);
                        imageline($puzzle, $cx + 1, $cy + 5, $cx + 6, $cy, $arrowColor);

                        // Draw hole shadow on background
                        $this->drawHoleEffect($bgImage, $posX, $posY, $puzzleWidth, $puzzleHeight, $cornerRadius);

                        // Output puzzle
                        $puzzleBase64 = $this->imageToBase64Png($puzzle);
                        imagedestroy($puzzle);

                        // Output background
                        $bgBase64 = $this->imageToBase64Png($bgImage);
                        imagedestroy($bgImage);

                        if ($puzzleBase64 && $bgBase64) {
                            $useImage = true;
                        } else {
                            $failReason = 'base64编码失败';
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            $failReason = '异常：' . $e->getMessage();
            if ($bgImage) {
                @imagedestroy($bgImage);
                $bgImage = null;
            }
        }

        if (!$useImage) {
            @file_put_contents($logFile, date('Y-m-d H:i:s') . ' use gradient fallback, reason=' . $failReason . "\n", FILE_APPEND);
            // Transparent glass puzzle piece (gradient fallback)
            $puzzle = imagecreatetruecolor($puzzleWidth, $puzzleHeight);
            imagealphablending($puzzle, false);
            imagesavealpha($puzzle, true);
            $transparent = imagecolorallocatealpha($puzzle, 0, 0, 0, 127);
            imagefill($puzzle, 0, 0, $transparent);
            imagealphablending($puzzle, true);

            // Glass-like semi-transparent fill
            $glassColor = imagecolorallocatealpha($puzzle, 255, 255, 255, 45);
            $this->roundedRect($puzzle, 0, 0, $puzzleWidth, $puzzleHeight, $cornerRadius, $glassColor, true);

            // White outer border
            $whiteBorder = imagecolorallocatealpha($puzzle, 255, 255, 255, 20);
            imagesetthickness($puzzle, 2);
            $this->roundedRect($puzzle, 1, 1, $puzzleWidth - 1, $puzzleHeight - 1, max(1, $cornerRadius - 1), $whiteBorder, false);

            // Blue accent border
            $accentColor = imagecolorallocatealpha($puzzle, 37, 99, 235, 50);
            imagesetthickness($puzzle, 1);
            $this->roundedRect($puzzle, 2, 2, $puzzleWidth - 2, $puzzleHeight - 2, max(1, $cornerRadius - 2), $accentColor, false);

            // Arrow icon
            $arrowColor = imagecolorallocatealpha($puzzle, 37, 99, 235, 10);
            imagesetthickness($puzzle, 3);
            $cx = $puzzleWidth / 2;
            $cy = $puzzleHeight / 2;
            imageline($puzzle, $cx - 7, $cy, $cx + 5, $cy, $arrowColor);
            imageline($puzzle, $cx + 1, $cy - 5, $cx + 6, $cy, $arrowColor);
            imageline($puzzle, $cx + 1, $cy + 5, $cx + 6, $cy, $arrowColor);

            $puzzleBase64 = $this->imageToBase64Png($puzzle);
            imagedestroy($puzzle);

            if (!$puzzleBase64) {
                return json(['code' => -1, 'msg' => '验证码生成失败，请刷新重试']);
            }
        }

        @file_put_contents($logFile, date('Y-m-d H:i:s') . ' captcha generate success useImage=' . ($useImage ? '1' : '0') . "\n", FILE_APPEND);

        // 安全：不返回缺口X坐标，仅服务端session保存，防止自动化绕过
        // y坐标仅用于前端拼图垂直定位，不影响安全性
        return json([
            'code' => 1,
            'token' => $captchaToken,
            'bg' => $useImage ? $bgBase64 : null,
            'bg_gradient' => $useImage ? null : $bgGradient,
            'puzzle' => $puzzleBase64,
            'puzzle_width' => $puzzleWidth,
            'puzzle_height' => $puzzleHeight,
            'bg_width' => $bgWidth,
            'y' => $posY,
        ]);
    }

    /**
     * Scan directory and return all valid image file paths
     * Supports: jpg/jpeg/png/gif/webp/bmp/avif/tiff/tif/svg (with fallback)
     */
    private function scanImageFiles($dir)
    {
        $files = [];
        if (!is_dir($dir)) return $files;
        $items = @scandir($dir);
        if (!$items) return $files;

        $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'avif', 'tiff', 'tif', 'svg'];
        $gdTypes = [IMAGETYPE_GIF, IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_BMP, IMAGETYPE_WEBP];
        if (defined('IMAGETYPE_AVIF')) {
            $gdTypes[] = IMAGETYPE_AVIF;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            // Skip hidden/system files and obvious non-images
            if (strpos($item, '.') === 0) continue;
            $file = $dir . $item;
            if (!is_file($file)) continue;

            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExts)) continue;

            // Validate image by getimagesize (supports jpg/png/gif/webp/bmp/avif etc.)
            $info = @getimagesize($file);
            if ($info && in_array($info[2], $gdTypes)) {
                $files[] = $file;
                continue;
            }

            // Fallback: if extension looks like an image but getimagesize failed,
            // still include it so loadImage can try Imagick or other handlers.
            $files[] = $file;
        }
        return $files;
    }

    /**
     * Convert GD image to base64 PNG data URL (with compression)
     */
    private function imageToBase64Png($image)
    {
        if (!$image) return '';
        $level = ob_get_level();
        ob_start();
        // Compression level 6: good balance between size and speed
        $ok = @imagepng($image, null, 6);
        if (!$ok) {
            while (ob_get_level() > $level) {
                @ob_end_clean();
            }
            return '';
        }
        $data = ob_get_clean();
        if (empty($data)) return '';
        $base64 = base64_encode($data);
        if (empty($base64)) return '';
        return 'data:image/png;base64,' . $base64;
    }

    /**
     * Load image from file path, support multiple formats.
     * Tries GD first, then format-specific loaders, then Imagick if available.
     */
    private function loadImage($file)
    {
        if (!file_exists($file) || !is_readable($file)) {
            return false;
        }

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        // For vector/heavy formats that GD can't handle, try Imagick first.
        if (in_array($ext, ['svg', 'tiff', 'tif'])) {
            $imagickImage = $this->loadImageWithImagick($file);
            if ($imagickImage) {
                return $imagickImage;
            }
        }

        $data = @file_get_contents($file);
        if ($data === false || $data === '') {
            return false;
        }

        // Try universal loader (JPEG/PNG/GIF/WebP/BMP/AVIF on supported PHP)
        $image = @imagecreatefromstring($data);
        if ($image) {
            return $image;
        }

        // Fallback: use format-specific GD loaders
        $loaders = [
            'jpg' => 'imagecreatefromjpeg',
            'jpeg' => 'imagecreatefromjpeg',
            'png' => 'imagecreatefrompng',
            'gif' => 'imagecreatefromgif',
            'webp' => 'imagecreatefromwebp',
            'bmp' => 'imagecreatefrombmp',
        ];
        if (isset($loaders[$ext]) && function_exists($loaders[$ext])) {
            $image = @$loaders[$ext]($file);
            if ($image) {
                return $image;
            }
        }

        // Last resort: try Imagick for any other format
        if (class_exists('Imagick')) {
            $imagickImage = $this->loadImageWithImagick($file);
            if ($imagickImage) {
                return $imagickImage;
            }
        }

        return false;
    }

    /**
     * Load image using Imagick and convert to GD resource
     */
    private function loadImageWithImagick($file)
    {
        if (!class_exists('Imagick')) {
            return false;
        }
        try {
            $imagick = new \Imagick();
            // For SVG, set a proper density/size before reading
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if ($ext === 'svg') {
                $imagick->setResolution(300, 300);
            }
            $imagick->readImage($file);
            if (!$imagick->getImageWidth() || !$imagick->getImageHeight()) {
                $imagick->clear();
                return false;
            }
            // Convert to raster PNG in memory
            $imagick->setImageFormat('png');
            $blob = $imagick->getImageBlob();
            $imagick->clear();
            if (empty($blob)) {
                return false;
            }
            $image = @imagecreatefromstring($blob);
            if ($image) {
                return $image;
            }
        } catch (\Throwable $e) {
            // Imagick failed, fall through
        }
        return false;
    }

    /**
     * Apply rounded corner mask to puzzle piece
     */
    private function applyRoundedMask(&$img, $width, $height, $radius)
    {
        // Create a mask with rounded corners
        $mask = imagecreatetruecolor($width, $height);
        imagealphablending($mask, false);
        imagesavealpha($mask, true);
        $transparent = imagecolorallocatealpha($mask, 0, 0, 0, 127);
        imagefill($mask, 0, 0, $transparent);

        $white = imagecolorallocatealpha($mask, 255, 255, 255, 0);
        $this->roundedRect($mask, 0, 0, $width, $height, $radius, $white, true);

        // Apply mask to original image
        for ($x = 0; $x < $width; $x++) {
            for ($y = 0; $y < $height; $y++) {
                $maskColor = imagecolorsforindex($mask, imagecolorat($mask, $x, $y));
                if ($maskColor['alpha'] == 127) {
                    $alpha = 127;
                    $color = imagecolorallocatealpha($img, 0, 0, 0, $alpha);
                    imagesetpixel($img, $x, $y, $color);
                }
            }
        }
        imagedestroy($mask);
    }

    /**
     * Draw hole shadow effect on background
     */
    private function drawHoleEffect(&$bgImage, $posX, $posY, $width, $height, $radius)
    {
        // Create a temporary overlay for the hole
        $overlay = imagecreatetruecolor($width, $height);
        imagealphablending($overlay, false);
        imagesavealpha($overlay, true);
        $transparent = imagecolorallocatealpha($overlay, 0, 0, 0, 127);
        imagefill($overlay, 0, 0, $transparent);

        // Semi-transparent dark fill
        $darkColor = imagecolorallocatealpha($overlay, 0, 0, 0, 80);
        $this->roundedRect($overlay, 0, 0, $width, $height, $radius, $darkColor, true);

        // Inner shadow line
        $shadowColor = imagecolorallocatealpha($overlay, 0, 0, 0, 40);
        imagesetthickness($overlay, 2);
        $this->roundedRect($overlay, 2, 2, $width - 2, $height - 2, max(1, $radius - 2), $shadowColor, false);

        // Merge overlay onto background
        imagealphablending($bgImage, true);
        imagecopy($bgImage, $overlay, $posX, $posY, 0, 0, $width, $height);
        imagedestroy($overlay);
    }

    /**
     * Draw a rounded rectangle (filled or stroked)
     */
    private function roundedRect($img, $x1, $y1, $x2, $y2, $radius, $color, $fill = true)
    {
        $radius = min($radius, abs($x2 - $x1) / 2, abs($y2 - $y1) / 2);
        if ($fill) {
            imagefilledrectangle($img, $x1 + $radius, $y1, $x2 - $radius, $y2, $color);
            imagefilledrectangle($img, $x1, $y1 + $radius, $x2, $y2 - $radius, $color);
            imagefilledellipse($img, $x1 + $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
            imagefilledellipse($img, $x2 - $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
            imagefilledellipse($img, $x1 + $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
            imagefilledellipse($img, $x2 - $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
        } else {
            imageline($img, $x1 + $radius, $y1, $x2 - $radius, $y1, $color);
            imageline($img, $x1 + $radius, $y2, $x2 - $radius, $y2, $color);
            imageline($img, $x1, $y1 + $radius, $x1, $y2 - $radius, $color);
            imageline($img, $x2, $y1 + $radius, $x2, $y2 - $radius, $color);
            imagearc($img, $x1 + $radius, $y1 + $radius, $radius * 2, $radius * 2, 180, 270, $color);
            imagearc($img, $x2 - $radius, $y1 + $radius, $radius * 2, $radius * 2, 270, 360, $color);
            imagearc($img, $x1 + $radius, $y2 - $radius, $radius * 2, $radius * 2, 90, 180, $color);
            imagearc($img, $x2 - $radius, $y2 - $radius, $radius * 2, $radius * 2, 0, 90, $color);
        }
    }

    /**
     * Convert HSL to RGB
     */
    private function hslToRgb($h, $s, $l)
    {
        $h /= 360;
        $r = $g = $b = $l;
        if ($s != 0) {
            $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
            $p = 2 * $l - $q;
            $r = $this->hue2rgb($p, $q, $h + 1 / 3);
            $g = $this->hue2rgb($p, $q, $h);
            $b = $this->hue2rgb($p, $q, $h - 1 / 3);
        }
        return [round($r * 255), round($g * 255), round($b * 255)];
    }

    private function hue2rgb($p, $q, $t)
    {
        if ($t < 0) $t += 1;
        if ($t > 1) $t -= 1;
        if ($t < 1 / 6) return $p + ($q - $p) * 6 * $t;
        if ($t < 1 / 2) return $q;
        if ($t < 2 / 3) return $p + ($q - $p) * (2 / 3 - $t) * 6;
        return $p;
    }

    // Verify sliding captcha
    public function verify()
    {
        // IP 频率限制：每分钟最多验证 10 次
        if (!$this->checkIpRateLimit('captcha_verify', 10, 60)) {
            return json(['code' => -1, 'msg' => '操作过于频繁，请稍后再试']);
        }

        // Token 校验：防止伪造验证请求
        $reqToken = input('captcha_token', '');
        $storedToken = session('slide_captcha_token');
        if (empty($reqToken) || empty($storedToken) || $reqToken !== $storedToken) {
            return json(['code' => -1, 'msg' => '验证已过期，请刷新后重试']);
        }

        // 过期检查：120秒后失效
        $expire = session('slide_captcha_expire');
        if (!$expire || time() > $expire) {
            $this->clearCaptchaSession();
            return json(['code' => -1, 'msg' => '验证已过期，请刷新后重试']);
        }

        // 尝试次数限制：最多 3 次
        $attempts = intval(session('slide_captcha_attempts'));
        if ($attempts >= 3) {
            $this->clearCaptchaSession();
            return json(['code' => -1, 'msg' => '验证次数过多，请刷新后重试']);
        }
        session('slide_captcha_attempts', $attempts + 1);

        $slideX = input('slide_x', 0);
        $storedX = session('slide_captcha_x');

        if ($storedX === null) {
            return json(['code' => -1, 'msg' => '请先获取验证码']);
        }

        // Allow 5px tolerance
        if (abs(intval($slideX) - intval($storedX)) <= 5) {
            // 如果传入了 email 参数，绑定到验证码
            $email = input('email', '');
            if (!empty($email) && is_valid_email($email)) {
                session('slide_captcha_email', strtolower(trim($email)));
            }
            session('slide_captcha_verified', true);
            $this->clearCaptchaSession();
            return json(['code' => 1, 'msg' => '验证通过']);
        }

        // 失败后增加延迟，防止暴力破解（第1次无延迟，第2次500ms，第3次1000ms）
        $delay = ($attempts) * 500;
        if ($delay > 0) {
            usleep($delay * 1000); // ms 转微秒
        }

        return json(['code' => -1, 'msg' => '验证失败，请重试']);
    }

    /**
     * 清除验证码 session 数据（token、坐标、尝试次数、过期时间）
     */
    private function clearCaptchaSession()
    {
        session('slide_captcha_token', null);
        session('slide_captcha_x', null);
        session('slide_captcha_y', null);
        session('slide_captcha_attempts', null);
        session('slide_captcha_expire', null);
    }

    /**
     * IP 频率限制（基于文件计数器，无需数据库）
     * @param string $action 操作标识
     * @param int $maxTimes 最大允许次数
     * @param int $windowSec 时间窗口（秒）
     * @return bool true=允许，false=频率超限
     */
    private function checkIpRateLimit($action, $maxTimes, $windowSec)
    {
        $ip = request()->ip();
        $ipKey = md5($ip . $action);
        $cacheDir = defined('LOG_PATH') ? LOG_PATH : (PATH . 'runtime/log/');
        $rateDir = $cacheDir . 'rate_limit/';
        if (!is_dir($rateDir)) {
            @mkdir($rateDir, 0755, true);
        }
        $file = $rateDir . $ipKey . '.lim';

        $now = time();
        $records = [];
        if (file_exists($file)) {
            $content = @file_get_contents($file);
            if ($content) {
                $records = @json_decode($content, true) ?: [];
            }
        }

        // 清理过期记录
        $records = array_filter($records, function ($t) use ($now, $windowSec) {
            return ($now - $t) < $windowSec;
        });

        if (count($records) >= $maxTimes) {
            return false;
        }

        $records[] = $now;
        @file_put_contents($file, json_encode($records), LOCK_EX);
        return true;
    }

    // ============================================================
    // 极验行为验证 GT3（对应官方文档 server API：
    //   初始化  api.geetest.com/register.php  -> md5(challenge+key) 下发前端
    //   二次验证 api.geetest.com/validate.php -> seccode == md5(seccode) 则通过
    // ============================================================

    /** 极验调试日志（记录逐步校验结果，排查"验证失败"） */
    private static function gtLog($msg)
    {
        try {
            $dir = PATH . 'runtime/log/';
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            @file_put_contents($dir . 'geetest_debug.log', date('Y-m-d H:i:s') . ' ' . $msg . "\n", FILE_APPEND);
        } catch (\Throwable $e) {}
    }

    /**
     * 读取极验配置，返回 [是否启用, id, key, 版本('3'|'4')]
     * captcha_type: '0'=本站内置滑块, '1'=极验GT3, '2'=极验GT4
     */
    private static function geetestMode()
    {
        try {
            $web = function_exists('web_config') ? web_config() : [];
            $type = isset($web['captcha_type']) ? trim((string)$web['captcha_type']) : '';
            $id3  = isset($web['geetest_id']) ? trim($web['geetest_id']) : '';
            $key3 = isset($web['geetest_key']) ? trim($web['geetest_key']) : '';
            $id4  = isset($web['geetest4_id']) ? trim($web['geetest4_id']) : '';
            $key4 = isset($web['geetest4_key']) ? trim($web['geetest4_key']) : '';

            if ($type === '2') {
                return [($id4 !== '' && $key4 !== ''), $id4, $key4, '4'];
            }
            // 默认 GT3（兼容历史配置：填了 ID/Key 即启用）；captcha_type='0' 强制本站内置滑块
            return [($id3 !== '' && $key3 !== '' && $type !== '0'), $id3, $key3, '3'];
        } catch (\Throwable $e) {
            return [false, '', '', '3'];
        }
    }

    /**
     * 兼容旧调用：返回 [是否启用, id, key]
     */
    private static function geetestConfig()
    {
        list($enabled, $id, $key) = self::geetestMode();
        return [$enabled, $id, $key];
    }

    /**
     * 极验 GT3 验证初始化（客户端轮询本接口获取 gt/challenge）
     * 正常模式返回 success=1 + md5(challenge+key)；极验宕机时 success=0 进入宕机模式
     */
    public function gtregister()
    {
        list($enabled, $gtId, $gtKey) = self::geetestConfig();
        if (!$enabled) {
            return json(['code' => -1, 'msg' => '未启用极验验证']);
        }
        // bypass 状态探活（非阻塞，失败容忍）
        $ok = false;
        $challenge = '';
        try {
            $qs = http_build_query([
                'gt' => $gtId,
                'json_format' => 1,
                'digestmod' => 'md5',
                'new_captcha' => 1,
                'client_type' => 'web',
                'ip_address' => request()->ip(),
            ]);
            // 优先 https，失败再退回 http（部分服务器对外 http 出口被拦截）
            $resp = self::httpGet('https://api.geetest.com/register.php?' . $qs, 2, 3);
            if ($resp === '') {
                $resp = self::httpGet('http://api.geetest.com/register.php?' . $qs, 2, 3);
            }
            $obj = json_decode($resp, true);
            $challenge = is_array($obj) && !empty($obj['challenge']) ? $obj['challenge'] : '';
            $ok = (is_string($challenge) && strlen($challenge) == 32);
        } catch (\Throwable $e) {
            $ok = false;
        }

        if ($ok) {
            // 正常模式：challenge 需要用私钥 md5 加密后再下发给前端
            $finalChallenge = md5($challenge . $gtKey);
            $status = 1;
        } else {
            // 宕机模式（bypass）：本地生成 challenge，前端 offline=1
            $rnd1 = md5(mt_rand(0, 100));
            $rnd2 = md5(mt_rand(0, 100));
            $finalChallenge = $rnd1 . substr($rnd2, 0, 2);
            $status = 0;
        }
        // 按 challenge 分别存储（同页可能存在多个验证组件，不能互相覆盖）
        $pool = session('geetest_pool');
        if (!is_array($pool)) $pool = [];
        // 清理过期项
        $now = time();
        foreach ($pool as $c => $info) {
            if (!isset($info['expire']) || $info['expire'] < $now) unset($pool[$c]);
        }
        $pool[$finalChallenge] = ['status' => $status, 'expire' => $now + 300];
        session('geetest_pool', $pool);
        self::gtLog('REGISTER challenge=' . $finalChallenge . ' status=' . $status . ' gtId=' . substr($gtId, 0, 8) . '... keyLen=' . strlen($gtKey) . ' keyTail=' . substr($gtKey, -2) . ' poolSize=' . count($pool));

        return json([
            'success' => $ok ? 1 : 0,
            'gt' => $gtId,
            'challenge' => $finalChallenge,
            'new_captcha' => true,
        ]);
    }

    /**
     * 极验 GT3 二次验证（前端验证通过后回传三要素）
     * 通过后与内置滑块一样写入 slide_captcha_verified session，所有现有 Captcha::check()
     * 调用点无需改动即可全部生效。
     */
    public function gtvalidate()
    {
        list($enabled, $gtId, $gtKey) = self::geetestConfig();
        if (!$enabled) {
            return json(['code' => -1, 'msg' => '未启用极验验证']);
        }
        if (!self::rateLimitStatic('geetest_validate', 15, 60)) {
            return json(['code' => -1, 'msg' => '操作过于频繁，请稍后再试']);
        }

        $challenge = trim(input('geetest_challenge', ''));
        $validate  = trim(input('geetest_validate', ''));
        $seccode   = trim(input('geetest_seccode', ''));

        $pool = session('geetest_pool');
        if (!is_array($pool)) $pool = [];
        $info = isset($pool[$challenge]) ? $pool[$challenge] : null;
        // 极验 gt.js 提交时会在 challenge 后追加 2 位随机字符（register 池里存的是原始 32 位）。
        // 先用前缀匹配找到池记录取 status；验签必须用【提交上来的完整 challenge】，
        // 因为极验服务器的 validate 就是按带后缀的完整值计算的。
        $poolKey = $challenge;
        if (!$info && $challenge !== '') {
            foreach ($pool as $key => $row) {
                if ($key !== '' && strpos($challenge, $key) === 0) {
                    $info = $row;
                    $poolKey = $key;
                    self::gtLog('CHALLENGE_PREFIX_MATCH submitted=' . $challenge . ' matched=' . $key);
                    break;
                }
            }
        }

        if (!$info || empty($challenge)) {
            self::gtLog('VALIDATE_FAIL challenge_not_found challenge=' . $challenge . ' poolKeys=' . implode(',', array_keys($pool)));
            return json(['code' => -1, 'msg' => '验证已过期，请刷新重试']);
        }
        if (empty($info['expire']) || time() > intval($info['expire'])) {
            unset($pool[$poolKey]);
            session('geetest_pool', $pool);
            return json(['code' => -1, 'msg' => '验证已过期，请刷新重试']);
        }
        $status = intval($info['status']);

        $pass = false;
        try {
            // challenge 变体：极验 gt.js 提交时可能追加 2 位随机字符，
            // 这里把「提交值 / 池中值 / 去后缀值」都算一遍，任一命中即视为正确，避免误判
            $variants = array_values(array_unique(array_filter([
                $challenge,
                $poolKey,
                (strlen($challenge) > 32) ? substr($challenge, 0, 32) : '',
            ], function ($v) { return $v !== ''; })));

            if ($status == 1) {
                // 正常模式：本地验签 + 提交极验服务器二次验证（用提交的完整 challenge）
                $localOk = false;
                if (strlen($validate) == 32) {
                    foreach ($variants as $cv) {
                        if ($validate === md5($gtKey . 'geetest' . $cv)) { $localOk = true; break; }
                    }
                }
                self::gtLog('VALIDATE local: challenge=' . $challenge . ' poolKey=' . $poolKey
                    . ' validateGot=' . $validate
                    . ' expect(submitted)=' . md5($gtKey . 'geetest' . $challenge)
                    . ' expect(pool)=' . md5($gtKey . 'geetest' . $poolKey)
                    . ' localOk=' . ($localOk ? '1' : '0'));

                if ($localOk) {
                    $postData = [
                        'seccode' => $seccode,
                        'timestamp' => time(),
                        'challenge' => $challenge,
                        'captchaid' => $gtId,
                        'json_format' => 1,
                        'sdk' => 'php_3.0.0',
                        'client_type' => 'web',
                        'ip_address' => request()->ip(),
                    ];
                    $resp = self::httpPost('https://api.geetest.com/validate.php', $postData, 4);
                    if ($resp === '') {
                        $resp = self::httpPost('http://api.geetest.com/validate.php', $postData, 4);
                    }
                    self::gtLog('VALIDATE api resp=' . mb_substr((string)$resp, 0, 200)
                        . ' seccodeGot=' . $seccode . ' seccodeMd5=' . md5($seccode));
                    $obj = json_decode((string)$resp, true);
                    if (is_array($obj) && !empty($obj['seccode']) && $obj['seccode'] === md5($seccode)) {
                        $pass = true;
                    } elseif (empty($resp)) {
                        // 服务器无法访问极验接口（超时/被墙）时，本地验签已通过即放行，
                        // 避免用户明明滑过了却一直提示验证失败
                        self::gtLog('VALIDATE api unreachable, trust local sign');
                        $pass = true;
                    }
                }
            } else {
                // 宕机模式（bypass）：本地校验 md5(challenge) == validate
                $bypassOk = false;
                foreach ($variants as $cv) {
                    if ($validate === md5($cv)) { $bypassOk = true; break; }
                }
                self::gtLog('VALIDATE bypass: challenge=' . $challenge . ' poolKey=' . $poolKey
                    . ' got=' . $validate . ' ok=' . ($bypassOk ? '1' : '0'));
                $pass = $bypassOk;
            }
        } catch (\Throwable $e) {
            self::gtLog('VALIDATE exception=' . $e->getMessage());
            $pass = false;
        }
        self::gtLog('VALIDATE result=' . ($pass ? 'PASS' : 'FAIL') . ' challenge=' . $challenge);

        if (!$pass) {
            return json(['code' => -1, 'msg' => '验证未通过']);
        }

        // 支持邮箱绑定（与内置滑块一致的语义）
        $email = input('email', '');
        if (!empty($email) && function_exists('is_valid_email') && is_valid_email($email)) {
            session('slide_captcha_email', strtolower(trim($email)));
        }
        session('slide_captcha_verified', true);
        // 该 challenge 一次性使用，验证通过后从池中移除
        unset($pool[$poolKey], $pool[$challenge]);
        session('geetest_pool', $pool);
        return json(['code' => 1, 'msg' => '验证通过']);
    }

    // ============================================================
    // 极验行为验证 GT4（第四代）
    // 官方文档：https://docs.geetest.com/gt4/deploy/server
    // 二次验证：POST http://gcaptcha4.geetest.com/validate?captcha_id=xxx
    //   参数 lot_number / captcha_output / pass_token / gen_time / sign_token
    //   sign_token = HMAC-SHA256(key=captcha_key, message=lot_number)
    //   返回 {status:'success', result:'success'|'fail', reason:''}
    // ============================================================

    /**
     * GT4 二次验证（前端验证通过后回传四要素）
     * 通过后同样写入 slide_captcha_verified session，现有 Captcha::check() 调用点无需改动。
     */
    public function gt4validate()
    {
        list($enabled, $captchaId, $captchaKey, $ver) = self::geetestMode();
        if (!$enabled || $ver !== '4') {
            return json(['code' => -1, 'msg' => '未启用极验 GT4 验证']);
        }
        if (!self::rateLimitStatic('geetest4_validate', 20, 60)) {
            return json(['code' => -1, 'msg' => '操作过于频繁，请稍后再试']);
        }

        $lotNumber     = trim((string)input('lot_number', ''));
        $captchaOutput = trim((string)input('captcha_output', ''));
        $passToken     = trim((string)input('pass_token', ''));
        $genTime       = trim((string)input('gen_time', ''));

        if ($lotNumber === '' || $captchaOutput === '' || $passToken === '' || $genTime === '') {
            self::gtLog('GT4 VALIDATE_FAIL missing_params lot=' . $lotNumber . ' outLen=' . strlen($captchaOutput) . ' tokenLen=' . strlen($passToken) . ' gen=' . $genTime);
            return json(['code' => -1, 'msg' => '验证数据不完整，请重新验证']);
        }

        // 防重放：同一个 lot_number 只允许使用一次
        $used = session('geetest4_used');
        if (!is_array($used)) $used = [];
        $lotKey = md5($lotNumber);
        if (isset($used[$lotKey])) {
            self::gtLog('GT4 VALIDATE_FAIL replay lot=' . $lotNumber);
            return json(['code' => -1, 'msg' => '验证已失效，请重新验证']);
        }

        // 生成签名：HMAC-SHA256，密钥为 captcha_key，消息为 lot_number
        $signToken = hash_hmac('sha256', $lotNumber, $captchaKey);

        $postData = [
            'lot_number'     => $lotNumber,
            'captcha_output' => $captchaOutput,
            'pass_token'     => $passToken,
            'gen_time'       => $genTime,
            'sign_token'     => $signToken,
        ];

        $pass = false;
        $reason = '';
        try {
            $url = 'https://gcaptcha4.geetest.com/validate?captcha_id=' . urlencode($captchaId);
            $resp = self::httpPost($url, $postData, 6);
            if ($resp === '') {
                $resp = self::httpPost('http://gcaptcha4.geetest.com/validate?captcha_id=' . urlencode($captchaId), $postData, 6);
            }
            self::gtLog('GT4 VALIDATE api resp=' . mb_substr((string)$resp, 0, 300) . ' lot=' . $lotNumber . ' sign=' . substr($signToken, 0, 12));
            $obj = json_decode((string)$resp, true);
            if (is_array($obj)) {
                $reason = isset($obj['reason']) ? (string)$obj['reason'] : (isset($obj['msg']) ? (string)$obj['msg'] : '');
                if (isset($obj['result']) && $obj['result'] === 'success') {
                    $pass = true;
                }
            } elseif ($resp !== '') {
                // 返回了非 JSON 内容（如 WAF 拦截页），视为接口异常
                self::gtLog('GT4 VALIDATE non-json resp len=' . strlen((string)$resp));
            }
        } catch (\Throwable $e) {
            self::gtLog('GT4 VALIDATE exception=' . $e->getMessage());
            $pass = false;
        }
        self::gtLog('GT4 VALIDATE result=' . ($pass ? 'PASS' : 'FAIL') . ' lot=' . $lotNumber . ' reason=' . $reason);

        if (!$pass) {
            if (stripos($reason, 'unsupported node') !== false) {
                return json(['code' => -1, 'msg' => '验证服务暂时不可用（极验拒绝当前服务器地区），请联系管理员']);
            }
            $msg = '验证未通过，请重新验证';
            if ($reason !== '' && preg_match('/expire/i', $reason)) $msg = '验证已过期，请重新验证';
            return json(['code' => -1, 'msg' => $msg]);
        }

        // 记录已使用的 lot_number（只保留最近 30 条）
        $used[$lotKey] = time();
        if (count($used) > 30) $used = array_slice($used, -30, null, true);
        session('geetest4_used', $used);

        $email = input('email', '');
        if (!empty($email) && function_exists('is_valid_email') && is_valid_email($email)) {
            session('slide_captcha_email', strtolower(trim($email)));
        }
        session('slide_captcha_verified', true);
        return json(['code' => 1, 'msg' => '验证通过']);
    }

    /**
     * 简单 GET 请求（curl 优先，失败降级 file_get_contents）
     */
    private static function httpGet($url, $connectTimeout = 1, $timeout = 3)
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; GeeTestClient)');
            $data = curl_exec($ch);
            $err = curl_errno($ch);
            curl_close($ch);
            if ($err) return '';
            return $data === false ? '' : $data;
        }
        $ctx = stream_context_create(['http' => ['timeout' => $connectTimeout + $timeout]]);
        $data = @file_get_contents($url, false, $ctx);
        return $data === false ? '' : $data;
    }

    /**
     * 简单 POST 请求
     */
    private static function httpPost($url, $data, $timeout = 4)
    {
        $body = http_build_query($data);
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            // 部分极验接口对无 User-Agent 的裸请求会直接 403
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; GeeTestClient)');
            $resp = curl_exec($ch);
            $err = curl_errno($ch);
            curl_close($ch);
            if ($err) return '';
            return $resp === false ? '' : $resp;
        }
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => 'Content-type: application/x-www-form-urlencoded',
            'content' => $body,
            'timeout' => $timeout,
        ]]);
        $resp = @file_get_contents($url, false, $ctx);
        return $resp === false ? '' : $resp;
    }

    /**
     * 静态 IP 频率限制
     */
    private static function rateLimitStatic($action, $maxTimes, $windowSec)
    {
        $ip = request()->ip();
        $ipKey = md5($ip . $action);
        $cacheDir = defined('LOG_PATH') ? LOG_PATH : (defined('PATH') ? PATH . 'runtime/log/' : sys_get_temp_dir() . '/');
        $rateDir = rtrim($cacheDir, '/\\') . '/rate_limit/';
        if (!is_dir($rateDir)) @mkdir($rateDir, 0755, true);
        $file = $rateDir . $ipKey . '.lim';
        $now = time();
        $records = [];
        if (file_exists($file)) {
            $content = @file_get_contents($file);
            $records = $content ? (@json_decode($content, true) ?: []) : [];
        }
        $records = array_filter($records, function ($t) use ($now, $windowSec) { return ($now - $t) < $windowSec; });
        if (count($records) >= $maxTimes) return false;
        $records[] = $now;
        @file_put_contents($file, json_encode($records), LOCK_EX);
        return true;
    }

    // Check if captcha is verified (for server-side validation)
    // $email 可选，传入后同时验证绑定邮箱是否匹配
    public static function check($email = '')
    {
        $verified = session('slide_captcha_verified');
        if (!$verified) {
            return false;
        }
        // 如果传入了 email，验证是否与绑定邮箱一致
        if (!empty($email)) {
            $boundEmail = session('slide_captcha_email');
            if (empty($boundEmail) || strtolower(trim($email)) !== $boundEmail) {
                session('slide_captcha_verified', null);
                session('slide_captcha_email', null);
                return false;
            }
        }
        session('slide_captcha_verified', null);
        session('slide_captcha_email', null);
        return true;
    }
}
