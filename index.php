<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Настройки подключения к БД
$host = 'localhost';
$dbname = 'u82671';        // ваша база данных
$username = 'u82671';      // ваш логин
$password = '1266050';   // ваш пароль

$errors = [];
$success = false;
$formData = [];

// Функция для безопасного получения POST данных
function getPostValue($key, $default = '') {
    return isset($_POST[$key]) ? trim($_POST[$key]) : $default;
}

// Подключение к базе данных
try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (PDOException $e) {
    die("Ошибка подключения к базе данных: " . $e->getMessage());
}

// Функции валидации
function validateFullname($fullname) {
    if (empty($fullname)) {
        return 'ФИО обязательно для заполнения';
    }
    if (strlen($fullname) > 150) {
        return 'ФИО не должно превышать 150 символов';
    }
    if (!preg_match('/^[a-zA-Zа-яА-ЯёЁ\s\-]+$/u', $fullname)) {
        return 'ФИО может содержать только буквы, пробелы и дефисы';
    }
    return null;
}

function validatePhone($phone) {
    if (!empty($phone)) {
        if (strlen($phone) > 50) {
            return 'Телефон не должен превышать 50 символов';
        }
        if (!preg_match('/^[\+\d\s\-\(\)]+$/', $phone)) {
            return 'Некорректный формат телефона';
        }
    }
    return null;
}

function validateEmail($email) {
    if (empty($email)) {
        return 'E-mail обязателен для заполнения';
    }
    if (strlen($email) > 100) {
        return 'E-mail не должен превышать 100 символов';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'Некорректный формат e-mail';
    }
    return null;
}

function validateBirthdate($birthdate) {
    if (!empty($birthdate)) {
        $date = DateTime::createFromFormat('Y-m-d', $birthdate);
        if (!$date || $date->format('Y-m-d') !== $birthdate) {
            return 'Некорректная дата рождения';
        }
        if ($date > new DateTime()) {
            return 'Дата рождения не может быть в будущем';
        }
    }
    return null;
}

function validateGender($gender) {
    $allowed = ['male', 'female', 'other', 'unspecified'];
    if (!in_array($gender, $allowed)) {
        return 'Некорректное значение пола';
    }
    return null;
}

function validateLanguages($languages, $pdo) {
    if (empty($languages)) {
        return 'Выберите хотя бы один язык программирования';
    }
    if (count($languages) > 12) {
        return 'Выбрано слишком много языков';
    }
    
    // Проверяем, что все выбранные языки существуют в БД
    $placeholders = str_repeat('?,', count($languages) - 1) . '?';
    $stmt = $pdo->prepare("SELECT id FROM programming_languages WHERE name IN ($placeholders)");
    $stmt->execute($languages);
    $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (count($existing) != count($languages)) {
        return 'Один или несколько выбранных языков не поддерживаются';
    }
    return null;
}

function validateBiography($bio) {
    if (!empty($bio)) {
        if (strlen($bio) > 10000) {
            return 'Биография не должна превышать 10000 символов';
        }
    }
    return null;
}

function validateContract($contract) {
    if ($contract != 'on' && $contract != '1' && $contract !== true) {
        return 'Необходимо подтвердить ознакомление с контрактом';
    }
    return null;
}

