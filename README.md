# DriveX File Manager

A modern, feature-rich file management system built with PHP and Tailwind CSS.

![DriveX](https://img.shields.io/badge/DriveX-File%20Manager-blue)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple)
![Tailwind](https://img.shields.io/badge/Tailwind-CSS-green)

## 🚀 Quick Start


1.  Upload `index.php` to web server
    
2.  Create `files` directory
    
3.  Set permissions: `chmod 755 files/ trash/ .cache/`
    
4.  Access via browser
    

Default Login: admin / admin

## Features

*   File upload (drag & drop)
    
*   Folder creation & management
    
*   Dual view modes (List/Grid)
    
*   File preview (images, PDFs, videos, text)
    
*   Bulk operations
    
*   Search functionality
    
*   Starred items
    
*   Recent files tracking
    
*   Trash system (30-day retention)
    
*   Storage tracking
    
*   User authentication
    
*   Responsive design
    
*   Keyboard shortcuts
    
*   Drag & drop moving
    
*   Direct image links
    

## Configuration

php

// In index.php
define('ROOT\_DIR', \_\_DIR\_\_ . '/files');
define('MAX\_FILE\_SIZE', 500 \* 1024 \* 1024);
define('TRASH\_RETENTION\_DAYS', 30);

$USERS \= \[
    'admin' \=> password\_hash('new-password', PASSWORD\_DEFAULT)
\];

$PUBLIC\_ACCESS \= true; // Set false for private

## Requirements

*   PHP 7.4+
    
*   GD extension
    
*   Zip extension
    
*   Write permissions
    

## Usage

*   Upload: Drag files or click Upload
    
*   Create Folder: Click "New folder"
    
*   Navigate: Click folders
    
*   Preview: Click files
    
*   Select: Click items, Ctrl+Click for multi, Shift+Click for range
    
*   View Toggle: Use grid/list buttons
    

## Keyboard Shortcuts

*   `Ctrl+A` - Select all
    
*   `Delete` - Move to trash
    
*   `Escape` - Clear selection
    
*   `Ctrl+Click` - Multi-select
    
*   `Shift+Click` - Range select
    

## API Endpoints

text

?action=list          - List directory
?action=upload        - Upload files
?action=mkdir         - Create folder
?action=delete        - Delete files
?action=move          - Move files
?action=download      - Download file
?action=toggle\_star   - Star/unstar
?action=restore       - Restore from trash
?action=empty\_trash   - Empty trash
?action=get\_direct\_link - Shareable URL
?action=storage\_info  - Storage usage

## Default List View Code Change

In `index.php`, change:

javascript

// From:
let currentView \= 'grid';
setView('grid');

// To:
let currentView \= 'list';
setView('list');

## Troubleshooting

*   Files not showing: Check permissions (755)
    
*   Upload fails: Check PHP upload limits
    
*   Thumbnails not working: Enable GD extension
    
*   Login issues: Verify password hashes
    

## File Structure

text

index.php
files/
trash/
.cache/
  thumbs/
  starred.json
  recent.json

## Security

*   Path validation
    
*   Session authentication
    
*   MIME type validation
    
*   File size limits
    
*   Secure operations
