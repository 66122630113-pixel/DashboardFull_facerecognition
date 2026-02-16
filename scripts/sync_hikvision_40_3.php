<?php
// sync_hikvision_final.php (สูตรตื้อไม่เลิก - แก้ปัญหาได้ไม่ครบ 122 คน)
ini_set('display_errors', 1);
error_reporting(E_ALL);
set_time_limit(300); // ให้เวลารันนานๆ หน่อย

require_once '../config/db.php'; 

// 🔧 เลือกเครื่องที่จะดึง (แก้ IP ตรงนี้)
$device_ip   = "192.168.40.3"; 
$device_user = "admin";
$device_pass = "2023!!Chang";

echo "<h2>🔄 กำลังดูดข้อมูลจาก $device_ip (โหมด: ไม่ครบไม่เลิก)</h2>";

$url = "http://$device_ip/ISAPI/AccessControl/AcsEvent?format=json";

// ดึงย้อนหลัง 3 วัน (เผื่อบางคนไม่ได้สแกนวันนี้ จะได้ติดรายชื่อมาด้วย)
$startTime = date("Y-m-d", strtotime("-3 days")) . "T00:00:00+07:00";
$endTime   = date("Y-m-d") . "T23:59:59+07:00";

$next_position = 0;
$total_saved = 0;
$round = 1;

while (true) {
    
    // ยิงคำสั่งขอข้อมูล
    $postData = json_encode([
        "AcsEventCond" => [
            "searchID" => "FullSync_" . time(), 
            "searchResultPosition" => $next_position, // ขยับตำแหน่งไปเรื่อยๆ
            "maxResults" => 100, // ขอทีละ 100 พอ (เครื่องจะได้ไม่เอ๋อ)
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
        echo "<p style='color:red'>❌ รอบที่ $round พัง (HTTP $httpCode) - หยุด</p>";
        break;
    }

    $data = json_decode($response, true);
    
    // เช็คว่ามีข้อมูลส่งมาไหม?
    if (isset($data['AcsEvent']['InfoList']) && !empty($data['AcsEvent']['InfoList'])) {
        
        $count_in_page = count($data['AcsEvent']['InfoList']);
        echo "<li>รอบที่ $round: ได้มา $count_in_page รายการ (ตำแหน่ง $next_position)</li>";
        
        // บันทึกลง DB
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

        // *** สูตรใหม่: ถ้าได้มา 0 คือจบ แต่ถ้าได้มาบ้าง ให้ขยับไปต่อ ***
        if ($count_in_page == 0) {
            break; // หมดแล้วจริงๆ
        } else {
            $next_position += $count_in_page; // ขยับไปอ่านหน้าถัดไป เท่ากับจำนวนที่ได้มา
            $round++;
            flush(); // ดันข้อความออกหน้าจอ
        }

    } else {
        echo "<li style='color:green'>✅ ข้อมูลหมดเกลี้ยงแล้ว (Stop)</li>";
        break; 
    }
}

echo "<hr><h3>สรุป: บันทึกเพิ่มใหม่ทั้งหมด $total_saved รายการ</h3>";
?>