// Обработка отправки формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Сбор данных
    $formData = [
        'fullname' => getPostValue('fullname'),
        'phone' => getPostValue('phone'),
        'email' => getPostValue('email'),
        'birthdate' => getPostValue('birthdate'),
        'gender' => getPostValue('gender', 'unspecified'),
        'languages' => isset($_POST['fav_langs']) ? $_POST['fav_langs'] : [],
        'biography' => getPostValue('bio'),
        'contract' => isset($_POST['contract_agreed']) ? $_POST['contract_agreed'] : ''
    ];
    
    // Валидация всех полей
    $errors['fullname'] = validateFullname($formData['fullname']);
    $errors['phone'] = validatePhone($formData['phone']);
    $errors['email'] = validateEmail($formData['email']);
    $errors['birthdate'] = validateBirthdate($formData['birthdate']);
    $errors['gender'] = validateGender($formData['gender']);
    $errors['languages'] = validateLanguages($formData['languages'], $pdo);
    $errors['biography'] = validateBiography($formData['biography']);
    $errors['contract'] = validateContract($formData['contract']);
    
    // Фильтруем ошибки (убираем null значения)
    $errors = array_filter($errors);
    
    // Если ошибок нет - сохраняем в БД
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            // Вставка в таблицу applications
            $stmt = $pdo->prepare("
                INSERT INTO applications (fullname, phone, email, birthdate, gender, biography, contract_agreed)
                VALUES (:fullname, :phone, :email, :birthdate, :gender, :biography, :contract)
            ");
            
            $stmt->execute([
                ':fullname' => $formData['fullname'],
                ':phone' => $formData['phone'] ?: null,
                ':email' => $formData['email'],
                ':birthdate' => $formData['birthdate'] ?: null,
                ':gender' => $formData['gender'],
                ':biography' => $formData['biography'] ?: null,
                ':contract' => $formData['contract'] == 'on' ? 1 : 0
            ]);
            
            $applicationId = $pdo->lastInsertId();
            
            // Вставка языков программирования
            $stmtLang = $pdo->prepare("SELECT id FROM programming_languages WHERE name = ?");
            $stmtInsert = $pdo->prepare("
                INSERT INTO application_languages (application_id, language_id)
                VALUES (?, ?)
            ");
            
            foreach ($formData['languages'] as $langName) {
                $stmtLang->execute([$langName]);
                $langId = $stmtLang->fetchColumn();
                if ($langId) {
                    $stmtInsert->execute([$applicationId, $langId]);
                }
            }
            
            $pdo->commit();
            $success = true;
            
            // Очищаем данные формы после успешного сохранения
            $formData = [];
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors['db'] = 'Ошибка сохранения данных: ' . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Результат сохранения анкеты</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: linear-gradient(145deg, #e0eaf4 0%, #cfdef3 100%);
            font-family: 'Segoe UI', 'Roboto', system-ui, sans-serif;
            padding: 2rem 1.5rem;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        
        .result-container {
            max-width: 800px;
            width: 100%;
            background: white;
            border-radius: 2rem;
            box-shadow: 0 25px 45px -12px rgba(0, 0, 0, 0.35);
            overflow: hidden;
        }
        
        .result-header {
            background: linear-gradient(135deg, #1a2a3f, #0f1a2a);
            padding: 2rem;
            color: white;
        }
        
        .result-header h1 {
            font-size: 1.8rem;
            margin-bottom: 0.5rem;
        }
        
        .result-body {
            padding: 2rem;
        }
        
        .alert {
            padding: 1rem 1.2rem;
            border-radius: 1rem;
            margin-bottom: 1.5rem;
            font-weight: 500;
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
        
        .error-list {
            background: #fff5f5;
            border-left: 4px solid #dc2626;
            padding: 1rem;
            margin-bottom: 1.5rem;
            border-radius: 0.5rem;
        }
        
        .error-list ul {
            margin-left: 1.5rem;
            color: #dc2626;
        }
        
        .back-link {
            display: inline-block;
            margin-top: 1rem;
            color: #3b82f6;
            text-decoration: none;
            font-weight: 500;
        }
        
        .back-link:hover {
            text-decoration: underline;
        }
        
        .data-preview {
            background: #f8fafc;
            padding: 1rem;
            border-radius: 0.5rem;
            margin-top: 1rem;
            font-family: monospace;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
<div class="result-container">
    <div class="result-header">
        <h1>📋 Результат обработки</h1>
        <p>Серверная валидация и сохранение в базу данных u82671</p>
    </div>
    
    <div class="result-body">
        <?php if ($success): ?>
            <div class="alert alert-success">
                ✅ Данные успешно сохранены в базу данных <strong><?php echo htmlspecialchars($dbname); ?></strong>!
            </div>
            <p>Ваша анкета была успешно обработана и сохранена. Спасибо за регистрацию!</p>
            <a href="index.html" class="back-link">← Заполнить новую анкету</a>
            
        <?php elseif (!empty($errors)): ?>
            <div class="alert alert-error">
                ❌ При обработке формы обнаружены ошибки
            </div>
            
            <div class="error-list">
                <strong>Пожалуйста, исправьте следующие ошибки:</strong>
                <ul>
                    <?php foreach ($errors as $field => $error): ?>
                        <?php if ($field != 'db'): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ul>
            </div>
            
            <?php if (isset($errors['db'])): ?>
                <div class="alert alert-error">
                    💾 Ошибка базы данных: <?php echo htmlspecialchars($errors['db']); ?>
                </div>
            <?php endif; ?>
            
            <a href="index.html" class="back-link">← Вернуться к форме и исправить ошибки</a>
            
            <!-- Отображение отправленных данных для отладки -->
            <div class="data-preview">
                <strong>Отправленные данные:</strong><br>
                <?php foreach ($formData as $key => $value): ?>
                    <?php if (is_array($value)): ?>
                        <?php echo htmlspecialchars($key); ?>: <?php echo implode(', ', $value); ?><br>
                    <?php else: ?>
                        <?php echo htmlspecialchars($key); ?>: <?php echo htmlspecialchars($value); ?><br>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
