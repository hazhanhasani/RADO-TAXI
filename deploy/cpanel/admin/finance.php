<?php
declare(strict_types=1);
require __DIR__.'/_ui.php';
ra_require_admin();
$pdo=rado_db();$message='';$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  ra_require_csrf();
  try{
    $action=(string)($_POST['action']??'');
    if($action==='save_pricing'){
      $title=trim((string)($_POST['title']??'تعرفه اصلی بانه'))?:'تعرفه اصلی بانه';$vals=[];
      foreach(['base_fare','per_km','per_minute','waiting_per_minute','minimum_fare'] as $f){$v=filter_var($_POST[$f]??null,FILTER_VALIDATE_INT);if($v===false||$v<0)throw new RuntimeException('مبالغ تعرفه نامعتبر است.');$vals[$f]=$v;}
      $surge=(float)($_POST['surge_multiplier']??1);if($surge<1||$surge>5)throw new RuntimeException('ضریب شلوغی باید بین ۱ تا ۵ باشد.');
      $pdo->beginTransaction();$pdo->exec('UPDATE pricing_rules SET active=0,effective_to=NOW() WHERE active=1');
      $pdo->prepare('INSERT INTO pricing_rules(title,base_fare,per_km,per_minute,waiting_per_minute,minimum_fare,surge_multiplier,active,effective_from) VALUES(?,?,?,?,?,?,?,1,NOW())')->execute([$title,$vals['base_fare'],$vals['per_km'],$vals['per_minute'],$vals['waiting_per_minute'],$vals['minimum_fare'],$surge]);$pdo->commit();$message='تعرفه جدید فعال شد.';
    }
    if($action==='save_commission'){$rate=(float)($_POST['rate']??10);if($rate<0||$rate>100)throw new RuntimeException('کمیسیون نامعتبر است.');rado_set_setting($pdo,'default_driver_commission_rate',number_format($rate,2,'.',''));$message='کمیسیون پیش‌فرض ذخیره شد.';}
  }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$error=$e->getMessage();}
}
$pricing=rado_active_pricing_rule($pdo);$commission=(float)(rado_setting($pdo,'default_driver_commission_rate','10.00')??'10.00');
$gross=(int)ra_scalar($pdo,"SELECT COALESCE(SUM(final_fare),0) FROM trips WHERE status='completed'");
$platform=(int)ra_scalar($pdo,"SELECT COALESCE(SUM(amount),0) FROM ledger_entries WHERE user_id IS NULL AND entry_type='platform_commission_income'");
$settlements=ra_table_exists($pdo,'driver_settlements')?$pdo->query("SELECT ds.*,u.full_name FROM driver_settlements ds JOIN users u ON u.id=ds.driver_id ORDER BY ds.requested_at DESC LIMIT 80")->fetchAll():[];
$refunds=ra_table_exists($pdo,'refunds')?(int)ra_scalar($pdo,"SELECT COALESCE(SUM(amount),0) FROM refunds"):0;
ra_header('مالی و تعرفه','finance','قیمت‌گذاری، کمیسیون، تسویه و بازپرداخت');
if($message)echo '<div class="notice ok">'.ra_e($message).'</div>';if($error)echo '<div class="notice err">'.ra_e($error).'</div>';
?>
<div class="grid"><div class="card span3 metric"><small>گردش کل سفرها</small><b><?=ra_money($gross)?></b></div><div class="card span3 metric"><small>کمیسیون RADO</small><b><?=ra_money($platform)?></b></div><div class="card span3 metric"><small>بازپرداخت</small><b><?=ra_money($refunds)?></b></div><div class="card span3 metric"><small>کمیسیون پیش‌فرض</small><b><?=$commission?>٪</b></div></div>
<div class="grid"><div class="card span8"><h2 class="section-title">تعرفه فعال</h2><form method="post"><input type="hidden" name="csrf" value="<?=ra_e(ra_csrf())?>"><input type="hidden" name="action" value="save_pricing"><div class="formgrid"><div class="field full"><label>نام تعرفه</label><input name="title" value="<?=ra_e((string)($pricing['title']??'تعرفه اصلی بانه'))?>"></div><?php foreach(['base_fare'=>'مبلغ پایه','per_km'=>'هر کیلومتر','per_minute'=>'هر دقیقه مسیر','waiting_per_minute'=>'هر دقیقه انتظار','minimum_fare'=>'حداقل کرایه'] as $k=>$label):?><div class="field"><label><?=$label?> (ریال)</label><input name="<?=$k?>" type="number" min="0" value="<?=ra_e((string)($pricing[$k]??0))?>"></div><?php endforeach;?><div class="field"><label>ضریب شلوغی</label><input name="surge_multiplier" type="number" step="0.01" min="1" max="5" value="<?=ra_e((string)($pricing['surge_multiplier']??1))?>"></div><div class="full"><button class="btn goldbtn">فعال‌سازی تعرفه جدید</button></div></div></form></div><div class="card span4"><h2 class="section-title">کمیسیون راننده</h2><form method="post"><input type="hidden" name="csrf" value="<?=ra_e(ra_csrf())?>"><input type="hidden" name="action" value="save_commission"><div class="field"><label>درصد پیش‌فرض</label><input name="rate" type="number" step="0.1" min="0" max="100" value="<?=$commission?>"></div><p><button class="btn">ذخیره</button></p></form><p class="muted">کمیسیون اختصاصی هر راننده از بخش رانندگان قابل تغییر است.</p></div></div>
<div class="card" style="margin-top:14px"><h2 class="section-title">آخرین درخواست‌های تسویه</h2><div class="tablewrap"><table class="table"><tr><th>راننده</th><th>مبلغ</th><th>وضعیت</th><th>درخواست</th><th>پرداخت</th></tr><?php foreach($settlements as $s):?><tr><td><?=ra_e((string)$s['full_name'])?></td><td><?=ra_money($s['amount'])?></td><td><span class="badge"><?=ra_e((string)$s['status'])?></span></td><td><?=ra_e(ra_date($s['requested_at']))?></td><td><?=ra_e(ra_date($s['paid_at']??null))?></td></tr><?php endforeach;?></table></div></div>
<?php ra_footer();
