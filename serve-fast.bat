@echo off
REM Starts the API on http://127.0.0.1:8000 with PHP's OPcache switched on.
REM Plain "php artisan serve" runs without OPcache on this machine, so every request recompiles the whole
REM framework (about 1 second). With OPcache the same requests take about 50 ms.
REM Code edits are picked up within 2 seconds. Same server as "artisan serve" (runs from the public folder).
cd /d "%~dp0public"
php -d zend_extension=opcache -d opcache.enable=1 -d opcache.enable_cli=1 -d opcache.memory_consumption=192 -d opcache.max_accelerated_files=20000 -d opcache.validate_timestamps=1 -d opcache.revalidate_freq=2 -S 127.0.0.1:8000 ..\vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php