@echo off
chcp 65001 >nul
set KEY=C:\Users\narak\Desktop\social9-key.pem
set EC2=ec2-user@54.95.86.79
set ROOT=c:\xampp\htdocs\nine

echo Uploading api\ai.php ...
scp -i "%KEY%" "%ROOT%\api\ai.php" %EC2%:/var/www/html/api/
if errorlevel 1 goto err

echo Uploading includes\ai_file_reader.php ...
scp -i "%KEY%" "%ROOT%\includes\ai_file_reader.php" %EC2%:/var/www/html/includes/
if errorlevel 1 goto err

echo Uploading includes\chat\scripts.php ...
scp -i "%KEY%" "%ROOT%\includes\chat\scripts.php" %EC2%:/var/www/html/includes/chat/
if errorlevel 1 goto err

echo Uploading api\notifications.php ...
scp -i "%KEY%" "%ROOT%\api\notifications.php" %EC2%:/var/www/html/api/
if errorlevel 1 goto err

echo Uploading assets\js\error-collector.js ...
scp -i "%KEY%" "%ROOT%\assets\js\error-collector.js" %EC2%:/var/www/html/assets/js/
if errorlevel 1 goto err

echo Uploading assets\js\push-notifications.js ...
scp -i "%KEY%" "%ROOT%\assets\js\push-notifications.js" %EC2%:/var/www/html/assets/js/
if errorlevel 1 goto err

echo Uploading includes\chat\scripts.php ...
scp -i "%KEY%" "%ROOT%\includes\chat\scripts.php" %EC2%:/var/www/html/includes/chat/
if errorlevel 1 goto err

echo Uploading assets\css\chat-main.css ...
scp -i "%KEY%" "%ROOT%\assets\css\chat-main.css" %EC2%:/var/www/html/assets/css/
if errorlevel 1 goto err

echo Uploading chat.php ...
scp -i "%KEY%" "%ROOT%\chat.php" %EC2%:/var/www/html/
if errorlevel 1 goto err

echo Uploading admin\storage_billing.php ...
scp -i "%KEY%" "%ROOT%\admin\storage_billing.php" %EC2%:/var/www/html/admin/
if errorlevel 1 goto err

echo Done. 10 file(s) uploaded.
exit /b 0

:err
echo scp failed.
exit /b 1
