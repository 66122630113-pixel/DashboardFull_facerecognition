<?php
// sync_all_users_v2.php - สูตรแก้เครื่องกั๊กข้อมูล (ดึงทีละ 30 ก็ไม่หวั่น)
ini_set('display_errors', 1);
error_reporting(E_ALL);
set_time_limit(600); // ให้เวลาทำงานนานๆ (10 นาที) เพราะคนเยอะ

require_once '../config/db.php'; 

// 🔧 ตั้งค่าเครื่องเป้าหมาย (40.2 หรือ 40.3)
$device_ip   = "192.168.40.2"; 
$device_user = "admin";
$device_pass = "2023!!Chang";

echo "<h2>👥 กำลังดึงรายชื่อพนักงานแบบ 'ไม่ครบไม่เลิก' (Target: $device_ip)...</h2>";
echo "<hr>";

$url = "http://$device_ip/ISAPI/AccessControl/UserInfo/Search?format=json";

$next_position = 0;
$total_saved = 0;
$round = 1;

while (true) {
    
    // ขอไปทีละ 100 (แต่เดี๋ยวมันก็ส่งมาแค่ 30 แหละครับ แต่เราไม่แคร์)
    $postData = json_encode([
        "UserInfoSearchCond" => [
            "searchID" => "GetAllUsers_Fix30", 
            "searchResultPosition" => $next_position,
            "maxResults" => 100 
        ]
    ]);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
    curl_setopt($ch, CURLOPT_USERPWD, "$device_user:$device_pass");
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode != 200) {
        echo "<p style='color:red'>❌ รอบที่ $round เกิดข้อผิดพลาด (HTTP $httpCode) - หยุดทำงาน</p>";
        break;
    }

    $data = json_decode($response, true);

    // ตรวจสอบว่ามีข้อมูลส่งมาไหม
    if (isset($data['UserInfoSearch']['UserInfo']) && !empty($data['UserInfoSearch']['UserInfo'])) {
        
        $users_in_batch = $data['UserInfoSearch']['UserInfo'];
        $count = count($users_in_batch);
        
        echo "<li><b>รอบที่ $round:</b> เริ่มที่ตำแหน่ง $next_position | ได้มา $count คน (กำลังบันทึก...)</li>";
        
        // เตรียม SQL
        $stmt = $pdo->prepare("INSERT INTO employees (employee_code, employee_name) VALUES (:code, :name) ON DUPLICATE KEY UPDATE employee_name = :name");
        
        foreach ($users_in_batch as $user) {
            $code = $user['employeeNo'];
            $name = isset($user['name']) ? $user['name'] : 'ไม่ระบุชื่อ';
            
            $stmt->execute([':code' => $code, ':name' => $name]);
            $total_saved++;
        }

        // *** จุดเปลี่ยนสำคัญ: ไม่สนว่าจะได้มากี่คน ขอแค่ไม่ใช่ 0 ให้ไปต่อ ***
        $next_position += $count; // ขยับตำแหน่งไปอ่านหน้าถัดไป
        $round++;
        flush(); // ดันข้อความแสดงผลทันที

    } else {
        // ถ้า Response ว่างเปล่า หรือไม่มี Array UserInfo แสดงว่าหมดแล้วจริงๆ
        echo "<h3 style='color:green'>✅ ข้อมูลหมดแล้ว (รอบนี้ได้มา 0 คน) -> จบการทำงาน</h3>";
        break;
    }
}

echo "<hr>";
echo "<div style='background:#d1e7dd; padding:20px; border-radius:10px;'>";
echo "<h1>🎉 สรุปยอดรวมทั้งหมด: $total_saved คน</h1>";
echo "</div>";

// แถม: เช็คชื่อคนท้ายๆ หน่อยว่ามาไหม
echo "<h3>ตัวอย่างรายชื่อ 5 คนสุดท้ายที่ดึงมาได้:</h3>";
$stmt = $pdo->query("SELECT * FROM employees ORDER BY employee_code DESC LIMIT 5");
echo "<table border='1' cellpadding='5'><tr><th>รหัส</th><th>ชื่อ</th></tr>";
while ($row = $stmt->fetch()) {
    echo "<tr><td>{$row['employee_code']}</td><td>{$row['employee_name']}</td></tr>";
}
echo "</table>";
?>