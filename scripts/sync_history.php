<?php
// sync_history.php - สคริปต์ขุดเจาะย้อนหลัง (ตั้งแต่ต้นปี)
ini_set('display_errors', 1);
error_reporting(E_ALL);
set_time_limit(0); // ให้เวลารัน 10 นาที

require_once '../config/db.php'; 

// 🔧 เลือกเครื่องที่จะดึง (ทำทีละเครื่อง)
$device_ip   = "192.168.40.2"; 
$device_user = "admin";
$device_pass = "2023!!Chang";

echo "<h2>🕰️ กำลังขุดข้อมูลย้อนหลังตั้งแต่ 'ปีใหม่' (01/01/2026)...</h2>";
echo "<p>หมายเหตุ: ข้อมูลจะมาเฉพาะช่วงที่เครื่องสแกน 'เวลานาฬิกาถูกต้อง' เท่านั้น</p>";
echo "<hr>";

$url = "http://$device_ip/ISAPI/AccessControl/AcsEvent?format=json";

// *** ตั้งค่าย้อนไปต้นปี ***
$startTime = "2026-01-01T00:00:00+07:00";
$endTime   = date("Y-m-d") . "T23:59:59+07:00";

$next_position = 0;
$total_saved = 0;
$round = 1;

while (true) {
    
    // ดึงทีละ 100 รายการ
    $postData = json_encode([
        "AcsEventCond" => [
            "searchID" => "HistorySync_" . time(), 
            "searchResultPosition" => $next_position,
            "maxResults" => 100, 
            "major" => 5,        // เอา Event
            "minor" => 0,        // เอาทั้งนิ้วและหน้า
            "startTime" => $startTime,
            "endTime" => $endTime
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
        echo "<p style='color:red'>❌ Error (HTTP $httpCode) - หยุดทำงาน</p>";
        break;
    }

    $data = json_decode($response, true);
    
    if (isset($data['AcsEvent']['InfoList']) && !empty($data['AcsEvent']['InfoList'])) {
        
        $count_in_page = count($data['AcsEvent']['InfoList']);
        echo "<li>รอบที่ $round: ขุดเจอ $count_in_page รายการ (ตำแหน่ง $next_position)</li>";
        
        $stmt = $pdo->prepare("INSERT IGNORE INTO access_logs (employee_code, employee_name, checkin_time, device_name) VALUES (:code, :name, :time, :dev)");
        
        foreach ($data['AcsEvent']['InfoList'] as $log) {
            if (!isset($log['employeeNoString']) || trim($log['employeeNoString']) == '') continue;

            $empName = isset($log['name']) ? $log['name'] : '-';
            $dateTime = new DateTime($log['time']);
            $formattedTime = $dateTime->format('Y-m-d H:i:s');
            
            $stmt->execute([
                ':code' => $log['employeeNoString'],
                ':name' => $empName,
                ':time' => $formattedTime,
                ':dev'  => 'Scanner_' . $device_ip
            ]);
            
            if ($stmt->rowCount() > 0) $total_saved++;
        }

        if ($count_in_page == 0) {
            break; 
        } else {
            $next_position += $count_in_page;
            $round++;
            flush();
        }

    } else {
        echo "<li style='color:green'>✅ ขุดข้อมูลหมดแล้ว (จบการทำงาน)</li>";
        break; 
    }
}

echo "<hr><h3>สรุป: กู้คืนข้อมูลเก่าได้เพิ่ม $total_saved รายการ</h3>";
?>