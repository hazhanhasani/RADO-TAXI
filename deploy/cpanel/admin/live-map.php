<?php
declare(strict_types=1);
require __DIR__.'/_ui.php';
ra_require_admin();
$pdo=rado_db();
if(isset($_GET['json'])){
    $drivers=$pdo->query("SELECT d.user_id,u.full_name,d.plate_number,p.latitude,p.longitude,p.heading,p.speed_kph,p.last_seen_at FROM drivers d JOIN users u ON u.id=d.user_id JOIN driver_presence p ON p.driver_id=d.user_id WHERE d.status='approved' AND p.is_online=1 AND p.latitude IS NOT NULL AND p.longitude IS NOT NULL AND p.last_seen_at>=DATE_SUB(NOW(),INTERVAL 3 MINUTE)")->fetchAll();
    $trips=$pdo->query("SELECT id,status,pickup_lat,pickup_lng,destination_lat,destination_lng,pickup_label,destination_label,driver_id,estimated_fare FROM trips WHERE status IN('searching','driver_assigned','driver_arriving','arrived','in_progress') ORDER BY requested_at DESC LIMIT 200")->fetchAll();
    foreach($drivers as &$d)$d['last_seen_at_jalali']=rado_jalali_datetime((string)$d['last_seen_at']);
    rado_json(200,['ok'=>true,'drivers'=>$drivers,'trips'=>$trips,'time'=>rado_jalali_datetime(null,true)]);
}
$online=(int)ra_scalar($pdo,"SELECT COUNT(*) FROM drivers d JOIN driver_presence p ON p.driver_id=d.user_id WHERE d.status='approved' AND p.is_online=1 AND p.last_seen_at>=DATE_SUB(NOW(),INTERVAL 3 MINUTE)");
$active=(int)ra_scalar($pdo,"SELECT COUNT(*) FROM trips WHERE status IN('searching','driver_assigned','driver_arriving','arrived','in_progress')");
ra_header('نقشه زنده عملیات','operations','رانندگان آنلاین و سفرهای فعال بانه');
?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<div class="subnav"><a href="/admin/operations.php">مرکز عملیات</a><a href="/admin/dispatch.php">Dispatch پیشرفته</a><a class="on" href="/admin/live-map.php">نقشه زنده</a></div>
<div class="grid"><div class="card span3 metric"><small>راننده آنلاین</small><b id="onlineCount"><?=$online?></b></div><div class="card span3 metric"><small>سفر فعال</small><b id="tripCount"><?=$active?></b></div><div class="card span6"><div class="muted" id="liveStatus">در حال اتصال به داده زنده…</div></div></div>
<div class="card" style="margin-top:10px;padding:0;overflow:hidden"><div id="map" style="height:min(68vh,720px);min-height:430px"></div></div>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script><script>
const map=L.map('map',{zoomControl:true}).setView([35.9968,45.8853],14);L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'© OpenStreetMap'}).addTo(map);const layers=L.layerGroup().addTo(map);function esc(s){return String(s??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));}
async function load(){try{const r=await fetch('/admin/live-map.php?json=1',{cache:'no-store'});if(!r.ok)throw new Error('HTTP '+r.status);const d=await r.json();layers.clearLayers();for(const x of d.drivers||[]){const m=L.circleMarker([Number(x.latitude),Number(x.longitude)],{radius:9,color:'#171717',weight:3,fillColor:'#f5b400',fillOpacity:1}).addTo(layers);m.bindPopup('<b>'+esc(x.full_name)+'</b><br>پلاک: '+esc(x.plate_number)+'<br>'+esc(x.last_seen_at_jalali));}for(const t of d.trips||[]){const c=t.status==='searching'?'#e53935':'#1565c0';L.circleMarker([Number(t.pickup_lat),Number(t.pickup_lng)],{radius:6,color:c,fillOpacity:.8}).addTo(layers).bindPopup('<b>مبدا سفر</b><br>'+esc(t.pickup_label)+'<br>'+esc(t.status));L.circleMarker([Number(t.destination_lat),Number(t.destination_lng)],{radius:5,color:'#8e24aa',fillOpacity:.75}).addTo(layers).bindPopup('<b>مقصد</b><br>'+esc(t.destination_label));}document.getElementById('onlineCount').textContent=(d.drivers||[]).length;document.getElementById('tripCount').textContent=(d.trips||[]).length;document.getElementById('liveStatus').textContent=(d.drivers||[]).length+' راننده آنلاین · '+(d.trips||[]).length+' سفر فعال · '+d.time;}catch(e){document.getElementById('liveStatus').textContent='خطا در دریافت داده زنده؛ دوباره تلاش می‌شود.';}}
load();setInterval(load,8000);
</script>
<?php ra_footer();
