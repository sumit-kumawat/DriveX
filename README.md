DriveX File Manager
===================

A modern, feature-rich file management system built with PHP and Tailwind CSS.

[https://img.shields.io/badge/DriveX-File%20Manager-blue](https://img.shields.io/badge/DriveX-File%20Manager-blue)[https://img.shields.io/badge/PHP-7.4%2B-purple](https://img.shields.io/badge/PHP-7.4%2B-purple)[https://img.shields.io/badge/Tailwind-CSS-green](https://img.shields.io/badge/Tailwind-CSS-green)

🚀 Quick Start
--------------

### Installation

1.  Upload index.php to your web server
    
2.  Create a writable files directory
    
3.  Ensure PHP has write permissions
    
4.  Access via your web browser
    

### Default Login

*   **Username**: admin
    
*   **Password**: admin
    

⚠️ **Change password in production!**

✨ Features
----------

### 📁 Core Features

*   ✅ File upload (drag & drop)
    
*   ✅ Folder creation
    
*   ✅ File preview (images, PDFs, videos, text)
    
*   ✅ Download files
    
*   ✅ Copy direct links for images
    
*   ✅ Bulk operations
    

### 🎨 Interface

*   ✅ Dual view modes (Grid & List)
    
*   ✅ Responsive design
    
*   ✅ Dark/light theme
    
*   ✅ Breadcrumb navigation
    
*   ✅ Search functionality
    

### 🔐 Security

*   ✅ User authentication
    
*   ✅ Public/private access control
    
*   ✅ Secure path validation
    
*   ✅ Session management
    

### ⭐ Advanced Features

*   ✅ Starred items
    
*   ✅ Recent files tracking
    
*   ✅ Trash system (30-day retention)
    
*   ✅ Storage usage tracking
    
*   ✅ Drag & drop file moving
    

🛠️ Configuration
-----------------

### Basic Setup

php

Plain textANTLR4BashCC#CSSCoffeeScriptCMakeDartDjangoDockerEJSErlangGitGoGraphQLGroovyHTMLJavaJavaScriptJSONJSXKotlinLaTeXLessLuaMakefileMarkdownMATLABMarkupObjective-CPerlPHPPowerShell.propertiesProtocol BuffersPythonRRubySass (Sass)Sass (Scss)SchemeSQLShellSwiftSVGTSXTypeScriptWebAssemblyYAMLXML`   // In index.php - Update these values:  define('ROOT_DIR', __DIR__ . '/files');  define('MAX_FILE_SIZE', 500 * 1024 * 1024); // 500MB  $USERS = [      'admin' => password_hash('your-new-password', PASSWORD_DEFAULT)  ];  $PUBLIC_ACCESS = true; // Set false to require login for viewing   `

### Requirements

*   PHP 7.4+
    
*   Web server (Apache/Nginx)
    
*   Write permissions
    
*   GD extension (for thumbnails)
    
*   Zip extension
    

🎯 Usage
--------

### File Operations

*   **Upload**: Drag files or click Upload button
    
*   **Create Folder**: Click "New folder" button
    
*   **Navigate**: Click folders or use breadcrumbs
    
*   **Preview**: Click files to view in browser
    
*   **Download**: Use Download button
    

### Selection Methods

*   **Single click**: Select item
    
*   **Ctrl+Click**: Multi-select
    
*   **Shift+Click**: Range select
    
*   **Ctrl+A**: Select all
    

### View Modes

*   **List View** (Default): Detailed table layout
    
*   **Grid View**: Visual card layout with thumbnails
    

⌨️ Keyboard Shortcuts
---------------------

ShortcutActionCtrl+ASelect all itemsDeleteMove to trashEscapeClear selectionCtrl+ClickMulti-selectShift+ClickRange select

🔧 API Endpoints
----------------

### File Operations

text

Plain textANTLR4BashCC#CSSCoffeeScriptCMakeDartDjangoDockerEJSErlangGitGoGraphQLGroovyHTMLJavaJavaScriptJSONJSXKotlinLaTeXLessLuaMakefileMarkdownMATLABMarkupObjective-CPerlPHPPowerShell.propertiesProtocol BuffersPythonRRubySass (Sass)Sass (Scss)SchemeSQLShellSwiftSVGTSXTypeScriptWebAssemblyYAMLXML`   ?action=list          - List directory  ?action=upload        - Upload files    ?action=mkdir         - Create folder  ?action=delete        - Delete files  ?action=move          - Move files  ?action=download      - Download file   `

### Advanced Features

text

Plain textANTLR4BashCC#CSSCoffeeScriptCMakeDartDjangoDockerEJSErlangGitGoGraphQLGroovyHTMLJavaJavaScriptJSONJSXKotlinLaTeXLessLuaMakefileMarkdownMATLABMarkupObjective-CPerlPHPPowerShell.propertiesProtocol BuffersPythonRRubySass (Sass)Sass (Scss)SchemeSQLShellSwiftSVGTSXTypeScriptWebAssemblyYAMLXML`   ?action=toggle_star   - Star/unstar  ?action=restore       - Restore from trash  ?action=empty_trash   - Empty trash  ?action=get_direct_link - Get shareable URL  ?action=storage_info  - Get storage usage   `

🗂️ Directory Structure
-----------------------

text

Plain textANTLR4BashCC#CSSCoffeeScriptCMakeDartDjangoDockerEJSErlangGitGoGraphQLGroovyHTMLJavaJavaScriptJSONJSXKotlinLaTeXLessLuaMakefileMarkdownMATLABMarkupObjective-CPerlPHPPowerShell.propertiesProtocol BuffersPythonRRubySass (Sass)Sass (Scss)SchemeSQLShellSwiftSVGTSXTypeScriptWebAssemblyYAMLXML`   your-server/  ├── index.php          # Main application file  ├── files/            # User files directory  ├── trash/            # Trash storage  └── .cache/      ├── thumbs/       # Image thumbnails      └── starred.json  # Starred items data   `

🔒 Security Notes
-----------------

*   All file paths are validated
    
*   Authentication required for upload/delete
    
*   Session-based security
    
*   MIME type validation
    
*   File size limits enforced
    

🐛 Troubleshooting
------------------

### Common Issues

1.  **Files not appearing**: Check directory permissions (755)
    
2.  **Upload failures**: Check PHP upload limits
    
3.  **Thumbnails not working**: Enable GD extension
    
4.  **Login problems**: Verify password hashes
    

### Permission Setup

bash

Plain textANTLR4BashCC#CSSCoffeeScriptCMakeDartDjangoDockerEJSErlangGitGoGraphQLGroovyHTMLJavaJavaScriptJSONJSXKotlinLaTeXLessLuaMakefileMarkdownMATLABMarkupObjective-CPerlPHPPowerShell.propertiesProtocol BuffersPythonRRubySass (Sass)Sass (Scss)SchemeSQLShellSwiftSVGTSXTypeScriptWebAssemblyYAMLXML`   chmod 755 files/  chmod 755 trash/   chmod 755 .cache/   `

### PHP Settings

ini

Plain textANTLR4BashCC#CSSCoffeeScriptCMakeDartDjangoDockerEJSErlangGitGoGraphQLGroovyHTMLJavaJavaScriptJSONJSXKotlinLaTeXLessLuaMakefileMarkdownMATLABMarkupObjective-CPerlPHPPowerShell.propertiesProtocol BuffersPythonRRubySass (Sass)Sass (Scss)SchemeSQLShellSwiftSVGTSXTypeScriptWebAssemblyYAMLXML`   upload_max_filesize = 500M  post_max_size = 500M  max_execution_time = 300   `

📊 Features Overview
--------------------

FeatureStatusDescriptionFile Upload✅Drag & drop supportFolder Management✅Create nested foldersFile Preview✅Images, PDFs, videos, textBulk Operations✅Select multiple itemsSearch✅Real-time filteringThumbnails✅Auto-generated for imagesTrash System✅30-day retentionUser Authentication✅Secure login systemResponsive Design✅Mobile-friendly

🔄 Maintenance
--------------

### Regular Tasks

*   Monitor storage usage
    
*   Review trash contents
    
*   Update passwords periodically
    
*   Check server logs
    

### Backup Recommended

*   Backup files/ directory regularly
    
*   Backup .cache/ for user data
