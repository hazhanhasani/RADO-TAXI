<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__, 2);
require_once $root . '/rado-system/lib/app.php';
$config = require $root . '/rado-system/config.php';
$stateDir = $root . '/rado-system/state';
@mkdir($stateDir, 0755, true);
@mkdir($root . '/downloads', 0755, true);

$lock = fopen($stateDir . '/update.lock', 'c+');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit("Another update is running\n");

function radoUpdaterNowIsoTehran(): string {
    try {
        if (function_exists('rado_tehran_datetime')) return rado_tehran_datetime()->format(DateTimeInterface::ATOM);
        return (new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran')))->format(DateTimeInterface::ATOM);
    } catch (Throwable) {
        return date('c');
    }
}

function radoUpdaterCurlGet(string $url, array $headers, int $connectTimeout, int $timeout): array {
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_CONNECTTIMEOUT=>$connectTimeout,CURLOPT_TIMEOUT=>$timeout,CURLOPT_HTTPHEADER=>$headers]);
    $body=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$err=curl_error($ch);curl_close($ch);
    return [$body,$code,$err];
}
function radoUpdaterHttpGet(string $url, array $config): string {
    $last='';
    for($attempt=1;$attempt<=3;$attempt++){
        [$body,$code,$err]=radoUpdaterCurlGet($url,['Accept: application/vnd.github+json','User-Agent: '.$config['user_agent']],12,75);
        if($body!==false&&$code>=200&&$code<300)return (string)$body;
        $last="HTTP $code for $url".($err!==''?": $err":'');
        if($attempt<3) usleep(350000*$attempt);
    }
    throw new RuntimeException($last!==''?$last:"Unable to fetch $url");
}
function radoUpdaterDownload(string $url, string $dest, array $config, int $timeout = 180): void {
    $last='';
    for($attempt=1;$attempt<=3;$attempt++){
        $tmp=$dest.'.part';@unlink($tmp);
        $fp=fopen($tmp,'wb');if(!$fp)throw new RuntimeException("Cannot write $tmp");
        $ch=curl_init($url);
        curl_setopt_array($ch,[CURLOPT_FILE=>$fp,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_CONNECTTIMEOUT=>12,CURLOPT_TIMEOUT=>$timeout,CURLOPT_HTTPHEADER=>['User-Agent: '.$config['user_agent']]]);
        $ok=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$err=curl_error($ch);curl_close($ch);fclose($fp);
        if($ok&&$code>=200&&$code<300){@unlink($dest);if(!rename($tmp,$dest)){@unlink($tmp);throw new RuntimeException("Cannot publish download $dest");}return;}
        @unlink($tmp);$last="Download failed ($code)".($err!==''?": $err":'');
        if($attempt<3) usleep(500000*$attempt);
    }
    throw new RuntimeException($last!==''?$last:'Download failed');
}
function radoUpdaterAsset(array $assets, string $name): ?array { foreach($assets as $asset)if(($asset['name']??'')===$name)return $asset;return null; }
function radoUpdaterPreserve(string $rel, array $config): bool { foreach(($config['preserve']??[]) as $path){$path=trim((string)$path,'/');if($rel===$path||str_starts_with($rel,$path.'/'))return true;}return false; }
function radoUpdaterRemoveTree(string $path): void { if(!is_dir($path))return;$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $item)$item->isDir()?@rmdir($item->getPathname()):@unlink($item->getPathname());@rmdir($path); }
function radoUpdaterWriteCurrentError(string $stateDir, string $message): void {
    @file_put_contents($stateDir.'/last_error_current.log',$message,LOCK_EX);
    $history=$stateDir.'/last_error.log';
    $old=is_file($history)?(string)@file_get_contents($history):'';
    $combined=$old.$message;
    if(strlen($combined)>24000)$combined=substr($combined,-24000);
    @file_put_contents($history,$combined,LOCK_EX);
}
function radoUpdaterClearErrors(string $stateDir): void {
    @unlink($stateDir.'/last_error_current.log');
    @unlink($stateDir.'/bootstrap_error_current.log');
    @file_put_contents($stateDir.'/last_error.log','',LOCK_EX);
}

