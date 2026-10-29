<?php
require_once 'header.php'; // include / require কাজ করবে!

echo "<h1>স্বাগতম!</h1>";
echo "<p>এই সাইটটি GitHub Pages এ <strong>PHP-WASM</strong> দিয়ে ক্লায়েন্ট সাইডে পার্স হচ্ছে।</p>";

$items = ['PHP 8.2', 'WebAssembly', 'GitHub Pages', 'Zero Server Cost'];
echo "<ul>";
foreach ($items as $item) {
    echo "<li>" . htmlspecialchars($item) . "</li>";
}
echo "</ul>";

echo "<hr><small>Server Time: " . date('Y-m-d H:i:s') . "</small>";
