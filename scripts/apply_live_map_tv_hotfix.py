from pathlib import Path


def replace_once(path: Path, old: str, new: str) -> None:
    text = path.read_text(encoding='utf-8')
    if old not in text:
        raise SystemExit(f'Expected block not found in {path}: {old[:120]!r}')
    path.write_text(text.replace(old, new, 1), encoding='utf-8')


ui = Path('deploy/cpanel/admin/_ui.php')
replace_once(
    ui,
    "    'operations'=>['عملیات','/admin/operations.php','◎'],\n    'finance'=>['مالی','/admin/finance.php','﷼'],",
    "    'operations'=>['عملیات','/admin/operations.php','◎'],\n    'live'=>['زنده','/admin/live-map.php','●'],\n    'finance'=>['مالی','/admin/finance.php','﷼'],",
)

config = Path('deploy/cpanel/api/v1/maps/config/index.php')
replace_once(
    config,
    "$webKey = trim((string)($secrets['neshan_web_map_key'] ?? ''));\nif ($webKey === '') {",
    "$webKey = trim((string)($secrets['neshan_web_map_key'] ?? ''));\n$legacyKey = trim((string)($secrets['neshan_map_key'] ?? ''));\n$usingLegacy = false;\nif ($webKey === '' && $legacyKey !== '') {\n    $webKey = $legacyKey;\n    $usingLegacy = true;\n}\nif ($webKey === '') {",
)
replace_once(
    config,
    "    'map_key'=>$webKey,\n    'timezone'=>'Asia/Tehran',",
    "    'map_key'=>$webKey,\n    'legacy_key'=>$usingLegacy,\n    'timezone'=>'Asia/Tehran',",
)

maps = Path('deploy/cpanel/admin/maps.php')
replace_once(
    maps,
    '<span class="muted">نمایش نقشه داخل Passenger</span>',
    '<span class="muted">نمایش نقشه در اپ‌ها و مرکز عملیات زنده</span>',
)

live = Path('deploy/cpanel/admin/live-map.php')
replace_once(
    live,
    "ra_header('مرکز عملیات زنده RADO','operations','نمایش لحظه‌ای رانندگان، درخواست‌ها و سفرهای فعال بانه');",
    "ra_header('مرکز عملیات زنده RADO','live','نمایش لحظه‌ای رانندگان، درخواست‌ها و سفرهای فعال بانه');",
)
replace_once(
    live,
    '<script src="https://cdn.jsdelivr.net/npm/@neshan-maps-platform/maplibre-sdk@5.24.4/dist/neshan-maplibre-sdk.umd.js"></script>\n<script>',
    '<script>',
)
replace_once(
    live,
    "let streamHealthy=false;",
    "let streamHealthy=false;\nlet streamStarted=false;\nlet mapLoadTimer=null;",
)
replace_once(
    live,
    "function failMap(message){document.getElementById('mapErrorText').textContent=message;document.getElementById('mapError').classList.add('show');setConnection('offline','نقشه نشان در دسترس نیست');}",
    "function failMap(message){document.getElementById('mapErrorText').textContent=message;document.getElementById('mapError').classList.add('show');setConnection('offline','نقشه نشان در دسترس نیست');feed(message);}",
)
replace_once(
    live,
    "function connectStream(){\n  if(!window.EventSource){setConnection('offline','مرورگر EventSource را پشتیبانی نمی‌کند');return;}\n  const es=new EventSource('/admin/live-stream.php');",
    "function connectStream(){\n  if(streamStarted)return;streamStarted=true;\n  if(!window.EventSource){setConnection('offline','مرورگر EventSource را پشتیبانی نمی‌کند');return;}\n  const es=new EventSource('/admin/live-stream.php');",
)
replace_once(
    live,
    "    map.on('load',async()=>{\n      mapReady=true;addLiveLayers();await loadSnapshot();connectStream();\n      clearInterval(fallbackTimer);fallbackTimer=setInterval(loadSnapshot,30000);\n      setTimeout(()=>{if(snapshotState.drivers.length||snapshotState.trips.length)fitAll();},500);\n    });\n    map.on('error',e=>{if(!mapReady&&e?.error?.message)failMap('خطای نقشه نشان: '+e.error.message);});",
    "    mapLoadTimer=setTimeout(()=>{if(!mapReady)failMap('بارگذاری نقشه نشان بیش از حد طول کشید. Web Map Key، دسترسی دامنه و اینترنت تلویزیون را بررسی کنید.');},12000);\n    map.on('load',async()=>{\n      clearTimeout(mapLoadTimer);mapReady=true;document.getElementById('mapError').classList.remove('show');addLiveLayers();await loadSnapshot();\n      setConnection(streamHealthy?'live':'connecting',streamHealthy?'اتصال زنده برقرار است':'نقشه نشان آماده است؛ در حال اتصال زنده…');\n      setTimeout(()=>{if(snapshotState.drivers.length||snapshotState.trips.length)fitAll();},500);\n    });\n    map.on('error',e=>{if(!mapReady){const msg=e?.error?.message||e?.message||'خطای ناشناخته MapLibre';failMap('خطای نقشه نشان: '+msg);}});",
)
replace_once(
    live,
    "initMap();\n</script>",
    "function loadScript(src,timeoutMs=8000){\n  return new Promise((resolve,reject)=>{\n    const s=document.createElement('script');let done=false;\n    const timer=setTimeout(()=>{if(done)return;done=true;s.remove();reject(new Error('timeout '+src));},timeoutMs);\n    s.src=src;s.async=true;\n    s.onload=()=>{if(done)return;done=true;clearTimeout(timer);resolve();};\n    s.onerror=()=>{if(done)return;done=true;clearTimeout(timer);s.remove();reject(new Error('load failed '+src));};\n    document.head.appendChild(s);\n  });\n}\nasync function loadNeshanSdk(){\n  if(window.maplibregl?.Map)return;\n  const urls=[\n    'https://cdn.jsdelivr.net/npm/@neshan-maps-platform/maplibre-sdk@5.24.4/dist/neshan-maplibre-sdk.umd.js',\n    'https://unpkg.com/@neshan-maps-platform/maplibre-sdk@5.24.4/dist/neshan-maplibre-sdk.umd.js'\n  ];\n  const errors=[];\n  for(const url of urls){\n    try{setConnection('connecting','در حال دریافت موتور نقشه نشان…');await loadScript(url);if(window.maplibregl?.Map){feed('SDK نقشه نشان بارگذاری شد');return;}}catch(e){errors.push(String(e?.message||e));}\n  }\n  throw new Error('SDK نقشه نشان بارگذاری نشد. '+errors.join(' | '));\n}\nasync function bootLiveMap(){\n  await loadSnapshot();\n  connectStream();\n  clearInterval(fallbackTimer);fallbackTimer=setInterval(loadSnapshot,30000);\n  try{\n    await loadNeshanSdk();\n    setConnection('connecting','در حال دریافت نقشه از نشان…');\n    await initMap();\n  }catch(e){failMap(String(e?.message||e));}\n}\nbootLiveMap();\n</script>",
)

print('Live map TV hotfix applied successfully.')
