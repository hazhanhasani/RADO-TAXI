from pathlib import Path
import re


def replace_once(text: str, old: str, new: str, label: str) -> str:
    count = text.count(old)
    if count != 1:
        raise SystemExit(f"{label}: expected exactly 1 match, got {count}")
    return text.replace(old, new, 1)


def regex_once(text: str, pattern: str, replacement: str, label: str) -> str:
    out, count = re.subn(pattern, replacement, text, count=1, flags=re.S)
    if count != 1:
        raise SystemExit(f"{label}: expected exactly 1 match, got {count}")
    return out


# Passenger: the visible selector tip must land on the exact map centre.
passenger = Path("apps/passenger/lib/runtime_passenger.dart")
s = passenger.read_text()

s = replace_once(
    s,
    """  Future<LatLng> _exactCenter() async {
    if (!_mapReady) return _center;
    try {
      final point = await _map.getCurrentLocation().timeout(
        const Duration(seconds: 3),
      );
      if (point != null) _center = point;
    } catch (_) {}
    return _center;
  }
""",
    """  Future<LatLng> _exactCenter() async {
    if (!_mapReady) return _center;
    try {
      await _map.ready.timeout(const Duration(seconds: 3));
      // The Neshan map runs inside a WebView. Give the final camera frame a
      // moment to settle, then read the centre twice so a fast drag + confirm
      // cannot save the previous frame's coordinate.
      await Future<void>.delayed(const Duration(milliseconds: 90));
      final point = await _map.getCurrentLocation().timeout(
        const Duration(seconds: 3),
      );
      if (point != null) _center = point;
      await Future<void>.delayed(const Duration(milliseconds: 40));
      final verified = await _map.getCurrentLocation().timeout(
        const Duration(seconds: 2),
      );
      if (verified != null) _center = verified;
    } catch (_) {}
    return _center;
  }
""",
    "precise map centre",
)

s = replace_once(
    s,
    """    return Transform.translate(
      offset: const Offset(0, -38),
""",
    """    // The selector is about 108 px tall. Shifting by half its height
    // places the very bottom anchor dot exactly on the map camera centre.
    return Transform.translate(
      offset: const Offset(0, -54),
""",
    "selection pin anchor",
)
passenger.write_text(s)


# Admin live map: use native MapLibre GeoJSON layers for drivers. This avoids
# DOM-marker compatibility issues in the Neshan UMD wrapper and scales better.
live = Path("deploy/cpanel/admin/live-map.php")
s = live.read_text()
s = s.replace("const driverMarkers=new Map();\n", "", 1)

s = regex_once(
    s,
    r"function driverElement\(d\)\{.*?\n\}\nfunction tripPopup",
    "function tripPopup",
    "remove DOM driver marker implementation",
)

