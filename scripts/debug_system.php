<?php
// 1. เปิดโหมดแสดง Error ทั้งหมด (จะได้รู้ว่าพังตรงไหน)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<h2>🛠️ เริ่มต้นการตรวจสอบระบบ...</h2>";

// ==============================
// ส่วนที่ 1: ตั้งค่า (กรอกข้อมูลตรงนี้ให้ถูกนะครับ)
// ==============================
$db_host = "localhost";
$db_user = "root";
$db_pass = "";      // รหัสผ่าน Database (ถ้ามีใส่ด้วย)
$db_name = "hospital_project"; 

$device_ip   = "192.168.40.2"; // <--- IP ตามรูปที่คุณส่งมา
$device_user = "admin";
$device_pass = "2023!!Chang";    // <--- เช็คตัวพิมพ์เล็ก/ใหญ่ให้เป๊ะ

// ==============================
// ส่วนที่ 2: ทดสอบ Database
// ==============================
echo "Checking 1: เชื่อมต่อ Database... ";
try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "<span style='color:green;'>✅ ผ่าน (Connected)</span><br>";
} catch (PDOException $e) {
    die("<span style='color:red;'>❌ พังตรงนี้: " . $e->getMessage() . "</span>");
}

// ==============================
// ส่วนที่ 3: ทดสอบยิงไปหา Hikvision
// ==============================
echo "Checking 2: ยิงข้อมูลไปที่ $device_ip... <br>";

$url = "http://$device_ip/ISAPI/AccessControl/AcsEvent?format=json";

// ช่วงเวลา (ดึงของวันนี้)
$startTime = date("Y-m-d") . "T00:00:00+07:00";
$endTime   = date("Y-m-d") . "T23:59:59+07:00";
echo "Command: ค้นหาตั้งแต่ $startTime ถึง $endTime <br>";

$postData = json_encode([
    "AcsEventCond" => [
        "searchID" => "1",
        "searchResultPosition" => 0,
        "maxResults" => 10, // ลองดึงแค่ 10 ก่อน
        "major" => 0,
        "minor" => 0,
        "startTime" => $startTime,
        "endTime" => $endTime
    ]
]);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5); // ถ้าเชื่อมไม่ได้ใน 5 วิ ให้ตัดเลย
curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
curl_setopt($ch, CURLOPT_USERPWD, "$device_user:$device_pass");
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

// ==============================
// ส่วนที่ 4: แสดงผลลัพธ์
// ==============================
if ($httpCode == 200) {
    echo "<span style='color:green;'>✅ เชื่อมต่อเครื่องสแกนสำเร็จ (HTTP 200)</span><br>";
    $data = json_decode($response, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "<span style='color:red;'>❌ ได้ข้อมูลมา แต่รูปแบบ JSON ผิดพลาด</span><br>";
        var_dump($response);
    } else {
        $count = isset($data['AcsEvent']['InfoList']) ? count($data['AcsEvent']['InfoList']) : 0;
        echo "📥 พบข้อมูลจำนวน: <b>$count</b> รายการ<br>";
        
        // ลอง Insert ลง Database เลยถ้ามีข้อมูล
        if ($count > 0) {
             $stmt = $pdo->prepare("INSERT IGNORE INTO access_logs (employee_code, checkin_time, device_name) VALUES (:code, :time, :dev)");
             $inserted = 0;
             foreach ($data['AcsEvent']['InfoList'] as $log) {
                 $timeRaw = $log['time'];
                 $dateTime = new DateTime($timeRaw);
                 $formattedTime = $dateTime->format('Y-m-d H:i:s');
                 
                 $stmt->execute([
                    ':code' => $log['employeeNoString'],
                    ':time' => $formattedTime,
                    ':dev'  => 'DS-K1T320MFWX'
                 ]);
                 if ($stmt->rowCount() > 0) $inserted++;
             }
             echo "💾 บันทึกจริงลง Database สำเร็จ: $inserted รายการ<br>";
             echo "<a href='../case_entry.php'>👉 คลิกเพื่อกลับไปดูหน้าตาราง</a>";
        } else {
             echo "⚠️ ไม่พบข้อมูล (เครื่องอาจจะยังไม่มีคนสแกนวันนี้ หรือเวลาเครื่องไม่ตรง)<br>";
             echo "Response จากเครื่อง: <pre>" . print_r($data, true) . "</pre>";
        }
    }

} else {
    echo "<span style='color:red;'>❌ เชื่อมต่อไม่ได้ (HTTP Code: $httpCode)</span><br>";
    echo "Curl Error: $curlError <br>";
    echo "หมายเหตุ: ถ้าเป็น 0 หรือ Time out แปลว่า IP ผิด หรือคนละวงแลน<br>";
    echo "หมายเหตุ: ถ้าเป็น 401 แปลว่า รหัสผ่านผิด";
}
?>