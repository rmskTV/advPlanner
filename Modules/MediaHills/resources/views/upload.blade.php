<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>MediaHills - Загрузка данных телесмотрения</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            padding: 40px;
            max-width: 600px;
            width: 100%;
        }

        h1 {
            color: #333;
            margin-bottom: 10px;
            font-size: 28px;
        }

        .subtitle {
            color: #666;
            margin-bottom: 30px;
            font-size: 14px;
        }

        .upload-area {
            border: 3px dashed #667eea;
            border-radius: 10px;
            padding: 40px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            background: #f8f9ff;
        }

        .upload-area:hover {
            border-color: #764ba2;
            background: #f0f2ff;
        }

        .upload-area.dragover {
            border-color: #764ba2;
            background: #e8ebff;
            transform: scale(1.02);
        }

        .upload-icon {
            font-size: 48px;
            margin-bottom: 15px;
        }

        .upload-text {
            color: #333;
            font-size: 16px;
            margin-bottom: 10px;
        }

        .upload-hint {
            color: #999;
            font-size: 12px;
        }

        .file-input {
            display: none;
        }

        .btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 25px;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.3s;
            margin-top: 20px;
            width: 100%;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.4);
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .selected-file {
            margin-top: 20px;
            padding: 15px;
            background: #e8f5e9;
            border-radius: 10px;
            display: none;
        }

        .selected-file.show {
            display: block;
        }

        .selected-file-name {
            color: #2e7d32;
            font-weight: 500;
        }

        .alert {
            padding: 15px;
            border-radius: 10px;
            margin-top: 20px;
            display: none;
        }

        .alert.show {
            display: block;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .stats {
            margin-top: 15px;
            padding: 15px;
            background: #fff;
            border-radius: 8px;
            border: 1px solid #c3e6cb;
        }

        .stats-item {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
        }

        .stats-label {
            color: #666;
        }

        .stats-value {
            font-weight: 600;
            color: #333;
        }

        .loader {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #667eea;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 20px auto;
            display: none;
        }

        .loader.show {
            display: block;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
<div class="container">
    <h1>📊 MediaHills</h1>
    <p class="subtitle">Загрузка данных о телесмотрении</p>

    <div class="upload-area" id="uploadArea">
        <div class="upload-icon">📁</div>
        <div class="upload-text">Перетащите файл сюда или нажмите для выбора</div>
        <div class="upload-hint">Поддерживаются форматы: .xlsx, .xls (макс. 10MB)</div>
    </div>

    <input type="file" class="file-input" id="fileInput" accept=".xlsx,.xls">

    <div class="selected-file" id="selectedFile">
        <strong>Выбран файл:</strong> <span class="selected-file-name" id="fileName"></span>
    </div>

    <button class="btn" id="uploadBtn" disabled>Загрузить данные</button>

    <div class="loader" id="loader"></div>

    <div class="alert" id="alert"></div>
</div>

<script>
    const uploadArea = document.getElementById('uploadArea');
    const fileInput = document.getElementById('fileInput');
    const uploadBtn = document.getElementById('uploadBtn');
    const selectedFile = document.getElementById('selectedFile');
    const fileName = document.getElementById('fileName');
    const alert = document.getElementById('alert');
    const loader = document.getElementById('loader');

    let selectedFileData = null;

    // Клик по области загрузки
    uploadArea.addEventListener('click', () => fileInput.click());

    // Выбор файла
    fileInput.addEventListener('change', (e) => {
        handleFileSelect(e.target.files[0]);
    });

    // Drag & Drop
    uploadArea.addEventListener('dragover', (e) => {
        e.preventDefault();
        uploadArea.classList.add('dragover');
    });

    uploadArea.addEventListener('dragleave', () => {
        uploadArea.classList.remove('dragover');
    });

    uploadArea.addEventListener('drop', (e) => {
        e.preventDefault();
        uploadArea.classList.remove('dragover');
        handleFileSelect(e.dataTransfer.files[0]);
    });

    function handleFileSelect(file) {
        if (!file) return;

        if (!file.name.match(/\.(xlsx|xls)$/i)) {
            showAlert('Пожалуйста, выберите файл Excel (.xlsx или .xls)', 'error');
            return;
        }

        selectedFileData = file;
        fileName.textContent = file.name;
        selectedFile.classList.add('show');
        uploadBtn.disabled = false;
        alert.classList.remove('show');
    }

    // Загрузка файла
    uploadBtn.addEventListener('click', async () => {
        if (!selectedFileData) return;

        const formData = new FormData();
        formData.append('file', selectedFileData);

        uploadBtn.disabled = true;
        loader.classList.add('show');
        alert.classList.remove('show');

        try {
            const response = await fetch('{{ route('mediahills.upload') }}', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: formData
            });

            const data = await response.json();

            if (data.success) {
                showAlert('Данные успешно загружены!', 'success', data.stats);
                resetForm();
            } else {
                showAlert(data.message || 'Ошибка при загрузке файла', 'error');
                uploadBtn.disabled = false;
            }
        } catch (error) {
            showAlert('Ошибка при отправке файла: ' + error.message, 'error');
            uploadBtn.disabled = false;
        } finally {
            loader.classList.remove('show');
        }
    });

    function showAlert(message, type, stats = null) {
        alert.className = `alert alert-${type} show`;

        let html = `<strong>${message}</strong>`;

        if (stats) {
            html += '<div class="stats">';
            html += `<div class="stats-item"><span class="stats-label">Обработано записей:</span><span class="stats-value">${stats.processed}</span></div>`;
            html += `<div class="stats-item"><span class="stats-label">Создано новых:</span><span class="stats-value">${stats.created}</span></div>`;
            html += `<div class="stats-item"><span class="stats-label">Обновлено:</span><span class="stats-value">${stats.updated}</span></div>`;
            html += `<div class="stats-item"><span class="stats-label">Ошибок:</span><span class="stats-value">${stats.errors}</span></div>`;
            if (stats.channels && stats.channels.length > 0) {
                html += `<div class="stats-item"><span class="stats-label">Каналов:</span><span class="stats-value">${stats.channels.join(', ')}</span></div>`;
            }
            html += '</div>';
        }

        alert.innerHTML = html;
    }

    function resetForm() {
        selectedFileData = null;
        fileInput.value = '';
        selectedFile.classList.remove('show');
        uploadBtn.disabled = true;
    }
</script>
</body>
</html>
