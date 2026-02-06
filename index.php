<?php
session_start();

/* ================== DATABASE ================== */
$conn = new mysqli("localhost","root","","hotelms");
if ($conn->connect_error) die("DB Error");

/* ================== ROUTING ================== */
$action = $_GET['action'] ?? (!isset($_SESSION['user']) ? 'login' : 'dashboard');

/* ================== SESSION GUARD ================== */
if (!isset($_SESSION['user']) && !in_array($action,['login','register'])) {
    header("Location: index.php?action=login");
    exit;
}

/* ================== USE CASE HANDLER ================== */
switch ($action) {

/* ========== LOGIN ========== */
case 'login':
    if ($_SERVER['REQUEST_METHOD']=='POST') {
        $u=$_POST['username'];
        $p=$_POST['password'];

        $q=$conn->query("SELECT * FROM users WHERE username='$u'");
        if ($q->num_rows) {
            $user=$q->fetch_assoc();
            if (password_verify($p,$user['password_hash'])) {
                $_SESSION['user']=$user;
                header("Location: index.php?action=dashboard");
                exit;
            }
        }
        $error="Login gagal";
    }
break;

/* ========== REGISTER GUEST ========== */
case 'register':
    if ($_SERVER['REQUEST_METHOD']=='POST') {
        $name=$_POST['name'];
        $u=$_POST['username'];
        $p=password_hash($_POST['password'],PASSWORD_DEFAULT);

        $conn->query("INSERT INTO users(name,username,password_hash,role)
                      VALUES('$name','$u','$p','guest')");
        header("Location: index.php?action=login");
        exit;
    }
break;

/* ========== DASHBOARD ========== */
case 'dashboard':
    $role=$_SESSION['user']['role'];
    $show_dashboard=true;
break;

/* ========== BOOK ROOM FORM PAGE ========== */
case 'book_room_form':
    if ($_SESSION['user']['role']!='guest') die("Forbidden");
    $show_book_room_form=true;
break;

/* ========== ROOM STATUS PAGE ========== */
case 'room_status':
    $show_room_status=true;
break;

/* ========== RESERVATION STATUS PAGE ========== */
case 'reservation_status':
    $role=$_SESSION['user']['role'];

    if ($role=='guest') {
        $uid=$_SESSION['user']['id'];
        $data=$conn->query("
            SELECT r.id, rm.room_number, r.status,
                   p.amount, p.status AS pay_status,
                   u.name AS guest_name, p.id AS pay_id
            FROM reservations r
            JOIN guests g ON r.guest_id=g.id
            JOIN users u ON g.user_id=u.id
            JOIN rooms rm ON r.room_id=rm.id
            JOIN payments p ON p.reservation_id=r.id
            WHERE g.user_id=$uid
        ");
    } else {
        $data=$conn->query("
            SELECT r.id, rm.room_number, r.status,
                   p.amount, p.status AS pay_status,
                   p.id AS pay_id, u.name AS guest_name, p.method
            FROM reservations r
            JOIN guests g ON r.guest_id=g.id
            JOIN users u ON g.user_id=u.id
            JOIN rooms rm ON r.room_id=rm.id
            JOIN payments p ON p.reservation_id=r.id
        ");
    }
    $show_reservation_status=true;
break;

/* ========== ADD ROOM FORM PAGE ========== */
case 'add_room_form':
    if ($_SESSION['user']['role']!='admin') die("Admin only");
    $show_add_room_form=true;
break;

/* ========== BOOK ROOM (GUEST) ========== */
case 'book_room':
    if ($_SESSION['user']['role']!='guest') die("Forbidden");

    $uid=$_SESSION['user']['id'];
    $room=$_POST['room'];
    $ci=$_POST['checkin'];
    $co=$_POST['checkout'];

    /* check availability */
    $avail=$conn->query("SELECT id FROM rooms WHERE id=$room AND status='available'");
    if ($avail->num_rows==0) {
        $_SESSION['error']='Kamar tidak tersedia.';
        header("Location: index.php?action=dashboard");
        exit;
    }

    /* store temp booking in session */
    $_SESSION['temp_booking']=[
        'room_id'=>$room,
        'checkin'=>$ci,
        'checkout'=>$co
    ];

    header("Location: index.php?action=payment_page");
    exit;

/* ========== PAYMENT PAGE (GUEST) ========== */
case 'payment_page':
    if ($_SESSION['user']['role']!='guest') die("Forbidden");
    if (!isset($_SESSION['temp_booking'])) {
        header("Location: index.php?action=dashboard");
        exit;
    }

    $room_id=$_SESSION['temp_booking']['room_id'];
    $ci=$_SESSION['temp_booking']['checkin'];
    $co=$_SESSION['temp_booking']['checkout'];

    $room=$conn->query("SELECT * FROM rooms WHERE id=$room_id")->fetch_assoc();
    $night=(strtotime($co)-strtotime($ci))/(60*60*24);
    $total=$room['price_per_night']*$night;

    $show_payment_form=true;
break;

/* ========== PROCESS PAYMENT (GUEST) ========== */
case 'process_payment':
    if ($_SESSION['user']['role']!='guest') die("Forbidden");
    if (!isset($_SESSION['temp_booking'])) {
        header("Location: index.php?action=dashboard");
        exit;
    }

    $uid=$_SESSION['user']['id'];
    $method=$_POST['method'];
    $room_id=$_SESSION['temp_booking']['room_id'];
    $ci=$_SESSION['temp_booking']['checkin'];
    $co=$_SESSION['temp_booking']['checkout'];
    
    /* Calculate total */
    $harga=$conn->query("SELECT price_per_night FROM rooms WHERE id=$room_id")->fetch_assoc()['price_per_night'];
    $night=(strtotime($co)-strtotime($ci))/(60*60*24);
    $total=$harga*$night;

    /* For cash and card, show confirmation first */
    if (in_array($method, ['cash', 'card'])) {
        $_SESSION['temp_payment']=[
            'method'=>$method,
            'amount'=>$total
        ];
        header("Location: index.php?action=confirm_payment_method");
        exit;
    }

    /* For online methods, create reservation immediately */
    /* guest 1 user = 1 record */
    $cek=$conn->query("SELECT id FROM guests WHERE user_id=$uid");
    if ($cek->num_rows==0) {
        $conn->query("INSERT INTO guests(user_id) VALUES($uid)");
        $guest_id=$conn->insert_id;
    } else {
        $guest_id=$cek->fetch_assoc()['id'];
    }

    /* create reservation */
    $conn->query("INSERT INTO reservations(guest_id,room_id,check_in,check_out) VALUES($guest_id,$room_id,'$ci','$co')");
    $res_id=$conn->insert_id;

    /* create payment */
    $conn->query("INSERT INTO payments(reservation_id,amount,method,status) VALUES($res_id,$total,'$method','unpaid')");
    $pay_id=$conn->insert_id;

    unset($_SESSION['temp_booking']);

    /* set session for payment verification */
    $_SESSION['payment_info']=[
        'pay_id'=>$pay_id,
        'method'=>$method,
        'amount'=>$total,
        'created_at'=>time()
    ];

    header("Location: index.php?action=verify_payment");
    exit;

/* ========== CONFIRM PAYMENT METHOD (CASH/CARD) ========== */
case 'confirm_payment_method':
    if ($_SESSION['user']['role']!='guest') die("Forbidden");
    if (!isset($_SESSION['temp_booking']) || !isset($_SESSION['temp_payment'])) {
        header("Location: index.php?action=dashboard");
        exit;
    }

    $method=$_SESSION['temp_payment']['method'];
    $amount=$_SESSION['temp_payment']['amount'];
    $show_confirm_payment=true;
break;

/* ========== LANJUTKAN PAYMENT METHOD (CASH/CARD) ========== */
case 'lanjutkan_payment_method':
    if ($_SESSION['user']['role']!='guest') die("Forbidden");
    if (!isset($_SESSION['temp_booking']) || !isset($_SESSION['temp_payment'])) {
        header("Location: index.php?action=dashboard");
        exit;
    }

    $uid=$_SESSION['user']['id'];
    $method=$_SESSION['temp_payment']['method'];
    $amount=$_SESSION['temp_payment']['amount'];
    $room_id=$_SESSION['temp_booking']['room_id'];
    $ci=$_SESSION['temp_booking']['checkin'];
    $co=$_SESSION['temp_booking']['checkout'];

    /* guest 1 user = 1 record */
    $cek=$conn->query("SELECT id FROM guests WHERE user_id=$uid");
    if ($cek->num_rows==0) {
        $conn->query("INSERT INTO guests(user_id) VALUES($uid)");
        $guest_id=$conn->insert_id;
    } else {
        $guest_id=$cek->fetch_assoc()['id'];
    }

    /* create reservation */
    $conn->query("INSERT INTO reservations(guest_id,room_id,check_in,check_out) VALUES($guest_id,$room_id,'$ci','$co')");
    $res_id=$conn->insert_id;

    /* create payment */
    $conn->query("INSERT INTO payments(reservation_id,amount,method,status) VALUES($res_id,$amount,'$method','unpaid')");
    $pay_id=$conn->insert_id;

    unset($_SESSION['temp_booking']);
    unset($_SESSION['temp_payment']);

    /* set session for payment verification */
    $_SESSION['payment_info']=[
        'pay_id'=>$pay_id,
        'method'=>$method,
        'amount'=>$amount,
        'created_at'=>time()
    ];

    header("Location: index.php?action=verify_payment");
    exit;

/* ========== UBAH METODE PEMBAYARAN ========== */
case 'change_payment_method':
    if ($_SESSION['user']['role']!='guest') die("Forbidden");
    unset($_SESSION['temp_payment']);
    header("Location: index.php?action=payment_page");
    exit;

/* ========== VERIFY PAYMENT (GUEST) ========== */
case 'verify_payment':
    if ($_SESSION['user']['role']!='guest') die("Forbidden");
    if (!isset($_SESSION['payment_info'])) {
        header("Location: index.php?action=dashboard");
        exit;
    }

    $pay_id=$_SESSION['payment_info']['pay_id'];
    $method=$_SESSION['payment_info']['method'];
    $amount=$_SESSION['payment_info']['amount'];
    $created_at=$_SESSION['payment_info']['created_at'];
    $elapsed=time()-$created_at;

    /* timeout handling */
    if ($method!='cash' && $method!='card' && $elapsed > 300) { /* 5 minutes for online methods */
        /* mark payment as expired */
        $conn->query("UPDATE payments SET status='unpaid' WHERE id=$pay_id");
        unset($_SESSION['payment_info']);
        $_SESSION['error']='Waktu pembayaran berakhir. Silakan lakukan pemesanan ulang.';
        header("Location: index.php?action=dashboard");
        exit;
    }

    $show_verify_page=true;
break;

/* ========== CHANGE PAYMENT METHOD (GUEST) ========== */
case 'change_payment_method':
    if ($_SESSION['user']['role']!='guest') die("Forbidden");
    $pay_id=$_POST['pay_id'];
    $conn->query("DELETE FROM payments WHERE id=$pay_id");
    unset($_SESSION['payment_info']);
    header("Location: index.php?action=dashboard");
    exit;

/* ========== CONFIRM PAYMENT (STAFF / ADMIN) ========== */
case 'confirm_payment':
    if ($_SESSION['user']['role']=='guest') die("Forbidden");
    $pid=$_GET['id'];
    
    /* get reservation id from payment */
    $payment=$conn->query("SELECT reservation_id FROM payments WHERE id=$pid")->fetch_assoc();
    $res_id=$payment['reservation_id'];
    
    /* get room id from reservation */
    $reservation=$conn->query("SELECT room_id FROM reservations WHERE id=$res_id")->fetch_assoc();
    $room_id=$reservation['room_id'];
    
    /* mark payment as paid */
    $conn->query("UPDATE payments SET status='paid' WHERE id=$pid");
    
    /* mark room as booked */
    $conn->query("UPDATE rooms SET status='booked' WHERE id=$room_id");
    
    header("Location: index.php?action=reservation_status");
    exit;

/* ========== CANCEL PAYMENT (STAFF / ADMIN) ========== */
case 'cancel_payment':
    if ($_SESSION['user']['role']=='guest') die("Forbidden");
    $pid=$_GET['id'];
    
    /* get reservation id from payment */
    $payment=$conn->query("SELECT reservation_id FROM payments WHERE id=$pid")->fetch_assoc();
    $res_id=$payment['reservation_id'];
    
    /* get room id from reservation */
    $reservation=$conn->query("SELECT room_id FROM reservations WHERE id=$res_id")->fetch_assoc();
    $room_id=$reservation['room_id'];
    
    /* delete payment record */
    $conn->query("DELETE FROM payments WHERE id=$pid");
    
    /* delete reservation record */
    $conn->query("DELETE FROM reservations WHERE id=$res_id");
    
    /* mark room as available again */
    $conn->query("UPDATE rooms SET status='available' WHERE id=$room_id");
    
    header("Location: index.php?action=reservation_status");
    exit;

/* ========== ROOM MANAGEMENT (ADMIN) ========== */
case 'add_room':
    if ($_SESSION['user']['role']!='admin') die("Admin only");
    if ($_SERVER['REQUEST_METHOD']=='POST') {
        $no=$_POST['number'];
        $type=$_POST['type'];
        $price=$_POST['price'];
        $conn->query("
            INSERT INTO rooms(room_number,type,price_per_night,status)
            VALUES('$no','$type',$price,'available')
        ");
        header("Location: index.php?action=dashboard");
        exit;
    }
break;

/* ========== LOGOUT ========== */
case 'logout':
    session_destroy();
    header("Location: index.php?action=login");
    exit;
}
?>

<!DOCTYPE html>
<html>
<head>
<title>HotelMS</title>
<link rel="stylesheet" href="style.css">
</head>
<body>

<?php if ($action=='login'): ?>
<div class="card">
<h2>Login</h2>
<form method="post">
<input name="username" placeholder="Username" required>
<input type="password" name="password" placeholder="Password" required>
<button>Login</button>
</form>
<a href="?action=register">Daftar</a>
<?= isset($error)?$error:'' ?>
</div>

<?php elseif ($action=='register'): ?>
<div class="card">
<h2>Register Tamu</h2>
<form method="post">
<input name="name" placeholder="Nama" required>
<input name="username" placeholder="Username" required>
<input type="password" name="password" placeholder="Password" required>
<button>Register</button>
</form>
</div>

<?php else: ?>

<!-- NAVBAR -->
<div class="navbar">
<div class="navbar-brand">HotelMS</div>
<div class="navbar-menu">
<?php if ($_SESSION['user']['role']=='guest'): ?>
<a href="?action=dashboard" class="<?= $action=='dashboard' ? 'active' : '' ?>">Dashboard</a>
<a href="?action=book_room_form" class="<?= $action=='book_room_form' ? 'active' : '' ?>">Booking Kamar</a>
<a href="?action=room_status" class="<?= $action=='room_status' ? 'active' : '' ?>">Status Kamar</a>
<a href="?action=reservation_status" class="<?= $action=='reservation_status' ? 'active' : '' ?>">Status Reservasi</a>
<?php elseif ($_SESSION['user']['role']=='admin'): ?>
<a href="?action=dashboard" class="<?= $action=='dashboard' ? 'active' : '' ?>">Dashboard</a>
<a href="?action=room_status" class="<?= $action=='room_status' ? 'active' : '' ?>">Status Kamar</a>
<a href="?action=add_room_form" class="<?= $action=='add_room_form' ? 'active' : '' ?>">Tambah Kamar</a>
<a href="?action=reservation_status" class="<?= $action=='reservation_status' ? 'active' : '' ?>">Status Reservasi</a>
<?php elseif ($_SESSION['user']['role']=='staff'): ?>
<a href="?action=dashboard" class="<?= $action=='dashboard' ? 'active' : '' ?>">Dashboard</a>
<a href="?action=room_status" class="<?= $action=='room_status' ? 'active' : '' ?>">Status Kamar</a>
<a href="?action=reservation_status" class="<?= $action=='reservation_status' ? 'active' : '' ?>">Status Reservasi</a>
<?php endif; ?>
<a href="?action=logout" onclick="return confirm('Apakah Anda yakin ingin logout?');" class="logout-btn">Logout</a>
</div>
</div>

<div class="container">

<!-- DASHBOARD -->
<?php if ($action=='dashboard' && isset($show_dashboard)): ?>
<div class="dashboard">
<h2>Dashboard <?= ucfirst($_SESSION['user']['role']) ?></h2>

<?php if ($_SESSION['user']['role']=='guest'): ?>
<div class="welcome-message">
<p>Selamat datang, <strong><?= $_SESSION['user']['name'] ?></strong>!</p>
</div>

<div class="dashboard-shortcuts">
<a href="?action=book_room_form" class="shortcut-card shortcut-primary">
<h3>📅 Booking Kamar</h3>
<p>Pesan kamar favorit Anda sekarang</p>
</a>
<a href="?action=room_status" class="shortcut-card shortcut-info">
<h3>🏨 Status Kamar</h3>
<p>Lihat ketersediaan kamar</p>
</a>
<a href="?action=reservation_status" class="shortcut-card shortcut-success">
<h3>📋 Status Reservasi</h3>
<p>Kelola pemesanan Anda</p>
</a>
</div>

<?php else: /* Admin atau Staff */ ?>
<?php
/* Get statistics */
$stats_rooms=$conn->query("SELECT COUNT(*) as total, SUM(status='available') as ready FROM rooms")->fetch_assoc();
$stats_rooms['tidak_ready'] = $stats_rooms['total'] - $stats_rooms['ready'];
$stats_payments=$conn->query("SELECT COUNT(*) as total, SUM(status='paid') as paid, SUM(status='unpaid') as unpaid FROM payments")->fetch_assoc();
?>

<div class="stats-grid">
<div class="stat-card stat-primary">
<h3><?= $stats_rooms['ready'] ?></h3>
<p>Kamar Ready</p>
</div>
<div class="stat-card stat-danger">
<h3><?= $stats_rooms['tidak_ready'] ?></h3>
<p>Kamar Tidak Ready</p>
</div>
<div class="stat-card stat-success">
<h3><?= $stats_payments['paid'] ?></h3>
<p>Pembayaran Lunas</p>
</div>
<div class="stat-card stat-warning">
<h3><?= $stats_payments['unpaid'] ?></h3>
<p>Pembayaran Belum Lunas</p>
</div>
</div>

<div class="dashboard-shortcuts">
<a href="?action=room_status" class="shortcut-card shortcut-info">
<h3>🏨 Status Kamar</h3>
<p>Kelola status kamar</p>
</a>
<a href="?action=reservation_status" class="shortcut-card shortcut-success">
<h3>📋 Status Reservasi</h3>
<p>Lihat semua reservasi</p>
</a>
<?php if ($_SESSION['user']['role']=='admin'): ?>
<a href="?action=add_room_form" class="shortcut-card shortcut-primary">
<h3>➕ Tambah Kamar</h3>
<p>Tambahkan kamar baru</p>
</a>
<?php endif; ?>
</div>
<?php endif; ?>
</div>

<!-- BOOKING KAMAR FORM -->
<?php elseif ($action=='book_room_form' && isset($show_book_room_form)): ?>
<?php if (isset($_SESSION['error'])) { echo '<div class="error">'.$_SESSION['error'].'</div>'; unset($_SESSION['error']); } ?>
<div class="content-page">
<h2>Booking Kamar</h2>

<div class="page-grid">
<div class="form-section">
<h3>Form Pemesanan</h3>
<form class="booking-form" method="post" action="?action=book_room">
<div class="form-group">
<label>Pilih Kamar:</label>
<select name="room" required>
<option value="">-- Pilih Kamar --</option>
<?php
$r=$conn->query("SELECT * FROM rooms WHERE status='available'");
while($x=$r->fetch_assoc()):
?>
<option value="<?= $x['id'] ?>">
<?= $x['room_number']." - ".$x['type']." (Rp ".number_format($x['price_per_night'],0,',','.').")" ?>
</option>
<?php endwhile; ?>
</select>
</div>
<div class="form-group">
<label>Check-in:</label>
<input type="date" name="checkin" required>
</div>
<div class="form-group">
<label>Check-out:</label>
<input type="date" name="checkout" required>
</div>
<button class="btn-primary">Booking Kamar</button>
</form>
</div>

<div class="table-section">
<h3>Status Kamar Tersedia</h3>
<table>
<tr>
<th>No Kamar</th>
<th>Tipe</th>
<th>Harga/Malam</th>
<th>Status</th>
</tr>
<?php
$r2=$conn->query("SELECT * FROM rooms");
while($room=$r2->fetch_assoc()):
?>
<tr>
<td><?= $room['room_number'] ?></td>
<td><?= $room['type'] ?></td>
<td>Rp <?= number_format($room['price_per_night'],0,',','.') ?></td>
<td><span class="badge <?= $room['status']=='available' ? 'badge-success' : 'badge-danger' ?>"><?= $room['status']=='available' ? 'Ready' : 'Tidak Ready' ?></span></td>
</tr>
<?php endwhile; ?>
</table>
</div>
</div>
</div>

<!-- ROOM STATUS -->
<?php elseif ($action=='room_status' && isset($show_room_status)): ?>
<div class="content-page">
<h2>Status Kamar</h2>

<table class="table-full">
<tr>
<th>No Kamar</th>
<th>Tipe</th>
<th>Harga/Malam</th>
<th>Status</th>
</tr>
<?php
$r2=$conn->query("SELECT * FROM rooms");
while($room=$r2->fetch_assoc()):
?>
<tr>
<td><?= $room['room_number'] ?></td>
<td><?= $room['type'] ?></td>
<td>Rp <?= number_format($room['price_per_night'],0,',','.') ?></td>
<td><span class="badge <?= $room['status']=='available' ? 'badge-success' : 'badge-danger' ?>"><?= $room['status']=='available' ? 'Ready' : 'Tidak Ready' ?></span></td>
</tr>
<?php endwhile; ?>
</table>
</div>

<!-- RESERVATION STATUS -->
<?php elseif ($action=='reservation_status' && isset($show_reservation_status)): ?>
<div class="content-page">
<h2>Status Reservasi</h2>

<table class="table-full">
<tr>
<th>Nama Pemesan</th>
<th>Kamar</th>
<th>Status</th>
<th>Total</th>
<th>Pembayaran</th>
<?php if ($_SESSION['user']['role']!='guest'): ?>
<th>Metode Pembayaran</th>
<th>Aksi</th>
<?php endif; ?>
</tr>
<?php while($d=$data->fetch_assoc()): ?>
<tr>
<td><?= $d['guest_name'] ?></td>
<td><?= $d['room_number'] ?></td>
<td><span class="badge badge-info"><?= $d['status'] ?></span></td>
<td>Rp <?= number_format($d['amount'],0,',','.') ?></td>
<td><span class="badge <?= $d['pay_status']=='paid' ? 'badge-success' : 'badge-warning' ?>"><?= $d['pay_status']=='paid' ? 'Lunas' : 'Belum Lunas' ?></span></td>
<?php if ($_SESSION['user']['role']!='guest'): ?>
<td>
<?php 
$method_display=[
    'qris'=>'QRIS',
    'transfer'=>'Transfer Bank',
    'ewallet'=>'E-Wallet',
    'cash'=>'Tunai',
    'card'=>'Kartu Kredit/Debit'
];
echo isset($method_display[$d['method']]) ? $method_display[$d['method']] : ucfirst($d['method']);
?>
</td>
<td>
<?php if ($d['pay_status']=='unpaid'): ?>
<a href="?action=confirm_payment&id=<?= $d['pay_id'] ?>" class="btn-small btn-confirm" style="margin-right: 5px;">Konfirmasi</a>
<a href="?action=cancel_payment&id=<?= $d['pay_id'] ?>" class="btn-small" style="background: #e74c3c; color: white; padding: 8px 15px; font-size: 13px; border-radius: 6px; text-decoration: none; display: inline-block;" onclick="return confirm('Apakah Anda yakin ingin membatalkan pemesanan ini?');">Batalkan</a>
<?php else: ?>
<span class="text-muted">-</span>
<?php endif; ?>
</td>
<?php endif; ?>
</tr>
<?php endwhile; ?>
</table>
</div>

<!-- ADD ROOM FORM -->
<?php elseif ($action=='add_room_form' && isset($show_add_room_form)): ?>
<div class="content-page">
<h2>Tambah Kamar</h2>

<div class="form-container">
<form class="booking-form" method="post" action="?action=add_room">
<div class="form-group">
<label>Nomor Kamar:</label>
<input type="text" name="number" placeholder="Contoh: 101, 102, dll" required>
</div>
<div class="form-group">
<label>Tipe Kamar:</label>
<input type="text" name="type" placeholder="Contoh: Standar, Deluxe, Suite" required>
</div>
<div class="form-group">
<label>Harga per Malam (Rp):</label>
<input type="number" name="price" placeholder="Contoh: 500000" required>
</div>
<button class="btn-primary">Tambah Kamar</button>
</form>
</div>
</div>

<!-- PAYMENT PAGES -->
<?php elseif ($action=='payment_page' && isset($show_payment_form)): ?>
<div class="card">
<h2>Pilih Metode Pembayaran</h2>
<p>Total Pembayaran: <strong>Rp <?= number_format($total, 0, ',', '.') ?></strong></p>
<p>Nomor Kamar: <strong><?= $room['room_number'] ?></strong> (<?= $room['type'] ?>)</p>
<p>Check-in: <?= $ci ?> | Check-out: <?= $co ?></p>

<form method="post" action="?action=process_payment">
<div class="payment-methods">
<label><input type="radio" name="method" value="qris" checked> QRIS</label>
<label><input type="radio" name="method" value="transfer"> Transfer Bank</label>
<label><input type="radio" name="method" value="ewallet"> E-Wallet</label>
<label><input type="radio" name="method" value="cash"> Cash</label>
<label><input type="radio" name="method" value="card"> Kartu Kredit/Debit</label>
</div>
<button>Lanjutkan Pembayaran</button>
</form>
<a href="?action=dashboard" style="margin-top: 10px; display: inline-block;">Batalkan</a>
</div>

<?php elseif ($action=='confirm_payment_method' && isset($show_confirm_payment)): ?>
<div class="card">
<h2>Konfirmasi Metode Pembayaran</h2>
<div class="payment-info">
<?php
if ($method=='cash'):
    ?>
    <h3>Pembayaran dengan Tunai</h3>
    <div class="alert alert-warning">
    <strong>⚠️ Penting!</strong><br>
    Anda akan melakukan pembayaran kepada staff kami di resepsionis paling lambat 1 hari setelah pemesanan untuk memproses pemesanan Anda. Selama pembayaran belum dilakukan, status kamar tetap dalam kondisi ready dan belum dianggap sebagai pemesanan yang sah. Pemesanan akan diteruskan ke admin untuk dikonfirmasi.
    </div>
    <p>Jumlah Pembayaran: <strong>Rp <?= number_format($amount, 0, ',', '.') ?></strong></p>
    <?php
elseif ($method=='card'):
    ?>
    <h3>Pembayaran dengan Kartu Kredit/Debit</h3>
    <div class="alert alert-warning">
    <strong>⚠️ Penting!</strong><br>
    Silakan berikan kartu Anda kepada staff kami di resepsionis. Anda harus segera melakukan pembayaran paling lambat 1 hari setelah pemesanan untuk memproses pemesanan Anda. Selama pembayaran belum dilakukan, status kamar tetap dalam kondisi ready dan belum dianggap sebagai pemesanan yang sah. Pemesanan akan diteruskan ke admin untuk dikonfirmasi.
    </div>
    <p>Jumlah Pembayaran: <strong>Rp <?= number_format($amount, 0, ',', '.') ?></strong></p>
    <?php
endif;
?>
</div>
<div style="margin-top: 20px;">
    <form method="post" action="?action=lanjutkan_payment_method" style="display: inline;">
    <button class="btn-primary">Lanjutkan</button>
    </form>
    <form method="post" action="?action=change_payment_method" style="display: inline; margin-left: 10px;">
    <button type="submit" style="background: orange; color: white; padding: 10px 20px; border: none; border-radius: 6px; cursor: pointer;">Ubah Metode Pembayaran</button>
    </form>
</div>
</div>

<?php elseif ($action=='verify_payment' && isset($show_verify_page)): ?>
<div class="card">
<h2>Verifikasi Pembayaran</h2>
<div class="payment-info">
<?php
if ($method=='qris'):
    $qr_url="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=https://hotel.local/pay/".$pay_id;
    ?>
    <h3>Pembayaran via QRIS</h3>
    <p>Silakan pindai kode QR di bawah ini menggunakan aplikasi pembayaran Anda:</p>
    <img src="<?= $qr_url ?>" alt="QRIS" style="max-width: 200px; margin: 20px 0;">
    <p>Jumlah Pembayaran: <strong>Rp <?= number_format($amount, 0, ',', '.') ?></strong></p>
    <p><em>Sisa waktu pembayaran: <span id="timer">5:00</span> menit</em></p>
    <?php
elseif ($method=='transfer'):
    ?>
    <h3>Pembayaran via Transfer Bank</h3>
    <p>Silakan lakukan transfer ke salah satu rekening di bawah ini:</p>
    <table style="width: 100%; margin: 15px 0;">
    <tr><td><strong>Bank BCA</strong></td><td>1234567890 a/n PT Hotel Kami</td></tr>
    <tr><td><strong>Bank Mandiri</strong></td><td>1230004567890 a/n PT Hotel Kami</td></tr>
    <tr><td><strong>Bank BNI</strong></td><td>0123456789 a/n PT Hotel Kami</td></tr>
    </table>
    <p>Jumlah Pembayaran: <strong>Rp <?= number_format($amount, 0, ',', '.') ?></strong></p>
    <p><em>Sisa waktu pembayaran: <span id="timer">5:00</span> menit</em></p>
    <?php
elseif ($method=='ewallet'):
    ?>
    <h3>Pembayaran via E-Wallet</h3>
    <p>Silakan lakukan transfer ke nomor berikut sesuai dengan e-wallet pilihan Anda:</p>
    <table style="width: 100%; margin: 15px 0;">
    <tr><td><strong>Dana</strong></td><td>081234567890</td></tr>
    <tr><td><strong>Spay</strong></td><td>081234567890</td></tr>
    <tr><td><strong>GoPay</strong></td><td>081234567890</td></tr>
    <tr><td><strong>OVO</strong></td><td>081234567890</td></tr>
    </table>
    <p>Jumlah Pembayaran: <strong>Rp <?= number_format($amount, 0, ',', '.') ?></strong></p>
    <p><em>Sisa waktu pembayaran: <span id="timer">5:00</span> menit</em></p>
    <?php
elseif ($method=='cash'):
    ?>
    <h3>Pembayaran dengan Tunai</h3>
    <div class="alert alert-warning">
    <strong>✓ Pesanan Diterima!</strong><br>
    Pesanan Anda telah diteruskan ke admin. Silakan lakukan pembayaran kepada staff kami di resepsionis paling lambat 1 hari setelah pemesanan untuk memproses pemesanan Anda.
    </div>
    <p>Jumlah Pembayaran: <strong>Rp <?= number_format($amount, 0, ',', '.') ?></strong></p>
    <?php
elseif ($method=='card'):
    ?>
    <h3>Pembayaran dengan Kartu Kredit/Debit</h3>
    <div class="alert alert-warning">
    <strong>✓ Pesanan Diterima!</strong><br>
    Pesanan Anda telah diteruskan ke admin. Silakan berikan kartu Anda kepada staff kami di resepsionis. Anda harus segera melakukan pembayaran paling lambat 1 hari setelah pemesanan untuk memproses pemesanan Anda.
    </div>
    <p>Jumlah Pembayaran: <strong>Rp <?= number_format($amount, 0, ',', '.') ?></strong></p>
    <?php
endif;
?>
</div>
<a href="?action=dashboard" style="margin-top: 10px; display: inline-block;">Kembali ke Dashboard</a>
</div>

<?php if (in_array($method, ['qris', 'transfer', 'ewallet'])): ?>
<script>
var timeLeft = 300;
setInterval(function() {
    timeLeft--;
    var min = Math.floor(timeLeft / 60);
    var sec = timeLeft % 60;
    document.getElementById('timer').textContent = min + ':' + (sec < 10 ? '0' : '') + sec;
    if (timeLeft <= 0) {
        window.location.href = '?action=dashboard';
    }
}, 1000);
</script>
<?php endif; ?>

<?php endif; ?>

</div><!-- end container -->
<?php endif; ?>

</body>
</html>
