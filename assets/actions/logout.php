<?php
session_start();

// Detect role before destroying session
$role = $_SESSION['role'] ?? 'citizen';

// Destroy session completely
session_unset();
session_destroy();

// Redirect based on role
if ($role === 'ho') {
    header("Location: /DengResQ/ho/views/ho_auth.php");
} elseif ($role === 'hospital') {
     // ✅ Fixed path — matches actual file location
    header("Location: /DengResQ/hospital/views/hospital_auth.php");
} else {
    header("Location: /DengResQ/citizen/views/citizen_auth.php");
}
exit();
?>