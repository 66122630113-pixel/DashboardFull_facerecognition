<?php
// dashboard_full.php - ฉบับสมบูรณ์ (รวมทุกฟีเจอร์)
require_once 'config/db.php';

// ==========================================
// ส่วนที่ 1: เตรียมข้อมูล & ฟิลเตอร์
// ==========================================
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date("Y-m-d");
$end_date   = isset($_GET['end_date'])   ? $_GET['end_date']   : date("Y-m-d");
$search     = isset($_GET['search'])     ? trim($_GET['search']) : '';
$device     = isset($_GET['device'])     ? $_GET['device']     : '';

// เริ่มต้นสร้าง SQL สำหรับ Report
$sql_report = "SELECT 
                employee_code, employee_name,
                DATE(checkin_time) as work_date,
                MIN(checkin_time) as time_in,
                MAX(checkin_time) as time_out,
                MIN(device_name) as device_location
               FROM access_logs
               WHERE (DATE(checkin_time) BETWEEN :start AND :end)
               AND employee_code IS NOT NULL AND employee_code != ''
               AND employee_name != '-'";

// เก็บตัวแปรสำหรับ Bind Param
$params = [':start' => $start_date, ':end' => $end_date];

// --- Logic ค้นหาหลายคำ (Multi-keyword) ---
if (!empty($search)) {
    $keywords = explode(',', $search); // แยกคำด้วยคอมม่า
    $search_conditions = [];
    
    foreach ($keywords as $index => $word) {
        $word = trim($word);
        if (empty($word)) continue;
        
        $param_name = ":search_{$index}";
        $search_conditions[] = "(employee_name LIKE $param_name OR employee_code LIKE $param_name)";
        $params[$param_name] = "%$word%";
    }
    
    if (count($search_conditions) > 0) {
        $sql_report .= " AND (" . implode(' OR ', $search_conditions) . ")";
    }
}

// --- Logic กรองเครื่อง ---
if (!empty($device)) {
    $sql_report .= " AND device_name LIKE :device";
    $params[':device'] = "%$device%";
}

// Group By เพื่อรวมการสแกนซ้ำ
$sql_report .= " GROUP BY employee_code, DATE(checkin_time) ORDER BY work_date DESC, time_in ASC";

