@echo off
cd /d E:\work\portofolio\aalyk\back
"C:\Users\karen o_o\AppData\Local\Temp\php83\php.exe" -n -d extension_dir="C:\Users\karen o_o\AppData\Local\Temp\php83\ext" -d extension=php_curl.dll -d extension=php_openssl.dll -d extension=php_mbstring.dll -d extension=php_fileinfo.dll -d extension=php_pdo_sqlite.dll -d extension=php_sqlite3.dll -S 0.0.0.0:8000 server.php
