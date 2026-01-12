<?php
use PHPUnit\Framework\TestCase;

final class RateLimitTest extends TestCase {
  public function testRateLimitApiRequests(): void {
    $username = 'rl_' . bin2hex(random_bytes(4));
    $email = $username . '@example.com';
    $uid = jarvis_create_user($username, $email, null, password_hash('pass', PASSWORD_DEFAULT), bin2hex(random_bytes(12)));
    $this->assertIsInt($uid);
    $pdo = jarvis_pdo(); $this->assertNotNull($pdo);
    // Insert 10 recent api_requests rows for /api/photos
    for ($i=0; $i<10; $i++) {
      $stmt = $pdo->prepare('INSERT INTO api_requests (user_id,client_type,endpoint,method,status_code) VALUES (:u, :c, :e, :m, :s)');
      $stmt->execute([':u'=>$uid, ':c'=>'web', ':e'=>'/api/photos', ':m'=>'POST', ':s'=>201]);
    }
    $allowed = jarvis_rate_limit($uid, '/api/photos', 15);
    $this->assertTrue($allowed);
    // Add more to exceed
    for ($i=0; $i<6; $i++) {
      $stmt = $pdo->prepare('INSERT INTO api_requests (user_id,client_type,endpoint,method,status_code) VALUES (:u, :c, :e, :m, :s)');
      $stmt->execute([':u'=>$uid, ':c'=>'web', ':e'=>'/api/photos', ':m'=>'POST', ':s'=>201]);
    }
    $allowed2 = jarvis_rate_limit($uid, '/api/photos', 15);
    $this->assertFalse($allowed2);
  }
}
