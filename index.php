<?php
/**
 * 图片批量下载工具 - 支持循环调用API
 * 自动创建 images/ 目录
 */

$config = [
    'save_dir' => 'images',
    'hash_file' => 'hashes.txt',
    'allowed_ext' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'],
    'timeout' => 30,
];

// 创建目录
if (!is_dir($config['save_dir'])) {
    mkdir($config['save_dir'], 0777, true);
}

// 获取已存哈希
function getSavedHashes($file) {
    if (!file_exists($file)) return [];
    return array_filter(array_map('trim', file($file)));
}

// 保存哈希
function saveHash($file, $hash) {
    file_put_contents($file, $hash . "\n", FILE_APPEND);
}

// 下载图片
function downloadImage($url, $saveDir, $allowedExt, &$error = '') {
    global $config;
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => $config['timeout'],
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    
    $data = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    
    if ($httpCode != 200 || empty($data)) {
        $error = "HTTP $httpCode";
        return null;
    }
    
    // 检测扩展名
    $ext = pathinfo(parse_url($url, PHP_URL_PATH), PATH_EXTENSION);
    $ext = strtolower($ext);
    if (!in_array($ext, $allowedExt)) {
        // 尝试从Content-Type推断
        if (stripos($contentType, 'jpeg') !== false || stripos($contentType, 'jpg') !== false) $ext = 'jpg';
        elseif (stripos($contentType, 'png') !== false) $ext = 'png';
        elseif (stripos($contentType, 'gif') !== false) $ext = 'gif';
        elseif (stripos($contentType, 'webp') !== false) $ext = 'webp';
        else $ext = 'jpg';
    }
    
    // 计算MD5查重
    $hash = md5($data);
    
    // 检查重复
    $savedHashes = getSavedHashes($config['hash_file']);
    if (in_array($hash, $savedHashes)) {
        $error = '重复跳过';
        return null;
    }
    
    // 生成文件名
    $filename = $hash . '.' . $ext;
    $filepath = $saveDir . '/' . $filename;
    
    // 保存
    file_put_contents($filepath, $data);
    saveHash($config['hash_file'], $hash);
    
    return $filename;
}

// API批量获取图片列表
function fetchFromApi($apiUrl) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $apiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode != 200) {
        return ['success' => false, 'error' => "API HTTP $httpCode"];
    }
    
    $data = json_decode($response, true);
    if (!$data) {
        return ['success' => false, 'error' => 'JSON解析失败'];
    }
    
    // 支持多种API格式，自动提取图片URL
    $urls = [];
    
    // 格式1: { "images": ["url1", "url2"] }
    if (isset($data['images'])) {
        $urls = is_array($data['images']) ? $data['images'] : [];
    }
    // 格式2: { "data": [{ "url": "xxx" }] }
    elseif (isset($data['data']) && is_array($data['data'])) {
        foreach ($data['data'] as $item) {
            if (is_string($item)) $urls[] = $item;
            elseif (isset($item['url'])) $urls[] = $item['url'];
            elseif (isset($item['image'])) $urls[] = $item['image'];
            elseif (isset($item['src'])) $urls[] = $item['src'];
        }
    }
    // 格式3: 直接是数组
    elseif (is_array($data)) {
        foreach ($data as $item) {
            if (is_string($item)) $urls[] = $item;
            elseif (isset($item['url'])) $urls[] = $item['url'];
        }
    }
    
    return ['success' => true, 'urls' => $urls];
}

