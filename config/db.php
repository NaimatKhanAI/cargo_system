<?php
$host="localhost";
$user="root";
$pass="";
$db="cargo_system";

$conn=new mysqli($host,$user,$pass);
$conn->query("CREATE DATABASE IF NOT EXISTS $db");
$conn->select_db($db);

$conn->query("CREATE TABLE IF NOT EXISTS users(
id INT AUTO_INCREMENT PRIMARY KEY,
username VARCHAR(50),
password VARCHAR(50)
)");

$conn->query("CREATE TABLE IF NOT EXISTS bilty(
id INT AUTO_INCREMENT PRIMARY KEY,
date DATE,
vehicle VARCHAR(50),
bilty_no VARCHAR(50),
party VARCHAR(100),
location VARCHAR(100),
freight INT,
tender INT,
profit INT
)");

$colCheck = $conn->query("SHOW COLUMNS FROM bilty LIKE 'party'");
if($colCheck && $colCheck->num_rows === 0){
$conn->query("ALTER TABLE bilty ADD party VARCHAR(100) AFTER bilty_no");
}

$conn->query("CREATE TABLE IF NOT EXISTS account_entries(
id INT AUTO_INCREMENT PRIMARY KEY,
entry_date DATE,
category VARCHAR(20),
entry_type VARCHAR(10),
amount DECIMAL(12,2),
note VARCHAR(255),
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$check=$conn->query("SELECT * FROM users WHERE username='admin'");
if($check->num_rows==0){
$conn->query("INSERT INTO users(username,password) VALUES('admin','1234')");
}
?>