function radoUpdaterInstallCore(string $root,string $stateDir,array $config,array $assets,array $meta,string $tag): void {
    if(empty($meta['cpanel']['asset']))return;
    file_put_contents($stateDir.'/update_status',"installing\n",LOCK_EX);
    $cpName=basename((string)$meta['cpanel']['asset']);$cpAsset=radoUpdaterAsset($assets,$cpName);if(!$cpAsset)throw new RuntimeException("Missing cPanel asset: $cpName");
    $tmpZip=tempnam(sys_get_temp_dir(),'rado-cp-');radoUpdaterDownload((string)$cpAsset['browser_download_url'],$tmpZip,$config,180);
    $expected=(string)($meta['cpanel']['sha256']??'');if($expected!==''&&!hash_equals($expected,(string)hash_file('sha256',$tmpZip))){@unlink($tmpZip);throw new RuntimeException('cPanel SHA256 mismatch');}
    $zip=new ZipArchive();if($zip->open($tmpZip)!==true)throw new RuntimeException('Cannot open cPanel ZIP');
    $tmpDir=sys_get_temp_dir().'/rado-extract-'.bin2hex(random_bytes(6));if(!mkdir($tmpDir,0755,true)&&!is_dir($tmpDir))throw new RuntimeException('Cannot create extraction directory');
    if(!$zip->extractTo($tmpDir))throw new RuntimeException('Cannot extract cPanel ZIP');$zip->close();@unlink($tmpZip);
    foreach(['index.php','rado-system/lib/app.php','rado-system/lib/migrate.php','rado-system/lib/tick.php','rado-system/bin/rado-update.php','rado-system/bin/rado-update-core.php'] as $required){if(!is_file($tmpDir.'/'.$required))throw new RuntimeException("Invalid cPanel package, missing: $required");}
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmpDir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
    foreach($it as $item){$rel=substr($item->getPathname(),strlen($tmpDir)+1);if(radoUpdaterPreserve($rel,$config))continue;$dest=$root.'/'.$rel;if($item->isDir()){@mkdir($dest,0755,true);}else{@mkdir(dirname($dest),0755,true);if(!copy($item->getPathname(),$dest))throw new RuntimeException("Cannot install file: $rel");}}
    radoUpdaterRemoveTree($tmpDir);
    require_once $root.'/rado-system/lib/migrate.php';rado_run_database_migrations($root);
    $tmpState=$stateDir.'/release.json.tmp';file_put_contents($tmpState,json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT),LOCK_EX);rename($tmpState,$stateDir.'/release.json');
    file_put_contents($stateDir.'/current_tag',$tag."\n",LOCK_EX);file_put_contents($stateDir.'/target_tag',$tag."\n",LOCK_EX);file_put_contents($stateDir.'/update_status',"core_ok\n",LOCK_EX);
}
function radoUpdaterMirrorApps(string $root,string $stateDir,array $config,array $assets,array $meta): void {
    $errors=[];$keep=[];
    foreach(['passenger','driver'] as $app){
        try{$assetName=(string)($meta['apps'][$app]['asset']??'');if($assetName==='')continue;$keep[]=basename($assetName);$asset=radoUpdaterAsset($assets,$assetName);if(!$asset)throw new RuntimeException("Missing release asset: $assetName");$dest=$root.'/downloads/'.basename($assetName);$expected=(string)($meta['apps'][$app]['sha256']??'');if(is_file($dest)&&($expected===''||hash_equals($expected,(string)hash_file('sha256',$dest))))continue;$tmp=$dest.'.tmp';radoUpdaterDownload((string)$asset['browser_download_url'],$tmp,$config,360);if($expected!==''&&!hash_equals($expected,(string)hash_file('sha256',$tmp))){@unlink($tmp);throw new RuntimeException("SHA256 mismatch for $assetName");}if(!rename($tmp,$dest))throw new RuntimeException("Cannot publish mirrored APK: $assetName");}catch(Throwable $e){$errors[]=$app.': '.$e->getMessage();}
    }
    if($errors){file_put_contents($stateDir.'/mirror_errors.log','['.rado_jalali_datetime(null,true).'] '.implode(' | ',$errors)."\n",FILE_APPEND|LOCK_EX);file_put_contents($stateDir.'/mirror_status',"partial\n",LOCK_EX);}
    else{
        @unlink($stateDir.'/mirror_errors.log');file_put_contents($stateDir.'/mirror_status',"ok\n",LOCK_EX);
        foreach(glob($root.'/downloads/RADO-*-v*.apk')?:[] as $old){if(!in_array(basename($old),$keep,true))@unlink($old);}
    }
}

