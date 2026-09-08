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
        du.full_name driver_name,du.phone driver_phone,
        pp.latitude passenger_lat,pp.longitude passenger_lng,pp.accuracy_m passenger_accuracy_m,pp.last_seen_at passenger_last_seen_at
        FROM trips t
        LEFT JOIN users pu ON pu.id=t.passenger_id
        LEFT JOIN users du ON du.id=t.driver_id
        LEFT JOIN passenger_presence pp ON pp.trip_id=t.id AND pp.passenger_id=t.passenger_id AND pp.last_seen_at>=DATE_SUB(NOW(),INTERVAL 2 MINUTE)
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
        $t['passenger_lat']=$t['passenger_lat']===null?null:(float)$t['passenger_lat'];
        $t['passenger_lng']=$t['passenger_lng']===null?null:(float)$t['passenger_lng'];
        $t['passenger_accuracy_m']=$t['passenger_accuracy_m']===null?null:(float)$t['passenger_accuracy_m'];
        $t['passenger_last_seen_at_jalali']=$t['passenger_last_seen_at']?rado_jalali_datetime((string)$t['passenger_last_seen_at']):null;
        unset($t['passenger_last_seen_at']);
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
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@neshan-maps-platform/maplibre-sdk@5.24.4/dist/neshan-maplibre-sdk.css">
<style>
.live-toolbar{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}.live-dot{display:inline-block;width:9px;height:9px;border-radius:50%;background:#1b9a4b;margin-left:6px;box-shadow:0 0 0 4px #1b9a4b22}.live-dot.connecting{background:#f5b400;box-shadow:0 0 0 4px #f5b40022}.live-dot.offline{background:#b11f28;box-shadow:0 0 0 4px #b11f2822}.map-shell{margin-top:10px;padding:0!important;overflow:hidden;position:relative}.map-legend{position:absolute;z-index:20;left:12px;bottom:30px;background:#fff;border-radius:13px;padding:8px 10px;box-shadow:0 6px 22px #0002;font-size:10px;line-height:1.9}.legend-row{display:flex;align-items:center;gap:6px}.legend-swatch{width:9px;height:9px;border-radius:99px;display:inline-block}.driver-marker{width:38px;height:38px;display:grid;place-items:center}.driver-car{width:34px;height:34px;display:grid;place-items:center;border-radius:50%;border:3px solid #171717;background:#f5b400;font-size:18px;box-shadow:0 4px 12px #0004;transition:background .2s,border-color .2s,transform .18s linear;transform-origin:center}.driver-car.free{background:#2fbf71}.driver-car.enroute{background:#f5b400}.driver-car.intrip{background:#4b86e8;color:#fff}.driver-car.stale{background:#d4d4d4}.maplibregl-popup-content{direction:rtl;text-align:right;min-width:220px;border-radius:13px;padding:12px;box-shadow:0 8px 26px #0002}.live-feed{max-height:88px;overflow:auto;font-size:10px;line-height:1.8}.tv-btn{white-space:nowrap}.provider-pill{display:inline-flex;align-items:center;gap:5px;margin-right:8px;padding:4px 8px;border-radius:99px;background:#eef7ff;color:#14558a;font-size:9px}.map-error{position:absolute;inset:12px;z-index:30;display:none;place-items:center;text-align:center;background:#fffdf8;border:1px solid #f2d58b;border-radius:14px;padding:20px;color:#6b5200}.map-error.show{display:grid}.tv-mode .side,.tv-mode .topbar,.tv-mode .subnav{display:none!important}.tv-mode .main{max-width:none;padding:7px}.tv-mode .grid{margin-top:0}.tv-mode #map{height:calc(100vh - 92px)!important;min-height:620px}.tv-mode .map-shell{margin-top:7px}.tv-mode .card.metric{padding:9px 12px}.tv-mode .metric b{font-size:24px}
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
  <div class="live-toolbar"><div><span id="liveDot" class="live-dot connecting"></span><b id="liveStatus">در حال آماده‌سازی نقشه نشان…</b><span class="provider-pill">Neshan MapLibre</span></div><div class="muted" id="liveTime">—</div></div>
  <div class="live-feed muted" id="liveFeed">مرکز عملیات آماده است.</div>
</div>
<div class="card map-shell">
  <div id="map" style="height:min(72vh,780px);min-height:460px"></div>
  <div class="map-error" id="mapError"><div><b>نقشه نشان آماده نشد</b><div class="muted" id="mapErrorText" style="margin-top:8px">Web Map Key نشان را در تنظیمات نقشه بررسی کنید.</div></div></div>
  <div class="map-legend">
    <div class="legend-row"><span class="legend-swatch" style="background:#2fbf71"></span>راننده آزاد</div>
    <div class="legend-row"><span class="legend-swatch" style="background:#f5b400"></span>در مسیر مسافر</div>
    <div class="legend-row"><span class="legend-swatch" style="background:#4b86e8"></span>سفر در حال انجام</div>
    <div class="legend-row"><span class="legend-swatch" style="background:#e53935"></span>درخواست منتظر</div>
    <div class="legend-row"><span class="legend-swatch" style="background:#ff8a00"></span>موقعیت زنده مسافر</div>
    <div class="legend-row"><span class="legend-swatch" style="background:#8e24aa"></span>مقصد</div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/@neshan-maps-platform/maplibre-sdk@5.24.4/dist/neshan-maplibre-sdk.umd.js"></script>
<script>
<?php if($tv):?>document.body.classList.add('tv-mode');<?php endif;?>
let map=null;
let mapReady=false;
const driverMarkers=new Map();
let snapshotState={drivers:[],trips:[]};
let snapshotTimer=null;
let fallbackTimer=null;
let streamHealthy=false;

function esc(s){return String(s??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));}
function money(v){return new Intl.NumberFormat('fa-IR').format(Number(v||0))+' ریال';}
function waitLabel(sec){sec=Math.max(0,Number(sec||0));if(sec<60)return Math.floor(sec)+' ثانیه';return Math.floor(sec/60)+' دقیقه';}
function feed(text){const box=document.getElementById('liveFeed');const line=document.createElement('div');line.textContent='• '+text;box.prepend(line);while(box.children.length>5)box.lastElementChild.remove();}
function setConnection(state,text){const dot=document.getElementById('liveDot');dot.className='live-dot '+(state==='live'?'':state);document.getElementById('liveStatus').textContent=text;}
function failMap(message){document.getElementById('mapErrorText').textContent=message;document.getElementById('mapError').classList.add('show');setConnection('offline','نقشه نشان در دسترس نیست');}
function statusClass(driverId){const t=snapshotState.trips.find(x=>String(x.driver_id||'')===String(driverId)&&['driver_assigned','driver_arriving','arrived','in_progress'].includes(x.status));if(!t)return 'free';return t.status==='in_progress'?'intrip':'enroute';}
function driverPopup(d){const cls=statusClass(d.user_id);const state=cls==='free'?'آزاد':cls==='intrip'?'دارای مسافر':'در مسیر مسافر';return '<b>'+esc(d.full_name||'راننده RADO')+'</b><br>وضعیت: '+state+'<br>پلاک: '+esc(d.plate_number||'—')+'<br>خودرو: '+esc(((d.vehicle_make||'')+' '+(d.vehicle_model||'')).trim()||'—')+'<br>سرعت: '+esc(d.speed_kph==null?'—':Math.round(d.speed_kph)+' km/h')+'<br>آخرین GPS: '+esc(d.last_seen_at_jalali||'—');}
function driverElement(d){
  const wrap=document.createElement('div');wrap.className='driver-marker';
  const car=document.createElement('div');car.className='driver-car '+statusClass(d.user_id);car.textContent='🚕';car.dataset.role='car';
  car.style.transform='rotate('+Number(d.heading||0)+'deg)';wrap.appendChild(car);return wrap;
}
function syncDriverVisual(marker,d){
  const car=marker.getElement()?.querySelector('[data-role="car"]');
  if(car){car.className='driver-car '+statusClass(d.user_id);car.style.transform='rotate('+Number(d.heading||0)+'deg)';}
  marker.setPopup(new maplibregl.Popup({offset:22,closeButton:false}).setHTML(driverPopup(d)));
}
function smoothMove(marker,lat,lng){
  const from=marker.getLngLat(),to={lng:Number(lng),lat:Number(lat)},started=performance.now(),duration=650;
  function step(now){const p=Math.min(1,(now-started)/duration),ease=1-Math.pow(1-p,3);marker.setLngLat([from.lng+(to.lng-from.lng)*ease,from.lat+(to.lat-from.lat)*ease]);if(p<1)requestAnimationFrame(step);}
  requestAnimationFrame(step);
}
function upsertDriver(d,animate=true){
  if(!mapReady)return;
  const id=String(d.user_id);let marker=driverMarkers.get(id);
  if(!marker){marker=new maplibregl.Marker({element:driverElement(d),anchor:'center'}).setLngLat([Number(d.longitude),Number(d.latitude)]).addTo(map);driverMarkers.set(id,marker);}
  else if(animate)smoothMove(marker,d.latitude,d.longitude);else marker.setLngLat([Number(d.longitude),Number(d.latitude)]);
  syncDriverVisual(marker,d);
}
function removeMissingDrivers(){
  const ids=new Set(snapshotState.drivers.map(x=>String(x.user_id)));
  for(const [id,m] of driverMarkers){if(!ids.has(id)){m.remove();driverMarkers.delete(id);}}
}
function tripPopup(t){return '<b>'+esc(t.status_fa||t.status)+'</b><br>مسافر: '+esc(t.passenger_name||'مسافر RADO')+' — '+esc(t.passenger_phone||'')+'<br>راننده: '+esc(t.driver_name||'هنوز تخصیص نشده')+'<br>'+esc(t.pickup_label||'مبدا')+' ← '+esc(t.destination_label||'مقصد')+'<br>کرایه: '+money(t.final_fare??t.estimated_fare)+'<br>انتظار: '+waitLabel(t.wait_seconds)+'<br>'+esc(t.requested_at_jalali||'');}
function passengerPopup(t){return '<b>'+esc(t.passenger_name||'مسافر RADO')+'</b><br>'+esc(t.passenger_phone||'')+'<br>موقعیت زنده مسافر<br>دقت GPS: '+esc(t.passenger_accuracy_m==null?'—':Math.round(t.passenger_accuracy_m)+' متر')+'<br>آخرین بروزرسانی: '+esc(t.passenger_last_seen_at_jalali||'همین حالا');}
function geoData(){
  const points=[],lines=[],passengers=[];
  for(const t of snapshotState.trips){
    const waiting=['requested','searching'].includes(t.status);
    const popup=tripPopup(t);
    points.push({type:'Feature',geometry:{type:'Point',coordinates:[Number(t.pickup_lng),Number(t.pickup_lat)]},properties:{kind:'pickup',waiting:waiting?1:0,popup}});
    points.push({type:'Feature',geometry:{type:'Point',coordinates:[Number(t.destination_lng),Number(t.destination_lat)]},properties:{kind:'destination',waiting:0,popup:'<b>مقصد</b><br>'+esc(t.destination_label||'—')}});
    if(!waiting)lines.push({type:'Feature',geometry:{type:'LineString',coordinates:[[Number(t.pickup_lng),Number(t.pickup_lat)],[Number(t.destination_lng),Number(t.destination_lat)]]},properties:{trip_id:String(t.id)}});
    if(t.passenger_lat!=null&&t.passenger_lng!=null)passengers.push({type:'Feature',geometry:{type:'Point',coordinates:[Number(t.passenger_lng),Number(t.passenger_lat)]},properties:{popup:passengerPopup(t)}});
  }
  return {
    points:{type:'FeatureCollection',features:points},
    lines:{type:'FeatureCollection',features:lines},
    passengers:{type:'FeatureCollection',features:passengers},
  };
}
function setGeoData(){
  if(!mapReady)return;
  const data=geoData();
  const a=map.getSource('rado-trip-points'),b=map.getSource('rado-trip-lines'),c=map.getSource('rado-passengers');
  if(a)a.setData(data.points);if(b)b.setData(data.lines);if(c)c.setData(data.passengers);
}
function addLiveLayers(){
  const empty={type:'FeatureCollection',features:[]};
  map.addSource('rado-trip-lines',{type:'geojson',data:empty});
  map.addLayer({id:'rado-trip-lines',type:'line',source:'rado-trip-lines',paint:{'line-color':'#171717','line-width':2.8,'line-opacity':.48,'line-dasharray':[2,2]}});
  map.addSource('rado-trip-points',{type:'geojson',data:empty});
  map.addLayer({id:'rado-pickups',type:'circle',source:'rado-trip-points',filter:['==',['get','kind'],'pickup'],paint:{'circle-radius':9,'circle-color':['case',['==',['get','waiting'],1],'#e53935','#1565c0'],'circle-stroke-width':3,'circle-stroke-color':'#ffffff'}});
  map.addLayer({id:'rado-destinations',type:'circle',source:'rado-trip-points',filter:['==',['get','kind'],'destination'],paint:{'circle-radius':8,'circle-color':'#8e24aa','circle-stroke-width':3,'circle-stroke-color':'#ffffff'}});
  map.addSource('rado-passengers',{type:'geojson',data:empty});
  map.addLayer({id:'rado-passengers',type:'circle',source:'rado-passengers',paint:{'circle-radius':11,'circle-color':'#ff8a00','circle-stroke-width':3,'circle-stroke-color':'#ffffff'}});
  for(const layer of ['rado-pickups','rado-destinations','rado-passengers']){
    map.on('mouseenter',layer,()=>map.getCanvas().style.cursor='pointer');
    map.on('mouseleave',layer,()=>map.getCanvas().style.cursor='');
    map.on('click',layer,e=>{const f=e.features?.[0];if(!f)return;const html=String(f.properties?.popup||'');new maplibregl.Popup({closeButton:false,offset:12}).setLngLat(e.lngLat).setHTML(html).addTo(map);});
  }
}
function updateCounters(){
  const activeDrivers=new Set(snapshotState.trips.filter(t=>['driver_assigned','driver_arriving','arrived','in_progress'].includes(t.status)&&t.driver_id).map(t=>String(t.driver_id)));
  document.getElementById('onlineCount').textContent=snapshotState.drivers.length;
  document.getElementById('freeCount').textContent=Math.max(0,snapshotState.drivers.length-activeDrivers.size);
  document.getElementById('waitingCount').textContent=snapshotState.trips.filter(t=>['requested','searching'].includes(t.status)).length;
  document.getElementById('tripCount').textContent=snapshotState.trips.filter(t=>['driver_assigned','driver_arriving','arrived','in_progress'].includes(t.status)).length;
}
function renderSnapshot(data){
  snapshotState={drivers:data.drivers||[],trips:data.trips||[]};
  removeMissingDrivers();
  for(const d of snapshotState.drivers)upsertDriver(d,true);
  setGeoData();updateCounters();
  document.getElementById('liveTime').textContent=data.time||'';
}
async function loadSnapshot(){
  try{
    const r=await fetch('/admin/live-map.php?json=1',{cache:'no-store'});
    const data=await r.json();
    if(!data.ok)throw new Error('snapshot_failed');
    renderSnapshot(data);
    if(!streamHealthy)setConnection('connecting','داده زنده آماده است؛ در حال اتصال به Stream…');
  }catch(e){setConnection('offline','خطا در دریافت Snapshot');}
}
function scheduleSnapshot(delay=180){
  clearTimeout(snapshotTimer);snapshotTimer=setTimeout(loadSnapshot,delay);
}
function patchDriver(payload){
  const id=String(payload.driver_id||'');if(!id)return;
  const index=snapshotState.drivers.findIndex(x=>String(x.user_id)===id);
  if(payload.online===false){if(index>=0)snapshotState.drivers.splice(index,1);const m=driverMarkers.get(id);if(m){m.remove();driverMarkers.delete(id);}updateCounters();return;}
  if(index<0){scheduleSnapshot(80);return;}
  const d=snapshotState.drivers[index];
  if(payload.lat!=null)d.latitude=Number(payload.lat);if(payload.lng!=null)d.longitude=Number(payload.lng);
  if(payload.heading!=null)d.heading=Number(payload.heading);if(payload.speed_kph!=null)d.speed_kph=Number(payload.speed_kph);
  upsertDriver(d,true);
}
function patchPassenger(payload){
  const trip=snapshotState.trips.find(t=>String(t.id)===String(payload.trip_id||''));if(!trip)return;
  trip.passenger_lat=Number(payload.lat);trip.passenger_lng=Number(payload.lng);trip.passenger_accuracy_m=payload.accuracy_m==null?null:Number(payload.accuracy_m);trip.passenger_last_seen_at_jalali='همین حالا';setGeoData();
}
function connectStream(){
  if(!window.EventSource){setConnection('offline','مرورگر EventSource را پشتیبانی نمی‌کند');return;}
  const es=new EventSource('/admin/live-stream.php');
  es.addEventListener('ready',()=>{streamHealthy=true;setConnection('live','اتصال زنده برقرار است');feed('Stream زنده متصل شد');});
  es.addEventListener('heartbeat',e=>{streamHealthy=true;setConnection('live','اتصال زنده برقرار است');try{const d=JSON.parse(e.data);document.getElementById('liveTime').textContent=d.server_time?.jalali||document.getElementById('liveTime').textContent;}catch(_){}});
  es.addEventListener('ops',e=>{
    streamHealthy=true;setConnection('live','اتصال زنده برقرار است');
    let evt;try{evt=JSON.parse(e.data);}catch(_){return;}
    const type=String(evt.event_type||''),payload=evt.payload||{};
    if((type==='presence'||type==='driver_location')&&payload.driver_id){patchDriver(payload);return;}
    if(type==='passenger_location'){patchPassenger(payload);return;}
    const label={trip_created:'درخواست سفر جدید',trip_assigned:'راننده به سفر تخصیص یافت',arrived:'راننده به مبدا رسید',started:'سفر شروع شد',completed:'سفر پایان یافت',cancelled:'سفر لغو شد'}[type];
    if(label)feed(label);
    scheduleSnapshot(90);
  });
  es.addEventListener('reconnect',()=>{streamHealthy=false;setConnection('connecting','در حال اتصال مجدد…');});
  es.addEventListener('stream_error',()=>{streamHealthy=false;setConnection('connecting','Stream موقتاً قطع شد؛ Snapshot فعال است');});
  es.onerror=()=>{streamHealthy=false;setConnection('connecting','در حال بازیابی اتصال زنده…');};
}
async function initMap(){
  try{
    if(!window.maplibregl)throw new Error('Neshan MapLibre SDK بارگذاری نشد.');
    const r=await fetch('/api/v1/maps/config/',{cache:'no-store'}),cfg=await r.json();
    if(!r.ok||!cfg.ok||!cfg.map_key)throw new Error(cfg.message||'Web Map Key نشان تنظیم نشده است.');
    map=new maplibregl.Map({
      container:'map',
      style:'https://static.neshan.org/sdk/maplibre/styles/light.json',
      center:[45.8853,35.9968],
      zoom:14,
      minZoom:9,
      maxZoom:19,
      apiKey:String(cfg.map_key),
      attributionControl:true,
    });
    map.addControl(new maplibregl.NavigationControl({showCompass:true,showZoom:true}),'top-left');
    map.on('load',async()=>{
      mapReady=true;addLiveLayers();await loadSnapshot();connectStream();
      clearInterval(fallbackTimer);fallbackTimer=setInterval(loadSnapshot,30000);
      setTimeout(()=>{if(snapshotState.drivers.length||snapshotState.trips.length)fitAll();},500);
    });
    map.on('error',e=>{if(!mapReady&&e?.error?.message)failMap('خطای نقشه نشان: '+e.error.message);});
  }catch(e){failMap(String(e?.message||e));}
}
function fitAll(){
  if(!mapReady)return;
  const bounds=new maplibregl.LngLatBounds();let count=0;
  for(const d of snapshotState.drivers){bounds.extend([Number(d.longitude),Number(d.latitude)]);count++;}
  for(const t of snapshotState.trips){bounds.extend([Number(t.pickup_lng),Number(t.pickup_lat)]);bounds.extend([Number(t.destination_lng),Number(t.destination_lat)]);count+=2;if(t.passenger_lat!=null&&t.passenger_lng!=null){bounds.extend([Number(t.passenger_lng),Number(t.passenger_lat)]);count++;}}
  if(count>0)map.fitBounds(bounds,{padding:70,maxZoom:15,duration:600});
}
initMap();
</script>
<?php ra_footer(); ?>
