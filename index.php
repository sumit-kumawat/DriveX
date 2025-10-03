<?php
// index.php - DriveX File Manager with Advanced Features
session_start();

// --- Configuration ---
define('ROOT_DIR', __DIR__ . '/files');
define('TRASH_DIR', __DIR__ . '/trash');
define('THUMB_DIR', __DIR__ . '/.cache/thumbs');
define('MAX_FILE_SIZE', 500 * 1024 * 1024);
define('TRASH_RETENTION_DAYS', 30);

// Create directories if they don't exist
if (!is_dir(ROOT_DIR)) mkdir(ROOT_DIR, 0755, true);
if (!is_dir(TRASH_DIR)) mkdir(TRASH_DIR, 0755, true);
if (!is_dir(THUMB_DIR)) mkdir(THUMB_DIR, 0755, true);

// Users configuration
$USERS = [
    'admin' => password_hash('admin', PASSWORD_DEFAULT)
];

// Public access settings
$PUBLIC_ACCESS = true;

// Data files
$STARRED_FILE = __DIR__ . '/.cache/starred.json';
$RECENT_FILE = __DIR__ . '/.cache/recent.json';
if (!is_dir(dirname($STARRED_FILE))) mkdir(dirname($STARRED_FILE), 0755, true);

// API Response Helpers
function json_ok($data = []) {
    header('Content-Type: application/json');
    echo json_encode(array_merge(['ok' => true], (array)$data));
    exit;
}