// 路由
$action = $_GET['action'] ?? $_POST['action'] ?? 'index';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>图片批量下载工具</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; padding: 20px; }
        .container { max-width: 800px; margin: 0 auto; background: #fff; border-radius: 8px; padding: 24px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        h1 { font-size: 20px; margin-bottom: 20px; color: #333; }
        .tabs { display: flex; gap: 12px; margin-bottom: 20px; border-bottom: 1px solid #eee; padding-bottom: 12px; }
        .tab { padding: 8px 16px; border-radius: 6px; cursor: pointer; color: #666; text-decoration: none; }
        .tab.active { background: #007AFF; color: #fff; }
        .form-group { margin-bottom: 16px; }
        label { display: block; margin-bottom: 6px; color: #666; font-size: 14px; }
        textarea { width: 100%; height: 120px; padding: 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; font-family: monospace; resize: vertical; }
        input[type="text"], input[type="number"] { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; }
        button { background: #007AFF; color: #fff; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-size: 14px; }
        button:hover { background: #0056b3; }
        .result { margin-top: 16px; padding: 12px; border-radius: 6px; font-size: 14px; }
        .result.success { background: #d4edda; color: #155724; }
        .result.error { background: #f8d7da; color: #721c24; }
        .result.info { background: #d1ecf1; color: #0c5460; }
        .gallery { display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 12px; margin-top: 20px; }
        .gallery-item { position: relative; aspect-ratio: 1; border-radius: 6px; overflow: hidden; background: #f0f0f0; }
        .gallery-item img { width: 100%; height: 100%; object-fit: cover; }
        .gallery-item .name { position: absolute; bottom: 0; left: 0; right: 0; background: rgba(0,0,0,0.6); color: #fff; padding: 4px 8px; font-size: 10px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .stats { display: flex; gap: 20px; margin-bottom: 20px; padding: 12px; background: #f8f9fa; border-radius: 6px; }
        .stat { text-align: center; }
        .stat-value { font-size: 24px; font-weight: bold; color: #007AFF; }
        .stat-label { font-size: 12px; color: #666; }
        .row { display: flex; gap: 12px; }
        .row .form-group { flex: 1; }
    </style>
</head>
<body>
<?php if ($action == 'download' && ($_POST['urls'] || $_POST['api'] || $_POST['loop_api'])): ?>
<?php
$results = [];
$successCount = 0;
$skipCount = 0;
$errorCount = 0;

// 循环调用API下载多张
if ($_POST['loop_api'] && $_POST['loop_count']) {
    $apiUrl = $_POST['loop_api'];
    $loopCount = intval($_POST['loop_count']);
    $delay = intval($_POST['delay'] ?? 0);
    
    echo '<div class="container">';
    echo '<h1>循环下载中...</h1>';
    echo '<div class="result info">API: ' . htmlspecialchars($apiUrl) . '</div>';
    echo '<div class="result info">数量: ' . $loopCount . ' | 间隔: ' . $delay . 'ms</div>';
    echo '<pre style="margin-top:12px;padding:12px;background:#f8f9fa;border-radius:6px;font-size:12px;max-height:300px;overflow:auto;">';
    
    for ($i = 1; $i <= $loopCount; $i++) {
        $error = '';
        $filename = downloadImage($apiUrl, $config['save_dir'], $config['allowed_ext'], $error);
        
        if ($filename) {
            $results[] = "[$i] ✓ $filename";
            $successCount++;
            echo "[$i] ✓ $filename\n";
        } else {
            if ($error === '重复跳过') {
                $results[] = "[$i] ⏭️ 重复跳过";
                $skipCount++;
                echo "[$i] ⏭️ 重复跳过\n";
            } else {
                $results[] = "[$i] ✗ $error";
                $errorCount++;
                echo "[$i] ✗ $error\n";
            }
        }
        
        // 刷新输出
        if (ob_get_level()) ob_flush();
        flush();
        
        // 延迟
        if ($delay > 0 && $i < $loopCount) {
            usleep($delay * 1000);
        }
    }
    
    echo '</pre>';
    echo '<div class="result success">完成! 成功: ' . $successCount . ' | 重复: ' . $skipCount . ' | 失败: ' . $errorCount . '</div>';
    echo '<a href="?action=index" class="tab" style="display:inline-block;margin-top:12px;">返回</a>';
    echo '</div>';
}
elseif ($_POST['api']) {
    // API批量获取
    $apiResult = fetchFromApi($_POST['api']);
    if ($apiResult['success']) {
        $urls = $apiResult['urls'];
        echo '<div class="result info">API返回 ' . count($urls) . ' 个图片地址</div>';
    } else {
        echo '<div class="result error">API错误: ' . $apiResult['error'] . '</div>';
        $urls = [];
    }
} else {
    $urls = array_filter(array_map('trim', explode("\n", $_POST['urls'])));
}

if (!empty($urls)) {
    foreach ($urls as $url) {
        if (empty($url)) continue;
        $error = '';
        $filename = downloadImage($url, $config['save_dir'], $config['allowed_ext'], $error);
        $results[] = $url . ' => ' . ($filename ?: $error);
        if ($filename) $successCount++;
        else $skipCount++;
    }
}
?>
<?php if (empty($_POST['loop_api'])): ?>
<div class="container">
    <h1>下载完成</h1>
    <div class="result success">成功: <?= $successCount ?> | 重复跳过: <?= $skipCount ?></div>
    <pre style="margin-top:12px;padding:12px;background:#f8f9fa;border-radius:6px;font-size:12px;max-height:300px;overflow:auto;"><?= implode("\n", $results) ?></pre>
    <a href="?action=index" class="tab" style="display:inline-block;margin-top:12px;">返回</a>
</div>
<?php endif; ?>
<?php else: ?>
<div class="container">
    <h1>🖼️ 图片批量下载工具</h1>
    
    <div class="stats">
        <div class="stat">
            <div class="stat-value"><?= count(getSavedHashes($config['hash_file'])) ?></div>
            <div class="stat-label">已存图片</div>
        </div>
        <div class="stat">
            <div class="stat-value"><?= is_dir($config['save_dir']) ? count(glob($config['save_dir'] . '/*')) : 0 ?></div>
            <div class="stat-label">本地文件</div>
        </div>
    </div>
    
    <div class="tabs">
        <a href="?action=index" class="tab <?= $action=='index'?'active':'' ?>">单图/批量</a>
        <a href="?action=gallery" class="tab <?= $action=='gallery'?'active':'' ?>">图片列表</a>
    </div>
    
    <?php if ($action == 'gallery'): ?>
    <div class="gallery">
    <?php
    $files = glob($config['save_dir'] . '/*');
    if ($files): foreach ($files as $file):
        if (is_dir($file)) continue;
    ?>
        <div class="gallery-item">
            <img src="<?= $config['save_dir'] . '/' . basename($file) ?>" loading="lazy">
            <div class="name"><?= basename($file) ?></div>
        </div>
    <?php endforeach; else: ?>
        <p style="color:#999;grid-column:1/-1;">暂无图片</p>
    <?php endif; ?>
    </div>
    <?php else: ?>
    
    <!-- 循环下载模式 -->
    <div style="margin-bottom:24px;padding:16px;background:#f8f9fa;border-radius:8px;border:2px solid #007AFF;">
        <h3 style="margin-bottom:12px;color:#007AFF;">🔄 循环调用API下载 (适合每次返回单张图片的API)</h3>
        <form method="post" action="?action=download">
            <input type="hidden" name="action" value="download">
            <div class="form-group">
                <label>API地址</label>
                <input type="text" name="loop_api" placeholder="https://example.com/api">
            </div>
            <div class="row">
                <div class="form-group">
                    <label>下载数量</label>
                    <input type="number" name="loop_count" value="10" min="1" max="500">
                </div>
                <div class="form-group">
                    <label>间隔 (毫秒)</label>
                    <input type="number" name="delay" value="500" min="0" max="5000">
                </div>
            </div>
            <button type="submit">开始循环下载</button>
        </form>
    </div>
    
    <hr style="margin:20px 0;border:none;border-top:1px solid #eee;">
    
    <!-- 传统模式 -->
    <form method="post" action="?action=download">
        <input type="hidden" name="action" value="download">
        <div class="form-group">
            <label>API地址 (返回图片URL列表的JSON API)</label>
            <input type="text" name="api" placeholder="https://example.com/api/images">
        </div>
        <div style="text-align:center;margin:8px 0;color:#999;">— 或 —</div>
        <div class="form-group">
            <label>图片URL (每行一个)</label>
            <textarea name="urls" placeholder="https://example.com/image1.jpg&#10;https://example.com/image2.png"></textarea>
        </div>
        <button type="submit">开始下载</button>
    </form>
    <div style="margin-top:16px;font-size:12px;color:#999;">
        <p>• 自动查重: 相同MD5的图片会自动跳过</p>
        <p>• 自动创建: 首次使用自动创建 images/ 目录</p>
        <p>• 支持格式: jpg, png, gif, webp, bmp</p>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>
</body>
</html>