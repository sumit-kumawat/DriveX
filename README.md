# DriveX File Manager

A modern, feature-rich file management system built with PHP and Tailwind CSS.

![DriveX](https://img.shields.io/badge/DriveX-File%20Manager-blue)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple)
![Tailwind](https://img.shields.io/badge/Tailwind-CSS-green)

## 🚀 Quick Start

### Installation
1. Upload `index.php` to your web server
2. Create a writable `files` directory
3. Ensure PHP has write permissions
4. Access via your web browser

### Default Login
- **Username**: `admin`
- **Password**: `admin`

⚠️ **Change the default password in production!**

## ✨ Features

### 📁 Core File Management
- File upload (drag & drop support)
- Folder creation and management  
- File preview (images, PDFs, videos, text files)
- Download files
- Copy direct links for images
- Bulk operations
- Search functionality

### 🎨 Dual View Modes
- **List View** (Default) - Detailed table layout
- **Grid View** - Visual card layout with thumbnails
- Easy toggle between views

### 🔐 Security & Access
- User authentication system
- Public/private access control
- Secure path validation
- Session management

### ⭐ Advanced Features
- Starred items system
- Recent files tracking
- Trash system (30-day auto cleanup)
- Storage usage tracking
- Drag & drop file moving
- Keyboard shortcuts

## 🛠️ Configuration

### Basic Setup
Edit these values in `index.php`:

```php
define('ROOT_DIR', __DIR__ . '/files');
define('MAX_FILE_SIZE', 500 * 1024 * 1024); // 500MB

$USERS = [
    'admin' => password_hash('your-new-password', PASSWORD_DEFAULT)
];

$PUBLIC_ACCESS = true; // Set to false to require login for viewing