function json_err($msg = 'error') {
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

// Security Functions
function safe_path($path, $base = ROOT_DIR) {
    // Normalize base path
    $real_base = realpath($base) ?: $base;
    if ($real_base === false) return false;
    
    // Handle empty path
    if (empty($path) || $path === '/') {
        return $real_base;
    }
    
    // Build full path
    $joined = rtrim($real_base, '/') . '/' . ltrim($path, '/');
    $real = realpath($joined);
    
    // For non-existent paths, check if the parent directory exists
    if ($real === false) {
        $parent = realpath(dirname($joined));
        if ($parent && strpos($parent, $real_base) === 0) {
            return $joined; // Allow creation of new files/folders
        }
        return false;
    }
    
    // Ensure the resolved path is within base directory
    return strpos($real, $real_base) === 0 ? $real : false;
}

// File Operations
function human_filesize($bytes, $decimals = 2) {
    if ($bytes == 0) return '0 B';
    $s = ['B', 'KB', 'MB', 'GB', 'TB'];
    $e = floor(log($bytes, 1024));
    return sprintf('%.' . $decimals . 'f %s', ($bytes / pow(1024, $e)), $s[$e]);
}

function delete_recursive($path) {
    if (is_file($path) || is_link($path)) return @unlink($path);
    $items = array_diff(scandir($path), ['.', '..']);
    foreach ($items as $it) {
        if (!delete_recursive($path . '/' . $it)) return false;
    }
    return @rmdir($path);
}

function move_to_trash($path) {
    $relative_path = str_replace(ROOT_DIR . '/', '', $path);
    $trash_path = TRASH_DIR . '/' . $relative_path . '_' . time();
    
    // Create directory structure in trash
    $trash_dir = dirname($trash_path);
    if (!is_dir($trash_dir)) {
        mkdir($trash_dir, 0755, true);
    }
    
    return rename($path, $trash_path);
}

function restore_from_trash($trash_path) {
    // Extract original path and timestamp
    $parts = explode('_', basename($trash_path));
    $timestamp = array_pop($parts);
    $original_name = implode('_', $parts);
    $relative_path = dirname($trash_path) !== TRASH_DIR ? 
        str_replace(TRASH_DIR . '/', '', dirname($trash_path)) . '/' . $original_name : 
        $original_name;
    
    $restore_path = ROOT_DIR . '/' . $relative_path;
    
    // Create directory structure
    $restore_dir = dirname($restore_path);
    if (!is_dir($restore_dir)) {
        mkdir($restore_dir, 0755, true);
    }
    
    return rename($trash_path, $restore_path);
}

function move_file($source_path, $target_path) {
    // Create target directory if it doesn't exist
    $target_dir = dirname($target_path);
    if (!is_dir($target_dir)) {
        mkdir($target_dir, 0755, true);
    }
    
    return rename($source_path, $target_path);
}

function cleanup_old_trash() {
    if (!is_dir(TRASH_DIR)) return;
    
    $now = time();
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(TRASH_DIR, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    
    foreach ($items as $item) {
        if ($item->isFile() || $item->isDir()) {
            $filename = $item->getFilename();
            if (preg_match('/_(\d+)$/', $filename, $matches)) {
                $timestamp = $matches[1];
                if ($now - $timestamp > (TRASH_RETENTION_DAYS * 24 * 60 * 60)) {
                    if ($item->isDir()) {
                        delete_recursive($item->getPathname());
                    } else {
                        unlink($item->getPathname());
                    }
                }
            }
        }
    }
}

function make_thumb($file) {
    if (!file_exists($file)) return false;
    
    $base = THUMB_DIR;
    @mkdir($base, 0755, true);
    $key = md5(realpath($file) ?: $file);
    $out = $base . '/' . $key . '.jpg';
    
    if (file_exists($out) && filemtime($out) >= filemtime($file)) return $out;
    
    $mi = @mime_content_type($file);
    if (!$mi || strpos($mi, 'image/') !== 0) return false;
    
    $info = @getimagesize($file);
    if (!$info) return false;
    
    list($w, $h) = $info;
    $max = 400;
    $ratio = min($max / $w, $max / $h, 1);
    $nw = max(1, (int)($w * $ratio));
    $nh = max(1, (int)($h * $ratio));
    
    switch ($info[2]) {
        case IMAGETYPE_JPEG: $src = @imagecreatefromjpeg($file); break;
        case IMAGETYPE_PNG: $src = @imagecreatefrompng($file); break;
        case IMAGETYPE_GIF: $src = @imagecreatefromgif($file); break;
        case IMAGETYPE_WEBP: $src = @imagecreatefromwebp($file); break;
        default: return false;
    }
    
    if (!$src) return false;
    
    $dst = imagecreatetruecolor($nw, $nh);
    if ($info[2] == IMAGETYPE_PNG || $info[2] == IMAGETYPE_GIF) {
        imagecolortransparent($dst, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
    }
    
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagejpeg($dst, $out, 85);
    imagedestroy($src);
    imagedestroy($dst);
    return $out;
}

function get_file_icon($mime, $is_dir = false) {
    if ($is_dir) return 'folder';
    
    $icons = [
        'image/' => 'image',
        'video/' => 'film',
        'audio/' => 'music',
        'text/' => 'file-text',
        'application/pdf' => 'file-text',
        'application/msword' => 'file-text',
        'application/vnd.ms-excel' => 'file-text',
        'application/zip' => 'archive',
    ];
    
    foreach ($icons as $pattern => $icon) {
        if (strpos($mime, $pattern) === 0) return $icon;
    }
    
    return 'file';
}

// Starred items management
function get_starred_items() {
    global $STARRED_FILE;
    if (!file_exists($STARRED_FILE)) return [];
    $data = file_get_contents($STARRED_FILE);
    return $data ? json_decode($data, true) ?: [] : [];
}

function save_starred_items($items) {
    global $STARRED_FILE;
    file_put_contents($STARRED_FILE, json_encode($items));
}

function toggle_starred($path) {
    $starred = get_starred_items();
    $index = array_search($path, $starred);
    
    if ($index !== false) {
        unset($starred[$index]);
    } else {
        $starred[] = $path;
    }
    
    save_starred_items(array_values($starred));
    return $index === false;
}

function is_starred($path) {
    $starred = get_starred_items();
    return in_array($path, $starred);
}

// Recent items management
function get_recent_items() {
    global $RECENT_FILE;
    if (!file_exists($RECENT_FILE)) return [];
    $data = file_get_contents($RECENT_FILE);
    return $data ? json_decode($data, true) ?: [] : [];
}

function save_recent_items($items) {
    global $RECENT_FILE;
    file_put_contents($RECENT_FILE, json_encode($items));
}

function add_recent_item($path, $name, $is_dir = false, $mime = '') {
    $recent = get_recent_items();
    
    // Remove if already exists
    $recent = array_filter($recent, function($item) use ($path) {
        return $item['path'] !== $path;
    });
    
    // Add to beginning
    array_unshift($recent, [
        'path' => $path,
        'name' => $name,
        'is_dir' => $is_dir,
        'mime' => $mime,
        'timestamp' => time()
    ]);
    
    // Keep only last 50 items
    $recent = array_slice($recent, 0, 50);
    
    save_recent_items($recent);
}

function calculate_storage_usage() {
    $total_size = 0;
    $file_count = 0;
    $folder_count = 0;
    
    if (!is_dir(ROOT_DIR)) return [
        'total_size' => 0,
        'file_count' => 0,
        'folder_count' => 0,
        'used_percentage' => 0,
        'used_gb' => 0,
        'total_gb' => 15
    ];
    
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(ROOT_DIR, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    
    foreach ($iterator as $item) {
        if ($item->isFile()) {
            $total_size += $item->getSize();
            $file_count++;
        } elseif ($item->isDir()) {
            $folder_count++;
        }
    }
    
    // Calculate percentage (assuming 15GB total storage)
    $total_storage = 15 * 1024 * 1024 * 1024; // 15GB in bytes
    $used_percentage = $total_size > 0 ? min(100, ($total_size / $total_storage) * 100) : 0;
    
    return [
        'total_size' => $total_size,
        'file_count' => $file_count,
        'folder_count' => $folder_count,
        'used_percentage' => round($used_percentage, 1),
        'used_gb' => round($total_size / (1024 * 1024 * 1024), 2),
        'total_gb' => 15
    ];
}

// Cleanup old trash on first load
cleanup_old_trash();

// Authentication
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $u = $_POST['user'] ?? '';
    $p = $_POST['pass'] ?? '';
    if (isset($USERS[$u]) && password_verify($p, $USERS[$u])) {
        $_SESSION['user'] = $u;
        json_ok(['user' => $u]);
    } else {
        json_err('Invalid credentials');
    }
}

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

$logged_in = isset($_SESSION['user']);
$public_view = $PUBLIC_ACCESS || $logged_in;

// Calculate storage info
$storage_info = calculate_storage_usage();

// API Endpoints
if (isset($_REQUEST['action'])) {
    $act = $_REQUEST['action'];
    
    if ($act === 'list') {
        if (!$public_view) json_err('Login required');
        
        $type = $_GET['type'] ?? 'files'; // files, trash, recent, starred
        $path = $_GET['path'] ?? '';
        
        if ($type === 'trash') {
            $real = safe_path('', TRASH_DIR);
            $base_dir = TRASH_DIR;
        } else {
            $real = safe_path($path);
            $base_dir = ROOT_DIR;
        }
        
        if ($real === false) json_err('Invalid path');
        if (!is_dir($real)) json_err('Not a directory');
        
        if ($type === 'recent') {
            $recent_items = get_recent_items();
            $files = [];
            
            foreach ($recent_items as $recent) {
                $file_path = ROOT_DIR . '/' . $recent['path'];
                if (file_exists($file_path)) {
                    $files[] = [
                        'name' => $recent['name'],
                        'is_dir' => $recent['is_dir'],
                        'size' => $recent['is_dir'] ? 0 : filesize($file_path),
                        'mime' => $recent['mime'],
                        'modified' => filemtime($file_path),
                        'path' => $recent['path'],
                        'icon' => get_file_icon($recent['mime'], $recent['is_dir']),
                        'timestamp' => $recent['timestamp'],
                        'starred' => is_starred($recent['path'])
                    ];
                }
            }
        } elseif ($type === 'starred') {
            $starred_paths = get_starred_items();
            $files = [];
            
            foreach ($starred_paths as $starred_path) {
                $file_path = ROOT_DIR . '/' . $starred_path;
                if (file_exists($file_path)) {
                    $is_dir = is_dir($file_path);
                    $mime = $is_dir ? 'directory' : (mime_content_type($file_path) ?: 'application/octet-stream');
                    
                    $files[] = [
                        'name' => basename($starred_path),
                        'is_dir' => $is_dir,
                        'size' => $is_dir ? 0 : filesize($file_path),
                        'mime' => $mime,
                        'modified' => filemtime($file_path),
                        'path' => $starred_path,
                        'icon' => get_file_icon($mime, $is_dir),
                        'starred' => true
                    ];
                }
            }
        } else {
            // Get items from directory
            $items = @scandir($real);
            if ($items === false) {
                json_err('Cannot read directory');
            }
            
            $items = array_diff($items, ['.', '..']);
            $files = [];
            
            foreach ($items as $name) {
                $file_path = $real . '/' . $name;
                
                if ($type === 'trash') {
                    // Parse trash item name to get original name
                    if (preg_match('/^(.+)_(\d+)$/', $name, $matches)) {
                        $original_name = $matches[1];
                        $timestamp = $matches[2];
                        $days_remaining = TRASH_RETENTION_DAYS - floor((time() - $timestamp) / (24 * 60 * 60));
                    } else {
                        $original_name = $name;
                        $days_remaining = TRASH_RETENTION_DAYS;
                    }
                } else {
                    $original_name = $name;
                    $days_remaining = null;
                }
                
                $is_dir = is_dir($file_path);
                $mime = $is_dir ? 'directory' : (@mime_content_type($file_path) ?: 'application/octet-stream');
                
                $file_data = [
                    'name' => $type === 'trash' ? $original_name : $name,
                    'original_name' => $type === 'trash' ? $name : null,
                    'is_dir' => $is_dir,
                    'size' => $is_dir ? 0 : filesize($file_path),
                    'mime' => $mime,
                    'modified' => filemtime($file_path),
                    'path' => $type === 'trash' ? $name : ltrim($path . '/' . $name, '/'),
                    'icon' => get_file_icon($mime, $is_dir),
                    'days_remaining' => $days_remaining,
                    'timestamp' => $type === 'trash' ? ($timestamp ?? null) : null
                ];
                
                // Add starred status for non-trash items
                if ($type !== 'trash') {
                    $file_data['starred'] = is_starred(ltrim($path . '/' . $name, '/'));
                }
                
                $files[] = $file_data;
            }
        }
        
        // Sort: folders first, then by name
        usort($files, function($a, $b) {
            if ($a['is_dir'] && !$b['is_dir']) return -1;
            if (!$a['is_dir'] && $b['is_dir']) return 1;
            return strcasecmp($a['name'], $b['name']);
        });
        
        json_ok(['files' => $files, 'path' => $path, 'type' => $type]);
    }
    
    if ($act === 'upload') {
        if (!$logged_in) json_err('Login required');
        $path = $_POST['path'] ?? '';
        $real = safe_path($path);
        if ($real === false || !is_dir($real)) json_err('Invalid path');
        
        $uploaded = [];
        foreach ($_FILES as $field) {
            if (is_array($field['name'])) {
                for ($i = 0; $i < count($field['name']); $i++) {
                    if ($field['error'][$i] === UPLOAD_ERR_OK) {
                        $name = basename($field['name'][$i]);
                        $dest = $real . '/' . $name;
                        if (move_uploaded_file($field['tmp_name'][$i], $dest)) {
                            @make_thumb($dest);
                            $uploaded[] = $name;
                            add_recent_item(ltrim($path . '/' . $name, '/'), $name, false, mime_content_type($dest));
                        }
                    }
                }
            } else {
                if ($field['error'] === UPLOAD_ERR_OK) {
                    $name = basename($field['name']);
                    $dest = $real . '/' . $name;
                    if (move_uploaded_file($field['tmp_name'], $dest)) {
                        @make_thumb($dest);
                        $uploaded[] = $name;
                        add_recent_item(ltrim($path . '/' . $name, '/'), $name, false, mime_content_type($dest));
                    }
                }
            }
        }
        json_ok(['uploaded' => $uploaded]);
    }
    
    if ($act === 'mkdir') {
        if (!$logged_in) json_err('Login required');
        $path = $_POST['path'] ?? '';
        $name = $_POST['name'] ?? 'New Folder';
        $real = safe_path($path);
        if ($real === false) json_err('Invalid path');
        
        $new_path = $real . '/' . $name;
        if (!mkdir($new_path, 0755, true)) json_err('Failed to create folder');
        
        add_recent_item(ltrim($path . '/' . $name, '/'), $name, true, 'directory');
        json_ok(['created' => $name]);
    }
    
    if ($act === 'delete') {
        if (!$logged_in) json_err('Login required');
        $paths = $_POST['paths'] ?? [];
        $permanent = $_POST['permanent'] ?? false;
        
        if (!is_array($paths)) $paths = [$paths];
        
        $results = [];
        foreach ($paths as $path) {
            $real = safe_path($path);
            
            if ($real === false || $real === ROOT_DIR) {
                $results[$path] = 'Invalid operation';
                continue;
            }
            
            if ($permanent) {
                $results[$path] = delete_recursive($real) ? 'success' : 'Delete failed';
            } else {
                $results[$path] = move_to_trash($real) ? 'success' : 'Move to trash failed';
            }
        }
        
        json_ok(['results' => $results]);
    }
    
    if ($act === 'restore') {
        if (!$logged_in) json_err('Login required');
        $paths = $_POST['paths'] ?? [];
        
        if (!is_array($paths)) $paths = [$paths];
        
        $results = [];
        foreach ($paths as $path) {
            $real = safe_path($path, TRASH_DIR);
            if ($real === false) {
                $results[$path] = 'Invalid path';
                continue;
            }
            
            $results[$path] = restore_from_trash($real) ? 'success' : 'Restore failed';
        }
        
        json_ok(['results' => $results]);
    }
    
    if ($act === 'move') {
        if (!$logged_in) json_err('Login required');
        $source_paths = $_POST['source_paths'] ?? [];
        $target_path = $_POST['target_path'] ?? '';
        
        if (!is_array($source_paths)) $source_paths = [$source_paths];
        
        $real_target = safe_path($target_path);
        if ($real_target === false || !is_dir($real_target)) {
            json_err('Invalid target directory');
        }
        
        $results = [];
        foreach ($source_paths as $source_path) {
            $real_source = safe_path($source_path);
            if ($real_source === false) {
                $results[$source_path] = 'Invalid source path';
                continue;
            }
            
            $target_file_path = $real_target . '/' . basename($source_path);
            $results[$source_path] = move_file($real_source, $target_file_path) ? 'success' : 'Move failed';
        }
        
        json_ok(['results' => $results]);
    }
    
    if ($act === 'empty_trash') {
        if (!$logged_in) json_err('Login required');
        
        if (!is_dir(TRASH_DIR)) {
            mkdir(TRASH_DIR, 0755, true);
            json_ok();
        }
        
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(TRASH_DIR, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        
        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        
        json_ok();
    }
    
    if ($act === 'toggle_star') {
        if (!$public_view) json_err('Access denied');
        $path = $_POST['path'] ?? '';
        $starred = toggle_starred($path);
        json_ok(['starred' => $starred]);
    }
    
    if ($act === 'get_direct_link') {
        if (!$public_view) json_err('Access denied');
        $path = $_GET['path'] ?? '';
        $real = safe_path($path);
        if ($real === false || is_dir($real)) json_err('Invalid file');
        
        // Generate direct URL that opens in browser
        $base_url = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . 
                   $_SERVER['HTTP_HOST'] . 
                   str_replace('index.php', '', $_SERVER['SCRIPT_NAME']);
        
        $direct_link = $base_url . 'files/' . $path;
        json_ok(['url' => $direct_link]);
    }
    
    if ($act === 'storage_info') {
        $info = calculate_storage_usage();
        json_ok($info);
    }
    
    if ($act === 'view') {
        // Handle direct file viewing for public URLs
        $request_uri = $_SERVER['REQUEST_URI'];
        $base_path = str_replace('index.php', '', $_SERVER['SCRIPT_NAME']);
        $file_path = str_replace($base_path . 'files/', '', $request_uri);
        
        $real_path = safe_path($file_path);
        if ($real_path && file_exists($real_path) && !is_dir($real_path)) {
            $mime = mime_content_type($real_path) ?: 'application/octet-stream';
            header('Content-Type: ' . $mime);
            header('Content-Length: ' . filesize($real_path));
            header('Content-Disposition: inline; filename="' . basename($real_path) . '"');
            readfile($real_path);
            exit;
        } else {
            http_response_code(404);
            echo 'File not found';
            exit;
        }
    }
    
    if ($act === 'download') {
        if (!$public_view) json_err('Access denied');
        $path = $_GET['path'] ?? '';
        $real = safe_path($path);
        if ($real === false || is_dir($real)) {
            http_response_code(404);
            exit;
        }
        
        $mime = mime_content_type($real) ?: 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($real));
        header('Content-Disposition: attachment; filename="' . basename($real) . '"');
        readfile($real);
        exit;
    }
    
    if ($act === 'thumb') {
        if (!$public_view) json_err('Access denied');
        $path = $_GET['path'] ?? '';
        $real = safe_path($path);
        if ($real === false || is_dir($real)) {
            http_response_code(404);
            exit;
        }
        
        $thumb = make_thumb($real);
        if ($thumb && file_exists($thumb)) {
            header('Content-Type: image/jpeg');
            header('Cache-Control: public, max-age=86400');
            readfile($thumb);
        } else {
            header('Content-Type: image/svg+xml');
            echo '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="150" viewBox="0 0 200 150"><rect width="200" height="150" fill="#f3f4f6"/><text x="100" y="75" text-anchor="middle" dy=".3em" font-family="Arial" font-size="14" fill="#9ca3af">No preview</text></svg>';
        }
        exit;
    }
}

// Handle direct file access via pretty URLs
if (strpos($_SERVER['REQUEST_URI'], '/files/') !== false && !isset($_GET['action'])) {
    $request_uri = $_SERVER['REQUEST_URI'];
    $base_path = str_replace('index.php', '', $_SERVER['SCRIPT_NAME']);
    $file_path = str_replace($base_path . 'files/', '', $request_uri);
    
    $real_path = safe_path($file_path);
    if ($real_path && file_exists($real_path) && !is_dir($real_path)) {
        $mime = mime_content_type($real_path) ?: 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($real_path));
        header('Content-Disposition: inline; filename="' . basename($real_path) . '"');
        readfile($real_path);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DriveX - File Manager</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .file-item:hover .file-actions { opacity: 1; }
        .file-actions { opacity: 0; transition: opacity 0.2s; }
        .drag-over { background-color: #f0f9ff; border-color: #3b82f6; }
        .context-menu { 
            position: fixed; 
            background: white; 
            border: 1px solid #e5e7eb; 
            border-radius: 8px; 
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); 
            z-index: 1000; 
            min-width: 180px; 
            padding: 0.5rem 0; 
        }
        .context-menu-item { 
            padding: 0.5rem 1rem; 
            cursor: pointer; 
            display: flex; 
            align-items: center; 
            gap: 0.5rem; 
            font-size: 0.875rem; 
        }
        .context-menu-item:hover { background-color: #f3f4f6; }
        .notification {
            position: fixed;
            top: 1rem;
            right: 1rem;
            padding: 1rem;
            border-radius: 8px;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            z-index: 1000;
            transform: translateX(400px);
            transition: transform 0.3s ease;
        }
        .notification.show { transform: translateX(0); }
        .preview-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.8);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }
        .preview-content {
            max-width: 90vw;
            max-height: 90vh;
            background: white;
            border-radius: 12px;
            overflow: hidden;
        }
        .starred { color: #f59e0b; }
        .selected { background-color: #eff6ff !important; border-color: #3b82f6 !important; }
        .grid-view { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 1rem; }
        .dragging { opacity: 0.5; }
        .drop-zone { border: 2px dashed #3b82f6; background-color: #eff6ff; }
        .bulk-actions { 
            position: fixed; 
            bottom: 2rem; 
            left: 50%; 
            transform: translateX(-50%); 
            background: white; 
            padding: 1rem 2rem; 
            border-radius: 12px; 
            box-shadow: 0 10px 25px -3px rgba(0, 0, 0, 0.1); 
            border: 1px solid #e5e7eb;
            z-index: 100;
        }
    </style>
</head>
<body class="bg-gray-50 min-h-screen">
    <!-- Navigation -->
    <nav class="bg-white border-b border-gray-200 sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-16">
                <!-- Logo and Brand -->
                <div class="flex items-center space-x-4">
                    <a href="/" class="flex items-center space-x-3">
                        <div class="w-8 h-8 bg-gradient-to-br from-blue-600 to-purple-600 rounded-lg flex items-center justify-center">
                            <i class="fas fa-cloud text-white text-sm"></i>
                        </div>
                        <span class="text-xl font-bold text-gray-900">DriveX</span>
                    </a>
                    
                    <!-- Breadcrumbs -->
                    <div class="flex items-center space-x-2 text-sm" id="breadcrumbs">
                        <span class="text-gray-600">My files</span>
                    </div>
                </div>

                <!-- Search Bar -->
                <div class="flex-1 max-w-2xl mx-8">
                    <div class="relative">
                        <input type="text" id="search" placeholder="Search files and folders" 
                               class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg bg-gray-50 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition-colors">
                        <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                    </div>
                </div>

                <!-- User Actions -->
                <div class="flex items-center space-x-3">
                    <?php if($logged_in): ?>
                        <button onclick="showUploadModal()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-medium transition-colors flex items-center space-x-2">
                            <i class="fas fa-upload"></i>
                            <span>Upload</span>
                        </button>
                        <div class="relative group">
                            <button class="flex items-center space-x-2 p-2 rounded-lg hover:bg-gray-100 transition-colors">
                                <div class="w-8 h-8 bg-blue-100 rounded-full flex items-center justify-center">
                                    <i class="fas fa-user text-blue-600 text-sm"></i>
                                </div>
                                <span class="text-sm font-medium text-gray-700"><?= htmlspecialchars($_SESSION['user']) ?></span>
                                <i class="fas fa-chevron-down text-gray-400 text-xs"></i>
                            </button>
                            <div class="absolute right-0 top-full mt-1 w-48 bg-white rounded-lg shadow-lg border border-gray-200 opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all">
                                <a href="?action=logout" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 rounded-lg">
                                    <i class="fas fa-sign-out-alt mr-2"></i>Sign out
                                </a>
                            </div>
                        </div>
                    <?php else: ?>
                        <button onclick="showLoginModal()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-medium transition-colors">
                            Sign in
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
        <div class="flex gap-6">
            <!-- Sidebar -->
            <div class="w-64 flex-shrink-0">
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4">
                    <!-- Quick Actions -->
                    <div class="space-y-2 mb-6">
                        <button onclick="showUploadModal()" class="w-full flex items-center space-x-3 p-3 rounded-lg hover:bg-gray-50 transition-colors text-gray-700">
                            <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center">
                                <i class="fas fa-upload text-blue-600"></i>
                            </div>
                            <span class="font-medium">Upload</span>
                        </button>
                        <button onclick="createFolder()" class="w-full flex items-center space-x-3 p-3 rounded-lg hover:bg-gray-50 transition-colors text-gray-700">
                            <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center">
                                <i class="fas fa-folder-plus text-green-600"></i>
                            </div>
                            <span class="font-medium">New folder</span>
                        </button>
                    </div>

                    <!-- Navigation -->
                    <nav class="space-y-1">
                        <a href="javascript:void(0)" onclick="loadSection('files', '')" class="flex items-center space-x-3 p-3 rounded-lg bg-blue-50 text-blue-600 font-medium">
                            <i class="fas fa-cloud w-5 text-center"></i>
                            <span>My files</span>
                        </a>
                        <a href="javascript:void(0)" onclick="loadSection('recent', '')" class="flex items-center space-x-3 p-3 rounded-lg hover:bg-gray-50 transition-colors text-gray-700">
                            <i class="fas fa-clock w-5 text-center"></i>
                            <span>Recent</span>
                        </a>
                        <a href="javascript:void(0)" onclick="loadSection('starred', '')" class="flex items-center space-x-3 p-3 rounded-lg hover:bg-gray-50 transition-colors text-gray-700">
                            <i class="fas fa-star w-5 text-center"></i>
                            <span>Starred</span>
                        </a>
                        <a href="javascript:void(0)" onclick="loadSection('trash', '')" class="flex items-center space-x-3 p-3 rounded-lg hover:bg-gray-50 transition-colors text-gray-700">
                            <i class="fas fa-trash w-5 text-center"></i>
                            <span>Trash</span>
                        </a>
                    </nav>

                    <!-- Storage -->
                    <div class="mt-8 pt-6 border-t border-gray-200">
                        <div class="flex justify-between text-sm text-gray-600 mb-2">
                            <span>Storage</span>
                            <span id="storage-percent"><?= $storage_info['used_percentage'] ?>% used</span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-2">
                            <div id="storage-bar" class="bg-blue-600 h-2 rounded-full transition-all duration-500" style="width: <?= $storage_info['used_percentage'] ?>%"></div>
                        </div>
                        <p id="storage-text" class="text-xs text-gray-500 mt-2">
                            <?= $storage_info['used_gb'] ?> GB of <?= $storage_info['total_gb'] ?> GB used
                        </p>
                    </div>
                </div>
            </div>

            <!-- Main Content -->
            <div class="flex-1">
                <!-- Toolbar -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 mb-6">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-4">
                            <h2 class="text-lg font-semibold text-gray-900" id="current-section">My files</h2>
                            <span class="text-sm text-gray-500" id="file-count">0 items</span>
                            <span id="trash-info" class="text-sm text-orange-600 hidden">
                                Items will be automatically deleted after 30 days
                            </span>
                            <span id="selection-count" class="text-sm text-blue-600 hidden">
                                <span id="selected-count">0</span> selected
                            </span>
                        </div>
                        <div class="flex items-center space-x-3">
                            <select id="sort-by" class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-200">
                                <option value="name">Sort by name</option>
                                <option value="modified">Sort by date</option>
                                <option value="size">Sort by size</option>
                            </select>
                            <div class="flex border border-gray-300 rounded-lg overflow-hidden">
                                <button id="grid-view-btn" class="p-2 bg-white text-gray-600 hover:text-blue-600 transition-colors border-r border-gray-300">
                                    <i class="fas fa-th-large"></i>
                                </button>
                                <button id="list-view-btn" class="p-2 bg-blue-100 text-blue-600">
                                    <i class="fas fa-list"></i>
                                </button>
                            </div>
                            <button id="empty-trash-btn" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg font-medium transition-colors hidden" onclick="emptyTrash()">
                                Empty Trash
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Files Container -->
                <div id="files-container">
                    <!-- Grid View -->
                    <div id="grid-view" class="grid-view">
                        <!-- Files will be loaded here -->
                    </div>

                    <!-- List View -->
                    <div id="list-view" class="hidden">
                        <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                            <table class="w-full">
                                <thead class="bg-gray-50 border-b border-gray-200">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            <input type="checkbox" id="select-all" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Size</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Modified</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="list-view-body" class="bg-white divide-y divide-gray-200">
                                    <!-- Files will be loaded here -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Empty State -->
                <div id="empty-state" class="hidden text-center py-12">
                    <div class="w-24 h-24 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-folder-open text-gray-400 text-3xl"></i>
                    </div>
                    <h3 class="text-lg font-medium text-gray-900 mb-2" id="empty-title">No files yet</h3>
                    <p class="text-gray-500 mb-6" id="empty-description">Upload files or create folders to get started</p>
                    <button onclick="showUploadModal()" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-medium transition-colors" id="empty-action">
                        Upload files
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bulk Actions Bar -->
    <div id="bulk-actions" class="bulk-actions hidden">
        <div class="flex items-center space-x-4">
            <span class="text-sm font-medium text-gray-700">
                <span id="bulk-selected-count">0</span> items selected
            </span>
            <div class="flex space-x-2">
                <button onclick="bulkDownload()" class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded text-sm transition-colors">
                    <i class="fas fa-download mr-1"></i> Download
                </button>
                <button onclick="bulkDelete()" class="bg-red-600 hover:bg-red-700 text-white px-3 py-1 rounded text-sm transition-colors">
                    <i class="fas fa-trash mr-1"></i> Delete
                </button>
                <button onclick="bulkStar()" class="bg-yellow-600 hover:bg-yellow-700 text-white px-3 py-1 rounded text-sm transition-colors">
                    <i class="fas fa-star mr-1"></i> Star
                </button>
                <button onclick="clearSelection()" class="bg-gray-600 hover:bg-gray-700 text-white px-3 py-1 rounded text-sm transition-colors">
                    <i class="fas fa-times mr-1"></i> Clear
                </button>
            </div>
        </div>
    </div>

    <!-- Preview Modal -->
    <div id="preview-modal" class="preview-overlay">
        <div class="preview-content">
            <div class="flex justify-between items-center p-4 border-b border-gray-200">
                <h3 class="text-lg font-semibold" id="preview-title">Preview</h3>
                <button onclick="closePreview()" class="text-gray-500 hover:text-gray-700">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            <div class="p-4" id="preview-content">
                <!-- Preview content will be loaded here -->
            </div>
        </div>
    </div>

    <!-- Login Modal -->
    <div id="login-modal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden">
        <div class="bg-white rounded-2xl p-8 w-full max-w-md mx-4">
            <div class="text-center mb-8">
                <div class="w-16 h-16 bg-gradient-to-br from-blue-600 to-purple-600 rounded-2xl flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-cloud text-white text-2xl"></i>
                </div>
                <h2 class="text-2xl font-bold text-gray-900">Welcome to DriveX</h2>
                <p class="text-gray-600 mt-2">Sign in to your account</p>
            </div>
            <form id="login-form" class="space-y-4">
                <div>
                    <input type="text" id="username" placeholder="Username" 
                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition-colors">
                </div>
                <div>
                    <input type="password" id="password" placeholder="Password" 
                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition-colors">
                </div>
                <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white py-3 rounded-lg font-medium transition-colors">
                    Sign in
                </button>
            </form>
            <div class="text-center mt-6">
                <button onclick="hideLoginModal()" class="text-gray-600 hover:text-gray-800 transition-colors">
                    Cancel
                </button>
            </div>
        </div>
    </div>

    <!-- Upload Modal -->
    <div id="upload-modal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden">
        <div class="bg-white rounded-2xl p-6 w-full max-w-md mx-4">
            <div class="flex items-center justify-between mb-6">
                <h3 class="text-lg font-semibold text-gray-900">Upload files</h3>
                <button onclick="hideUploadModal()" class="text-gray-400 hover:text-gray-600 transition-colors">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            <div id="drop-zone" class="border-2 border-dashed border-gray-300 rounded-xl p-8 text-center transition-colors">
                <i class="fas fa-cloud-upload-alt text-3xl text-gray-400 mb-4"></i>
                <p class="text-gray-600 mb-2">Drag and drop files here</p>
                <p class="text-sm text-gray-500 mb-4">or</p>
                <input type="file" id="file-input" multiple class="hidden">
                <button onclick="document.getElementById('file-input').click()" 
                        class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-medium transition-colors">
                    Browse files
                </button>
            </div>
            <div id="upload-progress" class="mt-4 space-y-2 hidden">
                <!-- Upload progress will be shown here -->
            </div>
        </div>
    </div>

    <!-- Move To Modal -->
    <div id="move-modal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden">
        <div class="bg-white rounded-2xl p-6 w-full max-w-md mx-4">
            <div class="flex items-center justify-between mb-6">
                <h3 class="text-lg font-semibold text-gray-900">Move Items</h3>
                <button onclick="hideMoveModal()" class="text-gray-400 hover:text-gray-600 transition-colors">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            <div class="space-y-4">
                <p class="text-gray-600">Select destination folder:</p>
                <div id="move-folders" class="border border-gray-300 rounded-lg max-h-60 overflow-y-auto p-2">
                    <!-- Folders will be loaded here -->
                </div>
                <div class="flex justify-end space-x-3">
                    <button onclick="hideMoveModal()" class="px-4 py-2 text-gray-600 hover:text-gray-800 transition-colors">
                        Cancel
                    </button>
                    <button onclick="performMove()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition-colors">
                        Move Here
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Context Menu -->
    <div id="context-menu" class="context-menu hidden"></div>

    <!-- Notification -->
    <div id="notification" class="notification hidden"></div>

    <script>
        let currentPath = '';
        let currentSection = 'files';
        let currentView = 'list'; // Default to grid view
        let filesCache = [];
        let selectedItems = new Set();
        let dragSource = null;
        let moveTarget = null;
        const loggedIn = <?= $logged_in ? 'true' : 'false'; ?>;
        const publicView = <?= $public_view ? 'true' : 'false'; ?>;

        // API functions
        async function apiCall(action, data = null) {
            const url = new URL(window.location);
            const params = new URLSearchParams();
            params.set('action', action);
            
            if (data && typeof data === 'object') {
                for (const [key, value] of Object.entries(data)) {
                    if (value !== null && value !== undefined) {
                        if (Array.isArray(value)) {
                            value.forEach(v => params.append(key + '[]', v));
                        } else {
                            params.set(key, value);
                        }
                    }
                }
            }

            try {
                if (data instanceof FormData) {
                    // For FormData (file uploads)
                    params.forEach((value, key) => {
                        data.append(key, value);
                    });
                    const response = await fetch('?' + params.toString(), { 
                        method: 'POST', 
                        body: data 
                    });
                    return await response.json();
                } else {
                    // For regular data
                    const response = await fetch('?' + params.toString(), {
                        method: data ? 'POST' : 'GET',
                        body: data ? new URLSearchParams(data) : null
                    });
                    return await response.json();
                }
            } catch (error) {
                showNotification('Network error: ' + error.message, 'error');
                return { ok: false, error: 'Network error' };
            }
        }

        // View management
        function setView(view) {
            currentView = view;
            const gridView = document.getElementById('grid-view');
            const listView = document.getElementById('list-view');
            const gridBtn = document.getElementById('grid-view-btn');
            const listBtn = document.getElementById('list-view-btn');

            if (view === 'grid') {
                gridView.classList.remove('hidden');
                listView.classList.add('hidden');
                gridBtn.classList.add('bg-blue-100', 'text-blue-600');
                gridBtn.classList.remove('bg-white', 'text-gray-600');
                listBtn.classList.add('bg-white', 'text-gray-600');
                listBtn.classList.remove('bg-blue-100', 'text-blue-600');
            } else {
                gridView.classList.add('hidden');
                listView.classList.remove('hidden');
                listBtn.classList.add('bg-blue-100', 'text-blue-600');
                listBtn.classList.remove('bg-white', 'text-gray-600');
                gridBtn.classList.add('bg-white', 'text-gray-600');
                gridBtn.classList.remove('bg-blue-100', 'text-blue-600');
            }
            renderFiles();
        }

        // Section loading
        function loadSection(section, path = '') {
            currentSection = section;
            currentPath = path;
            selectedItems.clear();
            updateBulkActions();
            
            // Update sidebar active state
            document.querySelectorAll('.sidebar a').forEach(a => {
                a.classList.remove('bg-blue-50', 'text-blue-600', 'font-medium');
                a.classList.add('text-gray-700');
            });
            
            // Find and activate the clicked item
            const activeItem = Array.from(document.querySelectorAll('.sidebar a')).find(a => 
                a.textContent.trim().toLowerCase() === section.toLowerCase() || 
                (section === 'files' && a.textContent.trim().toLowerCase() === 'my files')
            );
            
            if (activeItem) {
                activeItem.classList.add('bg-blue-50', 'text-blue-600', 'font-medium');
                activeItem.classList.remove('text-gray-700');
            }
            
            updateSectionUI();
            listFiles(path);
        }

        function updateSectionUI() {
            const sectionTitle = document.getElementById('current-section');
            const trashInfo = document.getElementById('trash-info');
            const emptyTrashBtn = document.getElementById('empty-trash-btn');
            const emptyTitle = document.getElementById('empty-title');
            const emptyDescription = document.getElementById('empty-description');
            const emptyAction = document.getElementById('empty-action');
            
            switch (currentSection) {
                case 'files':
                    sectionTitle.textContent = 'My files';
                    trashInfo.classList.add('hidden');
                    emptyTrashBtn.classList.add('hidden');
                    emptyTitle.textContent = 'No files yet';
                    emptyDescription.textContent = 'Upload files or create folders to get started';
                    emptyAction.textContent = 'Upload files';
                    emptyAction.onclick = showUploadModal;
                    break;
                case 'recent':
                    sectionTitle.textContent = 'Recent files';
                    trashInfo.classList.add('hidden');
                    emptyTrashBtn.classList.add('hidden');
                    emptyTitle.textContent = 'No recent files';
                    emptyDescription.textContent = 'Files you recently uploaded will appear here';
                    emptyAction.textContent = 'Upload files';
                    emptyAction.onclick = showUploadModal;
                    break;
                case 'starred':
                    sectionTitle.textContent = 'Starred items';
                    trashInfo.classList.add('hidden');
                    emptyTrashBtn.classList.add('hidden');
                    emptyTitle.textContent = 'No starred items';
                    emptyDescription.textContent = 'Star files and folders to see them here';
                    emptyAction.textContent = 'Browse files';
                    emptyAction.onclick = () => loadSection('files', '');
                    break;
                case 'trash':
                    sectionTitle.textContent = 'Trash';
                    trashInfo.classList.remove('hidden');
                    emptyTrashBtn.classList.remove('hidden');
                    emptyTitle.textContent = 'Trash is empty';
                    emptyDescription.textContent = 'Items you delete will appear here for 30 days';
                    emptyAction.textContent = 'Browse files';
                    emptyAction.onclick = () => loadSection('files', '');
                    break;
            }
        }

        // File operations
        async function listFiles(path = '') {
            try {
                showLoading();
                const result = await apiCall('list', { 
                    type: currentSection, 
                    path: path 
                });
                
                if (result.ok) {
                    currentPath = path;
                    filesCache = result.files;
                    updateBreadcrumbs(path);
                    renderFiles();
                    updateStorageInfo();
                } else {
                    showNotification(result.error, 'error');
                }
            } catch (error) {
                showNotification('Failed to load files', 'error');
            } finally {
                hideLoading();
            }
        }

        function showLoading() {
            const gridView = document.getElementById('grid-view');
            const listBody = document.getElementById('list-view-body');
            
            if (currentView === 'grid') {
                gridView.innerHTML = `
                    <div class="col-span-full text-center py-12">
                        <div class="flex justify-center items-center space-x-2">
                            <div class="animate-spin rounded-full h-6 w-6 border-b-2 border-blue-600"></div>
                            <span class="text-gray-500">Loading files...</span>
                        </div>
                    </div>
                `;
            } else {
                listBody.innerHTML = `
                    <tr>
                        <td colspan="5" class="px-6 py-8 text-center">
                            <div class="flex justify-center items-center space-x-2">
                                <div class="animate-spin rounded-full h-6 w-6 border-b-2 border-blue-600"></div>
                                <span class="text-gray-500">Loading files...</span>
                            </div>
                        </td>
                    </tr>
                `;
            }
        }

        function hideLoading() {
            // Loading state is automatically replaced when files are rendered
        }

        function updateBreadcrumbs(path) {
            const breadcrumbs = document.getElementById('breadcrumbs');
            
            if (currentSection === 'files') {
                const parts = path ? path.split('/').filter(p => p) : [];
                let html = '<a href="javascript:void(0)" onclick="listFiles(\'\')" class="text-blue-600 hover:text-blue-800 transition-colors">My files</a>';
                
                let current = '';
                parts.forEach(part => {
                    current += (current ? '/' : '') + part;
                    html += ` <span class="text-gray-400">/</span> <a href="javascript:void(0)" onclick="listFiles('${current}')" class="text-blue-600 hover:text-blue-800 transition-colors">${part}</a>`;
                });
                
                breadcrumbs.innerHTML = html;
            } else {
                breadcrumbs.innerHTML = `<span class="text-gray-600">${document.getElementById('current-section').textContent}</span>`;
            }
        }

        function renderFiles() {
            const searchTerm = document.getElementById('search').value.toLowerCase();
            const filteredFiles = filesCache.filter(file => 
                file.name.toLowerCase().includes(searchTerm)
            );

            document.getElementById('file-count').textContent = `${filteredFiles.length} item${filteredFiles.length !== 1 ? 's' : ''}`;
            
            if (filteredFiles.length === 0) {
                document.getElementById('empty-state').classList.remove('hidden');
                document.getElementById('files-container').classList.add('hidden');
                return;
            }

            document.getElementById('empty-state').classList.add('hidden');
            document.getElementById('files-container').classList.remove('hidden');
            
            if (currentView === 'grid') {
                renderGridView(filteredFiles);
            } else {
                renderListView(filteredFiles);
            }
        }

        function renderGridView(files) {
            const grid = document.getElementById('grid-view');
            grid.innerHTML = '';

            files.forEach(file => {
                const card = createGridCard(file);
                grid.appendChild(card);
            });
        }

        function createGridCard(file) {
            const div = document.createElement('div');
            div.className = `bg-white rounded-xl border-2 p-4 hover:shadow-md transition-all file-item cursor-pointer ${selectedItems.has(file.path) ? 'selected' : ''}`;
            div.dataset.filePath = file.path;
            
            // Click to open folder or select file
            div.onclick = (e) => {
                if (e.ctrlKey || e.metaKey) {
                    // Ctrl+Click for multi-select
                    toggleSelection(file.path);
                } else if (e.shiftKey) {
                    // Shift+Click for range select
                    selectRange(file.path);
                } else {
                    // Regular click - open folder or select single item
                    if (file.is_dir && currentSection === 'files') {
                        listFiles(file.path);
                    } else {
                        clearSelection();
                        toggleSelection(file.path);
                    }
                }
            };
            
            div.ondblclick = () => {
                if (file.is_dir && currentSection === 'files') {
                    listFiles(file.path);
                } else if (!file.is_dir) {
                    previewFile(file.path);
                }
            };
            
            // Drag and drop
            div.draggable = true;
            div.ondragstart = (e) => handleDragStart(e, file);
            div.ondragover = (e) => handleDragOver(e, file);
            div.ondragleave = (e) => handleDragLeave(e, file);
            div.ondrop = (e) => handleDrop(e, file);
            div.ondragend = handleDragEnd;

            let iconClass = 'text-gray-400';
            let icon = 'fa-file';
            
            if (file.is_dir) {
                icon = 'fa-folder';
                iconClass = 'text-yellow-500';
            } else if (file.mime.startsWith('image/')) {
                icon = 'fa-file-image';
                iconClass = 'text-green-500';
            } else if (file.mime.startsWith('video/')) {
                icon = 'fa-file-video';
                iconClass = 'text-purple-500';
            }

            const starIcon = file.starred ? 'fas fa-star starred' : 'far fa-star';
            const daysRemaining = file.days_remaining !== null && file.days_remaining > 0 ? 
                `<div class="text-xs text-orange-600 mt-1">${file.days_remaining} days left</div>` : '';

            div.innerHTML = `
                <div class="flex flex-col items-center text-center h-full">
                    <div class="relative mb-3 flex-1 flex items-center justify-center">
                        ${file.mime.startsWith('image/') ? `
                            <img src="?action=thumb&path=${encodeURIComponent(file.path)}" 
                                 class="w-20 h-20 object-cover rounded-lg" 
                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex'">
                        ` : ''}
                        <div class="w-20 h-20 rounded-lg bg-gray-100 flex items-center justify-center ${file.mime.startsWith('image/') ? 'hidden' : ''}">
                            <i class="fas ${icon} text-2xl ${iconClass}"></i>
                        </div>
                        <div class="absolute top-0 left-0">
                            <input type="checkbox" ${selectedItems.has(file.path) ? 'checked' : ''} 
                                   class="rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                                   onclick="event.stopPropagation(); toggleSelection('${file.path}')">
                        </div>
                    </div>
                    <div class="flex-1 flex flex-col justify-between w-full">
                        <h3 class="font-medium text-gray-900 text-sm mb-1 truncate w-full" title="${file.name}">${file.name}</h3>
                        <div class="flex items-center justify-between">
                            <p class="text-xs text-gray-500">
                                ${file.is_dir ? 'Folder' : humanFileSize(file.size)}
                            </p>
                            ${currentSection !== 'trash' ? `
                                <button onclick="event.stopPropagation(); toggleStar('${file.path}')" 
                                        class="text-gray-400 hover:text-yellow-500 transition-colors">
                                    <i class="${starIcon} text-sm"></i>
                                </button>
                            ` : ''}
                        </div>
                        ${daysRemaining}
                    </div>
                </div>
            `;

            return div;
        }

        function renderListView(files) {
            const tbody = document.getElementById('list-view-body');
            tbody.innerHTML = '';

            files.forEach(file => {
                const row = createListRow(file);
                tbody.appendChild(row);
            });

            // Update select all checkbox
            const selectAll = document.getElementById('select-all');
            const allSelected = files.length > 0 && files.every(file => selectedItems.has(file.path));
            const someSelected = files.some(file => selectedItems.has(file.path));
            
            selectAll.checked = allSelected;
            selectAll.indeterminate = someSelected && !allSelected;
        }

        function createListRow(file) {
            const tr = document.createElement('tr');
            tr.className = `hover:bg-gray-50 file-item cursor-pointer ${selectedItems.has(file.path) ? 'selected' : ''}`;
            tr.dataset.filePath = file.path;
            
            // Click to select or open
            tr.onclick = (e) => {
                if (e.target.type === 'checkbox') return;
                
                if (e.ctrlKey || e.metaKey) {
                    toggleSelection(file.path);
                } else if (e.shiftKey) {
                    selectRange(file.path);
                } else {
                    if (file.is_dir && currentSection === 'files') {
                        listFiles(file.path);
                    } else {
                        clearSelection();
                        toggleSelection(file.path);
                    }
                }
            };
            
            tr.ondblclick = () => {
                if (file.is_dir && currentSection === 'files') {
                    listFiles(file.path);
                } else if (!file.is_dir) {
                    previewFile(file.path);
                }
            };

            // Drag and drop
            tr.draggable = true;
            tr.ondragstart = (e) => handleDragStart(e, file);
            tr.ondragover = (e) => handleDragOver(e, file);
            tr.ondragleave = (e) => handleDragLeave(e, file);
            tr.ondrop = (e) => handleDrop(e, file);
            tr.ondragend = handleDragEnd;

            let iconClass = 'text-gray-400';
            let icon = 'fa-file';
            
            if (file.is_dir) {
                icon = 'fa-folder';
                iconClass = 'text-yellow-500';
            } else if (file.mime.startsWith('image/')) {
                icon = 'fa-file-image';
                iconClass = 'text-green-500';
            }

            const starIcon = file.starred ? 'fas fa-star starred' : 'far fa-star';
            const daysRemaining = file.days_remaining !== null && file.days_remaining > 0 ? 
                `<span class="text-xs text-orange-600 ml-2">${file.days_remaining} days left</span>` : '';

            tr.innerHTML = `
                <td class="px-6 py-4 whitespace-nowrap" onclick="event.stopPropagation()">
                    <input type="checkbox" ${selectedItems.has(file.path) ? 'checked' : ''} 
                           class="rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                           onchange="toggleSelection('${file.path}')">
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                    <div class="flex items-center">
                        <i class="fas ${icon} ${iconClass} mr-3"></i>
                        <div class="text-sm font-medium text-gray-900">${file.name}</div>
                        ${daysRemaining}
                    </div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                    <div class="text-sm text-gray-900">${file.is_dir ? '-' : humanFileSize(file.size)}</div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                    <div class="text-sm text-gray-500">${new Date(file.modified * 1000).toLocaleDateString()}</div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                    <div class="file-actions flex space-x-2">
                        ${currentSection === 'trash' ? `
                            <button onclick="event.stopPropagation(); restoreFile('${file.original_name}')" 
                                    class="text-green-600 hover:text-green-900 transition-colors">
                                Restore
                            </button>
                            <button onclick="event.stopPropagation(); permanentDelete('${file.original_name}')" 
                                    class="text-red-600 hover:text-red-900 transition-colors">
                                Delete
                            </button>
                        ` : `
                            ${file.is_dir ? `
                                <button onclick="event.stopPropagation(); listFiles('${file.path}')" 
                                        class="text-blue-600 hover:text-blue-900 transition-colors">
                                    Open
                                </button>
                            ` : `
                                <button onclick="event.stopPropagation(); previewFile('${file.path}')" 
                                        class="text-blue-600 hover:text-blue-900 transition-colors">
                                    View
                                </button>
                                <button onclick="event.stopPropagation(); downloadFile('${file.path}')" 
                                        class="text-green-600 hover:text-green-900 transition-colors">
                                    Download
                                </button>
                                ${file.mime.startsWith('image/') ? `
                                    <button onclick="event.stopPropagation(); copyImageLink('${file.path}')" 
                                            class="text-purple-600 hover:text-purple-900 transition-colors">
                                        Copy Link
                                    </button>
                                ` : ''}
                            `}
                            ${currentSection !== 'starred' ? `
                                <button onclick="event.stopPropagation(); toggleStar('${file.path}')" 
                                        class="text-gray-600 hover:text-yellow-600 transition-colors">
                                    <i class="${starIcon}"></i>
                                </button>
                            ` : ''}
                            ${loggedIn ? `
                                <button onclick="event.stopPropagation(); deleteFile('${file.path}')" 
                                        class="text-red-600 hover:text-red-900 transition-colors">
                                    Delete
                                </button>
                            ` : ''}
                        `}
                    </div>
                </td>
            `;

            return tr;
        }

        // Selection functions
        function toggleSelection(filePath) {
            if (selectedItems.has(filePath)) {
                selectedItems.delete(filePath);
            } else {
                selectedItems.add(filePath);
            }
            updateSelectionUI();
            updateBulkActions();
        }

        function selectRange(filePath) {
            const currentIndex = filesCache.findIndex(f => f.path === filePath);
            if (currentIndex === -1) return;

            // Find last selected item
            let lastSelectedIndex = -1;
            for (let i = filesCache.length - 1; i >= 0; i--) {
                if (selectedItems.has(filesCache[i].path)) {
                    lastSelectedIndex = i;
                    break;
                }
            }

            if (lastSelectedIndex === -1) {
                selectedItems.add(filePath);
            } else {
                const start = Math.min(currentIndex, lastSelectedIndex);
                const end = Math.max(currentIndex, lastSelectedIndex);
                for (let i = start; i <= end; i++) {
                    selectedItems.add(filesCache[i].path);
                }
            }

            updateSelectionUI();
            updateBulkActions();
        }

        function clearSelection() {
            selectedItems.clear();
            updateSelectionUI();
            updateBulkActions();
        }

        function updateSelectionUI() {
            // Update grid view
            document.querySelectorAll('#grid-view .file-item').forEach(item => {
                const filePath = item.dataset.filePath;
                if (selectedItems.has(filePath)) {
                    item.classList.add('selected');
                    item.querySelector('input[type="checkbox"]').checked = true;
                } else {
                    item.classList.remove('selected');
                    item.querySelector('input[type="checkbox"]').checked = false;
                }
            });

            // Update list view
            document.querySelectorAll('#list-view-body tr').forEach(tr => {
                const filePath = tr.dataset.filePath;
                if (selectedItems.has(filePath)) {
                    tr.classList.add('selected');
                    tr.querySelector('input[type="checkbox"]').checked = true;
                } else {
                    tr.classList.remove('selected');
                    tr.querySelector('input[type="checkbox"]').checked = false;
                }
            });

            // Update selection count
            const selectionCount = document.getElementById('selection-count');
            const selectedCount = document.getElementById('selected-count');
            if (selectedItems.size > 0) {
                selectionCount.classList.remove('hidden');
                selectedCount.textContent = selectedItems.size;
            } else {
                selectionCount.classList.add('hidden');
            }
        }

        function updateBulkActions() {
            const bulkActions = document.getElementById('bulk-actions');
            const bulkSelectedCount = document.getElementById('bulk-selected-count');
            
            if (selectedItems.size > 0) {
                bulkActions.classList.remove('hidden');
                bulkSelectedCount.textContent = selectedItems.size;
            } else {
                bulkActions.classList.add('hidden');
            }
        }

        // Bulk operations
        function bulkDownload() {
            if (selectedItems.size === 0) return;
            
            // For now, download first selected file
            // In a real implementation, you might want to create a zip of all selected files
            const firstSelected = Array.from(selectedItems)[0];
            downloadFile(firstSelected);
        }

        async function bulkDelete() {
            if (selectedItems.size === 0) return;
            
            if (!confirm(`Are you sure you want to move ${selectedItems.size} item(s) to trash?`)) return;
            
            try {
                const result = await apiCall('delete', { 
                    paths: Array.from(selectedItems)
                });
                
                if (result.ok) {
                    showNotification(`Moved ${selectedItems.size} item(s) to trash`);
                    clearSelection();
                    listFiles(currentPath);
                    updateStorageInfo();
                } else {
                    showNotification('Some items could not be deleted', 'error');
                }
            } catch (error) {
                showNotification('Delete failed', 'error');
            }
        }

        async function bulkStar() {
            if (selectedItems.size === 0) return;
            
            try {
                // Star all selected items
                for (const filePath of selectedItems) {
                    await apiCall('toggle_star', { path: filePath });
                }
                showNotification(`Starred ${selectedItems.size} item(s)`);
                if (currentSection === 'starred') {
                    listFiles(currentPath);
                } else {
                    renderFiles();
                }
            } catch (error) {
                showNotification('Failed to star items', 'error');
            }
        }

        // Drag and drop functions
        function handleDragStart(e, file) {
            dragSource = file;
            e.dataTransfer.setData('text/plain', file.path);
            e.dataTransfer.effectAllowed = 'move';
            
            // Add dragging class to source element
            e.target.classList.add('dragging');
        }

        function handleDragOver(e, file) {
            if (dragSource && file.is_dir && currentSection === 'files') {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                e.target.classList.add('drop-zone');
            }
        }

        function handleDragLeave(e, file) {
            e.target.classList.remove('drop-zone');
        }

        async function handleDrop(e, file) {
            e.preventDefault();
            e.target.classList.remove('drop-zone');
            
            if (!dragSource || !file.is_dir || currentSection !== 'files') return;
            
            if (dragSource.path === file.path) {
                showNotification('Cannot move item to itself', 'error');
                return;
            }

            try {
                const result = await apiCall('move', {
                    source_paths: [dragSource.path],
                    target_path: file.path
                });
                
                if (result.ok) {
                    showNotification(`Moved "${dragSource.name}" to "${file.name}"`);
                    listFiles(currentPath);
                } else {
                    showNotification('Move failed', 'error');
                }
            } catch (error) {
                showNotification('Move failed', 'error');
            }
        }

        function handleDragEnd(e) {
            e.target.classList.remove('dragging');
            dragSource = null;
            // Remove drop-zone classes from all elements
            document.querySelectorAll('.drop-zone').forEach(el => {
                el.classList.remove('drop-zone');
            });
        }

        // Move modal functions
        function showMoveModal() {
            if (selectedItems.size === 0) return;
            loadMoveFolders();
            document.getElementById('move-modal').classList.remove('hidden');
        }

        function hideMoveModal() {
            document.getElementById('move-modal').classList.add('hidden');
        }

        async function loadMoveFolders() {
            const moveFolders = document.getElementById('move-folders');
            moveFolders.innerHTML = '<div class="text-center py-4 text-gray-500">Loading folders...</div>';
            
            try {
                // Get all folders for moving
                const result = await apiCall('list', { type: 'files', path: '' });
                if (result.ok) {
                    const folders = result.files.filter(f => f.is_dir);
                    let html = '';
                    
                    // Add current directory option
                    html += `
                        <div class="p-2 hover:bg-gray-100 rounded cursor-pointer" onclick="selectMoveTarget('')">
                            <i class="fas fa-folder text-yellow-500 mr-2"></i>
                            <span>Current Folder</span>
                        </div>
                    `;
                    
                    // Add all subfolders
                    folders.forEach(folder => {
                        html += `
                            <div class="p-2 hover:bg-gray-100 rounded cursor-pointer" onclick="selectMoveTarget('${folder.path}')">
                                <i class="fas fa-folder text-yellow-500 mr-2"></i>
                                <span>${folder.name}</span>
                            </div>
                        `;
                    });
                    
                    moveFolders.innerHTML = html;
                }
            } catch (error) {
                moveFolders.innerHTML = '<div class="text-center py-4 text-red-500">Failed to load folders</div>';
            }
        }

        function selectMoveTarget(path) {
            moveTarget = path;
            // Highlight selected folder
            document.querySelectorAll('#move-folders > div').forEach(div => {
                div.classList.remove('bg-blue-100');
            });
            event.target.closest('div').classList.add('bg-blue-100');
        }

        async function performMove() {
            if (!moveTarget || selectedItems.size === 0) return;
            
            try {
                const result = await apiCall('move', {
                    source_paths: Array.from(selectedItems),
                    target_path: moveTarget
                });
                
                if (result.ok) {
                    showNotification(`Moved ${selectedItems.size} item(s)`);
                    hideMoveModal();
                    clearSelection();
                    listFiles(currentPath);
                } else {
                    showNotification('Move failed', 'error');
                }
            } catch (error) {
                showNotification('Move failed', 'error');
            }
        }

        // File actions (existing functions remain the same, but updated for bulk operations)
        async function toggleStar(filePath) {
            try {
                const result = await apiCall('toggle_star', { path: filePath });
                
                if (result.ok) {
                    showNotification(result.starred ? 'Added to starred' : 'Removed from starred');
                    if (currentSection === 'starred' && !result.starred) {
                        listFiles(currentPath);
                    } else {
                        // Update the specific file's star status
                        const file = filesCache.find(f => f.path === filePath);
                        if (file) {
                            file.starred = result.starred;
                            renderFiles();
                        }
                    }
                } else {
                    showNotification(result.error, 'error');
                }
            } catch (error) {
                showNotification('Failed to toggle star', 'error');
            }
        }

        async function copyImageLink(filePath) {
            try {
                const result = await apiCall('get_direct_link', { path: filePath });
                if (result.ok) {
                    await navigator.clipboard.writeText(result.url);
                    showNotification('Image link copied to clipboard!');
                } else {
                    showNotification('Failed to get image link', 'error');
                }
            } catch (error) {
                showNotification('Failed to copy link', 'error');
            }
        }

        function previewFile(filePath) {
            const file = filesCache.find(f => f.path === filePath);
            if (!file) return;

            const modal = document.getElementById('preview-modal');
            const title = document.getElementById('preview-title');
            const content = document.getElementById('preview-content');

            title.textContent = file.name;
            content.innerHTML = '<div class="text-center py-8"><div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600 mx-auto"></div><p class="mt-2 text-gray-500">Loading preview...</p></div>';
            
            modal.style.display = 'flex';

            if (file.mime.startsWith('image/')) {
                content.innerHTML = `
                    <div class="flex justify-center">
                        <img src="?action=download&path=${encodeURIComponent(filePath)}&inline=1" 
                             class="max-w-full max-h-96 object-contain rounded-lg"
                             onerror="this.style.display='none'; this.parentElement.innerHTML='<p class=\\'text-center py-8 text-gray-500\\'>Failed to load image</p>'">
                    </div>
                `;
            } else if (file.mime.startsWith('video/')) {
                content.innerHTML = `
                    <div class="flex justify-center">
                        <video controls class="max-w-full max-h-96 rounded-lg">
                            <source src="?action=download&path=${encodeURIComponent(filePath)}&inline=1" type="${file.mime}">
                            Your browser does not support the video tag.
                        </video>
                    </div>
                `;
            } else if (file.mime === 'application/pdf') {
                content.innerHTML = `
                    <div class="w-full h-96">
                        <iframe src="?action=download&path=${encodeURIComponent(filePath)}&inline=1" 
                                class="w-full h-full border-0 rounded-lg"></iframe>
                    </div>
                `;
            } else if (file.mime.startsWith('text/') || file.name.match(/\.(md|txt|php|js|json|css|html|xml|csv)$/i)) {
                fetch(`?action=download&path=${encodeURIComponent(filePath)}&inline=1`)
                    .then(response => {
                        if (!response.ok) throw new Error('Failed to load file');
                        return response.text();
                    })
                    .then(text => {
                        content.innerHTML = `
                            <div class="bg-gray-100 rounded-lg p-4 max-h-96 overflow-auto">
                                <pre class="whitespace-pre-wrap text-sm font-mono">${escapeHtml(text)}</pre>
                            </div>
                        `;
                    })
                    .catch(() => {
                        content.innerHTML = '<p class="text-center py-8 text-gray-500">Cannot preview this file type</p>';
                    });
            } else {
                content.innerHTML = `
                    <div class="text-center py-8">
                        <i class="fas fa-file text-4xl text-gray-400 mb-4"></i>
                        <p class="text-gray-500">Cannot preview this file type</p>
                        <button onclick="downloadFile('${filePath}')" class="mt-4 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition-colors">
                            Download File
                        </button>
                    </div>
                `;
            }
        }

        function closePreview() {
            document.getElementById('preview-modal').style.display = 'none';
        }

        function downloadFile(filePath) {
            window.open(`?action=download&path=${encodeURIComponent(filePath)}`, '_blank');
        }

        async function deleteFile(filePath) {
            if (!confirm('Are you sure you want to move this item to trash?')) return;
            
            try {
                const result = await apiCall('delete', { paths: [filePath] });
                
                if (result.ok) {
                    showNotification('Item moved to trash');
                    listFiles(currentPath);
                    updateStorageInfo();
                } else {
                    showNotification(result.error, 'error');
                }
            } catch (error) {
                showNotification('Delete failed', 'error');
            }
        }

        async function restoreFile(trashPath) {
            try {
                const result = await apiCall('restore', { paths: [trashPath] });
                
                if (result.ok) {
                    showNotification('Item restored successfully');
                    listFiles(currentPath);
                    updateStorageInfo();
                } else {
                    showNotification(result.error, 'error');
                }
            } catch (error) {
                showNotification('Restore failed', 'error');
            }
        }

        async function permanentDelete(trashPath) {
            if (!confirm('Are you sure you want to permanently delete this item? This action cannot be undone.')) return;
            
            try {
                const result = await apiCall('delete', { paths: [trashPath], permanent: 'true' });
                
                if (result.ok) {
                    showNotification('Item permanently deleted');
                    listFiles(currentPath);
                    updateStorageInfo();
                } else {
                    showNotification(result.error, 'error');
                }
            } catch (error) {
                showNotification('Delete failed', 'error');
            }
        }

        async function emptyTrash() {
            if (!confirm('Are you sure you want to empty the trash? This action cannot be undone.')) return;
            
            try {
                const result = await apiCall('empty_trash');
                if (result.ok) {
                    showNotification('Trash emptied successfully');
                    listFiles(currentPath);
                    updateStorageInfo();
                } else {
                    showNotification(result.error, 'error');
                }
            } catch (error) {
                showNotification('Failed to empty trash', 'error');
            }
        }

        async function createFolder() {
            if (!loggedIn) {
                showNotification('Please sign in to create folders', 'error');
                return;
            }

            const name = prompt('Enter folder name:');
            if (!name) return;

            try {
                const result = await apiCall('mkdir', { path: currentPath, name: name });
                
                if (result.ok) {
                    showNotification('Folder created successfully');
                    listFiles(currentPath);
                } else {
                    showNotification(result.error, 'error');
                }
            } catch (error) {
                showNotification('Failed to create folder', 'error');
            }
        }

        // Select all functionality
        document.getElementById('select-all').addEventListener('change', function(e) {
            if (this.checked) {
                // Select all files
                filesCache.forEach(file => {
                    selectedItems.add(file.path);
                });
            } else {
                // Deselect all
                selectedItems.clear();
            }
            updateSelectionUI();
            updateBulkActions();
        });

        // Storage functions
        async function updateStorageInfo() {
            try {
                const result = await apiCall('storage_info');
                if (result.ok) {
                    document.getElementById('storage-percent').textContent = result.used_percentage + '% used';
                    document.getElementById('storage-bar').style.width = result.used_percentage + '%';
                    document.getElementById('storage-text').textContent = 
                        result.used_gb + ' GB of ' + result.total_gb + ' GB used';
                }
            } catch (error) {
                console.error('Failed to update storage info:', error);
            }
        }

        // Utility functions
        function humanFileSize(bytes) {
            if (bytes === 0) return '0 B';
            const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
            const i = Math.floor(Math.log(bytes) / Math.log(1024));
            return (bytes / Math.pow(1024, i)).toFixed(2) + ' ' + sizes[i];
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function showNotification(message, type = 'success') {
            const notification = document.getElementById('notification');
            notification.textContent = message;
            notification.className = `notification bg-${type === 'success' ? 'green' : 'red'}-50 border border-${type === 'success' ? 'green' : 'red'}-200 text-${type === 'success' ? 'green' : 'red'}-800 show`;
            setTimeout(() => notification.classList.remove('show'), 3000);
        }

        // Modal functions
        function showLoginModal() {
            document.getElementById('login-modal').classList.remove('hidden');
        }

        function hideLoginModal() {
            document.getElementById('login-modal').classList.add('hidden');
        }

        function showUploadModal() {
            if (!loggedIn) {
                showLoginModal();
                return;
            }
            document.getElementById('upload-modal').classList.remove('hidden');
        }

        function hideUploadModal() {
            document.getElementById('upload-modal').classList.add('hidden');
        }

        // Event listeners
        document.getElementById('search').addEventListener('input', renderFiles);
        document.getElementById('sort-by').addEventListener('change', renderFiles);

        // View toggle buttons
        document.getElementById('grid-view-btn').addEventListener('click', () => setView('grid'));
        document.getElementById('list-view-btn').addEventListener('click', () => setView('list'));

        document.getElementById('login-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData();
            formData.append('action', 'login');
            formData.append('user', document.getElementById('username').value);
            formData.append('pass', document.getElementById('password').value);

            try {
                const response = await fetch('', { method: 'POST', body: formData });
                const result = await response.json();
                
                if (result.ok) {
                    location.reload();
                } else {
                    showNotification(result.error, 'error');
                }
            } catch (error) {
                showNotification('Login failed', 'error');
            }
        });

        // File upload handling
        document.getElementById('file-input').addEventListener('change', handleFileUpload);
        
        const dropZone = document.getElementById('drop-zone');
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            dropZone.addEventListener(eventName, preventDefaults, false);
        });

        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }

        ['dragenter', 'dragover'].forEach(eventName => {
            dropZone.addEventListener(eventName, () => dropZone.classList.add('drag-over'), false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            dropZone.addEventListener(eventName, () => dropZone.classList.remove('drag-over'), false);
        });

        dropZone.addEventListener('drop', handleDrop, false);

        function handleFileUpload(e) {
            const files = e.target.files;
            handleFiles(files);
        }

        async function handleFiles(files) {
            const formData = new FormData();
            formData.append('path', currentPath);
            
            for (let file of files) {
                formData.append('file[]', file);
            }

            try {
                const result = await apiCall('upload', formData);
                if (result.ok) {
                    showNotification(`Successfully uploaded ${result.uploaded.length} file(s)`);
                    hideUploadModal();
                    listFiles(currentPath);
                    updateStorageInfo();
                } else {
                    showNotification(result.error, 'error');
                }
            } catch (error) {
                showNotification('Upload failed', 'error');
            }
        }

        // Close preview when clicking overlay
        document.getElementById('preview-modal').addEventListener('click', (e) => {
            if (e.target.id === 'preview-modal') {
                closePreview();
            }
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            // Ctrl+A to select all
            if ((e.ctrlKey || e.metaKey) && e.key === 'a') {
                e.preventDefault();
                filesCache.forEach(file => {
                    selectedItems.add(file.path);
                });
                updateSelectionUI();
                updateBulkActions();
            }
            
            // Escape to clear selection
            if (e.key === 'Escape') {
                clearSelection();
            }
            
            // Delete to move to trash
            if (e.key === 'Delete' && selectedItems.size > 0 && loggedIn) {
                bulkDelete();
            }
        });

        // Initialize
        loadSection('files', '');
        setView('list'); // Set default view to grid
    </script>
</body>
</html>