s = regex_once(
    s,
    r"function geoData\(\)\{.*?\n\}\nfunction updateCounters",
    r"""function geoData(){
  const points=[],lines=[],passengers=[],drivers=[];
  for(const d of snapshotState.drivers){
    const lat=Number(d.latitude),lng=Number(d.longitude);
    if(!Number.isFinite(lat)||!Number.isFinite(lng))continue;
    const state=statusClass(d.user_id);
    drivers.push({
      type:'Feature',
      geometry:{type:'Point',coordinates:[lng,lat]},
      properties:{
        driver_id:String(d.user_id),
        state,
        heading:Number(d.heading||0),
        popup:driverPopup(d),
      },
    });
  }
  for(const t of snapshotState.trips){
    const waiting=['requested','searching'].includes(t.status);
    const popup=tripPopup(t);
    points.push({type:'Feature',geometry:{type:'Point',coordinates:[Number(t.pickup_lng),Number(t.pickup_lat)]},properties:{kind:'pickup',waiting:waiting?1:0,popup}});
    points.push({type:'Feature',geometry:{type:'Point',coordinates:[Number(t.destination_lng),Number(t.destination_lat)]},properties:{kind:'destination',waiting:0,popup:'<b>مقصد</b><br>'+esc(t.destination_label||'—')}});
    if(!waiting)lines.push({type:'Feature',geometry:{type:'LineString',coordinates:[[Number(t.pickup_lng),Number(t.pickup_lat)],[Number(t.destination_lng),Number(t.destination_lat)]]},properties:{trip_id:String(t.id)}});
    if(t.passenger_lat!=null&&t.passenger_lng!=null)passengers.push({type:'Feature',geometry:{type:'Point',coordinates:[Number(t.passenger_lng),Number(t.passenger_lat)]},properties:{popup:passengerPopup(t)}});
  }
  return {
    drivers:{type:'FeatureCollection',features:drivers},
    points:{type:'FeatureCollection',features:points},
    lines:{type:'FeatureCollection',features:lines},
    passengers:{type:'FeatureCollection',features:passengers},
  };
}
function setGeoData(){
  if(!mapReady)return;
  const data=geoData();
  const d=map.getSource('rado-drivers'),a=map.getSource('rado-trip-points'),b=map.getSource('rado-trip-lines'),c=map.getSource('rado-passengers');
  if(d)d.setData(data.drivers);if(a)a.setData(data.points);if(b)b.setData(data.lines);if(c)c.setData(data.passengers);
}
function addLiveLayers(){
  const empty={type:'FeatureCollection',features:[]};
  map.addSource('rado-drivers',{type:'geojson',data:empty});
  map.addLayer({id:'rado-drivers-halo',type:'circle',source:'rado-drivers',paint:{'circle-radius':18,'circle-color':'#ffffff','circle-opacity':.92,'circle-stroke-width':1,'circle-stroke-color':'#171717','circle-stroke-opacity':.15}});
  map.addLayer({id:'rado-drivers',type:'circle',source:'rado-drivers',paint:{'circle-radius':13,'circle-color':['match',['get','state'],'free','#2fbf71','intrip','#4b86e8','#f5b400'],'circle-stroke-width':3,'circle-stroke-color':'#171717'}});
  map.addLayer({id:'rado-driver-core',type:'circle',source:'rado-drivers',paint:{'circle-radius':4.5,'circle-color':'#171717','circle-stroke-width':1.5,'circle-stroke-color':'#ffffff'}});
  map.addSource('rado-trip-lines',{type:'geojson',data:empty});
  map.addLayer({id:'rado-trip-lines',type:'line',source:'rado-trip-lines',paint:{'line-color':'#171717','line-width':2.8,'line-opacity':.48,'line-dasharray':[2,2]}});
  map.addSource('rado-trip-points',{type:'geojson',data:empty});
  map.addLayer({id:'rado-pickups',type:'circle',source:'rado-trip-points',filter:['==',['get','kind'],'pickup'],paint:{'circle-radius':9,'circle-color':['case',['==',['get','waiting'],1],'#e53935','#1565c0'],'circle-stroke-width':3,'circle-stroke-color':'#ffffff'}});
  map.addLayer({id:'rado-destinations',type:'circle',source:'rado-trip-points',filter:['==',['get','kind'],'destination'],paint:{'circle-radius':8,'circle-color':'#8e24aa','circle-stroke-width':3,'circle-stroke-color':'#ffffff'}});
  map.addSource('rado-passengers',{type:'geojson',data:empty});
  map.addLayer({id:'rado-passengers',type:'circle',source:'rado-passengers',paint:{'circle-radius':11,'circle-color':'#ff8a00','circle-stroke-width':3,'circle-stroke-color':'#ffffff'}});
  for(const layer of ['rado-drivers','rado-pickups','rado-destinations','rado-passengers']){
    map.on('mouseenter',layer,()=>map.getCanvas().style.cursor='pointer');
    map.on('mouseleave',layer,()=>map.getCanvas().style.cursor='');
    map.on('click',layer,e=>{const f=e.features?.[0];if(!f)return;const html=String(f.properties?.popup||'');new maplibregl.Popup({closeButton:false,offset:12}).setLngLat(e.lngLat).setHTML(html).addTo(map);});
  }
}
function updateCounters""",
    "driver GeoJSON layers",
)

s = replace_once(
    s,
    """function renderSnapshot(data){
  snapshotState={drivers:data.drivers||[],trips:data.trips||[]};
  removeMissingDrivers();
  for(const d of snapshotState.drivers)upsertDriver(d,true);
  setGeoData();updateCounters();
  document.getElementById('liveTime').textContent=data.time||'';
}
""",
    """function renderSnapshot(data){
  snapshotState={drivers:data.drivers||[],trips:data.trips||[]};
  setGeoData();updateCounters();
  document.getElementById('liveTime').textContent=data.time||'';
}
""",
    "snapshot driver rendering",
)

s = regex_once(
    s,
    r"function patchDriver\(payload\)\{.*?\n\}\nfunction patchPassenger",
    r"""function patchDriver(payload){
  const id=String(payload.driver_id||'');if(!id)return;
  const index=snapshotState.drivers.findIndex(x=>String(x.user_id)===id);
  if(payload.online===false){
    if(index>=0)snapshotState.drivers.splice(index,1);
    setGeoData();updateCounters();return;
  }
  if(index<0){scheduleSnapshot(80);return;}
  const d=snapshotState.drivers[index];
  if(payload.lat!=null)d.latitude=Number(payload.lat);if(payload.lng!=null)d.longitude=Number(payload.lng);
  if(payload.heading!=null)d.heading=Number(payload.heading);if(payload.speed_kph!=null)d.speed_kph=Number(payload.speed_kph);
  d.last_seen_at_jalali='همین حالا';
  setGeoData();updateCounters();
}
function patchPassenger""",
    "realtime driver GeoJSON patch",
)

s = replace_once(
    s,
    """  if(count>0)map.fitBounds(bounds,{padding:70,maxZoom:15,duration:600});
""",
    """  if(count===1){
    const d=snapshotState.drivers[0];
    if(d)map.easeTo({center:[Number(d.longitude),Number(d.latitude)],zoom:15,duration:500});
    else map.fitBounds(bounds,{padding:70,maxZoom:15,duration:600});
  }else if(count>1)map.fitBounds(bounds,{padding:70,maxZoom:15,duration:600});
""",
    "single driver camera fit",
)

live.write_text(s)
print("RADO map precision + driver GeoJSON hotfix applied")
