<!-- resources/views/bitrix/install.blade.php -->
<!DOCTYPE html>
<html>
<head>
    <script src="https://api.bitrix24.com/api/v1/"></script>
</head>
<body>
<script>
    BX24.init(function(){
        BX24.installFinish(); // Сообщаем Б24, что установка завершена
    });
</script>
<p>Приложение установлено!</p>
</body>
</html>
