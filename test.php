<?php
// test_connection.php
try {
    $pdo = new PDO(
        "mysql:host=localhost;dbname=u82671;charset=utf8mb4",
        "u82671",
        "1266050"
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "✅ Подключение успешно!<br>";
    
    // Проверка таблиц
    $stmt = $pdo->query("SHOW TABLES");
    echo "Таблицы в базе данных:<br>";
    while ($row = $stmt->fetch()) {
        echo "- " . $row[0] . "<br>";
    }
    
} catch (PDOException $e) {
    echo "❌ Ошибка: " . $e->getMessage();
}
?>
