<?php
declare(strict_types=1);
require __DIR__.'/_ui.php';
require_once dirname(__DIR__).'/rado-system/lib/api_ir.php';
ra_require_admin();
$pdo=rado_db();
$root=dirname(__DIR__);
$file=$root.'/rado-system/private/ci-secrets.php';
$secrets=is_file($file)?require $file:[];if(!is_array($secrets))$secrets=[];
$message='';$error='';
function rv_write_secret_array(string $path,array $data):void{
  $body="<?php\nreturn ".var_export($data,true).";\n";
  if(file_put_contents($path,$body,LOCK_EX)===false)throw new RuntimeException('ذخیره کلید API.ir ممکن نشد.');
  @chmod($path,0600);
}
if($_SERVER['REQUEST_METHOD']==='POST'){
  ra_require_csrf();
  try{
    $action=(string)($_POST['action']??'save');
    if($action==='save'){
      $token=trim((string)($_POST['api_ir_token']??''));
      if($token!=='')$secrets['api_ir_token']=$token;
      if(trim((string)($secrets['api_ir_token']??''))==='')throw new RuntimeException('Token سرویس API.ir را وارد کنید.');
      rv_write_secret_array($file,$secrets);
      $shahkar=(string)($_POST['api_ir_shahkar_mode']??'Shahkar');if(!in_array($shahkar,['Shahkar','ShahkarLite'],true))$shahkar='Shahkar';
      $bio=(string)($_POST['api_ir_biometric_mode']??'VideoLive');if(!in_array($bio,['VideoLive','VideoVerify'],true))$bio='VideoLive';
      $match=max(50,min(100,(int)($_POST['api_ir_matching_threshold']??90)));
      $live=max(50,min(100,(int)($_POST['api_ir_liveness_threshold']??80)));
      $speech=max(1,min(100,(int)($_POST['api_ir_speech_threshold']??50)));
      $speechText=mb_substr(trim((string)($_POST['api_ir_speech_text']??'')),0,300,'UTF-8');
      if($speechText==='')$speechText='من با آگاهی کامل قوانین رانندگی رادو را می‌پذیرم';
      rado_set_setting($pdo,'api_ir_shahkar_mode',$shahkar);
      rado_set_setting($pdo,'api_ir_biometric_mode',$bio);
      rado_set_setting($pdo,'api_ir_matching_threshold',(string)$match);
      rado_set_setting($pdo,'api_ir_liveness_threshold',(string)$live);
      rado_set_setting($pdo,'api_ir_speech_threshold',(string)$speech);
      rado_set_setting($pdo,'api_ir_speech_text',$speechText);
      rado_set_setting($pdo,'driver_verification_require_driving_score',isset($_POST['require_driving_score'])?'1':'0');
      rado_set_setting($pdo,'driver_verification_require_active_plates',isset($_POST['require_active_plates'])?'1':'0');
      $message='تنظیمات احراز هویت ذخیره شد — '.rado_jalali_datetime();
    }
  }catch(Throwable $e){$error=$e->getMessage();}
  $secrets=is_file($file)?require $file:$secrets;if(!is_array($secrets))$secrets=[];
}
$configured=trim((string)($secrets['api_ir_token']??''))!=='';
$shahkar=(string)(rado_setting($pdo,'api_ir_shahkar_mode','Shahkar')??'Shahkar');
$bio=(string)(rado_setting($pdo,'api_ir_biometric_mode','VideoLive')??'VideoLive');
$match=(int)(rado_setting($pdo,'api_ir_matching_threshold','90')??'90');
$live=(int)(rado_setting($pdo,'api_ir_liveness_threshold','80')??'80');
$speech=(int)(rado_setting($pdo,'api_ir_speech_threshold','50')??'50');
$speechText=(string)(rado_setting($pdo,'api_ir_speech_text','من با آگاهی کامل قوانین رانندگی رادو را می‌پذیرم')??'');
$needScore=(rado_setting($pdo,'driver_verification_require_driving_score','1')??'1')==='1';
$needPlates=(rado_setting($pdo,'driver_verification_require_active_plates','0')??'0')==='1';
ra_header('API.ir و احراز هویت','verification','کلید محرمانه، آستانه‌های بایومتریک و سیاست تأیید راننده');
?>
<?php if($message):?><div class="notice ok"><?=ra_e($message)?></div><?php endif;?>
<?php if($error):?><div class="notice err"><?=ra_e($error)?></div><?php endif;?>
<div class="grid">
 <div class="card span4 metric"><small>API.ir Token</small><b><?=$configured?'فعال':'تنظیم نشده'?></b><span class="muted">فقط روی cPanel نگهداری می‌شود</span></div>
 <div class="card span4 metric"><small>cURL</small><b><?=function_exists('curl_init')?'آماده':'غیرفعال'?></b><span class="muted">ارتباط HTTPS با API.ir</span></div>
 <div class="card span4 metric"><small>بایومتریک</small><b><?=ra_e($bio)?></b><span class="muted">Match <?=$match?>% · Liveness <?=$live?>%</span></div>
