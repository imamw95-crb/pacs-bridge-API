@echo off
rem Wrapper Windows Task Scheduler (dipakai lewat pull-santesrv1-hidden.vbs).
rem Menjalankan tarikan study SANTESRV1 -> Orthanc tiap menit dan menulis log.
php "%~dp0pull-santesrv1.php" --date=today --quiet >> "%~dp0..\data\pull-windows.log" 2>&1
