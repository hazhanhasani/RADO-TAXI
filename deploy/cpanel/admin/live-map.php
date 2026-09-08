<?php
declare(strict_types=1);
require __DIR__.'/_ui.php';
ra_require_admin();
$pdo=rado_db();

if(isset($_GET['json'])){
    header('Cache-Control: no-store, no-cache, must-revalidate');
    $drivers=$pdo->query("SELECT d.user_id,u.full_name,u.phone,d.plate_number,d.vehicle_make,d.vehicle_model,d.vehicle_color,p.latitude,p.longitude,p.heading,p.speed_kph,p.last_seen_at
        FROM drivers d
        JOIN users u ON u.id=d.user_id
        JOIN driver_presence p ON p.driver_id=d.user_id
        WHERE d.status='approved' AND p.is_online=1
          AND p.latitude IS NOT NULL AND p.longitude IS NOT NULL
          AND p.last_seen_at>=DATE_SUB(NOW(),INTERVAL 3 MINUTE)
        ORDER BY p.last_seen_at DESC LIMIT 500")->fetchAll();

    $trips=$pdo->query("SELECT t.id,t.status,t.pickup_lat,t.pickup_lng,t.destination_lat,t.destination_lng,
        t.pickup_label,t.destination_label,t.driver_id,t.passenger_id,t.estimated_fare,t.final_fare,t.requested_at,
        TIMESTAMPDIFF(SECOND,t.requested_at,NOW()) wait_seconds,
        pu.full_name passenger_name,pu.phone passenger_phone,
        du.full_name driver_name,du.phone driver_phone
        FROM trips t
        LEFT JOIN users pu ON pu.id=t.passenger_id
        LEFT JOIN users du ON du.id=t.driver_id
        WHERE t.status IN('requested','searching','driver_assigned','driver_arriving','arrived','in_progress')
        ORDER BY t.requested_at DESC LIMIT 300")->fetchAll();

    foreach($drivers as &$d){
        $d['latitude']=(float)$d['latitude'];$d['longitude']=(float)$d['longitude'];
        $d['heading']=$d['heading']===null?null:(int)$d['heading'];
        $d['speed_kph']=$d['speed_kph']===null?null:(float)$d['speed_kph'];
        $d['last_seen_at_jalali']=rado_jalali_datetime((string)$d['last_seen_at']);
    }
    unset($d);
    foreach($trips as &$t){
        $t['pickup_lat']=(float)$t['pickup_lat'];$t['pickup_lng']=(float)$t['pickup_lng'];
        $t['destination_lat']=(float)$t['destination_lat'];$t['destination_lng']=(float)$t['destination_lng'];
        $t['estimated_fare']=(int)($t['estimated_fare']??0);
        $t['final_fare']=$t['final_fare']===null?null:(int)$t['final_fare'];
        $t['wait_seconds']=max(0,(int)($t['wait_seconds']??0));
        $t['requested_at_jalali']=rado_jalali_datetime((string)$t['requested_at']);
        $t['status_fa']=rado_trip_status_fa((string)$t['status']);
    }
    unset($t);
    rado_json(200,['ok'=>true,'drivers'=>$drivers,'trips'=>$trips,'time'=>rado_jalali_datetime(null,true)]);
}

$online=(int)ra_scalar($pdo,"SELECT COUNT(*) FROM drivers d JOIN driver_presence p ON p.driver_id=d.user_id WHERE d.status='approved' AND p.is_online=1 AND p.last_seen_at>=DATE_SUB(NOW(),INTERVAL 3 MINUTE)");
$active=(int)ra_scalar($pdo,"SELECT COUNT(*) FROM trips WHERE status IN('driver_assigned','driver_arriving','arrived','in_progress')");
$waiting=(int)ra_scalar($pdo,"SELECT COUNT(*) FROM trips WHERE status IN('requested','searching')");
$tv=isset($_GET['tv']);
ra_header('مرکز عملیات زنده RADO','operations','نمایش لحظه‌ای رانندگان، درخواست‌ها و سفرهای فعال بانه');
?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<style>
.live-toolbar{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}.live-dot{display:inline-block;width:9px;height:9px;border-radius:50%;background:#1b9a4b;margin-left:6px;box-shadow:0 0 0 4px #1b9a4b22}.live-dot.connecting{background:#f5b400;box-shadow:0 0 0 4px #f5b40022}.live-dot.offline{background:#b11f28;box-shadow:0 0 0 4px #b11f2822}.map-shell{margin-top:10px;padding:0!important;overflow:hidden;position:relative}.map-legend{position:absolute;z-index:500;left:12px;bottom:12px;background:#fff;border-radius:13px;padding:8px 10px;box-shadow:0 6px 22px #0002;font-size:10px;line-height:1.9}.legend-row{display:flex;align-items:center;gap:6px}.legend-swatch{width:9px;height:9px;border-radius:99px;display:inline-block}.driver-car{width:34px;height:34px;display:grid;place-items:center;border-radius:50%;border:3px solid #171717;background:#f5b400;font-size:18px;box-shadow:0 4px 12px #0004;transition:background .2s,border-color .2s}.driver-car.free{background:#2fbf71}.driver-car.enroute{background:#f5b400}.driver-car.intrip{background:#4b86e8;color:#fff}.driver-car.stale{background:#d4d4d4}.request-pin{width:22px;height:22px;border-radius:50%;border:4px solid #fff;box-shadow:0 2px 8px #0005}.request-pin.waiting{background:#e53935}.request-pin.active{background:#1565c0}.dest-pin{width:18px;height:18px;border-radius:4px 50% 50% 50%;transform:rotate(45deg);background:#8e24aa;border:3px solid #fff;box-shadow:0 2px 8px #0004}.leaflet-popup-content{direction:rtl;text-align:right;min-width:210px}.live-feed{max-height:88px;overflow:auto;font-size:10px;line-height:1.8}.tv-btn{white-space:nowrap}.tv-mode .side,.tv-mode .topbar,.tv-mode .subnav{display:none!important}.tv-mode .main{max-width:none;padding:7px}.tv-mode .grid{margin-top:0}.tv-mode #map{height:calc(100vh - 92px)!important;min-height:620px}.tv-mode .map-shell{margin-top:7px}.tv-mode .card.metric{padding:9px 12px}.tv-mode .metric b{font-size:24px}
@media(max-width:800px){#map{min-height:500px!important}.map-legend{font-size:9px}.live-feed{display:none}}
</style>
<div class="subnav"><a href="/admin/operations.php">مرکز عملیات</a><a href="/admin/dispatch.php">Dispatch پیشرفته</a><a class="on" href="/admin/live-map.php">نقشه زنده</a><a class="tv-btn" href="/admin/live-map.php?tv=1">📺 حالت تلویزیون</a></div>
<div class="grid">
  <div class="card span3 metric"><small>راننده آنلاین</small><b id="onlineCount"><?=$online?></b></div>
  <div class="card span3 metric"><small>راننده آزاد</small><b id="freeCount">—</b></div>
  <div class="card span3 metric"><small>درخواست منتظر</small><b id="waitingCount"><?=$waiting?></b></div>
  <div class="card span3 metric"><small>سفر فعال</small><b id="tripCount"><?=$active?></b></div>
</div>
<div class="card" style="margin-top:10px">
  <div class="live-toolbar"><div><span id="liveDot" class="live-dot connecting"></span><b id="liveStatus">در حال اتصال به جریان زنده…</b></div><div class="muted" id="liveTime">—</div></div>
  <div class="live-feed muted" id="liveFeed">مرکز عملیات آماده است.</div>
</div>
<div class="card map-shell">
  <div id="map" style="height:min(72vh,780px);min-height:460px"></div>
  <div class="map-legend">
    <div class="legend-row"><span class="legend-swatch" style="background:#2fbf71"></span>راننده آزاد</div>
    <div class="legend-row"><span class="legend-swatch" style="background:#f5b400"></span>در مسیر مسافر</div>
    <div class="legend-row"><span class="legend-swatch" style="background:#4b86e8"></span>سفر در حال انجام</div>
    <div class="legend-row"><span class="legend-swatch" style="background:#e53935"></span>درخواست منتظر</div>
  </div>
</div>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
<?php if($tv):?>document.body.classList.add('tv-mode');<?php endif;?>
const map=L.map('map',{zoomControl:true,preferCanvas:true}).setView([35.9968,45.8853],14);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'© OpenStreetMap'}).addTo(map);
const driverMarkers=new Map();
const tripLayers=new Map();
let snapshotState={drivers:[],trips:[]};
let snapshotTimer=null;
let fallbackTimer=null;
let streamHealthy=false;

function esc(s){return String(s??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));}
function money(v){return new Intl.NumberFormat('fa-IR').format(Number(v||0))+' ریال';}
function waitLabel(sec){sec=Math.max(0,Number(sec||0));if(sec<60)return Math.floor(sec)+' ثانیه';const m=Math.floor(sec/60);return m+' دقیقه';}
function feed(text){const box=document.getElementById('liveFeed');const line=document.createElement('div');line.textContent='• '+text;box.prepend(line);while(box.children.length>5)box.lastElementChild.remove();}
function setConnection(state,text){const dot=document.getElementById('liveDot');dot.className='live-dot '+(state==='live'?'':state);document.getElementById('liveStatus').textContent=text;}
function statusClass(driverId){const t=snapshotState.trips.find(x=>String(x.driver_id||'')===String(driverId)&&['driver_assigned','driver_arriving','arrived','in_progress'].includes(x.status));if(!t)return 'free';return t.status==='in_progress'?'intrip':'enroute';}
function driverIcon(driverId,heading){const cls=statusClass(driverId);return L.divIcon({className:'',iconSize:[38,38],iconAnchor:[19,19],html:'<div class="driver-car '+cls+'" data-heading="'+Number(heading||0)+'" style="transform:rotate('+Number(heading||0)+'deg)">🚕</div>'});}
function smoothMove(marker,lat,lng,heading){const from=marker.getLatLng(),to=L.latLng(Number(lat),Number(lng));const start=performance.now(),duration=700;function step(now){const p=Math.min(1,(now-start)/duration);const ease=1-Math.pow(1-p,3);marker.setLatLng([from.lat+(to.lat-from.lat)*ease,from.lng+(to.lng-from.lng)*ease]);if(p<1)requestAnimationFrame(step);}requestAnimationFrame(step);const el=marker.getElement()?.querySelector('.driver-car');if(el&&heading!=null)el.style.transform='rotate('+Number(heading)+'deg)';}
function driverPopup(d){const cls=statusClass(d.user_id);const state=cls==='free'?'آزاد':cls==='intrip'?'دارای مسافر':'در مسیر مسافر';return '<b>'+esc(d.full_name||'راننده RADO')+'</b><br>وضعیت: '+state+'<br>پلاک: '+esc(d.plate_number||'—')+'<br>خودرو: '+esc(((d.vehicle_make||'')+' '+(d.vehicle_model||'')).trim()||'—')+'<br>سرعت: '+esc(d.speed_kph==null?'—':Math.round(d.speed_kph)+' km/h')+'<br>آخرین GPS: '+esc(d.last_seen_at_jalali||'—');}
function upsertDriver(d,animate=true){const id=String(d.user_id);let m=driverMarkers.get(id);if(!m){m=L.marker([Number(d.latitude),Number(d.longitude)],{icon:driverIcon(id,d.heading),zIndexOffset:800}).addTo(map);driverMarkers.set(id,m);}else{if(animate)smoothMove(m,d.latitude,d.longitude,d.heading);else m.setLatLng([Number(d.latitude),Number(d.longitude)]);m.setIcon(driverIcon(id,d.heading));}m.bindPopup(driverPopup(d));}
function tripPopup(t){return '<b>'+esc(t.status_fa||t.status)+'</b><br>مسافر: '+esc(t.passenger_name||'مسافر RADO')+' — '+esc(t.passenger_phone||'')+'<br>راننده: '+esc(t.driver_name||'هنوز تخصیص نشده')+'<br>'+esc(t.pickup_label||'مبدا')+' ← '+esc(t.destination_label||'مقصد')+'<br>کرایه: '+money(t.final_fare??t.estimated_fare)+'<br>انتظار: '+waitLabel(t.wait_seconds)+'<br>'+esc(t.requested_at_jalali||'');}
function upsertTrip(t){const id=String(t.id);let g=tripLayers.get(id);if(g){g.clearLayers();}else{g=L.layerGroup().addTo(map);tripLayers.set(id,g);}const waiting=['requested','searching'].includes(t.status);const origin=L.divIcon({className:'',iconSize:[24,24],iconAnchor:[12,12],html:'<div class="request-pin '+(waiting?'waiting':'active')+'"></div>'});const dest=L.divIcon({className:'',iconSize:[20,20],iconAnchor:[10,18],html:'<div class="dest-pin"></div>'});L.marker([Number(t.pickup_lat),Number(t.pickup_lng)],{icon:origin,zIndexOffset:500}).addTo(g).bindPopup(tripPopup(t));L.marker([Number(t.destination_lat),Number(t.destination_lng)],{icon:dest,zIndexOffset:400}).addTo(g).bindPopup('<b>مقصد</b><br>'+esc(t.destination_label||'—'));if(!waiting){L.polyline([[Number(t.pickup_lat),Number(t.pickup_lng)],[Number(t.destination_lat),Number(t.destination_lng)]],{weight:3,opacity:.55,dashArray:'6,7'}).addTo(g);}}
function updateCounters(){const activeDrivers=new Set(snapshotState.trips.filter(t=>['driver_assigned','driver_arriving','arrived','in_progress'].includes(t.status)&&t.driver_id).map(t=>String(t.driver_id)));document.getElementById('onlineCount').textContent=snapshotState.drivers.length;document.getElementById('freeCount').textContent=Math.max(0,snapshotState.drivers.length-activeDrivers.size);document.getElementById('waitingCount').textContent=snapshotState.trips.filter(t=>['requested','searching'].includes(t.status)).length;document.getElementById('tripCount').textContent=snapshotState.trips.filter(t=>['driver_assigned','driver_arriving','arrived','in_progress'].includes(t.status)).length;}
function renderSnapshot(d){snapshotState={drivers:d.drivers||[],trips:d.trips||[]};const driverIds=new Set(snapshotState.drivers.map(x=>String(x.user_id)));for(const [id,m] of driverMarkers){if(!driverIds.has(id)){map.removeLayer(m);driverMarkers.delete(id);}}for(const x of snapshotState.drivers)upsertDriver(x,true);const tripIds=new Set(snapshotState.trips.map(x=>String(x.id)));for(const [id,g] of tripLayers){if(!tripIds.has(id)){map.removeLayer(g);tripLayers.delete(id);}}for(const t of snapshotState.trips)upsertTrip(t);updateCounters();document.getElementById('liveTime').textContent=d.time||'';}
async function loadSnapshot(){try{const r=await fetch('/admin/live-map.php?json=1',{cache:'no-store'});if(!r.ok)throw new Error('HTTP '+r.status);const d=await r.json();renderSnapshot(d);return true;}catch(e){setConnection('offline','دریافت Snapshot ناموفق؛ تلاش مجدد ادامه دارد');return false;}}
function scheduleSnapshot(delay=120){clearTimeout(snapshotTimer);snapshotTimer=setTimeout(loadSnapshot,delay);}
function applyPresence(evt){const channel=String(evt.channel||'');const id=channel.startsWith('driver:')?channel.slice(7):'';const p=evt.payload||{};if(!id)return;if(p.online===false){const m=driverMarkers.get(id);if(m){map.removeLayer(m);driverMarkers.delete(id);}scheduleSnapshot(50);return;}const d=snapshotState.drivers.find(x=>String(x.user_id)===id);if(!d||p.lat==null||p.lng==null){scheduleSnapshot(50);return;}d.latitude=Number(p.lat);d.longitude=Number(p.lng);d.heading=p.heading;d.speed_kph=p.speed_kph;upsertDriver(d,true);}
function connectStream(){if(!window.EventSource){setConnection('offline','مرورگر Stream زنده را پشتیبانی نمی‌کند؛ حالت جایگزین فعال شد');fallbackTimer=setInterval(loadSnapshot,3000);return;}const es=new EventSource('/admin/live-stream.php');es.addEventListener('ready',()=>{streamHealthy=true;setConnection('live','اتصال زنده برقرار است');if(fallbackTimer){clearInterval(fallbackTimer);fallbackTimer=null;}});es.addEventListener('heartbeat',e=>{streamHealthy=true;setConnection('live','اتصال زنده برقرار است');try{const x=JSON.parse(e.data);document.getElementById('liveTime').textContent=x.server_time?.jalali||document.getElementById('liveTime').textContent;}catch(_){}});es.addEventListener('ops',e=>{streamHealthy=true;let x;try{x=JSON.parse(e.data);}catch(_){return;}if(x.event_type==='presence'&&String(x.channel||'').startsWith('driver:')){applyPresence(x);}else{scheduleSnapshot(80);}if(x.event_type==='driver_assigned')feed('یک سفر به راننده تخصیص یافت.');else if(x.event_type==='trip_offer')feed('درخواست جدید برای راننده ارسال شد.');else if(x.event_type==='presence'&&x.payload?.online===true){}else feed('وضعیت عملیات تغییر کرد: '+String(x.event_type||'event'));});es.addEventListener('stream_error',()=>setConnection('offline','خطای Stream؛ اتصال مجدد خودکار…'));es.onerror=()=>{streamHealthy=false;setConnection('connecting','در حال اتصال مجدد به جریان زنده…');};}
loadSnapshot().then(()=>connectStream());
</script>
<?php ra_footer();