</div>
<div class="grid">
 <div class="card span8">
  <h2 class="section-title">تنظیم اتصال و سیاست KYC</h2>
  <form method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?=ra_e(ra_csrf())?>"><input type="hidden" name="action" value="save">
   <div class="formgrid">
    <div class="field full"><label>API.ir Bearer Token</label><input type="password" name="api_ir_token" placeholder="برای تغییر، Token جدید را وارد کنید"><div class="muted">Token هرگز به اپ راننده یا مرورگر عمومی ارسال نمی‌شود.</div></div>
    <div class="field"><label>سرویس شاهکار</label><select name="api_ir_shahkar_mode"><option value="Shahkar" <?=$shahkar==='Shahkar'?'selected':''?>>Shahkar</option><option value="ShahkarLite" <?=$shahkar==='ShahkarLite'?'selected':''?>>Shahkar Lite</option></select></div>
    <div class="field"><label>مدل بایومتریک</label><select name="api_ir_biometric_mode"><option value="VideoLive" <?=$bio==='VideoLive'?'selected':''?>>VideoLive — چهره + زنده‌سنجی</option><option value="VideoVerify" <?=$bio==='VideoVerify'?'selected':''?>>VideoVerify — چهره + زنده‌سنجی + گفتار</option></select></div>
    <div class="field"><label>آستانه تطبیق چهره</label><input type="number" min="50" max="100" name="api_ir_matching_threshold" value="<?=$match?>"></div>
    <div class="field"><label>آستانه زنده‌سنجی</label><input type="number" min="50" max="100" name="api_ir_liveness_threshold" value="<?=$live?>"></div>
    <div class="field"><label>آستانه گفتار</label><input type="number" min="1" max="100" name="api_ir_speech_threshold" value="<?=$speech?>"></div>
    <div class="field full"><label>متن VideoVerify</label><textarea name="api_ir_speech_text" rows="3"><?=ra_e($speechText)?></textarea></div>
    <div class="field full"><label><input type="checkbox" name="require_driving_score" value="1" <?=$needScore?'checked':''?>> استعلام نمره منفی قبل از ارسال پرونده اجباری باشد</label></div>
    <div class="field full"><label><input type="checkbox" name="require_active_plates" value="1" <?=$needPlates?'checked':''?>> استعلام پلاک‌های فعال نیز اجباری باشد</label></div>
   </div>
   <div style="margin-top:12px"><button class="btn goldbtn">ذخیره تنظیمات</button></div>
  </form>
 </div>
 <div class="card span4">
  <h2 class="section-title">فلو فعال RADO</h2>
  <p class="muted">OTP موبایل → شاهکار → گواهینامه → نمره منفی → خودرو → تطبیق شبا → بایومتریک → مدارک → بررسی مدیر.</p>
  <div class="notice warn">تست عمومی Token انجام نمی‌دهیم چون بسیاری از استعلام‌های API.ir هزینه دارند. صحت Token هنگام اولین استعلام واقعی راننده مشخص می‌شود و نتیجه در Audit ثبت می‌شود.</div>
  <div class="subnav"><a href="/admin/verification.php">مرکز احراز هویت</a><a href="https://p.api.ir/development/document" target="_blank" rel="noopener">مستندات API.ir</a></div>
 </div>
</div>
<?php ra_footer();
