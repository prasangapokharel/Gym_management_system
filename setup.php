<?php
require_once 'includes/db.php';

// Check if admin user already exists
$stmt = $conn->prepare("SELECT id FROM admins WHERE username = 'admin'");
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
  // Create admin user
  $username = 'admin';
  $password = 'admin123';
  $email = 'admin@example.com';
  $fullname = 'System Administrator';
  
  // Hash password
  $hashed_password = password_hash($password, PASSWORD_DEFAULT);
  
  // Insert admin user
  $stmt = $conn->prepare("INSERT INTO admins (username, password, email, fullname) VALUES (?, ?, ?, ?)");
  $stmt->bind_param("ssss", $username, $hashed_password, $email, $fullname);
  
  if ($stmt->execute()) {
      echo "<div style='font-family: Arial, sans-serif; max-width: 600px; margin: 50px auto; padding: 20px; border: 1px solid #ddd; border-radius: 5px;'>";
      echo "<h2 style='color: #28a745;'>Admin Account Created Successfully</h2>";
      echo "<p>The admin account has been created with the following credentials:</p>";
      echo "<ul>";
      echo "<li><strong>Username:</strong> admin</li>";
      echo "<li><strong>Password:</strong> admin123</li>";
      echo "</ul>";
      echo "<p>Please <a href='index.php' style='color: #007bff; text-decoration: none;'>login</a> with these credentials.</p>";
      echo "<p style='color: #dc3545;'><strong>Important:</strong> Delete this setup.php file after successful setup for security reasons.</p>";
      echo "</div>";
  } else {
      echo "<div style='font-family: Arial, sans-serif; max-width: 600px; margin: 50px auto; padding: 20px; border: 1px solid #ddd; border-radius: 5px;'>";
      echo "<h2 style='color: #dc3545;'>Error</h2>";
      echo "<p>Failed to create admin account: " . $stmt->error . "</p>";
      echo "</div>";
  }
} else {
  echo "<div style='font-family: Arial, sans-serif; max-width: 600px; margin: 50px auto; padding: 20px; border: 1px solid #ddd; border-radius: 5px;'>";
  echo "<h2 style='color: #ffc107;'>Admin Account Already Exists</h2>";
  echo "<p>An admin account already exists in the database.</p>";
  echo "<p>If you've forgotten the password, you can use the <a href='forgot-password.php' style='color: #007bff; text-decoration: none;'>forgot password</a> feature or manually update the password in the database.</p>";
  echo "<p><a href='index.php' style='color: #007bff; text-decoration: none;'>Go to login page</a></p>";
  echo "</div>";
}

$stmt->close();
$conn->close();
?>

