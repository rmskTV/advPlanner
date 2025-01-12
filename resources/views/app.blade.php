
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{env('APP_NAME')}} loading</title>
    <link href="https://fonts.cdnfonts.com/css/lato" rel="stylesheet">
    @vite('resources/sass/app.scss')
</head>
<body>
<div id="app">
</div>

@vite('resources/js/app.js')
</body>
</html>
