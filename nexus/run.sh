#!/bin/bash
# Starts the website with bigger upload limits (PHP's default is only 2 MB).
cd "$(dirname "$0")"
echo "Open http://localhost:8000/   (press Ctrl+C to stop)"
php -d upload_max_filesize=20M -d post_max_size=100M -d max_file_uploads=10 -S localhost:8000
