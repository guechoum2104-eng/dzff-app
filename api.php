<?php
declare(strict_types=1);
$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>false,'message'=>'أنشئ config.php أولاً']); exit; }
$cfg = require $configFile; date_default_timezone_set($cfg['timezone'] ?? 'Africa/Algiers');
$isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$isHttps,'httponly'=>true,'samesite'=>'Strict']);
session_start(); header('Content-Type: application/json; charset=utf-8'); header('X-Content-Type-Options: nosniff'); header('Cache-Control: no-store');
try { $pdo = new PDO('mysql:host='.$cfg['db_host'].';dbname='.$cfg['db_name'].';charset=utf8mb4',$cfg['db_user'],$cfg['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]); }
catch(Throwable $e){ http_response_code(500); echo json_encode(['ok'=>false,'message'=>'تعذر الاتصال بقاعدة البيانات']); exit; }
function out($data,$code=200){ http_response_code($code); echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
function body(){ $v=json_decode(file_get_contents('php://input'),true); return is_array($v)?$v:[]; }
function needCsrf($d){ if(empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'],(string)($d['csrf']??''))) out(['ok'=>false,'message'=>'انتهت الجلسة، حدّث الصفحة وحاول مجدداً'],419); }
function adminOnly(){ if(empty($_SESSION['admin_id'])) out(['ok'=>false,'message'=>'يلزم تسجيل دخول الإدارة'],401); }
$action=$_GET['action']??''; $d=body();
if($action==='init' && $_SERVER['REQUEST_METHOD']==='GET'){
 if(empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32));
 $products=$pdo->query('SELECT id,name_ar AS name,name_fr AS fr,price,category AS cat,image_url AS img FROM products WHERE active=1 ORDER BY id')->fetchAll();
 $fees=[]; foreach($pdo->query('SELECT wilaya_name,home_fee,office_fee FROM delivery_fees') as $f) $fees[$f['wilaya_name']]=['home'=>(int)$f['home_fee'],'office'=>(int)$f['office_fee']];
 out(['ok'=>true,'csrf'=>$_SESSION['csrf'],'products'=>$products,'fees'=>$fees]);
}
if($action==='create_order' && $_SERVER['REQUEST_METHOD']==='POST'){
 needCsrf($d); if(!empty($d['website'])) out(['ok'=>false,'message'=>'طلب غير صالح'],422);
 $name=trim((string)($d['name']??'')); $phone=preg_replace('/\s+/','', (string)($d['phone']??'')); $wilaya=trim((string)($d['wilaya']??'')); $commune=trim((string)($d['commune']??'')); $type=$d['delivery']??''; $address=trim((string)($d['address']??'')); $notes=trim((string)($d['notes']??'')); $items=$d['items']??[];
 if(mb_strlen($name)<3 || !preg_match('/^[0-9+]{8,15}$/',$phone) || !$wilaya || !$commune || !in_array($type,['home','office'],true) || ($type==='home' && mb_strlen($address)<5) || !is_array($items) || count($items)<1 || count($items)>40) out(['ok'=>false,'message'=>'تحقق من الاسم والهاتف والعنوان والمنتجات'],422);
 $chk=$pdo->prepare('SELECT COUNT(*) FROM municipalities WHERE name=? AND wilaya_name=?'); $chk->execute([$commune,$wilaya]); if(!(int)$chk->fetchColumn()) out(['ok'=>false,'message'=>'البلدية لا تتبع الولاية المختارة'],422);
 $feeQ=$pdo->prepare('SELECT home_fee,office_fee FROM delivery_fees WHERE wilaya_name=?'); $feeQ->execute([$wilaya]); $feeRow=$feeQ->fetch(); if(!$feeRow) out(['ok'=>false,'message'=>'الولاية غير مدعومة'],422); $fee=(int)$feeRow[$type.'_fee'];
 $pdo->beginTransaction(); try { $subtotal=0; $valid=[]; $pQ=$pdo->prepare('SELECT id,name_ar,price FROM products WHERE id=? AND active=1'); foreach($items as $it){$id=(int)($it['id']??0);$qty=(int)($it['qty']??0);if($id<1||$qty<1||$qty>50) throw new RuntimeException('كمية منتج غير صالحة');$pQ->execute([$id]);$p=$pQ->fetch();if(!$p)throw new RuntimeException('أحد المنتجات لم يعد متوفراً');$line=(int)$p['price']*$qty;$subtotal+=$line;$valid[]=['id'=>$id,'name'=>$p['name_ar'],'price'=>(int)$p['price'],'qty'=>$qty];}
 if($subtotal>100000000) throw new RuntimeException('قيمة الطلب كبيرة جداً'); $total=$subtotal+$fee; $number='OFQ-'.date('ymd').'-'.strtoupper(bin2hex(random_bytes(3)));
 $ins=$pdo->prepare('INSERT INTO orders(order_number,customer_name,phone,wilaya_name,municipality_name,delivery_type,address,notes,subtotal,delivery_fee,total) VALUES(?,?,?,?,?,?,?,?,?,?,?)');$ins->execute([$number,$name,$phone,$wilaya,$commune,$type,$type==='home'?$address:null,$notes,$subtotal,$fee,$total]);$oid=(int)$pdo->lastInsertId();$ii=$pdo->prepare('INSERT INTO order_items(order_id,product_id,product_name,unit_price,quantity) VALUES(?,?,?,?,?)');foreach($valid as $v)$ii->execute([$oid,$v['id'],$v['name'],$v['price'],$v['qty']]);$pdo->commit();out(['ok'=>true,'order_number'=>$number,'subtotal'=>$subtotal,'delivery_fee'=>$fee,'total'=>$total]);
 }catch(Throwable $e){$pdo->rollBack();out(['ok'=>false,'message'=>$e instanceof RuntimeException?$e->getMessage():'تعذر حفظ الطلب، حاول مجدداً'],422);}
}
if($action==='admin_login' && $_SERVER['REQUEST_METHOD']==='POST'){needCsrf($d);$u=trim((string)($d['username']??''));$p=(string)($d['password']??'');$s=$pdo->prepare('SELECT id,password_hash FROM admins WHERE username=?');$s->execute([$u]);$a=$s->fetch();if(!$a||!password_verify($p,$a['password_hash']))out(['ok'=>false,'message'=>'اسم المستخدم أو كلمة المرور غير صحيحة'],401);session_regenerate_id(true);$_SESSION['admin_id']=(int)$a['id'];$_SESSION['admin_user']=$u;out(['ok'=>true]);}
if($action==='admin_logout' && $_SERVER['REQUEST_METHOD']==='POST'){needCsrf($d);$_SESSION=[];session_destroy();out(['ok'=>true]);}
if(str_starts_with($action,'admin_')){adminOnly();}
if($action==='admin_dashboard' && $_SERVER['REQUEST_METHOD']==='GET'){
 $stats=$pdo->query("SELECT COUNT(*) orders_count,COALESCE(SUM(total),0) revenue FROM orders WHERE status<>'cancelled'")->fetch();$pc=(int)$pdo->query('SELECT COUNT(*) FROM products WHERE active=1')->fetchColumn();$orders=$pdo->query('SELECT id,order_number,customer_name,phone,wilaya_name,municipality_name,delivery_type,address,notes,subtotal,delivery_fee,total,status,DATE_FORMAT(created_at,\'%Y-%m-%d %H:%i\') created_at FROM orders ORDER BY id DESC LIMIT 300')->fetchAll();$items=$pdo->prepare('SELECT product_name,unit_price,quantity FROM order_items WHERE order_id=?');foreach($orders as &$o){$items->execute([$o['id']]);$o['items']=$items->fetchAll();}unset($o);$fees=[];foreach($pdo->query('SELECT wilaya_name,home_fee,office_fee FROM delivery_fees ORDER BY wilaya_name') as $f)$fees[]=$f;out(['ok'=>true,'stats'=>['orders'=>(int)$stats['orders_count'],'sales'=>(int)$stats['revenue'],'products'=>$pc],'orders'=>$orders,'fees'=>$fees]);
}
if($action==='admin_status' && $_SERVER['REQUEST_METHOD']==='POST'){needCsrf($d);$allowed=['pending','confirmed','preparing','shipped','delivered','cancelled'];$status=$d['status']??'';$id=(int)($d['id']??0);if(!in_array($status,$allowed,true)||$id<1)out(['ok'=>false,'message'=>'حالة غير صالحة'],422);$s=$pdo->prepare('UPDATE orders SET status=? WHERE id=?');$s->execute([$status,$id]);out(['ok'=>true]);}
if($action==='admin_fees' && $_SERVER['REQUEST_METHOD']==='POST'){needCsrf($d);$rows=$d['fees']??[];if(!is_array($rows)||count($rows)>100)out(['ok'=>false,'message'=>'بيانات الأسعار غير صالحة'],422);$s=$pdo->prepare('UPDATE delivery_fees SET home_fee=?,office_fee=? WHERE wilaya_name=?');foreach($rows as $r){$h=(int)($r['home']??-1);$o=(int)($r['office']??-1);$w=(string)($r['wilaya']??'');if($h<0||$o<0||$h>100000||$o>100000)out(['ok'=>false,'message'=>'سعر توصيل غير صالح'],422);$s->execute([$h,$o,$w]);}out(['ok'=>true]);}
out(['ok'=>false,'message'=>'المسار غير معروف'],404);