try {
    $stmt = $pdo->prepare($sql_report);
    $stmt->execute($params);
    $logs_report = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { die("Report Error: " . $e->getMessage()); }

// ==========================================
// ส่วนที่ 2: ข้อมูล Real-time (10 รายการล่าสุด)
// ==========================================
$sql_realtime = "SELECT * FROM access_logs 
                 WHERE employee_code IS NOT NULL AND employee_name != '-' 
                 ORDER BY checkin_time DESC LIMIT 10"; 
$logs_realtime = $pdo->query($sql_realtime)->fetchAll(PDO::FETCH_ASSOC);

// ==========================================
// ฟังก์ชันแปลงชื่อเครื่อง (รองรับ . และ _)
// ==========================================
function getDeviceNiceName($dbName) {
    // 40.2 = ทันตกรรม (เช็คทั้ง . และ _)
    if (strpos($dbName, '40.2') !== false || strpos($dbName, '40_2') !== false) {
        return '<span class="badge-fin">🦷 ทันตกรรม (40.2)</span>';
    }
    // 40.3 = ห้องยา (เช็คทั้ง . และ _)
    if (strpos($dbName, '40.3') !== false || strpos($dbName, '40_3') !== false) {
        return '<span class="badge-rx">💊 ห้องยา (40.3)</span>';
    }
    // กรณีอื่นๆ
    return '<span class="badge-other">' . $dbName . '</span>';
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Hospital Smart Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', sans-serif; background: #f4f6f9; margin: 0; padding: 20px; color: #333; }
        .container { max-width: 1200px; margin: 0 auto; }
        
        /* Header */
        .header-box { display: flex; justify-content: space-between; align-items: center; background: white; padding: 20px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); margin-bottom: 20px; }
        .btn-sync { background: #2ecc71; color: white; border: none; padding: 10px 20px; border-radius: 50px; cursor: pointer; font-weight: bold; display: flex; align-items: center; gap: 8px; transition: 0.2s; }
        .btn-sync:hover { background: #27ae60; transform: translateY(-2px); }
        .btn-sync.loading { background: #bdc3c7; cursor: not-allowed; }

        /* Realtime Grid */
        .realtime-box { background: #fff; padding: 20px; border-radius: 12px; margin-bottom: 20px; border-left: 5px solid #3498db; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .realtime-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 10px; margin-top: 15px; }
        .log-card { background: #f8f9fa; padding: 12px; border-radius: 8px; border: 1px solid #e9ecef; }
        .log-time { color: #e74c3c; font-weight: bold; font-size: 1.1em; }

        /* Report Table */
        .report-box { background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .filter-bar { display: flex; gap: 10px; flex-wrap: wrap; background: #f1f3f5; padding: 15px; border-radius: 8px; margin-bottom: 20px; align-items: center; }
        input, select { padding: 8px 12px; border: 1px solid #ced4da; border-radius: 4px; outline: none; }
        input:focus { border-color: #3498db; }
        
        table { width: 100%; border-collapse: separate; border-spacing: 0; }
        th { background: #343a40; color: white; padding: 12px; text-align: left; cursor: pointer; user-select: none; position: sticky; top: 0; }
        th:first-child { border-top-left-radius: 8px; }
        th:last-child { border-top-right-radius: 8px; }
        th:hover { background: #495057; }
        td { padding: 12px; border-bottom: 1px solid #dee2e6; vertical-align: middle; }
        tr:hover { background-color: #f8f9fa; }

        /* Status Badges */
        .badge-fin { background: #fff3cd; color: #856404; padding: 3px 8px; border-radius: 12px; font-size: 0.85em; font-weight: 600; border: 1px solid #ffeeba; }
        .badge-rx { background: #d4edda; color: #155724; padding: 3px 8px; border-radius: 12px; font-size: 0.85em; font-weight: 600; border: 1px solid #c3e6cb; }
        .badge-warn { background: #ffeeba; color: #856404; padding: 4px 8px; border-radius: 4px; font-size: 0.85em; }
        .badge-ok { background: #c3e6cb; color: #155724; padding: 4px 8px; border-radius: 4px; font-size: 0.85em; }
        
        /* Sort Arrows */
        th::after { content: '↕'; float: right; opacity: 0.3; }
        th.asc::after { content: '▲'; opacity: 1; }
        th.desc::after { content: '▼'; opacity: 1; }
    </style>
</head>
<body>

<div class="container">

    <div class="header-box">
        <div>
            <h2 style="margin:0; color:#2c3e50;">🏥 Hospital Smart Dashboard</h2>
            <div style="color:#7f8c8d; font-size:0.9em; margin-top:5px;">ระบบติดตามการลงเวลาและสรุปผล (All-in-One)</div>
        </div>
        <button id="btnSync" class="btn-sync" onclick="syncData()">
            <span>🔄 ดึงข้อมูลใหม่เดี๋ยวนี้</span>
        </button>
    </div>

    <div class="realtime-box">
        <h3 style="margin:0; color:#2980b9;">📡 สแกนล่าสุด (Real-time)</h3>
        <div class="realtime-list">
            <?php foreach($logs_realtime as $log): ?>
            <div class="log-card">
                <div class="log-time"><?= date("H:i", strtotime($log['checkin_time'])) ?> <span style="font-size:0.7em; color:#999; font-weight:normal;">น.</span></div>
                <div style="font-weight:600; margin: 4px 0;"><?= htmlspecialchars($log['employee_name']) ?></div>
                <div><?= getDeviceNiceName($log['device_name']) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="report-box">
        <h3 style="margin-top:0; border-bottom:2px solid #eee; padding-bottom:15px;">📑 รายงานสรุป (คลิกหัวตารางเพื่อเรียง)</h3>
        
        <form method="GET" class="filter-bar">
            <div>
                <label>วันที่:</label> 
                <input type="date" name="start_date" value="<?= $start_date ?>"> ถึง <input type="date" name="end_date" value="<?= $end_date ?>">
            </div>
            <div style="flex-grow:1;">
                <input type="text" name="search" placeholder="พิมพ์ชื่อ หรือ รหัส" value="<?= htmlspecialchars($search) ?>" style="width: 100%; box-sizing: border-box;">
            </div>
            <div>
                <select name="device">
                    <option value="">-- ทุกจุด --</option>
                    <option value="40.2" <?= $device=='40.2'?'selected':'' ?>>ทันตกรรม (40.2)</option>
                    <option value="40.3" <?= $device=='40.3'?'selected':'' ?>>ห้องยา (40.3)</option>
                </select>
                <button type="submit" style="background:#3498db; color:white; border:none; padding:9px 20px; border-radius:4px; cursor:pointer; margin-right: 5px;">
    🔍 ค้นหา
</button>

<button type="button" onclick="exportCSV()" style="background:#27ae60; color:white; border:none; padding:9px 20px; border-radius:4px; cursor:pointer;">
    📥 Export Excel
</button>
            </div>
        </form>

        <table id="sortableTable">
            <thead>
                <tr>
                    <th onclick="sortTable(0)">วันที่</th>
                    <th onclick="sortTable(1)">ชื่อ-นามสกุล</th>
                    <th onclick="sortTable(2)">จุดหลัก</th>
                    <th onclick="sortTable(3)">เวลาเข้า</th>
                    <th onclick="sortTable(4)">เวลาออก</th>
                    <th onclick="sortTable(5)">สถานะ</th>
                </tr>
            </thead>
            <tbody>
                <?php if($logs_report): foreach($logs_report as $row): 
                    $is_single = ($row['time_in'] == $row['time_out']);
                    $ts_date = strtotime($row['work_date']);
                ?>
                <tr>
                    <td data-val="<?= $ts_date ?>"><?= date("d/m/Y", $ts_date) ?></td>
                    
                    <td data-val="<?= htmlspecialchars($row['employee_name']) ?>">
                        <b><?= htmlspecialchars($row['employee_name']) ?></b><br>
                        <small style="color:#adb5bd"><?= $row['employee_code'] ?></small>
                    </td>
                    
                    <td><?= getDeviceNiceName($row['device_location']) ?></td>
                    
                    <td style="color:#27ae60; font-weight:bold;"><?= date("H:i", strtotime($row['time_in'])) ?></td>
                    
                    <td style="color:#c0392b; font-weight:bold;"><?= $is_single ? '-' : date("H:i", strtotime($row['time_out'])) ?></td>
                    
                    <td data-val="<?= $is_single ? 0 : 1 ?>">
                        <?= $is_single ? '<span class="badge-warn">❓ ครั้งเดียว</span>' : '<span class="badge-ok">✅ ปกติ</span>' ?>
                    </td>
                </tr>
                <?php endforeach; else: ?>
                <tr><td colspan="6" style="text-align:center; padding:30px; color:#999;">ไม่พบข้อมูลตามเงื่อนไข</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>

<script>
// 1. Sync Data Function
async function syncData() {
    const btn = document.getElementById('btnSync');
    const originalContent = btn.innerHTML;
    
    btn.innerHTML = "<span>⏳ กำลังเชื่อมต่อ...</span>";
    btn.classList.add('loading');
    btn.disabled = true;

    try {
        // ** เช็ค Path ไฟล์ Sync ให้ถูกต้อง **
        const req1 = fetch('scripts/sync_hikvision_40_2.php'); 
        const req2 = fetch('scripts/sync_hikvision_40_3.php');

        await Promise.all([req1, req2]);
        
        alert("✅ ดึงข้อมูลสำเร็จเรียบร้อย!");
        location.reload(); 

    } catch (error) {
        alert("❌ เกิดข้อผิดพลาด: " + error);
        btn.innerHTML = originalContent;
        btn.classList.remove('loading');
        btn.disabled = false;
    }
}

// 2. Sort Table Function
function sortTable(n) {
  var table, rows, switching, i, x, y, shouldSwitch, dir, switchcount = 0;
  table = document.getElementById("sortableTable");
  switching = true;
  dir = "asc"; 

  // Reset Arrow
  var headers = table.getElementsByTagName("th");
  for (var h = 0; h < headers.length; h++) headers[h].classList.remove("asc", "desc");

  while (switching) {
    switching = false;
    rows = table.rows;
    for (i = 1; i < (rows.length - 1); i++) {
      shouldSwitch = false;
      x = rows[i].getElementsByTagName("TD")[n];
      y = rows[i + 1].getElementsByTagName("TD")[n];
      
      // ใช้ data-val ถ้ามี เพื่อความแม่นยำ (เช่น วันที่)
      var xVal = x.getAttribute("data-val") || x.innerText.toLowerCase();
      var yVal = y.getAttribute("data-val") || y.innerText.toLowerCase();

      if (!isNaN(xVal) && !isNaN(yVal)) { xVal = parseFloat(xVal); yVal = parseFloat(yVal); }

      if (dir == "asc") {
        if (xVal > yVal) { shouldSwitch = true; break; }
      } else if (dir == "desc") {
        if (xVal < yVal) { shouldSwitch = true; break; }
      }
    }
    if (shouldSwitch) {
      rows[i].parentNode.insertBefore(rows[i + 1], rows[i]);
      switching = true;
      switchcount ++;
    } else {
      if (switchcount == 0 && dir == "asc") {
        dir = "desc";
        switching = true;
      }
    }
  }
  headers[n].classList.add(dir);
}
// ... (ต่อจาก function sortTable เดิม) ...

function exportCSV() {
    // 1. ดึงค่าจากช่อง Input ทั้งหมด
    var start = document.querySelector('input[name="start_date"]').value;
    var end   = document.querySelector('input[name="end_date"]').value;
    var search = document.querySelector('input[name="search"]').value;
    var device = document.querySelector('select[name="device"]').value;
    
    // 2. สร้าง URL พร้อมพารามิเตอร์
    var url = 'export_csv.php?start_date=' + start + 
              '&end_date=' + end + 
              '&search=' + encodeURIComponent(search) + 
              '&device=' + device;
              
    // 3. เปิดหน้าต่างใหม่เพื่อดาวน์โหลด
    window.open(url, '_blank');
}
</script>

</body>
</html>