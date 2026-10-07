<?php
// Simple config
define('MAX_FILE_SIZE', 10 * 1024 * 1024); // 10 MB per file
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('COMPRESSED_DIR', __DIR__ . '/compressed/');
@mkdir(UPLOAD_DIR, 0755, true);
@mkdir(COMPRESSED_DIR, 0755, true);

$results = [];
$errors = [];

function formatBytes($bytes, $precision = 2) {
    $units = ['B','KB','MB','GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units)-1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

function compressImage($srcPath, $destPath, $quality, $outputFormat, $maxWidth, $maxHeight) {
    $info = getimagesize($srcPath);
    if (!$info) return false;
    [$origW, $origH, $type] = $info;
    $mime = $info['mime'];

    // Determine source type
    switch ($mime) {
        case 'image/jpeg': $srcImg = imagecreatefromjpeg($srcPath); break;
        case 'image/png':  $srcImg = imagecreatefrompng($srcPath); break;
        case 'image/webp': $srcImg = function_exists('imagecreatefromwebp') ? imagecreatefromwebp($srcPath) : false; break;
        case 'image/gif':  $srcImg = imagecreatefromgif($srcPath); break;
        case 'image/bmp':
        case 'image/x-ms-bmp': $srcImg = function_exists('imagecreatefrombmp') ? imagecreatefrombmp($srcPath) : false; break;
        default: return false;
    }
    if (!$srcImg) return false;

    // Calculate new dimensions
    $newW = $origW;
    $newH = $origH;
    if ($maxWidth > 0 || $maxHeight > 0) {
        $maxW = $maxWidth > 0 ? $maxWidth : $origW;
        $maxH = $maxHeight > 0 ? $maxHeight : $origH;
        $ratio = min($maxW / $origW, $maxH / $origH);
        if ($ratio < 1) {
            $newW = (int)($origW * $ratio);
            $newH = (int)($origH * $ratio);
        }
    }

    // Resize if needed
    if ($newW !== $origW || $newH !== $origH) {
        $dstImg = imagecreatetruecolor($newW, $newH);
        // Preserve transparency
        if (in_array($mime, ['image/png','image/webp','image/gif'])) {
            imagealphablending($dstImg, false);
            imagesavealpha($dstImg, true);
            $transparent = imagecolorallocatealpha($dstImg, 0, 0, 0, 127);
            imagefilledrectangle($dstImg, 0, 0, $newW, $newH, $transparent);
        }
        imagecopyresampled($dstImg, $srcImg, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
        imagedestroy($srcImg);
        $srcImg = $dstImg;
    } else {
        // Ensure alpha preserved for png/webp
        if (in_array($mime, ['image/png','image/webp'])) {
            imagealphablending($srcImg, false);
            imagesavealpha($srcImg, true);
        }
    }

    // Decide output format
    if ($outputFormat === 'auto') {
        // keep original but normalize bmp/gif to webp/jpeg
        if ($mime === 'image/png') $outputFormat = 'png';
        elseif ($mime === 'image/webp') $outputFormat = 'webp';
        elseif ($mime === 'image/gif') $outputFormat = 'gif';
        else $outputFormat = 'jpg';
    }
    // Ensure webp support
    if ($outputFormat === 'webp' && !function_exists('imagewebp')) $outputFormat = 'jpg';
    if ($outputFormat === 'png' && !function_exists('imagepng')) $outputFormat = 'jpg';

    $success = false;
    switch ($outputFormat) {
        case 'jpg':
        case 'jpeg':
            // Flatten transparency to white for jpg
            $bg = imagecreatetruecolor(imagesx($srcImg), imagesy($srcImg));
            $white = imagecolorallocate($bg, 255, 255, 255);
            imagefilledrectangle($bg, 0, 0, imagesx($srcImg), imagesy($srcImg), $white);
            imagecopy($bg, $srcImg, 0, 0, 0, 0, imagesx($srcImg), imagesy($srcImg));
            imagedestroy($srcImg);
            $srcImg = $bg;
            $success = imagejpeg($srcImg, $destPath, (int)$quality);
            break;
        case 'png':
            $pngQuality = (int)round(9 - ($quality / 100) * 9); // 0(best) -9(worst)
            $pngQuality = max(0, min(9, $pngQuality));
            $success = imagepng($srcImg, $destPath, $pngQuality);
            break;
        case 'webp':
            $success = imagewebp($srcImg, $destPath, (int)$quality);
            break;
        case 'gif':
            $success = imagegif($srcImg, $destPath);
            break;
    }
    imagedestroy($srcImg);
    return $success;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $quality = isset($_POST['quality']) ? (int)$_POST['quality'] : 75;
    $quality = max(10, min(100, $quality));
    $outputFormat = $_POST['format'] ?? 'auto';
    $allowedFormats = ['auto','jpg','png','webp','gif'];
    if (!in_array($outputFormat, $allowedFormats)) $outputFormat = 'auto';
    $maxWidth = isset($_POST['max_width']) ? (int)$_POST['max_width'] : 0;
    $maxHeight = isset($_POST['max_height']) ? (int)$_POST['max_height'] : 0;

    if (!empty($_FILES['images']['name'][0])) {
        $count = count($_FILES['images']['name']);
        for ($i = 0; $i < $count; $i++) {
            $error = $_FILES['images']['error'][$i];
            $tmp = $_FILES['images']['tmp_name'][$i];
            $name = $_FILES['images']['name'][$i];
            $size = $_FILES['images']['size'][$i];

            if ($error !== UPLOAD_ERR_OK) { $errors[] = "$name: upload error ($error)"; continue; }
            if ($size > MAX_FILE_SIZE) { $errors[] = "$name: exceeds 10MB limit"; continue; }

            // Detect MIME — works even if fileinfo extension is disabled
            $mime = null;
            if (function_exists('finfo_open')) {
                $finfo = @finfo_open(FILEINFO_MIME_TYPE);
                if ($finfo) { $mime = @finfo_file($finfo, $tmp); @finfo_close($finfo); }
            }
            if (!$mime && function_exists('mime_content_type')) {
                $mime = @mime_content_type($tmp);
            }
            if (!$mime) {
                $probe = @getimagesize($tmp);
                $mime = $probe['mime'] ?? '';
            }
            // Fallback: infer from extension if still empty
            if (!$mime) {
                $extProbe = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                $mime = match($extProbe) {
                    'jpg','jpeg' => 'image/jpeg',
                    'png' => 'image/png',
                    'webp' => 'image/webp',
                    'gif' => 'image/gif',
                    'bmp' => 'image/bmp',
                    default => ''
                };
            }
            $allowedMimes = ['image/jpeg','image/png','image/webp','image/gif','image/bmp','image/x-ms-bmp'];
            if (!in_array($mime, $allowedMimes)) { $errors[] = "$name: unsupported type ($mime)"; continue; }

            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $safeBase = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($name, PATHINFO_FILENAME));
            $safeBase = substr($safeBase, 0, 40) ?: 'image';
            $uniq = $safeBase . '_' . time() . '_' . bin2hex(random_bytes(3));

            // Resolve final extension
            $finalExt = $outputFormat === 'auto'
                ? ($ext === 'jpeg' ? 'jpg' : ($ext ?: 'jpg'))
                : $outputFormat;
            if ($outputFormat === 'auto') {
                if ($mime === 'image/png') $finalExt = 'png';
                elseif ($mime === 'image/webp') $finalExt = 'webp';
                elseif ($mime === 'image/gif') $finalExt = 'gif';
                else $finalExt = 'jpg';
            }
            if ($finalExt === 'jpeg') $finalExt = 'jpg';

            $destName = $uniq . '.' . $finalExt;
            $destPath = COMPRESSED_DIR . $destName;

            $origSize = $size;
            $ok = compressImage($tmp, $destPath, $quality, $outputFormat, $maxWidth, $maxHeight);
            if (!$ok || !file_exists($destPath)) {
                $errors[] = "$name: compression failed";
                continue;
            }
            $newSize = filesize($destPath);
            $saving = $origSize > 0 ? round(100 - ($newSize / $origSize * 100), 1) : 0;

            $results[] = [
                'original_name' => $name,
                'original_size' => $origSize,
                'original_size_f' => formatBytes($origSize),
                'compressed_name' => $destName,
                'compressed_size' => $newSize,
                'compressed_size_f' => formatBytes($newSize),
                'saving' => $saving,
                'url' => 'compressed/' . $destName,
                'ext' => $finalExt,
            ];
        }
    } else {
        $errors[] = "No files selected.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ImageCompress — PHP Image Compressor</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="nav">
  <div class="container nav-inner">
    <div class="logo"><span class="logo-icon">◈</span> ImageCompress</div>
    <div class="nav-meta">PHP • GD • JPEG / PNG / WebP / GIF</div>
  </div>
</header>

<main class="container">
  <section class="hero">
    <h1>Compress images <span>without losing quality</span></h1>
    <p>Fast, private, server-side compression. No third-party API. Supports JPEG, PNG, WebP & GIF. Resize, convert format, and download instantly.</p>
    <div class="badges">
      <span class="badge">GD Library ✓</span>
      <span class="badge">Batch upload</span>
      <span class="badge">Quality control</span>
      <span class="badge">Format conversion</span>
    </div>
  </section>

  <?php if (!empty($errors)): ?>
    <div class="alert alert-error">
      <strong>Errors:</strong>
      <ul><?php foreach ($errors as $e) echo "<li>" . htmlspecialchars($e) . "</li>"; ?></ul>
    </div>
  <?php endif; ?>

  <form method="POST" enctype="multipart/form-data" id="compressForm" class="card">
    <div class="grid">
      <div class="drop-zone" id="dropZone">
        <input type="file" name="images[]" id="fileInput" multiple accept="image/jpeg,image/png,image/webp,image/gif,image/bmp">
        <div class="drop-icon">⬆</div>
        <h3>Drag & drop images here</h3>
        <p>or click to browse — up to 10MB per file</p>
        <span class="file-hint">Supports JPG, PNG, WebP, GIF, BMP</span>
        <div id="fileList" class="file-list"></div>
      </div>

      <div class="controls">
        <label class="control">
          <span class="label-row"><span>Quality</span><span class="value" id="qualityVal">75%</span></span>
          <input type="range" name="quality" id="quality" min="10" max="100" value="75">
          <span class="help">Lower = smaller file, higher = better quality. 70–80 recommended.</span>
        </label>

        <label class="control">
          <span>Output format</span>
          <select name="format" id="format">
            <option value="auto" selected>Auto (keep original)</option>
            <option value="jpg">JPEG — best for photos</option>
            <option value="png">PNG — keeps transparency</option>
            <option value="webp">WebP — smallest size</option>
            <option value="gif">GIF</option>
          </select>
        </label>

        <div class="row">
          <label class="control flex1">
            <span>Max width (px)</span>
            <input type="number" name="max_width" placeholder="e.g. 1920" min="0">
          </label>
          <label class="control flex1">
            <span>Max height (px)</span>
            <input type="number" name="max_height" placeholder="e.g. 1080" min="0">
          </label>
        </div>
        <p class="help">Leave empty to keep original dimensions. Resizes proportionally.</p>

        <button type="submit" class="btn btn-primary" id="submitBtn">Compress Images</button>
        <p class="help center">Files are processed on your server and saved to <code>/compressed</code>.</p>
      </div>
    </div>
  </form>

  <?php if (!empty($results)): ?>
  <section class="results">
    <div class="results-head">
      <h2>Results — <?= count($results) ?> file(s) compressed</h2>
      <?php if (count($results) > 1): ?>
        <a class="btn btn-ghost" href="download.php?zip=1">Download All as ZIP</a>
      <?php endif; ?>
    </div>
    <div class="result-grid">
      <?php foreach ($results as $r): ?>
      <div class="result-card">
        <div class="result-preview">
          <img src="<?= htmlspecialchars($r['url']) ?>" alt="compressed">
          <span class="ext-badge"><?= strtoupper($r['ext']) ?></span>
        </div>
        <div class="result-body">
          <div class="result-name" title="<?= htmlspecialchars($r['original_name']) ?>"><?= htmlspecialchars($r['original_name']) ?></div>
          <div class="sizes">
            <span><?= $r['original_size_f'] ?></span>
            <span class="arrow">→</span>
            <span class="new-size"><?= $r['compressed_size_f'] ?></span>
            <span class="saving <?= $r['saving'] > 0 ? 'positive' : 'negative' ?>"><?= $r['saving'] > 0 ? '-' . $r['saving'] . '%' : $r['saving'] . '%' ?></span>
          </div>
          <div class="bar"><div class="bar-fill" style="width: <?= max(5, min(100, 100 - $r['saving'])) ?>%"></div></div>
          <div class="result-actions">
            <a class="btn btn-sm btn-primary" href="<?= htmlspecialchars($r['url']) ?>" download>Download</a>
            <a class="btn btn-sm btn-ghost" href="<?= htmlspecialchars($r['url']) ?>" target="_blank">View</a>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <section class="info-grid">
    <div class="info-card">
      <h3>How it works</h3>
      <ol>
        <li>Upload via form — PHP validates MIME & size</li>
        <li>GD re-encodes with chosen quality / dimensions</li>
        <li>PNG transparency & WebP preserved; JPEG flattened to white</li>
        <li>Compressed files stored in <code>/compressed</code></li>
      </ol>
    </div>
    <div class="info-card">
      <h3>Tips</h3>
      <ul>
        <li>WebP is ~25–35% smaller than JPEG at same quality.</li>
        <li>Use max width 1920 for web — huge savings on phone photos.</li>
        <li>PNG compression level is auto-mapped from quality slider.</li>
      </ul>
    </div>
    <div class="info-card">
      <h3>Requirements</h3>
      <ul>
        <li>PHP 7.4+ with <code>ext-gd</code> (and <code>imagewebp</code> for WebP)</li>
        <li><code>uploads/</code> & <code>compressed/</code> writable (0755)</li>
        <li><code>php.ini</code>: <code>upload_max_filesize</code> & <code>post_max_size</code> ≥ 10M</li>
      </ul>
    </div>
  </section>
</main>

<footer class="footer">
  <div class="container">Built with PHP GD • No external API • Files stay on your server</div>
</footer>

<script src="assets/app.js"></script>
</body>
</html>