try{
    file_put_contents($stateDir.'/update_status',"checking\n",LOCK_EX);
    $repo=$config['repo'];$release=json_decode(radoUpdaterHttpGet($config['github_api']."/repos/$repo/releases/latest",$config),true,512,JSON_THROW_ON_ERROR);
    $tag=(string)($release['tag_name']??'');if($tag==='')throw new RuntimeException('Latest GitHub release has no tag');file_put_contents($stateDir.'/target_tag',$tag."\n",LOCK_EX);
    $current=is_file($stateDir.'/current_tag')?trim((string)file_get_contents($stateDir.'/current_tag')):'';$assets=is_array($release['assets']??null)?$release['assets']:[];$metaAsset=radoUpdaterAsset($assets,'RADO-release.json');if(!$metaAsset)throw new RuntimeException('RADO-release.json missing from GitHub release');
    $tmpMeta=tempnam(sys_get_temp_dir(),'rado-meta-');radoUpdaterDownload((string)$metaAsset['browser_download_url'],$tmpMeta,$config,90);$meta=json_decode((string)file_get_contents($tmpMeta),true,512,JSON_THROW_ON_ERROR);@unlink($tmpMeta);
    if($current!==$tag){
        radoUpdaterInstallCore($root,$stateDir,$config,$assets,$meta,$tag);
    }else{
        $tmpState=$stateDir.'/release.json.tmp';file_put_contents($tmpState,json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT),LOCK_EX);rename($tmpState,$stateDir.'/release.json');
        // Self-heal: migrations are idempotent and must run even when code/tag already matches.
        // This repairs interrupted updates and hotfix deployments where files landed before DB changes.
        require_once $root.'/rado-system/lib/migrate.php';
        rado_run_database_migrations($root);
    }
    radoUpdaterMirrorApps($root,$stateDir,$config,$assets,$meta);
    try{require_once $root.'/rado-system/lib/tick.php';rado_run_platform_tick();@unlink($stateDir.'/tick-errors.log');}catch(Throwable $tickError){@file_put_contents($stateDir.'/tick-errors.log','['.rado_jalali_datetime(null,true).'] '.$tickError->getMessage()."\n",FILE_APPEND|LOCK_EX);}
    file_put_contents($stateDir.'/last_success_at',rado_jalali_datetime(null,true)."\n",LOCK_EX);file_put_contents($stateDir.'/last_success_iso',radoUpdaterNowIsoTehran()."\n",LOCK_EX);file_put_contents($stateDir.'/update_status',"ok\n",LOCK_EX);radoUpdaterClearErrors($stateDir);
    echo "RADO updated to $tag at ".rado_jalali_datetime(null,true)." Asia/Tehran\n";
}catch(Throwable $e){$line='['.rado_jalali_datetime(null,true).'] '.$e->getMessage()."\n";radoUpdaterWriteCurrentError($stateDir,$line);@file_put_contents($stateDir.'/update_status',"error\n",LOCK_EX);fwrite(STDERR,$e->getMessage()."\n");exit(1);
}finally{flock($lock,LOCK_UN);fclose($lock);}