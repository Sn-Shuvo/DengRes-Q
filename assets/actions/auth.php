<?php
ob_start();
session_start();
require_once __DIR__ . '/../../config/db_connect.php';
require_once '../../includes/gamification.php'; // ADD line 5

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $role   = $_POST['role']   ?? '';
    $action = $_POST['action'] ?? '';

    // ── LOGIN ─────────────────────────────────────────────────────────────────
    if ($action == 'login') {

        if ($role == 'citizen') {
            $id_val = $_POST['bcn'];
            $pass   = $_POST['pass'];
            $stmt   = $conn->prepare("SELECT bcn, password FROM citizens WHERE bcn = ?");
            $stmt->bind_param("s", $id_val);
            $stmt->execute();
            $user   = $stmt->get_result()->fetch_assoc();

            if ($user && password_verify($pass, $user['password'])) {
                $_SESSION['role']         = 'citizen';
                $_SESSION['user_id']      = $user['bcn'];
                   Gamification::syncWard($conn, $user['bcn']); // ADD line 25
                $_SESSION['reporter_bcn'] = $user['bcn'];
                header("Location: ../../citizen/views/dashboard.php");
                exit();
            } else {
                echo "<script>alert('Invalid credentials.'); window.history.back();</script>";
            }

        } elseif ($role == 'hospital') {
            $id_val = $_POST['h_id'];
            $pass   = $_POST['pass'];
            $stmt   = $conn->prepare("SELECT h_system_id, password FROM hospitals WHERE h_system_id = ?");
            $stmt->bind_param("s", $id_val);
            $stmt->execute();
            $user   = $stmt->get_result()->fetch_assoc();

            if ($user && (password_verify($pass, $user['password']) || $pass === $user['password'])) {
                session_regenerate_id(true);
                $_SESSION['role']        = 'hospital';
                $_SESSION['user_id']     = $user['h_system_id'];
                $_SESSION['hospital_id'] = $user['h_system_id'];
                $_SESSION['logged_in']   = true;
                $_SESSION['login_time']  = time();
                header("Location: ../../hospital/views/dashboard.php");
                exit();
            }
            echo "<script>alert('Invalid Hospital ID or Password.'); window.history.back();</script>";

        } elseif ($role == 'ho') {
            $raw_badge = trim($_POST['badge_number']);
            $raw_badge = preg_replace('/^HO-/i', '', $raw_badge);
            $id_val    = 'HO-' . $raw_badge;
            $division  = $_POST['division'];
            $pass      = $_POST['pin'];

            $stmt = $conn->prepare("
                SELECT badge_number, name, division, pin 
                FROM health_officers 
                WHERE badge_number = ? AND division = ?
            ");
            $stmt->bind_param("ss", $id_val, $division);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();

            if ($user && password_verify($pass, $user['pin'])) {
                $_SESSION['role']         = 'ho';
                $_SESSION['user_id']      = $user['badge_number'];
                $_SESSION['badge_number'] = $user['badge_number'];
                $_SESSION['ho_name']      = $user['name'];
                $_SESSION['ho_division']  = $user['division'];
                $_SESSION['login_time']   = time();
                header("Location: ../../ho/views/dashboard.php");
                exit();
            } else {
                echo "<script>alert('Invalid credentials.'); window.history.back();</script>";
            }
        }
    }

    // ── REGISTER ──────────────────────────────────────────────────────────────
    elseif ($action == 'register') {

        if ($role == 'citizen') {
            $bcn      = mysqli_real_escape_string($conn, $_POST['bcn']);
            $full_name= mysqli_real_escape_string($conn, $_POST['full_name'] ?? '');
            $phone    = mysqli_real_escape_string($conn, $_POST['phone']     ?? '');
            $age      = intval($_POST['age']);
            $email    = mysqli_real_escape_string($conn, $_POST['email']     ?? '');
           $blood    = mysqli_real_escape_string($conn, $_POST['blood_group']);
$bg_row   = mysqli_query($conn, "SELECT blood_group_id FROM blood_groups WHERE group_name='$blood'");
$bg_id    = ($bg_row && $r = mysqli_fetch_assoc($bg_row)) ? (int)$r['blood_group_id'] : 5;
            $lat      = floatval($_POST['lat']);
            $lng      = floatval($_POST['lng']);
            $location = mysqli_real_escape_string($conn, $_POST['location_label']);
            $pass     = password_hash($_POST['pass'], PASSWORD_DEFAULT);

            $check = mysqli_query($conn, "SELECT bcn FROM citizens WHERE bcn='$bcn'");
            if (mysqli_num_rows($check) > 0) {
                echo "<script>alert('BCN already registered.'); window.history.back();</script>";
                exit();
            }

            $sql = "INSERT INTO citizens 
            (bcn, full_name, phone, age, blood_group_id,
             latitude, longitude, location_label, password, email)
        VALUES
            ('$bcn','$full_name','$phone',$age,$bg_id,
             $lat,$lng,'$location','$pass','$email')";

            if (mysqli_query($conn, $sql)) {
                header("Location: ../../citizen/views/citizen_auth.php?status=success");
            } else {
                echo "<script>alert('Registration failed: " . mysqli_error($conn) . "'); window.history.back();</script>";
            }
            exit();
        }

        elseif ($role == 'hospital') {
            $h_id    = mysqli_real_escape_string($conn, $_POST['h_system_id'] ?? '');
            $h_name  = mysqli_real_escape_string($conn, $_POST['h_name']      ?? '');
            $license = mysqli_real_escape_string($conn, $_POST['license_no']  ?? '');
            $beds    = intval($_POST['total_beds'] ?? 0);
            $f_type  = mysqli_real_escape_string($conn, $_POST['facility_type'] ?? '');
            $email   = mysqli_real_escape_string($conn, $_POST['email']   ?? '');
            $phone   = mysqli_real_escape_string($conn, $_POST['phone']   ?? '');
            $address = mysqli_real_escape_string($conn, $_POST['address'] ?? '');
            $lat     = floatval($_POST['lat'] ?? 0);
            $lng     = floatval($_POST['lng'] ?? 0);

            // Password validation
            if (empty($_POST['pass'])) {
                echo "<script>alert('Password is required.'); window.history.back();</script>";
                exit();
            }
            if ($_POST['pass'] !== $_POST['pass_confirm']) {
                echo "<script>alert('Passwords do not match.'); window.history.back();</script>";
                exit();
            }

            $pass = password_hash($_POST['pass'], PASSWORD_DEFAULT);

            // Check duplicate ID
            $check = mysqli_query($conn, "
                SELECT h_system_id FROM hospitals WHERE h_system_id='$h_id'
            ");
            if (mysqli_num_rows($check) > 0) {
                echo "<script>alert('This System ID is already taken. Refresh and try again.'); window.history.back();</script>";
                exit();
            }

            $sql = "INSERT INTO hospitals 
                    (h_system_id, h_name, license_no, total_beds, facility_type,
                     email, phone, address, latitude, longitude, password) 
                    VALUES 
                    ('$h_id','$h_name','$license',$beds,'$f_type',
                     '$email','$phone','$address',$lat,$lng,'$pass')";

            if (mysqli_query($conn, $sql)) {
                echo "<script>
                    alert('✅ Facility registered successfully!\\n\\nYour Login ID: $h_id\\n\\nSave this ID — you will need it to login.');
                    window.location.href = '../../hospital/views/hospital_auth.php?status=success';
                </script>";
            } else {
                echo "<script>
                    alert('Registration failed: " . addslashes(mysqli_error($conn)) . "');
                    window.history.back();
                </script>";
            }
            exit();
        }
    }
}
?>