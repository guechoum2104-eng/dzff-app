
<?php
declare(strict_types=1);
session_start();

/* =========================================
   1. إعدادات قاعدة البيانات والإدارة
========================================= */

$DB_HOST = "localhost";
$DB_NAME = "ofoq_store";
$DB_USER = "root";
$DB_PASS = "";

$ADMIN_USER = "admin";
$ADMIN_INITIAL_PASSWORD = "ChangeMe@2026"; // غيّرها قبل التشغيل

try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $e) {
    exit("تعذر الاتصال بقاعدة البيانات. راجع إعدادات الاتصال في أعلى الملف.");
}

/* =========================================
   2. إنشاء الجداول تلقائياً
========================================= */

$pdo->exec("
CREATE TABLE IF NOT EXISTS admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    price DECIMAL(12,2) NOT NULL,
    image TEXT,
    category VARCHAR(100) DEFAULT 'عام',
    active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS orders (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    customer_name VARCHAR(150) NOT NULL,
    phone VARCHAR(30) NOT NULL,
    wilaya VARCHAR(100) NOT NULL,
    commune VARCHAR(150) NOT NULL,
    delivery_type VARCHAR(20) NOT NULL,
    address TEXT,
    notes TEXT,
    items LONGTEXT NOT NULL,
    subtotal DECIMAL(12,2) NOT NULL,
    delivery_fee DECIMAL(12,2) NOT NULL,
    total DECIMAL(12,2) NOT NULL,
    status VARCHAR(30) DEFAULT 'new',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

/* إنشاء المدير لأول مرة */
$count = (int)$pdo->query("SELECT COUNT(*) FROM admins")->fetchColumn();

if ($count === 0) {
    $stmt = $pdo->prepare(
        "INSERT INTO admins (username,password_hash) VALUES (?,?)"
    );
    $stmt->execute([
        $ADMIN_USER,
        password_hash($ADMIN_INITIAL_PASSWORD, PASSWORD_DEFAULT)
    ]);
}

/* إضافة المنتجات الأولية إذا كانت القاعدة فارغة */
$countProducts = (int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();

if ($countProducts === 0) {
    $initialProducts = [
        ["طقم منزلي أنيق",4200,"https://images.unsplash.com/photo-1616486338812-3dadae4b4ace?w=700","المنزل"],
        ["ساعة عصرية",5800,"https://images.unsplash.com/photo-1523275335684-37898b6baf30?w=700","الأناقة"],
        ["سماعات لاسلكية",3500,"https://images.unsplash.com/photo-1505740420928-5e560c06d30e?w=700","الإلكترونيات"],
        ["حقيبة يومية",4600,"https://images.unsplash.com/photo-1548036328-c9fa89d128fa?w=700","الأناقة"],
        ["مصباح ديكور",2900,"https://images.unsplash.com/photo-1507473885765-e6ed057f782c?w=700","المنزل"],
        ["ساعة ذكية",8900,"https://images.unsplash.com/photo-1523275335684-37898b6baf30?w=700","الإلكترونيات"],
        ["نظارات شمسية",2400,"https://images.unsplash.com/photo-1511499767150-a48a237f0083?w=700","الأناقة"],
        ["إكسسوارات منزلية",1800,"https://images.unsplash.com/photo-1600210492486-724fe5c67fb0?w=700","المنزل"]
    ];

    $stmt = $pdo->prepare(
        "INSERT INTO products (name,price,image,category) VALUES (?,?,?,?)"
    );

    foreach ($initialProducts as $p) {
        $stmt->execute($p);
    }
}

/* =========================================
   3. وظائف مساعدة وحماية
========================================= */

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

if (empty($_SESSION["csrf"])) {
    $_SESSION["csrf"] = bin2hex(random_bytes(32));
}

function verify_csrf(): void {
    if (
        !isset($_POST["csrf"], $_SESSION["csrf"]) ||
        !hash_equals($_SESSION["csrf"], $_POST["csrf"])
    ) {
        http_response_code(403);
        exit("طلب غير صالح. أعد تحميل الصفحة.");
    }
}

function is_admin(): bool {
    return !empty($_SESSION["admin_id"]);
}

function require_admin(): void {
    if (!is_admin()) {
        header("Location: index.php?page=admin");
        exit;
    }
}

$message = "";
$error = "";

/* =========================================
   4. معالجة العمليات
========================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    verify_csrf();

    $action = $_POST["action"] ?? "";

    /* تسجيل دخول المدير */
    if ($action === "login") {
        $stmt = $pdo->prepare("SELECT * FROM admins WHERE username=?");
        $stmt->execute([trim($_POST["username"] ?? "")]);
        $admin = $stmt->fetch();

        if ($admin && password_verify(
            $_POST["password"] ?? "",
            $admin["password_hash"]
        )) {
            session_regenerate_id(true);
            $_SESSION["admin_id"] = $admin["id"];
            header("Location: index.php?page=admin");
            exit;
        }

        $error = "اسم المستخدم أو كلمة المرور غير صحيحة";
    }

    /* تسجيل الخروج */
    if ($action === "logout") {
        $_SESSION = [];
        session_destroy();
        header("Location: index.php");
        exit;
    }

    /* تسجيل طلب الزبون */
    if ($action === "place_order") {
        $name = trim($_POST["name"] ?? "");
        $phone = trim($_POST["phone"] ?? "");
        $wilaya = trim($_POST["wilaya"] ?? "");
        $commune = trim($_POST["commune"] ?? "");
        $type = $_POST["delivery_type"] ?? "";
        $address = trim($_POST["address"] ?? "");
        $notes = trim($_POST["notes"] ?? "");
        $cart = json_decode($_POST["cart"] ?? "{}", true);

        if (
            $name === "" ||
            !preg_match('/^[0-9+\s()-]{8,20}$/', $phone) ||
            $wilaya === "" ||
            $commune === "" ||
            !in_array($type, ["home","office"], true) ||
            !is_array($cart) ||
            empty($cart)
        ) {
            $error = "يرجى التأكد من جميع معلومات الطلب";
        } elseif ($type === "home" && $address === "") {
            $error = "يرجى إدخال العنوان للتوصيل إلى المنزل";
        } else {
            try {
                $pdo->beginTransaction();

                $items = [];
                $subtotal = 0;

                foreach ($cart as $id => $quantity) {
                    $id = filter_var($id, FILTER_VALIDATE_INT);
                    $quantity = filter_var($quantity, FILTER_VALIDATE_INT);

                    if (!$id || !$quantity || $quantity < 1 || $quantity > 50) {
                        throw new Exception("بيانات المنتجات غير صالحة");
                    }

                    $stmt = $pdo->prepare(
                        "SELECT id,name,price FROM products WHERE id=? AND active=1"
                    );
                    $stmt->execute([$id]);
                    $product = $stmt->fetch();

                    if (!$product) {
                        throw new Exception("أحد المنتجات غير متوفر");
                    }

                    $price = (float)$product["price"];
                    $subtotal += $price * $quantity;

                    $items[] = [
                        "id" => (int)$product["id"],
                        "name" => $product["name"],
                        "price" => $price,
                        "quantity" => $quantity
                    ];
                }

                // أسعار توصيل تجريبية
                $deliveryFee = $type === "home" ? 900 : 600;
                $total = $subtotal + $deliveryFee;

                $stmt = $pdo->prepare("
                    INSERT INTO orders (
                        customer_name,phone,wilaya,commune,
                        delivery_type,address,notes,items,
                        subtotal,delivery_fee,total
                    ) VALUES (?,?,?,?,?,?,?,?,?,?,?)
                ");

                $stmt->execute([
                    $name,$phone,$wilaya,$commune,$type,
                    $address,$notes,
                    json_encode($items,JSON_UNESCAPED_UNICODE),
                    $subtotal,$deliveryFee,$total
                ]);

                $orderId = (int)$pdo->lastInsertId();

                $pdo->commit();

                $_SESSION["last_order"] = $orderId;

                header("Location: index.php?success=1");
                exit;

            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = "تعذر تسجيل الطلب. تحقق من المعلومات وحاول مجدداً.";
            }
        }
    }

    /* جميع العمليات التالية خاصة بالمدير */
    if (in_array($action, ["update_status","add_product","delete_product"], true)) {
        require_admin();
    }

    /* تغيير حالة الطلب */
    if ($action === "update_status") {
        $id = filter_var($_POST["order_id"] ?? null, FILTER_VALIDATE_INT);
        $status = $_POST["status"] ?? "";

        $allowed = [
            "new","confirmed","preparing",
            "shipped","delivered","cancelled"
        ];

        if ($id && in_array($status, $allowed, true)) {
            $stmt = $pdo->prepare("UPDATE orders SET status=? WHERE id=?");
            $stmt->execute([$status,$id]);
            $message = "تم تحديث حالة الطلب";
        }
    }

    /* إضافة منتج */
    if ($action === "add_product") {
        $name = trim($_POST["product_name"] ?? "");
        $price = (float)($_POST["product_price"] ?? 0);
        $image = trim($_POST["product_image"] ?? "");
        $category = trim($_POST["product_category"] ?? "عام");

        if ($name !== "" && $price > 0) {
            $stmt = $pdo->prepare("
                INSERT INTO products (name,price,image,category)
                VALUES (?,?,?,?)
            ");
            $stmt->execute([$name,$price,$image,$category]);
            $message = "تمت إضافة المنتج";
        } else {
            $error = "أدخل اسم المنتج وسعراً صحيحاً";
        }
    }

    /* حذف منتج */
    if ($action === "delete_product") {
        $id = filter_var($_POST["product_id"] ?? null, FILTER_VALIDATE_INT);

        if ($id) {
            $stmt = $pdo->prepare("UPDATE products SET active=0 WHERE id=?");
            $stmt->execute([$id]);
            $message = "تم حذف المنتج من المتجر";
        }
    }
}

/* =========================================
   5. جلب البيانات
========================================= */

$products = $pdo->query(
    "SELECT * FROM products WHERE active=1 ORDER BY id DESC"
)->fetchAll();

$orders = [];
$totalOrders = 0;
$newOrders = 0;
$totalSales = 0;

if (is_admin()) {
    $orders = $pdo->query(
        "SELECT * FROM orders ORDER BY id DESC"
    )->fetchAll();

    $totalOrders = (int)$pdo->query(
        "SELECT COUNT(*) FROM orders"
    )->fetchColumn();

    $newOrders = (int)$pdo->query(
        "SELECT COUNT(*) FROM orders WHERE status='new'"
    )->fetchColumn();

    $totalSales = (float)$pdo->query(
        "SELECT COALESCE(SUM(total),0) FROM orders WHERE status <> 'cancelled'"
    )->fetchColumn();
}

$statuses = [
    "new" => "طلب جديد",
    "confirmed" => "تم التأكيد",
    "preparing" => "قيد التحضير",
    "shipped" => "تم الشحن",
    "delivered" => "تم التسليم",
    "cancelled" => "ملغى"
];

$page = $_GET["page"] ?? "store";
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>أُفُق | OFOQ STORE</title>

<style>
:root {
    --red:#c71932;
    --green:#13834a;
    --dark:#14271e;
    --paper:#f6f8f5;
    --line:#e7ece7;
    --muted:#718078;
}

* { box-sizing:border-box; }

body {
    margin:0;
    font-family:Tahoma,Arial,sans-serif;
    background:var(--paper);
    color:#17211d;
}

button,input,select,textarea {
    font:inherit;
}

a { text-decoration:none; }

.wrap {
    width:min(1150px,94%);
    margin:auto;
}

.top {
    background:var(--dark);
    color:white;
    padding:10px;
    text-align:center;
    font-size:13px;
}

header {
    background:white;
    border-bottom:1px solid var(--line);
    position:sticky;
    top:0;
    z-index:10;
}

.nav {
    min-height:75px;
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:15px;
}

.logo {
    color:var(--red);
    font-size:24px;
    font-weight:900;
}

.logo span { color:var(--green); }

.navlinks {
    display:flex;
    gap:20px;
    font-size:14px;
}

.navlinks a { color:#34463a; }

.btn {
    border:0;
    padding:12px 17px;
    border-radius:11px;
    background:var(--red);
    color:white;
    font-weight:bold;
    cursor:pointer;
}

.btn.green { background:var(--green); }
.btn.light { background:#eaf5ee;color:var(--green); }

.hero {
    padding:65px 0;
    background:
        radial-gradient(circle at 10% 20%,#dff1e4,transparent 30%),
        radial-gradient(circle at 90% 10%,#ffe4e8,transparent 28%),
        white;
}

.hero h1 {
    font-size:clamp(32px,5vw,55px);
    line-height:1.4;
    margin:0 0 15px;
}

.hero p {
    color:var(--muted);
    line-height:2;
    max-width:600px;
}

.section { padding:45px 0; }

.section-title {
    font-size:27px;
    margin-bottom:25px;
}

.products {
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:17px;
}

.product {
    background:white;
    border:1px solid var(--line);
    border-radius:17px;
    overflow:hidden;
    transition:.2s;
}

.product:hover {
    transform:translateY(-4px);
    box-shadow:0 12px 30px #00000010;
}

.product img {
    width:100%;
    height:190px;
    object-fit:cover;
    background:#eee;
}

.product-body { padding:15px; }

.product h3 { margin:0 0 12px;font-size:16px; }

.price {
    color:var(--red);
    font-size:18px;
    font-weight:900;
}

.product-bottom {
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:8px;
}

.order-area {
    background:var(--dark);
    padding:50px 0;
    color:white;
}

.order-card {
    background:white;
    color:#17211d;
    padding:25px;
    border-radius:18px;
    max-width:800px;
}

.form-grid {
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:14px;
}

.field label {
    display:block;
    font-size:13px;
    font-weight:bold;
    margin-bottom:7px;
}

.field input,.field select,.field textarea {
    width:100%;
    border:1px solid var(--line);
    padding:13px;
    border-radius:10px;
    background:#fafcfa;
}

.field.full { grid-column:1/-1; }

.cart-box {
    background:#f1f7f2;
    padding:15px;
    border-radius:12px;
    margin:15px 0;
    color:#17211d;
}

.cart-row {
    display:flex;
    justify-content:space-between;
    gap:10px;
    padding:8px 0;
    border-bottom:1px solid #ddd;
}

.qty button {
    padding:3px 9px;
    border:0;
    background:#dcefe1;
    border-radius:5px;
    cursor:pointer;
}

.total {
    font-weight:900;
    color:var(--red);
    font-size:20px;
}

footer {
    background:#0d1b13;
    color:#c5d3c9;
    padding:25px;
    text-align:center;
}

.alert {
    padding:14px;
    margin:15px auto;
    border-radius:10px;
    max-width:1100px;
    background:#e4f4e8;
    color:#126b36;
}

.alert.error {
    background:#ffe8eb;
    color:#a90f27;
}

.admin {
    width:min(1200px,95%);
    margin:30px auto;
}

.stats {
    display:grid;
    grid-template-columns:repeat(3,1fr);
    gap:15px;
}

.stat,.panel {
    background:white;
    border:1px solid var(--line);
    padding:20px;
    border-radius:15px;
    margin-bottom:20px;
}

.stat small { color:var(--muted); }

.stat strong {
    display:block;
    font-size:27px;
    color:var(--green);
    margin-top:10px;
}

.table-wrap { overflow-x:auto; }

table {
    border-collapse:collapse;
    width:100%;
    min-width:850px;
}

td,th {
    text-align:right;
    padding:12px;
    border-bottom:1px solid var(--line);
    font-size:13px;
}

th { color:var(--muted); }

.admin input,.admin select {
    padding:9px;
    border:1px solid var(--line);
    border-radius:8px;
}

.login {
    max-width:420px;
    margin:70px auto;
    background:white;
    padding:28px;
    border-radius:18px;
}

.login input {
    width:100%;
    padding:13px;
    margin:8px 0;
    border:1px solid var(--line);
    border-radius:9px;
}

.login button { width:100%; }

@media(max-width:850px) {
    .products { grid-template-columns:repeat(2,1fr); }
    .navlinks { display:none; }
}

@media(max-width:550px) {
    .form-grid { grid-template-columns:1fr; }
    .field.full { grid-column:auto; }
    .stats { grid-template-columns:1fr; }
    .product img { height:145px; }
    .hero { padding:40px 0; }
}
</style>
</head>

<body>

<div class="top">
    🚚 توصيل إلى مختلف ولايات الجزائر — الدفع عند الاستلام
</div>

<header>
    <div class="wrap nav">
        <a class="logo" href="index.php">أُفُق <span>OFOQ</span></a>

        <nav class="navlinks">
            <a href="index.php">الرئيسية</a>
            <a href="#products">المنتجات</a>
            <a href="#order">اطلب الآن</a>
        </nav>

        <div>
            <a class="btn light" href="#order">سلة المشتريات (<span id="cartCount">0</span>)</a>
            <a class="btn" href="index.php?page=admin">الإدارة</a>
        </div>
    </div>
</header>

<?php if ($message): ?>
<div class="alert"><?= e($message) ?></div>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert error"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($page === "admin" && !is_admin()): ?>

<div class="login">
    <h2>لوحة إدارة OFOQ</h2>
    <p>تسجيل دخول المدير</p>

    <form method="post">
        <input type="hidden" name="csrf" value="<?= e($_SESSION["csrf"]) ?>">
        <input type="hidden" name="action" value="login">

        <input name="username" placeholder="اسم المستخدم" required>
        <input type="password" name="password" placeholder="كلمة المرور" required>

        <button class="btn">دخول إلى لوحة التحكم</button>
    </form>
</div>

<?php elseif ($page === "admin" && is_admin()): ?>

<div class="admin">
    <h1>لوحة التحكم — OFOQ STORE</h1>

    <form method="post" style="text-align:left;margin-bottom:20px">
        <input type="hidden" name="csrf" value="<?= e($_SESSION["csrf"]) ?>">
        <input type="hidden" name="action" value="logout">
        <button class="btn">تسجيل الخروج</button>
    </form>

    <div class="stats">
        <div class="stat">
            <small>إجمالي الطلبات</small>
            <strong><?= $totalOrders ?></strong>
        </div>

        <div class="stat">
            <small>الطلبات الجديدة</small>
            <strong><?= $newOrders ?></strong>
        </div>

        <div class="stat">
            <small>قيمة الطلبات غير الملغاة</small>
            <strong><?= number_format($totalSales,0) ?> دج</strong>
        </div>
    </div>

    <div class="panel">
        <h2>إدارة الطلبات</h2>

        <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>رقم</th>
                    <th>الزبون</th>
                    <th>الهاتف</th>
                    <th>الولاية</th>
                    <th>البلدية</th>
                    <th>التوصيل</th>
                    <th>المجموع</th>
                    <th>التاريخ</th>
                    <th>الحالة</th>
                    <th>حفظ</th>
                </tr>
            </thead>

            <tbody>
            <?php foreach ($orders as $o): ?>
                <tr>
                    <td>#<?= (int)$o["id"] ?></td>
                    <td><?= e($o["customer_name"]) ?></td>
                    <td><a href="tel:<?= e($o["phone"]) ?>"><?= e($o["phone"]) ?></a></td>
                    <td><?= e($o["wilaya"]) ?></td>
                    <td><?= e($o["commune"]) ?></td>
                    <td><?= $o["delivery_type"] === "home" ? "المنزل" : "المكتب" ?></td>
                    <td><?= number_format((float)$o["total"],0) ?> دج</td>
                    <td><?= e($o["created_at"]) ?></td>

                    <td>
                        <form method="post">
                            <input type="hidden" name="csrf" value="<?= e($_SESSION["csrf"]) ?>">
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="order_id" value="<?= (int)$o["id"] ?>">

                            <select name="status">
                                <?php foreach ($statuses as $key => $label): ?>
                                <option value="<?= e($key) ?>" <?= $o["status"] === $key ? "selected" : "" ?>>
                                    <?= e($label) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                    </td>

                    <td><button class="btn green">تحديث</button></form></td>
                </tr>

                <tr>
                    <td colspan="10">
                        <details>
                            <summary>تفاصيل الطلب #<?= (int)$o["id"] ?></summary>
                            <p>العنوان: <?= e($o["address"]) ?></p>
                            <p>ملاحظات: <?= e($o["notes"]) ?></p>

                            <?php
                            $orderItems = json_decode($o["items"], true) ?: [];
                            foreach ($orderItems as $item):
                            ?>
                            <p>
                                <?= e($item["name"]) ?>
                                — <?= (int)$item["quantity"] ?> ×
                                <?= number_format((float)$item["price"],0) ?> دج
                            </p>
                            <?php endforeach; ?>
                        </details>
                    </td>
                </tr>
            <?php endforeach; ?>

            <?php if (!$orders): ?>
                <tr><td colspan="10">لا توجد طلبات حالياً</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>

    <div class="panel">
        <h2>إضافة منتج جديد</h2>

        <form method="post">
            <input type="hidden" name="csrf" value="<?= e($_SESSION["csrf"]) ?>">
            <input type="hidden" name="action" value="add_product">

            <p><input name="product_name" placeholder="اسم المنتج" required></p>
            <p><input type="number" name="product_price" placeholder="السعر بالدينار" min="1" required></p>
            <p><input name="product_image" placeholder="رابط صورة المنتج"></p>
            <p><input name="product_category" placeholder="التصنيف"></p>

            <button class="btn green">إضافة المنتج</button>
        </form>
    </div>

    <div class="panel">
        <h2>المنتجات الحالية</h2>

        <?php foreach ($products as $p): ?>
            <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;border-bottom:1px solid #eee;padding:12px 0">
                <span><?= e($p["name"]) ?> — <?= number_format((float)$p["price"],0) ?> دج</span>

                <form method="post" onsubmit="return confirm('هل تريد حذف المنتج؟')">
                    <input type="hidden" name="csrf" value="<?= e($_SESSION["csrf"]) ?>">
                    <input type="hidden" name="action" value="delete_product">
                    <input type="hidden" name="product_id" value="<?= (int)$p["id"] ?>">
                    <button class="btn">حذف</button>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php else: ?>

<section class="hero">
    <div class="wrap">
        <h1>كل ما تحبّه،<br>في مكان واحد.</h1>
        <p>
            مرحباً بك في أُفُق OFOQ STORE.
            اكتشف منتجات مختارة بعناية، واطلب بسهولة مع خدمة التوصيل والدفع عند الاستلام.
        </p>
        <a href="#products" class="btn">اكتشف المنتجات ←</a>
        <a href="#order" class="btn green">اطلب الآن</a>
    </div>
</section>

<section class="section wrap" id="products">
    <h2 class="section-title">منتجاتنا</h2>

    <div class="products">
        <?php foreach ($products as $p): ?>
        <article class="product">
            <img
                src="<?= e($p["image"]) ?>"
                alt="<?= e($p["name"]) ?>"
                loading="lazy"
            >

            <div class="product-body">
                <h3><?= e($p["name"]) ?></h3>

                <div class="product-bottom">
                    <span class="price">
                        <?= number_format((float)$p["price"],0) ?> دج
                    </span>

                    <button
                        class="btn green"
                        onclick="addToCart(<?= (int)$p["id"] ?>)"
                    >+ أضف</button>
                </div>
            </div>
        </article>
        <?php endforeach; ?>
    </div>
</section>

<section class="order-area" id="order">
    <div class="wrap">
        <h2>أكمل طلبك</h2>
        <p>أدخل معلوماتك لتأكيد الطلب.</p>

        <div class="order-card">

            <h3>سلة المشتريات</h3>

            <div class="cart-box" id="cartLines">
                السلة فارغة حالياً
            </div>

            <p>
                المجموع:
                <strong class="total" id="cartTotal">0 دج</strong>
            </p>

            <form method="post" id="orderForm">
                <input type="hidden" name="csrf" value="<?= e($_SESSION["csrf"]) ?>">
                <input type="hidden" name="action" value="place_order">
                <input type="hidden" name="cart" id="cartInput">

                <div class="form-grid">

                    <div class="field">
                        <label>الاسم الكامل</label>
                        <input name="name" required maxlength="150">
                    </div>

                    <div class="field">
                        <label>رقم الهاتف</label>
                        <input name="phone" type="tel" required maxlength="20" placeholder="05 أو 06 أو 07">
                    </div>

                    <div class="field">
                        <label>الولاية</label>
                        <input name="wilaya" required placeholder="مثال: سطيف">
                    </div>

                    <div class="field">
                        <label>البلدية</label>
                        <input name="commune" required placeholder="اسم البلدية">
                    </div>

                    <div class="field full">
                        <label>نوع التوصيل</label>
                        <select name="delivery_type" id="deliveryType">
                            <option value="home">إلى المنزل — 900 دج</option>
                            <option value="office">إلى مكتب التوصيل — 600 دج</option>
                        </select>
                    </div>

                    <div class="field full" id="addressBox">
                        <label>العنوان بالتفصيل</label>
                        <input name="address" placeholder="الحي، الشارع، رقم المنزل">
                    </div>

                    <div class="field full">
                        <label>ملاحظات إضافية</label>
                        <textarea name="notes" rows="3"></textarea>
                    </div>

                </div>

                <div class="cart-box">
                    <p>مصاريف التوصيل: <strong id="deliveryPrice">900 دج</strong></p>
                    <p>المجموع النهائي: <strong class="total" id="finalTotal">0 دج</strong></p>
                </div>

                <button type="submit" class="btn green" style="width:100%">
                    تأكيد وإرسال الطلب
                </button>
            </form>

        </div>
    </div>
</section>

<?php endif; ?>

<footer>
    © <?= date("Y") ?> OFOQ STORE — جميع الحقوق محفوظة
</footer>

<script>
/* =========================================
   6. سلة المشتريات وإرسال الطلب
========================================= */

const products = <?= json_encode(
    array_map(function($p) {
        return [
            "id" => (int)$p["id"],
            "name" => $p["name"],
            "price" => (float)$p["price"]
        ];
    }, $products),
    JSON_UNESCAPED_UNICODE
) ?>;

let cart = {};

try {
    cart = JSON.parse(localStorage.getItem("ofoq_cart") || "{}") || {};
} catch(e) {
    cart = {};
}

function saveCart() {
    localStorage.setItem("ofoq_cart", JSON.stringify(cart));
}

function getProduct(id) {
    return products.find(p => p.id === Number(id));
}

function money(n) {
    return Number(n).toLocaleString("fr-FR") + " دج";
}

function subtotal() {
    return products.reduce((sum,p) => {
        return sum + p.price * (Number(cart[p.id]) || 0);
    }, 0);
}

function addToCart(id) {
    cart[id] = (Number(cart[id]) || 0) + 1;
    saveCart();
    renderCart();
}

function changeQty(id, amount) {
    cart[id] = (Number(cart[id]) || 0) + amount;

    if (cart[id] <= 0) {
        delete cart[id];
    }

    saveCart();
    renderCart();
}

function renderCart() {
    const lines = document.getElementById("cartLines");
    if (!lines) return;

    const entries = products.filter(p => Number(cart[p.id]) > 0);

    document.getElementById("cartCount").textContent =
        Object.values(cart).reduce((a,b) => a + Number(b), 0);

    if (!entries.length) {
        lines.textContent = "السلة فارغة حالياً";
    } else {
        lines.innerHTML = "";

        entries.forEach(p => {
            const row = document.createElement("div");
            row.className = "cart-row";

            const name = document.createElement("span");
            name.textContent = p.name + " — " + money(p.price * cart[p.id]);

            const qty = document.createElement("span");
            qty.className = "qty";

            const minus = document.createElement("button");
            minus.type = "button";
            minus.textContent = "−";
            minus.onclick = () => changeQty(p.id,-1);

            const number = document.createElement("b");
            number.textContent = cart[p.id];

            const plus = document.createElement("button");
            plus.type = "button";
            plus.textContent = "+";
            plus.onclick = () => changeQty(p.id,1);

            qty.append(minus,number,plus);
            row.append(name,qty);
            lines.appendChild(row);
        });
    }

    document.getElementById("cartTotal").textContent = money(subtotal());
    updateTotal();
}

function updateTotal() {
    const delivery = document.getElementById("deliveryType");
    if (!delivery) return;

    const fee = delivery.value === "home" ? 900 : 600;

    document.getElementById("deliveryPrice").textContent = money(fee);
    document.getElementById("finalTotal").textContent =
        money(subtotal() + fee);

    document.getElementById("addressBox").style.display =
        delivery.value === "home" ? "block" : "none";
}

const deliverySelect = document.getElementById("deliveryType");

if (deliverySelect) {
    deliverySelect.addEventListener("change",updateTotal);
}

const orderForm = document.getElementById("orderForm");

if (orderForm) {
    orderForm.addEventListener("submit", function(e) {
        if (subtotal() <= 0) {
            e.preventDefault();
            alert("أضف منتجاً واحداً على الأقل إلى السلة");
            return;
        }

        document.getElementById("cartInput").value =
            JSON.stringify(cart);

        const button = orderForm.querySelector('button[type="submit"]');
        button.disabled = true;
        button.textContent = "جارٍ إرسال الطلب...";
    });
}

renderCart();
updateTotal();

<?php if (isset($_GET["success"])): ?>
alert("تم استلام طلبك بنجاح. رقم الطلب: #<?= (int)($_SESSION["last_order"] ?? 0) ?>");
localStorage.removeItem("ofoq_cart");
<?php endif; ?>
</script>

</body>
</html>
