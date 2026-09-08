<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
$root = dirname(__DIR__, 2);
require $root . '/rado-system/lib/app.php';
$config = require $root . '/rado-system/config.php';
$stateDir = $root . '/rado-system/state';
@mkdir($stateDir, 0755, true);
@mkdir($root . '/downloads', 0755, true);
$lock = fopen($stateDir . '/update.lock', 'c+');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit("Another update is running\n");
function httpGet(string $url, array $config): string {
    $ch = curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>60,CURLOPT_HTTPHEADER=>['Accept: application/vnd.github+json','User-Agent: '.$config['user_agent']]]);
    $body=curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE); $err=curl_error($ch); curl_close($ch);
    if($body===false||$code<200||$code>=300) throw new RuntimeException("HTTP $code for $url: $err");
    return (string)$body;
}
function downloadFile(string $url,string $dest,array $config):void{
    $fp=fopen($dest,'wb'); if(!$fp) throw new RuntimeException("Cannot write $dest");
    $ch=curl_init($url); curl_setopt_array($ch,[CURLOPT_FILE=>$fp,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>180,CURLOPT_HTTPHEADER=>['User-Agent: '.$config['user_agent']]]);
    $ok=curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE); $err=curl_error($ch); curl_close($ch); fclose($fp);
    if(!$ok||$code<200||$code>=300){@unlink($dest);throw new RuntimeException("Download failed ($code): $err");}
}
function assetByName(array $assets,string $name):?array{foreach($assets as $asset) if(($asset['name']??'')===$name) return $asset; return null;}
function shouldPreserve(string $rel, array $config): bool {
    foreach (($config['preserve'] ?? []) as $path) {
        $path = trim((string)$path, '/');
        if ($rel === $path || str_starts_with($rel, $path . '/')) return true;
    }
    return false;
}
function runPhpScript(string $root,string $relative,string $label,bool $required=true): void {
    $script=$root.'/'.$relative;
    if(!is_file($script)){if($required)throw new RuntimeException("$label script is missing: $relative");return;}
    $cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' 2>&1';$output=[];$code=0;exec($cmd,$output,$code);
    if($code!==0&&$required)throw new RuntimeException($label.' failed: '.trim(implode(' | ',$output)));
    if($code!==0)@file_put_contents($root.'/rado-system/state/tick-errors.log','['.rado_jalali_datetime(null,true).'] '.$label.': '.trim(implode(' | ',$output))."\n",FILE_APPEND|LOCK_EX);
}
try{
    $repo=$config['repo'];
    $release=json_decode(httpGet($config['github_api']."/repos/$repo/releases/latest",$config),true,512,JSON_THROW_ON_ERROR);
    $tag=(string)($release['tag_name']??''); if($tag==='') throw new RuntimeException('Latest GitHub release has no tag');
    $current=is_file($stateDir.'/current_tag')?trim((string)file_get_contents($stateDir.'/current_tag')):'';
    $assets=$release['assets']??[];
    $metaAsset=assetByName($assets,'RADO-release.json'); if(!$metaAsset) throw new RuntimeException('RADO-release.json missing from GitHub release');
    $tmpMeta=tempnam(sys_get_temp_dir(),'rado-meta-'); downloadFile($metaAsset['browser_download_url'],$tmpMeta,$config);
    $meta=json_decode((string)file_get_contents($tmpMeta),true,512,JSON_THROW_ON_ERROR); @unlink($tmpMeta);
    foreach(['passenger','driver'] as $app){
        $assetName=(string)($meta['apps'][$app]['asset']??''); if($assetName==='') continue;
        $asset=assetByName($assets,$assetName); if(!$asset) throw new RuntimeException("Missing release asset: $assetName");
        $dest=$root.'/downloads/'.basename($assetName);
        $expected=(string)($meta['apps'][$app]['sha256']??'');
        if(!is_file($dest)||($expected!==''&&hash_file('sha256',$dest)!==$expected)){
            $tmp=$dest.'.tmp'; downloadFile($asset['browser_download_url'],$tmp,$config);
            if($expected!==''&&hash_file('sha256',$tmp)!==$expected){@unlink($tmp);throw new RuntimeException("SHA256 mismatch for $assetName");}
            rename($tmp,$dest);
        }
    }
    if($current!==$tag&&!empty($meta['cpanel']['asset'])){
        $cpName=basename((string)$meta['cpanel']['asset']); $cpAsset=assetByName($assets,$cpName); if(!$cpAsset) throw new RuntimeException("Missing cPanel asset: $cpName");
        $tmpZip=tempnam(sys_get_temp_dir(),'rado-cp-'); downloadFile($cpAsset['browser_download_url'],$tmpZip,$config);
        $expected=(string)($meta['cpanel']['sha256']??''); if($expected!==''&&hash_file('sha256',$tmpZip)!==$expected){@unlink($tmpZip);throw new RuntimeException('cPanel SHA256 mismatch');}
        $zip=new ZipArchive(); if($zip->open($tmpZip)!==true) throw new RuntimeException('Cannot open cPanel ZIP');
        $tmpDir=sys_get_temp_dir().'/rado-extract-'.bin2hex(random_bytes(6)); mkdir($tmpDir,0755,true); $zip->extractTo($tmpDir); $zip->close();
        $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmpDir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
        foreach($it as $item){
            $rel=substr($item->getPathname(),strlen($tmpDir)+1);
            if(shouldPreserve($rel,$config)) continue;
            $dest=$root.'/'.$rel;
            if($item->isDir()) @mkdir($dest,0755,true); else {@mkdir(dirname($dest),0755,true);copy($item->getPathname(),$dest);}
        }
        @unlink($tmpZip);
        runPhpScript($root,'rado-system/bin/rado-migrate.php','Database migration',true);
    }
    file_put_contents($stateDir.'/release.json.tmp',json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)); rename($stateDir.'/release.json.tmp',$stateDir.'/release.json');
    file_put_contents($stateDir.'/current_tag',$tag."\n");
    // Every existing 5-minute updater run is also the operational platform tick.
    runPhpScript($root,'rado-system/bin/rado-tick.php','Platform tick',false);
    file_put_contents($stateDir.'/last_success_at',rado_jalali_datetime(null,true)."\n");
    file_put_contents($stateDir.'/last_success_iso',rado_now_iso_tehran()."\n");
    echo "RADO updated to $tag at ".rado_jalali_datetime(null,true)." Asia/Tehran\n";
}catch(Throwable $e){
    file_put_contents($stateDir.'/last_error.log','['.rado_jalali_datetime(null,true).'] '.$e->getMessage()."\n",FILE_APPEND);
    fwrite(STDERR,$e->getMessage()."\n");
    exit(1);
}finally{flock($lock,LOCK_UN);fclose($lock);}